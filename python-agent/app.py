"""Flask entrypoint for the AI agent service (OpenAI Codex CLI backend)."""
from __future__ import annotations

import hmac
import logging
import os

from flask import Flask, abort, jsonify, request

from agent import analyse_match, chat_query

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s :: %(message)s")
logger = logging.getLogger("nrl-agent")

app = Flask(__name__)


def _require_secret() -> None:
    expected = os.environ.get("AI_AGENT_INTERNAL_SECRET", "")
    provided = request.headers.get("X-Agent-Secret", "")
    if not expected or not hmac.compare_digest(expected, provided):
        abort(401, description="invalid agent credentials")


@app.get("/health")
def health():
    return {"ok": True, "service": "nrl-ai-agent"}


@app.post("/analyse")
def analyse():
    _require_secret()
    payload = request.get_json(silent=True) or {}
    match_id = payload.get("match_id")
    if not isinstance(match_id, int):
        abort(400, description="match_id (int) required")

    logger.info("analyse start match_id=%s", match_id)
    try:
        result = analyse_match(match_id)
    except Exception:  # noqa: BLE001 - log and return 500 so Laravel can retry
        logger.exception("analyse failed match_id=%s", match_id)
        return jsonify({"ok": False, "error": "agent failure"}), 500

    logger.info("analyse done match_id=%s", match_id)
    return jsonify({"ok": True, **result})


@app.post("/chat")
def chat():
    _require_secret()
    payload = request.get_json(silent=True) or {}
    message = payload.get("message")
    history = payload.get("history", [])
    if not isinstance(message, str) or not message.strip():
        abort(400, description="message (string) required")

    logger.info("chat start: %s", message[:80])
    try:
        result = chat_query(message, history)
    except Exception:  # noqa: BLE001
        logger.exception("chat failed")
        return jsonify({"ok": False, "error": "agent failure"}), 500

    logger.info("chat done")
    return jsonify({"ok": True, "reply": result})


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=int(os.environ.get("PORT", "5000")))
