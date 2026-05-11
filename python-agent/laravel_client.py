"""HTTP client the agent uses to call back into Laravel's internal API."""
from __future__ import annotations

import os
from typing import Any

import httpx


class LaravelClient:
    def __init__(self) -> None:
        base = os.environ.get("CLAUDE_AGENT_CALLBACK_URL", "http://app:8000").rstrip("/")
        secret = os.environ.get("CLAUDE_AGENT_INTERNAL_SECRET", "")
        if not secret:
            raise RuntimeError("CLAUDE_AGENT_INTERNAL_SECRET must be set")
        self.base = f"{base}/api/internal/agent"
        self.headers = {"X-Agent-Secret": secret, "Accept": "application/json"}
        self.client = httpx.Client(headers=self.headers, timeout=30.0)

    def match_context(self, match_id: int) -> dict[str, Any]:
        return self._get(f"/match-context/{match_id}")

    def top_predictions(self, match_id: int) -> dict[str, Any]:
        return self._get(f"/top-predictions/{match_id}")

    def player_deep_stats(self, player_id: int) -> dict[str, Any]:
        return self._get(f"/player-deep-stats/{player_id}")

    def team_articles(self, team_id: int) -> dict[str, Any]:
        return self._get(f"/team-articles/{team_id}")

    def current_matches(self) -> dict[str, Any]:
        return self._get("/current-matches")

    def submit_adjusted_prediction(
        self, match_id: int, player_id: int, adjusted_score: int, reasoning: str
    ) -> dict[str, Any]:
        payload = {
            "match_id": match_id,
            "player_id": player_id,
            "adjusted_score": adjusted_score,
            "reasoning": reasoning,
        }
        response = self.client.post(f"{self.base}/submit-adjusted-prediction", json=payload)
        response.raise_for_status()
        return response.json()

    def _get(self, path: str) -> dict[str, Any]:
        response = self.client.get(f"{self.base}{path}")
        response.raise_for_status()
        return response.json()
