"""Codex CLI wrapper for NRL try-scorer prediction adjustment.

Subprocesses the OpenAI Codex CLI (`codex exec`) instead of running an agent
loop. Data is pre-fetched from Laravel and embedded in the prompt; the model
returns a JSON payload of adjustments which we feed back into Laravel.

Auth: Codex CLI reads `${CODEX_HOME}/auth.json`, mounted from the host where
`codex login` was run with a ChatGPT Pro account.
"""
from __future__ import annotations

import json
import logging
import os
import re
import subprocess
import tempfile
from typing import Any

from laravel_client import LaravelClient

logger = logging.getLogger("nrl-agent.codex")

CODEX_BIN = os.environ.get("CODEX_BIN", "codex")
CODEX_MODEL = os.environ.get("CODEX_MODEL", "").strip()


def _int_env(name: str, default: int) -> int:
    try:
        return int(os.environ.get(name, str(default)))
    except ValueError:
        logger.warning("invalid integer env %s=%r; using %d", name, os.environ.get(name), default)
        return default


CODEX_TIMEOUT = _int_env("CODEX_TIMEOUT_SECONDS", 300)
TOP_N_FOR_DEEP_STATS = _int_env("CODEX_TOP_N_DEEP", 6)

ANALYSIS_SYSTEM = """You are an NRL betting analyst reviewing try-scorer predictions.

Hard rules:
- Never recommend a player not in the final 1-17 team list for the match.
- Never output an adjustment without at least 3 cited signals.
- If a player's model probability is under 15%, do not adjust upward.
- Adjusted score must be 0-100 and within +/-15 of the original statistical score.

Analytical style:
- Reason from numbers, not reputation.
- Identify compound advantages where multiple signals stack.
- Acknowledge uncertainty. Mention why something is a risk, not just a strength.
- Plain Australian English. No hype, no emojis, no em-dashes, no exclamation marks.
- Favour wingers and fullbacks facing disrupted defensive edges.
- Penalise predictions that depend on signals contradicted by news.

Output:
Respond with a single JSON object matching the supplied schema. Include 5-8
players from the supplied top_predictions list, ordered by your conviction.
"""

CHAT_SYSTEM = """You are an NRL rugby league analyst. You have live data embedded below.

Rules:
- Cite specific numbers and stats.
- Be conversational but data-driven.
- Use bullet points for lists of players/stats.
- Keep responses concise but thorough.
- Plain Australian English, no hype, no emojis.
"""

ANALYSIS_SCHEMA = {
    "type": "object",
    "additionalProperties": False,
    "required": ["adjustments"],
    "properties": {
        "adjustments": {
            "type": "array",
            "minItems": 1,
            "items": {
                "type": "object",
                "additionalProperties": False,
                "required": ["player_id", "adjusted_score", "reasoning"],
                "properties": {
                    "player_id": {"type": "integer"},
                    "adjusted_score": {"type": "integer", "minimum": 0, "maximum": 100},
                    "reasoning": {"type": "string", "minLength": 10},
                },
            },
        }
    },
}


def _run_codex(prompt: str, output_schema: dict[str, Any] | None = None) -> str:
    """Invoke `codex exec`, return the final assistant message text."""
    with tempfile.TemporaryDirectory() as tmp:
        last_msg_path = os.path.join(tmp, "out.txt")
        schema_path: str | None = None
        if output_schema is not None:
            schema_path = os.path.join(tmp, "schema.json")
            with open(schema_path, "w", encoding="utf-8") as fh:
                json.dump(output_schema, fh)

        cmd = [
            CODEX_BIN,
            "exec",
            "--sandbox", "read-only",
            "--skip-git-repo-check",
            "--ephemeral",
            "--color", "never",
            "--output-last-message", last_msg_path,
        ]
        if CODEX_MODEL:
            cmd.extend(["--model", CODEX_MODEL])
        if schema_path:
            cmd.extend(["--output-schema", schema_path])
        cmd.append("-")  # read prompt from stdin

        logger.info(
            "codex exec model=%s schema=%s prompt_chars=%d",
            CODEX_MODEL or "<config default>",
            bool(schema_path),
            len(prompt),
        )
        proc = subprocess.run(
            cmd,
            input=prompt,
            capture_output=True,
            text=True,
            timeout=CODEX_TIMEOUT,
            env={**os.environ},
        )
        if proc.returncode != 0:
            logger.error("codex exit=%d stderr=%s", proc.returncode, proc.stderr[:500])
            raise RuntimeError(f"codex failed: {proc.stderr[:300]}")

        try:
            with open(last_msg_path, encoding="utf-8") as fh:
                return fh.read()
        except FileNotFoundError:
            logger.warning("codex did not write last-message file; falling back to stdout")
            return proc.stdout


def _extract_json(text: str) -> dict[str, Any]:
    """Find the first balanced JSON object in text and parse it."""
    cleaned = text.strip()
    try:
        return json.loads(cleaned)
    except json.JSONDecodeError:
        pass
    fenced = re.search(r"```(?:json)?\s*(\{.*?\})\s*```", cleaned, re.DOTALL)
    if fenced:
        return json.loads(fenced.group(1))
    start = cleaned.find("{")
    end = cleaned.rfind("}")
    if start >= 0 and end > start:
        return json.loads(cleaned[start : end + 1])
    raise ValueError("no JSON object found in Codex output")


