<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AgentInternalAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.ai_agent.internal_secret');
        $provided = (string) $request->header('X-Agent-Secret', '');

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            abort(401, 'invalid agent credentials');
        }

        return $next($request);
    }
}
