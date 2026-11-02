<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Billing\ClientOnboardingController;
use App\Models\Client;
use App\Models\Setting;
use App\Services\Clients\OnboardingForm;
use App\Services\Notify;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The onboarding form without a login, opened from a link the team shares.
 * - General link: each submission creates a new client waiting for approval.
 * - A client's own link: fills in that client's answers (once).
 */
class PublicOnboardingController extends Controller
{
    public function show(string $token, OnboardingForm $form): JsonResponse
    {
        $client = $this->resolve($token);

        return response()->json([
            'options' => $form->options(),
            'company' => config('app.name'),
            // Only the name a client's own link was made for; nothing else about them.
            'prefill' => $client ? ['business_name' => $client->company_name, 'full_name' => $client->contact_name] : (object) [],
        ]);
    }

    public function store(Request $request, string $token, OnboardingForm $form, Notify $notify): JsonResponse
    {
        $client = $this->resolve($token);

        // Bots fill every field, people never see this one.
        if (filled($request->input('fax_number'))) {
            return response()->json(['message' => 'Thank you!']);
        }

        $data = $form->validate($request);

        $client = DB::transaction(function () use ($client, $data, $request, $form) {
            $client ??= Client::create(['company_name' => $data['business_name'], 'status' => 'pending', 'source' => 'form']);
            if ($client->onboarding_token) {
                $client->forceFill(['onboarding_token' => null])->save(); // a client's own link works once
            }

            return $form->save($client, $data, $request, null);
        });

        $pending = $client->status === 'pending';
        $notify->send($notify->staffWith('clients.approve'), 'client_onboarded',
            $pending ? 'New client form: '.$data['business_name'] : $data['business_name'].' filled in the onboarding form',
            $data['full_name'].' ('.$data['phone'].') sent the onboarding form'.($pending ? '. Approve the client to start work.' : '.'),
            '/clients/'.$client->id);

        return response()->json(['message' => 'Thank you! Our team will contact you shortly.']);
    }

    private function resolve(string $token): ?Client
    {
        abort_if(strlen($token) < 20, 404, 'This form link is not valid.');
        if (hash_equals((string) Setting::get(ClientOnboardingController::GENERAL_LINK, ''), $token)) {
            return null;
        }
        $client = Client::where('onboarding_token', $token)->first();
        abort_unless($client, 404, 'This form link is not valid or has already been used. Please ask DigiFox Pro for a new link.');

        return $client;
    }
}
