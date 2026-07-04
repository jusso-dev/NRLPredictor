# CLAUDE.md — NRL Try Predictor

## Project overview

Laravel 11 + Livewire 3 app that predicts NRL try scorers and match winners using scraped data from nrl.com and an optional AI refinement pass (OpenAI Codex CLI, run in-process). Runs in Docker Compose with 4 services (app, queue, scheduler, mysql).

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
│   └── Controllers/
│       ├── Api/V1/       # Public REST API (matches, predictions, teams, multi-bet, odds)
│       └── ChatController.php  # Chat backed by Codex CLI, in-process
├── Jobs/                  # All scraper + analysis jobs (queued)
├── Livewire/              # Dashboard, MatchDetail, Chat, Jobs, Logs, Leaderboard, Accuracy
├── Models/                # Eloquent models (Team, Player, Matchup, Prediction, Round, etc.)
├── Services/
│   ├── SignalCalculator.php    # Weighted try-scorer signals (53 as of 2026-07)
│   ├── PredictionScorer.php    # Scores players per match, persists top 15
│   ├── WinPredictor.php        # Weighted match-winner signals (23 as of 2026-07)
│   ├── MultiBetBuilder.php     # Builds multi-bet suggestions (safe/balanced/value)
│   ├── CodexClient.php         # `codex exec` subprocess wrapper (Symfony Process)
│   ├── AgentContext.php        # Builds the JSON payloads embedded in AI prompts
│   └── TryPredictionAgent.php  # AI refinement pass (prompt build → codex → apply adjustments)
└── Support/               # HttpScraper, NrlMatchState, LaravelLogReader
```

## Architecture notes

- **Matchup** (not Match) is the Eloquent model — `match` is a PHP reserved word. Table is still `matches`.
- `Round::current()` picks the round to display: first checks date window, then upcoming, then `is_current` flag, then latest.
- `FetchDraw` defaults to current round + next round only. On a fresh DB with no rounds, it bootstraps all 27 by fanning out one job per round.
- The AI pass runs in-process: `TryPredictionAgent` builds the payload via `AgentContext`, shells out to `codex exec` via `CodexClient`, and applies clamped adjustments directly. There is no separate agent service.
- `SyncCurrentRoundData` chains all fetchers + prediction + AI in order via `Bus::chain()` with a `catch()` that logs chain aborts to the jobs page.
- `ShouldBeUnique` on most jobs prevents duplicate concurrent runs. Each job's `uniqueFor` must exceed its worst-case runtime (`tries × timeout + backoff`), and every `$timeout` must stay under `DB_QUEUE_RETRY_AFTER` (660s in docker-compose).
- Jobs using the `LogsDataFetch` trait get a `failed()` hook that closes the log row when a job times out or dies; `nrl:sweep-stuck-logs` (every 10 min) is the backstop.
- Team slugs must match the aliases in `FetchDraw::resolveTeam()`.
- nrl.com `matchState` tokens map to statuses in one place: `App\Support\NrlMatchState`.
- Players carry a stable `nrl_player_id` (from the team-list feed); prefer it over name slugs when matching.

## Prediction models

### Try scorers (SignalCalculator)
53 weighted signals (sum 452 as of 2026-07 — always derive from `SignalCalculator::WEIGHTS` / `maxPossibleScore()`, never hardcode). Top signals by weight: season_try_rate (20), recent_form (18), position_advantage (15), opponent_edge_weakness (15), opponent_missing_defenders (12). Weights can be overridden by AutoTune (`signal_weight_tunes` table).

### Match winners (WinPredictor)
23 weighted signals (sum 253) producing home/away win percentages that always total 100. Stored on `matches` table. Runs as part of `RunPredictionAnalysis`.

### Multi-bet (MultiBetBuilder)
Combines winner + try scorer legs. Reserves ~35% of slots for match winners. Max 2 legs per match. Three risk profiles control thresholds.

## API routes

- Public API: `/api/v1/*` — no auth, JSON responses
- Chat: `POST /api/chat` — no auth, runs Codex CLI in-process

## Database

MySQL 8.0. All migrations in `database/migrations/`. Teams must be seeded via `TeamSeeder` (not included in `DatabaseSeeder` due to Faker dependency issue).

## Betting odds (The Odds API)

`FetchOdds` pulls live NRL odds from The Odds API (`rugbyleague_nrl` sport key). It fetches:
- Match-level: h2h (match_winner), spreads, totals from AU bookmakers
- Player-level: anytime try scorer odds per event

Odds are stored in `odds_snapshots` and enriched onto multi-bet legs. The scheduler runs every 4 hours to conserve API credits. Env var: `ODDS_API_KEY`.

## Environment

Key env vars for AI features: `CODEX_MODEL` (blank = use `~/.codex/config.toml` default), `CODEX_TIMEOUT_SECONDS`. `ODDS_API_KEY` enables bookmaker odds integration. `APP_TIMEZONE` must be `Australia/Sydney` in every PHP container — kickoff-window gates compare Sydney kickoff times against `now()`.

## AI agent (Codex CLI via ChatGPT Pro)

The `app` and `queue` containers have the OpenAI Codex CLI installed (`docker/php/Dockerfile`) and shell out to it in non-interactive mode via `App\Services\CodexClient`. Auth uses a ChatGPT Pro subscription via OAuth — there is no API key.

One-time setup on the host (your Mac, not inside Docker):

```bash
npm install -g @openai/codex
codex login   # opens browser, sign in with ChatGPT Pro account
```

This writes `~/.codex/auth.json`. `docker-compose.yml` mounts `${HOME}/.codex` into the app and queue containers at `/root/.codex` (`CODEX_HOME`) so they reuse the same auth. The refresh token rotates inside the mounted directory, so keep it read-write.

Caveats:
- Auth ties to a single ChatGPT account. Concurrent AI analysis and chat calls share the same plan-level rate limit.
- `~/.codex/auth.json` contains a refresh token. Do not commit it. Do not bake it into the image.
- Pro plan usage is for personal use under OpenAI ToS.

The AI pass runs entirely in-process: `TryPredictionAgent` pre-fetches match context, top predictions, deep player stats, and team articles via `AgentContext`, embeds them as JSON in the prompt, calls `codex exec` with a JSON output schema, then validates, clamps (±15 of the statistical score, no upward moves below 15) and applies the adjustments, re-ranking the match afterwards.

## Testing

```bash
docker compose exec app php artisan test
```

## Common issues

- **"0 teams" / empty predictions**: Run `TeamSeeder` first — all fetch jobs depend on teams existing.
- **Predictions all score 100**: Scores are normalized per-match. Check raw signal values for differentiation.
- **Chat / AI review failing**: Ensure `~/.codex` is mounted with a valid `auth.json` (run `codex login` on the host) and `codex` is on PATH inside the container (`docker compose exec app codex --version`).
- **Code changes not reflected**: Files are baked into the Docker image at build time. Either `docker compose up --build` or `docker compose cp` the changed file in.
