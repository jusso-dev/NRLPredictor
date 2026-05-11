"""Claude Agent SDK wrapper for NRL try-scorer prediction adjustment."""
from __future__ import annotations

import asyncio
import json
import os
from typing import Any

from claude_agent_sdk import (
    AssistantMessage,
    ClaudeAgentOptions,
    ClaudeSDKClient,
    TextBlock,
    create_sdk_mcp_server,
    tool,
)

from laravel_client import LaravelClient

SYSTEM_PROMPT = """You are an NRL betting analyst reviewing try-scorer predictions for matches.

## Hard rules
- NEVER recommend a player not in the final 1-17 team list for the match.
- NEVER output a recommendation without at least 3 cited signals.
- If a player's model probability is under 15%, do not recommend them.
- Include the responsible gambling footer: "For informational use only. Gambling involves real financial risk. If you need support, call 1800 858 858 or visit www.betstop.gov.au."

## Workflow
1. Call get_match_context to understand team lists, injuries, venue, weather, and kickoff.
2. Call get_top_predictions to see the top 10 statistically-predicted try scorers with signals.
3. For interesting candidates, call get_player_deep_stats for career, venue, and head-to-head records.
4. Call get_team_articles for both teams to catch narrative factors (coach comments, tactical changes).
5. Identify compound advantages where multiple signals stack (hot form + weak opposing edge + milestone).
6. For each of the top ~8 players, call submit_adjusted_prediction with:
   - adjusted_score: the statistical score +/- up to 15 points based on contextual factors.
   - reasoning: 2-3 sentences citing specific stats and numbers.

## Analytical style
- Reason from the numbers, not from reputation.
- Bring up compound advantages where multiple signals stack.
- Mention WHY something is a risk, not just a strength. Acknowledge uncertainty.
- Write in plain Australian English. No hype, no exclamation marks.
- Do not use em-dashes, emojis, or filler phrases.
- Favour wingers and fullbacks facing disrupted defensive edges.
- Penalise predictions that depend on signals contradicted by news.
"""


def build_tools(client: LaravelClient):
    """Define the 5 tools the agent can call, closing over one LaravelClient per request."""

    @tool(
        "get_match_context",
        "Returns team lists, injuries, venue, kickoff and recent form for a match.",
        {"match_id": int},
    )
    async def get_match_context(args: dict[str, Any]):
        data = client.match_context(int(args["match_id"]))
        return {"content": [{"type": "text", "text": json.dumps(data)}]}

    @tool(
        "get_top_predictions",
        "Returns the top 10 statistically-predicted try scorers for a match with their signals.",
        {"match_id": int},
    )
    async def get_top_predictions(args: dict[str, Any]):
        data = client.top_predictions(int(args["match_id"]))
        return {"content": [{"type": "text", "text": json.dumps(data)}]}

    @tool(
        "get_player_deep_stats",
        "Returns career stats, opponent records and venue records for a player.",
        {"player_id": int},
    )
    async def get_player_deep_stats(args: dict[str, Any]):
        data = client.player_deep_stats(int(args["player_id"]))
        return {"content": [{"type": "text", "text": json.dumps(data)}]}

    @tool(
        "get_team_articles",
        "Returns recent NRL.com articles tagged with the given team.",
        {"team_id": int},
    )
    async def get_team_articles(args: dict[str, Any]):
        data = client.team_articles(int(args["team_id"]))
        return {"content": [{"type": "text", "text": json.dumps(data)}]}

    @tool(
        "submit_adjusted_prediction",
        "Updates a prediction record with an AI-adjusted score (0-100) and reasoning text.",
        {"match_id": int, "player_id": int, "adjusted_score": int, "reasoning": str},
    )
    async def submit_adjusted_prediction(args: dict[str, Any]):
        data = client.submit_adjusted_prediction(
            int(args["match_id"]),
            int(args["player_id"]),
            int(args["adjusted_score"]),
            str(args["reasoning"]),
        )
        return {"content": [{"type": "text", "text": json.dumps(data)}]}

    return [
        get_match_context,
        get_top_predictions,
        get_player_deep_stats,
        get_team_articles,
        submit_adjusted_prediction,
    ]


async def _run(match_id: int) -> dict[str, Any]:
    laravel = LaravelClient()
    tools = build_tools(laravel)
    server = create_sdk_mcp_server(name="nrl-tools", version="1.0.0", tools=tools)

    tool_names = [
        "mcp__nrl-tools__get_match_context",
        "mcp__nrl-tools__get_top_predictions",
        "mcp__nrl-tools__get_player_deep_stats",
        "mcp__nrl-tools__get_team_articles",
        "mcp__nrl-tools__submit_adjusted_prediction",
    ]

    options = ClaudeAgentOptions(
        system_prompt=SYSTEM_PROMPT,
        mcp_servers={"nrl-tools": server},
        allowed_tools=tool_names,
        model=os.environ.get("CLAUDE_AGENT_MODEL", "claude-sonnet-4-5"),
        max_turns=int(os.environ.get("CLAUDE_AGENT_MAX_TURNS", "12")),
        permission_mode="acceptEdits",
    )

    transcript: list[str] = []
    async with ClaudeSDKClient(options=options) as agent:
        await agent.query(
            f"Review and adjust try-scorer predictions for match ID {match_id}. "
            "Use the tools, then submit adjusted predictions for the top scorers."
        )
        async for message in agent.receive_response():
            if isinstance(message, AssistantMessage):
                for block in message.content:
                    if isinstance(block, TextBlock):
                        transcript.append(block.text)

    return {
        "match_id": match_id,
        "transcript": "\n".join(transcript)[:4000],
    }


