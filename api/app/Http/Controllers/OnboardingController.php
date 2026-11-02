<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\User;
use App\Services\Clients\OnboardingForm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * "Digital Marketing Client Onboarding Form": new clients fill it in on first
 * login; the account unlocks when it is submitted. Shared passwords are kept
 * encrypted and separate from the other answers.
 */
class OnboardingController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'options' => app(OnboardingForm::class)->options(),
            'prefill' => [
                'full_name' => $user->name,
                'business_name' => $user->client?->company_name ?? $user->company_name,
                'email' => $user->email,
                'phone' => $user->phone ?? $user->client?->phone,
            ],
        ]);
    }

    public function store(Request $request, OnboardingForm $form): JsonResponse
    {
        $user = $request->user();
        if (! $user->needsOnboarding()) {
            return response()->json(['message' => 'Onboarding already completed.'], 409);
        }

        $data = $form->validate($request);

        DB::transaction(function () use ($user, $data, $request, $form) {
            $client = $user->client ?? Client::create(['company_name' => $data['business_name'], 'status' => 'active', 'source' => 'register']);
            $form->save($client, $data, $request, $user);

            $user->forceFill([
                'name' => $data['full_name'],
                'phone' => $data['phone'],
                'client_id' => $client->id,
                'onboarding_data' => null, // answers live on the client record
                'onboarded_at' => now(),
                'status' => User::STATUS_ACTIVE,
            ])->save();
        });

        $notify = app(\App\Services\Notify::class);
        $notify->send($notify->admins(), 'client_onboarded', $data['business_name'].' finished onboarding',
            $data['full_name'].' filled in the onboarding form. Goals: '.implode(', ', $data['goals']).'.', '/clients/'.$user->fresh()->client_id);

        return response()->json(['message' => 'Thank you! Your account is now active.', 'user' => $user->fresh()->toAuthArray()]);
    }
}
