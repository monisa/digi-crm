<?php

namespace App\Http\Controllers\Leads;

use App\Http\Controllers\Controller;
use App\Jobs\ImportMetaLead;
use App\Models\MetaPage;
use App\Services\Leads\MetaLeads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class MetaController extends Controller
{
    public function __construct(private MetaLeads $meta) {}

    // ---------- Webhook (called by Meta, no login) ----------

    /** Meta checks the webhook URL once with hub.challenge. */
    public function verify(Request $request): Response
    {
        $ok = $request->query('hub_mode') === 'subscribe'
            && config('services.meta.verify_token')
            && hash_equals((string) config('services.meta.verify_token'), (string) $request->query('hub_verify_token'));

        return $ok ? response((string) $request->query('hub_challenge'), 200) : response('Forbidden', 403);
    }

    public function receive(Request $request): Response
    {
        if (! $this->meta->validSignature($request->getContent(), $request->header('X-Hub-Signature-256'))) {
            Log::warning('Meta webhook with a bad signature was ignored.');

            return response('Invalid signature', 403);
        }

        foreach ($request->input('entry', []) as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                if (($change['field'] ?? null) === 'leadgen' && isset($change['value']['leadgen_id'])) {
                    ImportMetaLead::dispatch((string) $change['value']['leadgen_id'], (string) ($change['value']['page_id'] ?? $entry['id']));
                }
            }
        }

        // Answer fast; the leads are fetched by the queue worker.
        return response('EVENT_RECEIVED', 200);
    }

    // ---------- Settings (admin) ----------

    public function index(): JsonResponse
    {
        return response()->json([
            'pages' => MetaPage::orderBy('name')->get()->map->toApiArray(),
            'webhook_url' => rtrim(config('app.url'), '/').'/v1/webhooks/meta',
            'webhook_ready' => (bool) (config('services.meta.app_secret') && config('services.meta.verify_token')),
            'razorpay' => [
                'keys' => app(\App\Services\Billing\Razorpay::class)->configured(),
                'live' => str_starts_with((string) config('services.razorpay.key_id'), 'rzp_live_'),
                'webhook' => filled(config('services.razorpay.webhook_secret')),
                'webhook_url' => rtrim(config('app.url'), '/').'/v1/webhooks/razorpay',
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page_id' => ['required', 'regex:/^\d{5,25}$/'],
            'access_token' => ['required', 'string', 'min:20', 'max:1000'],
        ], ['page_id.regex' => 'The Page ID is a long number (Facebook Page → About → Page transparency).']);

        try {
            $name = $this->meta->pageName($data['page_id'], $data['access_token']);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['access_token' => 'Meta said: '.$e->getMessage()]);
        }

        $page = MetaPage::updateOrCreate(['page_id' => $data['page_id']], ['name' => $name, 'access_token' => $data['access_token'], 'last_error' => null]);

        try {
            $this->meta->subscribe($page);
        } catch (RuntimeException $e) {
            $page->update(['last_error' => 'Saved, but subscribing for new leads failed: '.$e->getMessage()]);
        }

        return response()->json($page->fresh()->toApiArray(), 201);
    }

    public function destroy(MetaPage $metaPage): JsonResponse
    {
        $metaPage->delete();

        return response()->json(['deleted' => true]);
    }

    public function sync(): JsonResponse
    {
        $created = $this->meta->poll(72);

        return response()->json(['created' => $created, 'pages' => MetaPage::orderBy('name')->get()->map->toApiArray()]);
    }
}
