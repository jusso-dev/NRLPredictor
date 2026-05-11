# NRL Try Predictor

Signal-driven NRL match and try-scorer prediction engine. Scrapes live data from nrl.com, runs a weighted signal model to rank likely try scorers, predicts match winners, enriches with live bookmaker odds, and optionally refines predictions with a Claude-powered analyst agent. Ships with a Tailwind/Livewire dashboard, a chat interface, a multi-bet builder, and a full public REST API.

> **Why this exists.** It's a portfolio piece exploring weighted-signal modelling, AI agent tool-use, and a clean separation between deterministic statistical scoring and LLM-driven qualitative review. Predictions are for entertainment only — not betting advice.

![PHP](https://img.shields.io/badge/PHP-8.3-777BB4?logo=php&logoColor=white)
![Laravel](https://img.shields.io/badge/Laravel-11-FF2D20?logo=laravel&logoColor=white)
![Livewire](https://img.shields.io/badge/Livewire-3-4E56A6)
![Python](https://img.shields.io/badge/Python-3.11-3776AB?logo=python&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8.0-4479A1?logo=mysql&logoColor=white)
![License](https://img.shields.io/badge/license-MIT-green)

## Architecture

```
┌──────────────────────────────────────────────────────────┐
│  Docker Compose                                          │
│                                                          │
│  ┌─────────┐   ┌─────────┐   ┌───────────┐   ┌───────┐ │
│  │  app    │──▶│  queue  │   │ scheduler │   │ mysql │ │
│  │ Laravel │   │ worker  │   │  cron     │   │  8.0  │ │
│  │ :8000   │   │         │   │           │   │ :3307 │ │
│  └────┬────┘   └─────────┘   └───────────┘   └───────┘ │
│       │                                                  │
│       │ HTTP (internal)                                  │
│       ▼                                                  │
│  ┌──────────┐                                            │
│  │  agent   │  Python Flask + Claude Agent SDK           │
│  │  :5055   │  AI analysis, chat, tool-use agent loop   │
│  └──────────┘                                            │
└──────────────────────────────────────────────────────────┘
```

| Service | Purpose |
|---|---|
| **app** | Laravel 11 (PHP 8.3) — web UI, API, scraper jobs |
| **queue** | Processes background jobs (fetches, scoring, AI analysis) |
| **scheduler** | Runs Laravel's `schedule:work` for periodic data refreshes |
| **agent** | Python Flask service wrapping Claude Agent SDK for AI review and chat |
| **mysql** | MySQL 8.0 data store |

## Quick start

```bash
# 1. Copy env and set your keys
cp .env.example .env
php artisan key:generate  # or set APP_KEY manually

# Required for AI features:
#   CLAUDE_AGENT_TOKEN        — Anthropic API key
#   CLAUDE_AGENT_INTERNAL_SECRET — shared secret (openssl rand -hex 32)

# 2. Start everything
docker compose up -d --build

# 3. Seed the 17 NRL teams (required on first run)
docker compose exec app php artisan db:seed --class=TeamSeeder

# 4. Fetch current round data + generate predictions
docker compose exec app php artisan nrl:fetch-draw
docker compose exec app php artisan nrl:fetch-all
docker compose exec app php artisan nrl:predict

# App is now live at http://localhost:8000
```

## Data pipeline

### Scraper jobs

All scraping jobs implement `ShouldQueue` and are dispatched by the scheduler:

| Job | Schedule | Source | What it does |
|---|---|---|---|
| `FetchDraw` | Daily 04:30 | nrl.com/draw/data | Upserts rounds + match fixtures. Defaults to current round (+1) only; use `--all` for full season |
| `FetchTeamLists` | Every 30 min | nrl.com | Named team lists per match (1-17 + bench) |
| `FetchInjuryUpdates` | Every 30 min | nrl.com | Injury/suspension statuses |
| `FetchPlayerStats` | Every 2 hours | nrl.com player profiles | Career & season stats per player |
| `FetchNrlArticles` | Every 6 hours | nrl.com | Articles tagged by team |
| `FetchLiveScores` | Every 5 min (when live) | nrl.com | In-play scores |

### One-shot commands

| Command | Description |
|---|---|
| `nrl:fetch-draw` | Pull fixtures. `--round=7` for specific round, `--all` for all 27 |
| `nrl:fetch-all` | Run every scraper synchronously (good for bootstrapping) |
| `nrl:predict` | Score current round (or `nrl:predict 7`). Add `--ai` for Claude AI review |
| `nrl:seed-round` | Seed a hardcoded fixture list (fallback if scraper is down) |

### Sync flow

Clicking **"Sync round"** on the dashboard dispatches `SyncCurrentRoundData`, which chains:

```
FetchDraw → FetchTeamLists → FetchInjuryUpdates → FetchNrlArticles → RunPredictionAnalysis → DispatchAiAnalysisFanout
```

## Prediction engine

### Try-scorer signals (`SignalCalculator`)

Each player in the team list is scored against a stack of weighted signals, each producing a `strength` in `[0, 1]` and contributing `weight × strength` to the player's raw score. Final scores are normalised to `0–100` per match, and the top 15 are persisted as `Prediction` rows.

Signals fall into a few buckets:

- **Player form & history** — season try rate, recency-weighted EMA over recent games, career try rate, head-to-head try rate, venue record, milestone-game bumps, returning-player boost.
- **Role & position** — base position weight (wingers > props), starter vs interchange role, edge-mismatch detection.
- **Team attacking context** — team attacking form, possession share, set efficiency, tackle breaks, offload rate, kick pressure, explosive-rate (per-carry breaks/offloads), forced drop-outs, first-try rate.
- **Opponent weakness** — missing edge defenders, opponent try-concede rate, post-contact metres conceded, completion-pressure, late-game concede share, first-try concede share, yardage conceded, set-concede rate, explosive-rate conceded, opponent ruck penalties.
- **Market signals** — anytime try-scorer bookmaker odds, match total line, team favouritism (when `ODDS_API_KEY` is configured).

Signal weights live in `config/nrl-weights.php` and may be overlaid by tuned weights stored in `signal_weight_runs`. Each signal returns a structured `type / weight / strength / description` tuple so the UI can render a per-leg explanation.

### Match winner signals (`WinPredictor`)

Each match gets a home/away win probability from a stack of differential signals — `recent_form`, `head_to_head`, `injury_impact`, `points_for`, `points_against`, `home_advantage`, `squad_stability`, `explosive_rate_diff`, and `drop_out_diff` — stored on the `matches` table (`home_win_pct`, `away_win_pct`, `predicted_winner_id`, `win_signals`).

### AI review (optional)

When `--ai` is passed or the scheduler fires `DispatchAiAnalysisFanout`, each match is sent to the Python agent service. The Claude agent:

1. Reads match context, team lists, injuries via tool calls back into Laravel
2. Reviews the statistical predictions
3. Checks team articles for narrative factors
4. Submits adjusted scores and reasoning per player

AI reasoning is stored in `predictions.ai_reasoning`.

### Multi-bet builder (`MultiBetBuilder`)

Combines match winner and try-scorer predictions into a suggested multi-bet:

- Reserves slots for both leg types (roughly 35% winners, 65% try scorers)
- Max 2 legs per match for diversity
- Three risk profiles: `safe`, `balanced`, `value`
- Estimates per-leg probability and confidence
- Calculates combined probability across all legs
- Enriches each leg with live bookmaker odds when available

### Bookmaker odds (`FetchOdds`)

`nrl:fetch-odds` pulls live NRL odds from [The Odds API](https://the-odds-api.com) (`rugbyleague_nrl` sport key). It fetches match-level h2h, spreads, and totals from AU bookmakers, plus per-event anytime try-scorer odds. Snapshots are stored in `odds_snapshots` and enriched onto multi-bet legs. The scheduler runs every 4 hours to conserve API credits. Use `--no-player-props` to skip the per-event player props pass.

## Web UI

Built with **Livewire 3** + **Tailwind CSS**. Dark/light theme toggle.

| Route | Page | Description |
|---|---|---|
| `/` | Dashboard | Current round matches with win prediction bars, top try scorer pick per match, round leaderboard with signal summaries |
| `/match/{id}` | Match detail | Win prediction breakdown with per-team signals, ranked try scorer list with signal bars and descriptions, expandable AI reasoning, injury panels, milestone alerts |
| `/leaderboard` | Leaderboard | Cross-round try scorer rankings |
| `/accuracy` | Accuracy | Prediction accuracy tracking |
| `/chat` | Chat | Conversational Claude AI interface — ask about try scorers, matchups, player stats, injuries |
| `/jobs` | Jobs | Background job monitoring |
| `/logs` | Logs | Application log viewer |

## Public REST API

All endpoints are under `/api/v1/`. No authentication required.

### Rounds

```
GET /api/v1/rounds              — All rounds this season
GET /api/v1/rounds/current      — Current round
```

### Matches

```
GET /api/v1/matches             — All matches (filter: ?round=7&season=2026)
GET /api/v1/matches/current     — Current round matches with win predictions
GET /api/v1/matches/{id}        — Match detail with team lists, win signals
```

### Predictions

```
GET /api/v1/matches/{id}/predictions  — Try scorer predictions for a match
GET /api/v1/predictions/leaderboard   — Top 30 try scorers this round
```

### Teams & Players

```
GET /api/v1/teams               — All 17 NRL teams
GET /api/v1/teams/{id}          — Team with player roster
GET /api/v1/players/{id}        — Player stats, venue/opponent breakdowns
```

### Multi-bet

```
GET /api/v1/multi-bet           — Build a suggested multi-bet for the current round
    ?risk=balanced              — Risk profile: safe | balanced | value
    ?legs=6                     — Max legs (2-10)
```

Response includes per leg: type, selection, probability, confidence, reasoning, signals, and whether it's a value pick. Summary includes combined probability, overall confidence label, and a contextual recommendation.

### Chat

```
POST /api/chat                  — Send a message to the Claude AI analyst
    { "message": "Who should I pick for anytime try scorer in the Broncos game?",
      "history": [{"role": "user", "content": "..."}, {"role": "assistant", "content": "..."}] }
```

## Database schema

Key tables:

| Table | Description |
|---|---|
| `teams` | 17 NRL teams, keyed by `nrl_slug` |
| `players` | Player roster with career/season stats |
| `rounds` | Season rounds with start/end dates |
| `matches` | Fixtures with scores, status, win predictions, win signals |
| `match_team_lists` | Named team lists per match (player + position number) |
| `predictions` | Ranked try-scorer predictions with signal arrays and AI reasoning |
| `injuries` | Active injury reports per player |
| `suspensions` | Suspension records with games remaining |
| `try_events` | Historical try scoring events |
| `player_venue_stats` | Per-player try rate at each venue |
| `player_opponent_stats` | Per-player try rate vs each opponent |
| `articles` | NRL.com articles tagged by team |
| `data_fetch_logs` | Scraper job audit trail |

## Environment variables

See `.env.example` for full list. Key variables:

| Variable | Required | Description |
|---|---|---|
| `APP_KEY` | Yes | Laravel app key (`php artisan key:generate`) |
| `DB_*` | Yes | MySQL connection (defaults work with docker compose) |
| `CLAUDE_AGENT_TOKEN` | For AI | Anthropic API key |
| `CLAUDE_AGENT_INTERNAL_SECRET` | For AI | Shared secret between Laravel and agent service |
| `CLAUDE_AGENT_MODEL` | No | Model to use (default: `claude-sonnet-4-5`) |
| `CLAUDE_AGENT_MAX_TURNS` | No | Max agent tool-use turns (default: 12) |
| `CLAUDE_AGENT_SERVICE_URL` | No | Agent service URL (default: `http://agent:5000`) |
| `CLAUDE_AGENT_CALLBACK_URL` | No | Laravel URL the agent calls back to (default: `http://app:8000`) |

## Tech stack

- **Backend**: Laravel 11, PHP 8.3
- **Frontend**: Livewire 3, Tailwind CSS, Vite
- **AI**: Claude Agent SDK (Python), Anthropic API
- **Database**: MySQL 8.0
- **Infrastructure**: Docker Compose (5 services)
- **Data sources**: nrl.com public JSON endpoints, The Odds API

## Project layout

```
app/
├── Console/Commands/     Artisan commands (fetch + predict)
├── Http/
│   ├── Controllers/Api/V1/   Public REST API
│   ├── Controllers/Internal/ Agent tool callbacks (secret-authenticated)
│   └── Middleware/           AgentInternalAuth
├── Jobs/                 Scraper + analysis jobs (queued)
├── Livewire/             Dashboard, MatchDetail, Chat, Jobs, Logs, Leaderboard
├── Models/               Eloquent models
└── Services/             SignalCalculator, WinPredictor, MultiBetBuilder, …

python-agent/
├── app.py                Flask entrypoint (/analyse, /chat, /health)
├── agent.py              Claude Agent SDK loops
└── laravel_client.py     HTTP client for callbacks into Laravel
```

> One naming quirk: the Eloquent model is **`Matchup`** because `match` is a reserved word in PHP 8.0+. The underlying table is still `matches`.

## Security & deployment notes

- `.env` is **gitignored**. Treat the bundled `.env.example` as scaffolding only.
- Default DB credentials in `docker-compose.yml` (`nrl_secret`, `root_secret`) are for local development. Override `DB_PASSWORD` and `DB_ROOT_PASSWORD` before deploying anywhere reachable from the public internet.
- `CLAUDE_AGENT_INTERNAL_SECRET` gates the `/api/internal/agent/*` callback surface — generate a fresh value per environment with `openssl rand -hex 32`.
- The public REST API is intentionally unauthenticated for portfolio/demo purposes. Add a token or rate-limiter (`Route::middleware('throttle:60,1')`) before exposing it.
- Scrapers hit `nrl.com` public JSON endpoints — be a polite citizen and don't crank the schedule intervals down without good reason.

## Roadmap

- Backtesting harness that replays historic rounds against the live signal stack to track per-signal accuracy deltas.
- Auto-tuning loop that nudges signal weights based on accuracy-report deltas (`signal_weight_runs` table already in place).
- Public dashboard hosted somewhere persistent so the API can be poked at without standing up Docker locally.

## License

[MIT](LICENSE) © Justin Middler.

This project is for educational and entertainment purposes only. Predictions are not betting advice — gamble responsibly.
