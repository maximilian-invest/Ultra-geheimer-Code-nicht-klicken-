<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class VerifyApiKey
{
    public function handle(Request $request, Closure $next)
    {
        $key = $request->query('key')
            ?? $request->query('api_key')
            ?? $request->header('X-Api-Key');

        if ($key !== config('portal.api_key')) {
            return response()->json(['error' => 'Unauthorized', 'message' => 'Invalid or missing API key'], 401);
        }

        return $next($request);
    }
}
