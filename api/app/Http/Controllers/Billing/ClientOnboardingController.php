<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use App\Services\Clients\OnboardingForm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Staff side of the onboarding form: fill it in for a client (e.g. on a call),
 * and get the links clients use to fill it in themselves without logging in.
 */
class ClientOnboardingController extends Controller
{
    public const GENERAL_LINK = 'onboarding_link_token';

    public function show(Request $request, Client $client, OnboardingForm $form): JsonResponse
    {
        $this->authorizeClient($request->user(), $client);

        return response()->json([
            'options' => $form->options(),
            'prefill' => $form->prefill($client),
            'logins_on_file' => array_keys($client->credentials ?? []),
        ]);
    }

    public function store(Request $request, Client $client, OnboardingForm $form): JsonResponse
    {
        $this->authorizeClient($request->user(), $client);
        $data = $form->validate($request, staff: true);
        DB::transaction(fn () => $form->save($client, $data, $request, $request->user()));

        return response()->json(['message' => 'Onboarding answers saved.', 'client' => $client->fresh()->toApiArray()]);
    }

    /** This client's private form link (created on first use). */
    public function clientLink(Request $request, Client $client): JsonResponse
    {
        $this->authorizeClient($request->user(), $client);
        if (! $client->onboarding_token) {
            $client->forceFill(['onboarding_token' => Str::random(40)])->save();
        }

        return response()->json(['token' => $client->onboarding_token]);
    }

    /** The general "new client" form link, like a Google Form link. */
    public function generalLink(): JsonResponse
    {
        $token = Setting::get(self::GENERAL_LINK);
        if (! $token) {
            $token = Str::random(40);
            Setting::put(self::GENERAL_LINK, $token);
        }

        return response()->json(['token' => $token]);
    }

    /** Stop the old general link working (e.g. it was shared too widely). */
    public function resetGeneralLink(): JsonResponse
    {
        $token = Str::random(40);
        Setting::put(self::GENERAL_LINK, $token);

        return response()->json(['token' => $token]);
    }

    private function authorizeClient(User $user, Client $client): void
    {
        abort_unless(Client::visibleTo($user)->whereKey($client->id)->exists(), 403, 'This client is managed by someone else.');
    }
}
