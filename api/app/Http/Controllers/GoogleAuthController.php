<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "Sign in with Google". The browser gets an ID token from Google Identity
 * Services; we ask Google to verify it, check it was issued for our client ID,
 * then log the user in (or create a client account for a new email).
 */
class GoogleAuthController extends Controller
{
    public function config(): JsonResponse
    {
        return response()->json(['google_client_id' => config('services.google.client_id') ?: null]);
    }

    public function login(Request $request, AuthController $auth): JsonResponse
    {
        $request->validate(['credential' => ['required', 'string', 'max:4096']]);

        $clientId = config('services.google.client_id');
        abort_unless($clientId, 404, 'Google sign-in is not enabled.');

        $claims = $this->verify($request->string('credential'), $clientId);
        $email = Str::lower($claims['email']);

        $user = User::where('google_id', $claims['sub'])->first() ?? User::where('email', $email)->first();
        $created = false;

        if (! $user) {
            // New people signing in with Google are treated as new clients.
            $user = User::create([
                'name' => $claims['name'] ?? Str::before($email, '@'),
                'email' => $email,
                'password' => Str::password(32),
                'role' => User::ROLE_CLIENT,
                'status' => User::STATUS_PENDING_ONBOARDING,
            ]);
            $created = true;
        }

        $user->forceFill([
            'google_id' => $user->google_id ?: $claims['sub'],
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        $response = $auth->completeLogin($user, $request, true);
        if ($created && $response->getStatusCode() === 200) {
            $response->setStatusCode(201);
        }

        return $response;
    }

    /** @return array{sub: string, email: string, name?: string} */
    private function verify(string $idToken, string $clientId): array
    {
        $res = Http::timeout(10)->get('https://oauth2.googleapis.com/tokeninfo', ['id_token' => $idToken]);
        $claims = $res->json() ?? [];

        $valid = $res->successful()
            && ($claims['aud'] ?? null) === $clientId
            && in_array($claims['iss'] ?? null, ['accounts.google.com', 'https://accounts.google.com'], true)
            && (int) ($claims['exp'] ?? 0) > time()
            && filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)
            && ! empty($claims['sub']) && ! empty($claims['email']);

        if (! $valid) {
            throw ValidationException::withMessages(['credential' => 'Google sign-in failed. Please try again.']);
        }

        return $claims;
    }
}
