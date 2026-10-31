<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware: `permission:leads.view` (several keys = any of them).
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string ...$keys): Response
    {
        $user = $request->user();

        foreach ($keys as $key) {
            if ($user?->hasPermission($key)) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'You do not have access to this section.',
            'code' => 'FORBIDDEN',
        ], 403);
    }
}
