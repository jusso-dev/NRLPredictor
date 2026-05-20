<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin PHP client for the Python Flask agent service.
 *
 * Sends POST /analyse {match_id}. The Python service subprocesses the OpenAI
 * Codex CLI (authenticated via a host-mounted ChatGPT Pro session) and calls
 * back into Laravel via /api/internal/agent/* to submit adjusted predictions.
 */
class TryPredictionAgent
{
    public function analyse(int $matchId): void
    {
        $secret = (string) config('services.ai_agent.internal_secret');
        $serviceUrl = rtrim((string) config('services.ai_agent.service_url'), '/');

        if ($secret === '' || $serviceUrl === '') {
            Log::warning('TryPredictionAgent skipped: AI_AGENT_INTERNAL_SECRET / service URL not configured');

            return;
        }

        $response = Http::timeout(300)
            ->withHeaders(['X-Agent-Secret' => $secret])
            ->acceptJson()
            ->post("{$serviceUrl}/analyse", ['match_id' => $matchId]);

        if (! $response->successful()) {
            Log::error('AI agent service error', [
                'match_id' => $matchId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }
    }
}
