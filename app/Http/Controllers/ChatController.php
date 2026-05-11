<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatController extends Controller
{
    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'history' => ['sometimes', 'array'],
            'history.*.role' => ['required_with:history', 'string', 'in:user,assistant'],
            'history.*.content' => ['required_with:history', 'string'],
        ]);

        $serviceUrl = rtrim((string) config('services.claude_agent.service_url'), '/');
        $secret = (string) config('services.claude_agent.internal_secret');

        if ($serviceUrl === '' || $secret === '') {
            return response()->json(['ok' => false, 'error' => 'Chat service not configured'], 503);
        }

        try {
            $response = Http::timeout(120)
                ->withHeaders(['X-Agent-Secret' => $secret])
                ->acceptJson()
                ->post("{$serviceUrl}/chat", [
                    'message' => $data['message'],
                    'history' => $data['history'] ?? [],
                ]);

            if (! $response->successful()) {
                Log::error('Chat proxy error', ['status' => $response->status(), 'body' => $response->body()]);
                return response()->json(['ok' => false, 'error' => 'Agent service error'], 502);
            }

            return response()->json($response->json());
        } catch (\Throwable $e) {
            Log::error('Chat proxy exception', ['message' => $e->getMessage()]);
            return response()->json(['ok' => false, 'error' => 'Failed to reach agent service'], 502);
        }
    }
}
