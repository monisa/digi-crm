<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks every protected route until the account is active.
 * New clients get ONBOARDING_REQUIRED so the app can send them to the form.
 */
class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        return match ($user?->status) {
            User::STATUS_ACTIVE => $next($request),
            User::STATUS_PENDING_ONBOARDING => response()->json([
                'message' => 'Please complete the onboarding form to continue.',
                'code' => 'ONBOARDING_REQUIRED',
            ], 403),
            default => response()->json([
                'message' => 'Your account is not active. Please contact the administrator.',
                'code' => 'ACCOUNT_INACTIVE',
            ], 403),
        };
    }
}
