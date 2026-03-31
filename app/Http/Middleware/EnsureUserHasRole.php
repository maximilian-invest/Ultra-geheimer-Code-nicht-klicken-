<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $user = $request->user();
        if (!$user) {
            return $request->expectsJson()
                ? response()->json(["error" => "Unauthorized"], 403)
                : redirect()->route("login");
        }

        // Pruefe user_type direkt (zuverlaessiger als Spatie in manchen Kontexten)
        if (in_array($user->user_type, $roles)) {
            return $next($request);
        }

        // Fallback: Spatie-Rollen pruefen
        if (method_exists($user, "hasAnyRole") && $user->hasAnyRole($roles)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(["error" => "Unauthorized"], 403);
        }
        abort(403);
    }
}