def _build_analysis_prompt(match_id: int, payload: dict[str, Any]) -> str:
    return (
        ANALYSIS_SYSTEM
        + "\n\n## Match data\n"
        + f"match_id: {match_id}\n\n"
        + "```json\n"
        + json.dumps(payload, indent=2, default=str)
        + "\n```\n\n"
        + "Return the JSON object matching the output schema."
    )


def analyse_match(match_id: int) -> dict[str, Any]:
    laravel = LaravelClient()

    context = laravel.match_context(match_id)
    top = laravel.top_predictions(match_id)

    top_list = top.get("predictions") if isinstance(top, dict) else top
    if not isinstance(top_list, list):
        top_list = []
    original_scores = {
        int(entry["player_id"]): float(entry.get("score", 0))
        for entry in top_list
        if isinstance(entry, dict) and isinstance(entry.get("player_id"), int)
    }

    deep_stats: dict[int, Any] = {}
    for entry in top_list[:TOP_N_FOR_DEEP_STATS]:
        if not isinstance(entry, dict):
            continue
        pid = entry.get("player_id")
        if isinstance(pid, int):
            try:
                deep_stats[pid] = laravel.player_deep_stats(pid)
            except Exception:
                logger.exception("deep stats fetch failed pid=%s", pid)

    team_ids = []
    if isinstance(context, dict):
        for key in ("home_team_id", "away_team_id"):
            tid = context.get(key)
            if isinstance(tid, int):
                team_ids.append(tid)
        for key in ("home", "away"):
            side = context.get(key)
            if isinstance(side, dict) and isinstance(side.get("team_id"), int):
                team_ids.append(side["team_id"])
    team_ids = list(dict.fromkeys(team_ids))

    articles: dict[int, Any] = {}
    for tid in team_ids:
        try:
            articles[tid] = laravel.team_articles(tid)
        except Exception:
            logger.exception("articles fetch failed tid=%s", tid)

    payload = {
        "match_context": context,
        "top_predictions": top_list,
        "player_deep_stats": deep_stats,
        "team_articles": articles,
    }

    prompt = _build_analysis_prompt(match_id, payload)
    raw_message = _run_codex(prompt, output_schema=ANALYSIS_SCHEMA)

    try:
        parsed = _extract_json(raw_message)
    except (ValueError, json.JSONDecodeError) as exc:
        logger.error("could not parse Codex JSON: %s\noutput: %s", exc, raw_message[:800])
        return {"match_id": match_id, "transcript": raw_message[:4000], "submitted": 0}

    submitted = 0
    transcript_lines: list[str] = []
    for adj in parsed.get("adjustments", []):
        try:
            pid = int(adj["player_id"])
            score = max(0, min(100, int(adj["adjusted_score"])))
            reasoning = str(adj["reasoning"])
        except (KeyError, TypeError, ValueError):
            logger.warning("skipping malformed adjustment: %r", adj)
            continue
        if pid not in original_scores:
            logger.warning("skipping adjustment for player outside top predictions: pid=%s", pid)
            continue

        original = original_scores[pid]
        score = max(round(original - 15), min(round(original + 15), score))
        if original < 15 and score > round(original):
            logger.warning("preventing upward adjustment below 15%% original score: pid=%s", pid)
            score = round(original)

        try:
            laravel.submit_adjusted_prediction(match_id, pid, score, reasoning)
            submitted += 1
            transcript_lines.append(f"player_id={pid} score={score} :: {reasoning}")
        except Exception:
            logger.exception("submit failed match=%s pid=%s", match_id, pid)

    return {
        "match_id": match_id,
        "transcript": "\n".join(transcript_lines)[:4000],
        "submitted": submitted,
    }


def _build_chat_prompt(message: str, history: list[dict[str, str]], data: dict[str, Any]) -> str:
    history_text = ""
    if history:
        rendered = []
        for msg in history[-10:]:
            role = msg.get("role", "user")
            content = msg.get("content", "")
            rendered.append(f"{'User' if role == 'user' else 'Assistant'}: {content}")
        history_text = "## Previous conversation\n" + "\n".join(rendered) + "\n\n"

    return (
        CHAT_SYSTEM
        + "\n\n## Live NRL data\n"
        + "```json\n"
        + json.dumps(data, indent=2, default=str)
        + "\n```\n\n"
        + history_text
        + f"## User's new message\n{message}\n"
    )


def chat_query(message: str, history: list[dict[str, str]] | None = None) -> str:
    laravel = LaravelClient()

    data: dict[str, Any] = {}
    try:
        data["current_matches"] = laravel.current_matches()
    except Exception:
        logger.exception("current_matches fetch failed")

    match_id_match = re.search(r"\bmatch[\s_-]?id[\s:=]+(\d+)\b", message, re.IGNORECASE)
    if not match_id_match:
        match_id_match = re.search(r"\bmatch\s+(\d+)\b", message, re.IGNORECASE)
    if match_id_match:
        try:
            mid = int(match_id_match.group(1))
            data["focused_match_context"] = laravel.match_context(mid)
            data["focused_top_predictions"] = laravel.top_predictions(mid)
        except Exception:
            logger.exception("focused match fetch failed")

    prompt = _build_chat_prompt(message, history or [], data)
    return _run_codex(prompt).strip()[:6000]
