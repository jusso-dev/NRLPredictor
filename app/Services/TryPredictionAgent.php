<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin PHP client for the Python Flask Claude Agent service.
 *
 * Sends POST /analyse {match_id}. The Python service runs the Claude Agent
 * SDK loop, calling back into Laravel via /api/internal/agent/* to fetch
 * context and submit adjusted predictions.
 */
class TryPredictionAgent
{
    public function analyse(int $matchId): void
    {
        $token = (string) config('services.claude_agent.token');
        $secret = (string) config('services.claude_agent.internal_secret');
        $serviceUrl = rtrim((string) config('services.claude_agent.service_url'), '/');

        if ($token === '' || $secret === '' || $serviceUrl === '') {
            Log::warning('TryPredictionAgent skipped: CLAUDE_AGENT_TOKEN / CLAUDE_AGENT_INTERNAL_SECRET / service URL not configured');
            return;
        }

        $response = Http::timeout(300)
            ->withHeaders(['X-Agent-Secret' => $secret])
            ->acceptJson()
            ->post("{$serviceUrl}/analyse", ['match_id' => $matchId]);

        if (! $response->successful()) {
            Log::error('Claude agent service error', [
                'match_id' => $matchId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }
    }
}
