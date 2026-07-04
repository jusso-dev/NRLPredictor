<?php

namespace App\Http\Controllers;

use App\Models\Matchup;
use App\Services\AgentContext;
use App\Services\CodexClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Chat endpoint backed by the Codex CLI, run in-process. Pre-fetches
 * current-round data (plus a specific match if the message references one)
 * and embeds it in the prompt.
 */
class ChatController extends Controller
{
    protected const CHAT_SYSTEM = <<<'PROMPT'
You are an NRL rugby league analyst. You have live data embedded below.

Rules:
- Cite specific numbers and stats.
- Be conversational but data-driven.
- Use bullet points for lists of players/stats.
- Keep responses concise but thorough.
- Plain Australian English, no hype, no emojis.
PROMPT;

    protected const CHAT_TIMEOUT_SECONDS = 120;

    public function send(Request $request, CodexClient $codex, AgentContext $context): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'history' => ['sometimes', 'array'],
            'history.*.role' => ['required_with:history', 'string', 'in:user,assistant'],
            'history.*.content' => ['required_with:history', 'string'],
        ]);

        try {
            $embedded = ['current_matches' => $context->currentMatches()];

            if ($matchId = $this->referencedMatchId($data['message'])) {
                $match = Matchup::find($matchId);
                if ($match) {
                    $embedded['focused_match_context'] = $context->matchContext($match);
                    $embedded['focused_top_predictions'] = $context->topPredictions($match);
                }
            }

            $prompt = $this->buildPrompt($data['message'], $data['history'] ?? [], $embedded);
            $reply = trim($codex->exec($prompt, null, self::CHAT_TIMEOUT_SECONDS));

            return response()->json(['ok' => true, 'reply' => substr($reply, 0, 6000)]);
        } catch (\Throwable $e) {
            Log::error('Chat failed', ['message' => $e->getMessage()]);

            return response()->json(['ok' => false, 'error' => 'Chat agent failed'], 502);
        }
    }

    protected function referencedMatchId(string $message): ?int
    {
        if (preg_match('/\bmatch[\s_-]?id[\s:=]+(\d+)\b/i', $message, $m)
            || preg_match('/\bmatch\s+(\d+)\b/i', $message, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    protected function buildPrompt(string $message, array $history, array $embedded): string
    {
        $historyText = '';
        if ($history !== []) {
            $rendered = collect(array_slice($history, -10))
                ->map(fn ($msg) => (($msg['role'] ?? 'user') === 'user' ? 'User' : 'Assistant').': '.($msg['content'] ?? ''))
                ->implode("\n");
            $historyText = "## Previous conversation\n{$rendered}\n\n";
        }

        return self::CHAT_SYSTEM
            ."\n\n## Live NRL data\n```json\n"
            .json_encode($embedded, JSON_PRETTY_PRINT)
            ."\n```\n\n"
            .$historyText
            ."## User's new message\n{$message}\n";
    }
}