def analyse_match(match_id: int) -> dict[str, Any]:
    return asyncio.run(_run(match_id))


CHAT_SYSTEM_PROMPT = """You are an NRL rugby league analyst assistant. You have access to live match data,
player stats, predictions, and team news via the tools below.

When the user asks about try scorers, match predictions, player form, injuries, or any NRL data:
1. Use get_match_context and get_top_predictions for match-specific questions.
2. Use get_player_deep_stats for player-specific questions.
3. Use get_team_articles for team news and narrative context.

Always cite specific numbers and stats. Be conversational but data-driven.
Format your response with clear structure — use bullet points for lists of players/stats.
Keep responses concise but thorough.
"""


async def _chat(message: str, history: list[dict[str, str]]) -> str:
    laravel = LaravelClient()

    # Build tools — same as analysis but without submit
    @tool(
        "get_match_context",
        "Returns team lists, injuries, venue, kickoff and recent form for a match.",
        {"match_id": int},
    )
    async def get_match_context(args: dict[str, Any]):
        data = laravel.match_context(int(args["match_id"]))
        return {"content": [{"type": "text", "text": json.dumps(data)}]}

    @tool(
        "get_top_predictions",
        "Returns the top 10 statistically-predicted try scorers for a match with their signals.",
        {"match_id": int},
    )
    async def get_top_predictions(args: dict[str, Any]):
        data = laravel.top_predictions(int(args["match_id"]))
        return {"content": [{"type": "text", "text": json.dumps(data)}]}

    @tool(
        "get_player_deep_stats",
        "Returns career stats, opponent records and venue records for a player.",
        {"player_id": int},
    )
    async def get_player_deep_stats(args: dict[str, Any]):
        data = laravel.player_deep_stats(int(args["player_id"]))
        return {"content": [{"type": "text", "text": json.dumps(data)}]}

    @tool(
        "get_team_articles",
        "Returns recent NRL.com articles tagged with the given team.",
        {"team_id": int},
    )
    async def get_team_articles(args: dict[str, Any]):
        data = laravel.team_articles(int(args["team_id"]))
        return {"content": [{"type": "text", "text": json.dumps(data)}]}

    @tool(
        "list_current_matches",
        "Returns all matches in the current round with IDs, teams, and win predictions.",
        {},
    )
    async def list_current_matches(args: dict[str, Any]):
        data = laravel.current_matches()
        return {"content": [{"type": "text", "text": json.dumps(data)}]}

    chat_tools = [get_match_context, get_top_predictions, get_player_deep_stats, get_team_articles, list_current_matches]
    server = create_sdk_mcp_server(name="nrl-chat", version="1.0.0", tools=chat_tools)

    tool_names = [
        "mcp__nrl-chat__get_match_context",
        "mcp__nrl-chat__get_top_predictions",
        "mcp__nrl-chat__get_player_deep_stats",
        "mcp__nrl-chat__get_team_articles",
        "mcp__nrl-chat__list_current_matches",
    ]

    options = ClaudeAgentOptions(
        system_prompt=CHAT_SYSTEM_PROMPT,
        mcp_servers={"nrl-chat": server},
        allowed_tools=tool_names,
        model=os.environ.get("CLAUDE_AGENT_MODEL", "claude-sonnet-4-5"),
        max_turns=int(os.environ.get("CLAUDE_AGENT_MAX_TURNS", "8")),
        permission_mode="acceptEdits",
    )

    transcript: list[str] = []
    async with ClaudeSDKClient(options=options) as agent:
        # Build the query with history context
        context_parts = []
        for msg in history[-10:]:  # Last 10 messages for context
            role = msg.get("role", "user")
            content = msg.get("content", "")
            context_parts.append(f"{'User' if role == 'user' else 'Assistant'}: {content}")

        query = message
        if context_parts:
            query = "Previous conversation:\n" + "\n".join(context_parts) + "\n\nUser's new message: " + message

        await agent.query(query)
        async for msg in agent.receive_response():
            if isinstance(msg, AssistantMessage):
                for block in msg.content:
                    if isinstance(block, TextBlock):
                        transcript.append(block.text)

    return "\n".join(transcript)[:6000]


def chat_query(message: str, history: list[dict[str, str]] | None = None) -> str:
    return asyncio.run(_chat(message, history or []))
