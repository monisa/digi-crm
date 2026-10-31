<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Onboarding gate. The full client form (fields from the Google Form) is built
 * in Phase 5; for now it stores a basic business profile and unlocks the account.
 */
class OnboardingController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->needsOnboarding()) {
            return response()->json(['message' => 'Onboarding already completed.'], 409);
        }

        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:160'],
            'industry' => ['required', 'string', 'max:120'],
            'website' => ['nullable', 'url', 'max:190'],
            'city' => ['required', 'string', 'max:120'],
            'state' => ['required', 'string', 'max:120'],
            'gstin' => ['nullable', 'string', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][0-9A-Z]Z[0-9A-Z]$/'],
            'services' => ['required', 'array', 'min:1'],
            'services.*' => ['string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], ['gstin.regex' => 'Enter a valid 15-character GSTIN.']);

        $user->forceFill([
            'onboarding_data' => $data,
            'onboarded_at' => now(),
            'status' => User::STATUS_ACTIVE,
        ])->save();

        return response()->json(['message' => 'Thank you! Your account is now active.', 'user' => $user->toAuthArray()]);
    }
}
