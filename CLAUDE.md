# CLAUDE.md — NRL Try Predictor

## Project overview

Laravel 11 + Livewire 3 app that predicts NRL try scorers and match winners using scraped data from nrl.com and an optional AI refinement pass (OpenAI Codex CLI). Runs in Docker Compose with 5 services (app, queue, scheduler, agent, mysql).

## Key commands

```bash
# Start the app
docker compose up -d --build

# First-time setup (seed teams, fetch data, run predictions)
docker compose exec app php artisan db:seed --class=TeamSeeder
docker compose exec app php artisan nrl:fetch-all
docker compose exec app php artisan nrl:predict

# Fetch only current round (default behavior)
docker compose exec app php artisan nrl:fetch-draw

# Fetch all 27 rounds
docker compose exec app php artisan nrl:fetch-draw --all

# Run predictions with AI review
docker compose exec app php artisan nrl:predict --ai

# Fetch betting odds from The Odds API
docker compose exec app php artisan nrl:fetch-odds

# Fetch odds without player props (saves API credits)
docker compose exec app php artisan nrl:fetch-odds --no-player-props

# Clear caches after code changes
docker compose exec app php artisan optimize:clear
```

## Code layout

```
app/
├── Console/Commands/     # Artisan commands (FetchDrawCommand, PredictRoundCommand, etc.)
├── Http/
│   ├── Controllers/
│   │   ├── Api/V1/       # Public REST API (matches, predictions, teams, multi-bet, odds)
│   │   ├── Internal/     # Agent tool callbacks (secret-authenticated)
│   │   └── ChatController.php  # Proxies chat to Python agent
│   └── Middleware/        # AgentInternalAuth
├── Jobs/                  # All scraper + analysis jobs (queued)
├── Livewire/              # Dashboard, MatchDetail, Chat, Jobs, Logs, Leaderboard, Accuracy
├── Models/                # Eloquent models (Team, Player, Matchup, Prediction, Round, etc.)
├── Services/
│   ├── SignalCalculator.php    # 11 weighted try-scorer signals
│   ├── PredictionScorer.php    # Scores players per match, persists top 15
│   ├── WinPredictor.php        # 7 weighted match-winner signals
│   ├── MultiBetBuilder.php     # Builds multi-bet suggestions (safe/balanced/value)
│   └── TryPredictionAgent.php  # PHP client to Python agent service
└── Support/               # HttpScraper, LaravelLogReader

python-agent/
├── app.py                 # Flask entrypoint (/analyse, /chat, /health)
├── agent.py               # Codex CLI subprocess wrapper (analysis + chat)
├── laravel_client.py      # HTTP client for callbacks into Laravel
└── requirements.txt
```

## Architecture notes

- **Matchup** (not Match) is the Eloquent model — `match` is a PHP reserved word. Table is still `matches`.
- `Round::current()` picks the round to display: first checks date window, then upcoming, then `is_current` flag, then latest.
- `FetchDraw` defaults to current round + next round only. On a fresh DB with no rounds, it bootstraps all 27.
- The Python agent calls back into Laravel via `/api/internal/agent/*` endpoints (secret-authenticated).
- `SyncCurrentRoundData` chains all fetchers + prediction + AI in order via `Bus::chain()`.
- `ShouldBeUnique` on most jobs prevents duplicate concurrent runs.
- Team slugs must match the aliases in `FetchDraw::resolveTeam()`.

## Prediction models

### Try scorers (SignalCalculator)
11 signals, max possible score = sum of all weights (127). Top signals by weight: season_try_rate (20), recent_form (18), position_advantage (15), opponent_edge_weakness (15), opponent_missing_defenders (12), head_to_head (10).

### Match winners (WinPredictor)
7 signals producing home/away win percentages. Stored on `matches` table. Runs as part of `RunPredictionAnalysis`.

### Multi-bet (MultiBetBuilder)
Combines winner + try scorer legs. Reserves ~35% of slots for match winners. Max 2 legs per match. Three risk profiles control thresholds.

## API routes

- Public API: `/api/v1/*` — no auth, JSON responses
- Internal agent API: `/api/internal/agent/*` — requires `X-Agent-Secret` header
- Chat proxy: `POST /api/chat` — no auth, proxies to Python agent

## Database

MySQL 8.0. All migrations in `database/migrations/`. Teams must be seeded via `TeamSeeder` (not included in `DatabaseSeeder` due to Faker dependency issue).

## Betting odds (The Odds API)

`FetchOdds` pulls live NRL odds from The Odds API (`rugbyleague_nrl` sport key). It fetches:
- Match-level: h2h (match_winner), spreads, totals from AU bookmakers
- Player-level: anytime try scorer odds per event

Odds are stored in `odds_snapshots` and enriched onto multi-bet legs. The scheduler runs every 4 hours to conserve API credits. Env var: `ODDS_API_KEY`.

## Environment

Key env vars for AI features: `AI_AGENT_INTERNAL_SECRET` (shared secret between Laravel and the agent service), `CODEX_MODEL` (blank = use `~/.codex/config.toml` default). `ODDS_API_KEY` enables bookmaker odds integration.

## AI agent (Codex CLI via ChatGPT Pro)

The `agent` container shells out to the OpenAI Codex CLI in non-interactive mode. Auth uses a ChatGPT Pro subscription via OAuth — there is no API key.

One-time setup on the host (your Mac, not inside Docker):

```bash
npm install -g @openai/codex
codex login   # opens browser, sign in with ChatGPT Pro account
```

This writes `~/.codex/auth.json`. `docker-compose.yml` mounts `${HOME}/.codex` into the agent container at `/home/agent/.codex` so the container reuses the same auth. The refresh token rotates inside the mounted directory, so keep it read-write.

Caveats:
- Auth ties to a single ChatGPT account. Concurrent `/analyse` and `/chat` calls share the same plan-level rate limit.
- `~/.codex/auth.json` contains a refresh token. Do not commit it. Do not bake into the image.
- Pro plan usage is for personal use under OpenAI ToS.

The agent no longer runs an autonomous tool loop. `agent.py` pre-fetches match context, top predictions, deep player stats, and team articles from Laravel, embeds them as JSON in the prompt, calls `codex exec`, parses the JSON adjustments out of the response, and posts each one back to `/api/internal/agent/submit-adjusted-prediction`.

## Testing

```bash
docker compose exec app php artisan test
```

## Common issues

- **"0 teams" / empty predictions**: Run `TeamSeeder` first — all fetch jobs depend on teams existing.
- **Predictions all score 100**: Scores are normalized per-match. Check raw signal values for differentiation.
- **Chat not responding**: Ensure `AI_AGENT_INTERNAL_SECRET` is set, `~/.codex` is mounted with a valid `auth.json`, and the agent container is running.
- **Code changes not reflected**: Files are baked into the Docker image at build time. Either `docker compose up --build` or `docker compose cp` the changed file in.
