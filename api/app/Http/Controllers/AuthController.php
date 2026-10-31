<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_SECONDS = 900; // 15 minutes

    /** Client self-registration. Employees are created by the admin. */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'company_name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email:rfc', 'max:190', 'unique:users,email'],
            'phone' => ['required', 'string', 'regex:/^\+?[0-9 ]{8,16}$/'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        $user = User::create([
            ...$data,
            'email' => Str::lower($data['email']),
            'role' => User::ROLE_CLIENT,
            'status' => User::STATUS_PENDING_ONBOARDING,
        ]);

        return $this->tokenResponse($user, $request, 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $throttleKey = 'login:'.Str::lower($data['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            $minutes = (int) ceil(RateLimiter::availableIn($throttleKey) / 60);
            throw ValidationException::withMessages([
                'email' => "Too many failed attempts. Try again in {$minutes} minute(s).",
            ])->status(429);
        }

        $user = User::where('email', Str::lower($data['email']))->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($throttleKey, self::LOCKOUT_SECONDS);
            throw ValidationException::withMessages(['email' => 'The email or password is incorrect.']);
        }

        RateLimiter::clear($throttleKey);

        if (in_array($user->status, [User::STATUS_INACTIVE, User::STATUS_PENDING_APPROVAL], true)) {
            return response()->json([
                'message' => $user->status === User::STATUS_INACTIVE
                    ? 'Your account has been deactivated. Please contact the administrator.'
                    : 'Your account is waiting for administrator approval.',
                'code' => 'ACCOUNT_INACTIVE',
            ], 403);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return $this->tokenResponse($user, $request, 200, (bool) ($data['remember'] ?? false));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->profile($request->user()));
    }

    private function tokenResponse(User $user, Request $request, int $status, bool $remember = false): JsonResponse
    {
        $expires = $remember ? now()->addDays(30) : now()->addDay();
        $device = Str::limit((string) $request->userAgent(), 100, '') ?: 'web';
        $token = $user->createToken($device, ['*'], $expires)->plainTextToken;

        return response()->json([
            'token' => $token,
            'expires_at' => $expires->toIso8601String(),
            ...$this->profile($user),
        ], $status);
    }

    private function profile(User $user): array
    {
        $permissions = $user->permissionKeys();

        return [
            'user' => $user->toAuthArray(),
            'permissions' => $permissions,
            // Modules the user can open = modules with a ".view" permission.
            'modules' => array_values(array_map(
                fn ($k) => substr($k, 0, -5),
                array_filter($permissions, fn ($k) => str_ends_with($k, '.view')),
            )),
        ];
    }
}
