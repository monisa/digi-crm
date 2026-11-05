<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

/** "My profile" and "Change password" for whoever is signed in. */
class AccountController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'regex:/^\+?[0-9 ()-]{8,20}$/'],
        ], ['phone.regex' => 'Enter a valid phone number.']);

        $user->forceFill(['name' => trim($data['name']), 'phone' => $data['phone'] ?? null])->save();
        // Keep the HR record's name in step.
        Employee::where('user_id', $user->id)->update(['name' => $user->name]);

        return response()->json($user->fresh()->toAuthArray());
    }

    public function password(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->validate([
            'current_password' => ['required', 'current_password:sanctum'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::min(8)->letters()->numbers()],
        ], [
            'current_password.current_password' => 'Your current password is not right.',
            'password.different' => 'Choose a password different from the current one.',
        ]);

        $user->forceFill(['password' => (string) $request->input('password')])->save();
        // Sign out every other device; this one stays signed in.
        $current = $user->currentAccessToken();
        $user->tokens()->when($current && isset($current->id), fn ($q) => $q->where('id', '!=', $current->id))->delete();

        return response()->json(['message' => 'Password changed. Other devices have been signed out.']);
    }
}
