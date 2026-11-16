<?php

namespace App\Http\Controllers\WhatsApp;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Lead;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Services\Leads\MetaLeads;
use App\Services\Notify;
use App\Services\WhatsApp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * WhatsApp Business: an inbox, one thread per phone number, sending templates / replies,
 * and Meta's webhook for incoming messages and delivery ticks.
 */
class WhatsAppController extends Controller
{
    public function __construct(private WhatsApp $wa, private Notify $notify) {}

    // ---------- Settings → Integrations (admin) ----------

    public function settings(): JsonResponse
    {
        $s = $this->wa->settings();

        return response()->json([
            'connected' => $this->wa->configured(),
            'display_phone' => $s['display_phone'] ?? null,
            'name' => $s['name'] ?? null,
            'phone_number_id' => $s['phone_number_id'] ?? null,
            'waba_id' => $s['waba_id'] ?? null,
            'webhook_url' => rtrim(config('app.url'), '/').'/v1/webhooks/whatsapp',
            'webhook_ready' => (bool) (config('services.meta.app_secret') && config('services.meta.verify_token')),
            'last_incoming_at' => WhatsAppMessage::where('direction', 'in')->max('created_at'),
        ]);
    }

    public function connect(Request $request): JsonResponse
    {
        $d = $request->validate([
            'phone_number_id' => ['required', 'regex:/^\d{6,25}$/'],
            'waba_id' => ['required', 'regex:/^\d{6,25}$/'],
            'access_token' => ['required', 'string', 'min:20', 'max:1000'],
        ], [
            'phone_number_id.regex' => 'The Phone number ID is a long number (Meta → WhatsApp → API Setup).',
            'waba_id.regex' => 'The WhatsApp Business Account ID is a long number (Meta → WhatsApp → API Setup).',
        ]);
        try {
            $info = $this->wa->connect($d['phone_number_id'], $d['waba_id'], trim($d['access_token']));
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['access_token' => 'Meta said: '.$e->getMessage()]);
        }
        ActivityLog::record('updated', 'WhatsApp Business connected ('.($info['display_phone'] ?? '').')', type: 'settings');

        return $this->settings();
    }

    public function disconnect(): JsonResponse
    {
        $this->wa->disconnect();
        ActivityLog::record('updated', 'WhatsApp Business disconnected', type: 'settings');

        return $this->settings();
    }

    // ---------- Staff ----------

    /** Is a number connected, and how many unread messages this person has (for the menu badge). */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $unread = WhatsAppMessage::where('direction', 'in')->whereNull('seen_at')->get(['lead_id', 'client_id', 'phone'])
            ->filter(fn ($m) => $this->maySee($user, $m->lead_id, $m->client_id, $m->phone))->count();

        return response()->json(['connected' => $this->wa->configured(), 'can_send' => $user->hasPermission('whatsapp.create'), 'unread' => $unread]);
    }

    /** Recent conversations, newest first, with unread counts. */
    public function inbox(Request $request): JsonResponse
    {
        $user = $request->user();
        $search = trim((string) $request->query('search', ''));
        $latest = WhatsAppMessage::with(['lead:id,full_name,assigned_to', 'client:id,company_name,account_manager_id'])
            ->whereIn('id', WhatsAppMessage::selectRaw('MAX(id)')->groupBy('phone'))
            ->orderByDesc('id')->limit(300)->get();
        $unread = WhatsAppMessage::where('direction', 'in')->whereNull('seen_at')->selectRaw('phone, COUNT(*) n')->groupBy('phone')->pluck('n', 'phone');

        $rows = $latest->filter(fn (WhatsAppMessage $m) => $this->maySee($user, $m->lead_id, $m->client_id, $m->phone))
            ->map(fn (WhatsAppMessage $m) => [
                'phone' => $m->phone,
                'name' => $this->nameFor($m->phone, $m),
                'lead_id' => $m->lead_id, 'client_id' => $m->client_id,
                'last' => ['text' => $m->body ?: '['.$m->type.']', 'direction' => $m->direction, 'status' => $m->status, 'at' => $m->created_at?->toIso8601String()],
                'unread' => (int) ($unread[$m->phone] ?? 0),
            ])
            ->when($search !== '', fn ($c) => $c->filter(fn ($r) => str_contains(mb_strtolower($r['name'].' '.$r['phone']), mb_strtolower($search))))
            ->values();

        return response()->json(['data' => $rows, 'connected' => $this->wa->configured(), 'can_send' => $user->hasPermission('whatsapp.create')]);
    }

    /** One conversation (by lead, client or phone). Opening it marks incoming messages as seen. */
    public function thread(Request $request): JsonResponse
    {
        $c = $this->contact($request);
        $msgs = WhatsAppMessage::with('user:id,name')->where('phone', $c['phone'])->orderByDesc('id')->limit(200)->get()->reverse()->values();
        WhatsAppMessage::where('phone', $c['phone'])->where('direction', 'in')->whereNull('seen_at')->update(['seen_at' => now()]);
        $lastIn = $msgs->where('direction', 'in')->last()?->created_at;

        return response()->json([
            'contact' => $c,
            'data' => $msgs->map->toApiArray(),
            // Meta allows free text only within 24 hours of the person's last message.
            'window_open' => $lastIn && $lastIn->gt(now()->subDay()),
            'window_ends_at' => $lastIn?->copy()->addDay()->toIso8601String(),
            'connected' => $this->wa->configured(),
            'can_send' => $request->user()->hasPermission('whatsapp.create'),
        ]);
    }

    public function templates(): JsonResponse
    {
        try {
            return response()->json(['data' => $this->wa->templates(request()->boolean('fresh'))]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'data' => []], 422);
        }
    }

    public function send(Request $request): JsonResponse
    {
        $user = $request->user();
        $c = $this->contact($request);
        $d = $request->validate([
            'text' => ['nullable', 'string', 'max:4000', 'required_without:template'],
            'template' => ['nullable', 'string', 'max:120'],
            'header_values' => ['nullable', 'array', 'max:5'],
            'header_values.*' => ['string', 'max:60'],
            'body_values' => ['nullable', 'array', 'max:20'],
            'body_values.*' => ['string', 'max:500'],
        ], ['text.required_without' => 'Type a message or choose a template.']);

        try {
            if (! empty($d['template'])) {
                $t = collect($this->wa->templates())->firstWhere('name', $d['template']);
                abort_unless($t, 422, 'That template is not approved (or was removed). Press ↻ to refresh the list.');
                $hv = array_values($d['header_values'] ?? []);
                $bv = array_values($d['body_values'] ?? []);
                if (count($hv) < $t['header_vars'] || count($bv) < $t['body_vars'] || in_array('', array_map('trim', [...$hv, ...$bv]), true)) {
                    throw ValidationException::withMessages(['body_values' => 'Fill in every {{…}} value of the template.']);
                }
                [$id, $text] = $this->wa->sendTemplate($c['phone'], $t, $hv, $bv);
                $type = 'template';
            } else {
                $lastIn = WhatsAppMessage::where('phone', $c['phone'])->where('direction', 'in')->max('created_at');
                if (! $lastIn || now()->subDay()->gt($lastIn)) {
                    throw ValidationException::withMessages(['text' => 'More than 24 hours since their last message: send an approved template first.']);
                }
                $text = trim($d['text']);
                $id = $this->wa->sendText($c['phone'], $text);
                $type = 'text';
            }
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['text' => $e->getMessage()]);
        }

        $msg = WhatsAppMessage::create([
            'phone' => $c['phone'], 'lead_id' => $c['lead_id'], 'client_id' => $c['client_id'], 'user_id' => $user->id,
            'direction' => 'out', 'type' => $type, 'body' => $text, 'template' => $d['template'] ?? null, 'wa_id' => $id, 'status' => 'sent',
        ]);
        if ($c['lead_id']) {
            Lead::find($c['lead_id'])?->log($user, 'whatsapp', mb_strimwidth($text, 0, 300, '…'), 'Message sent');
        }

        return response()->json($msg->load('user:id,name')->toApiArray(), 201);
    }

    // ---------- Webhook (Meta, no login) ----------

    public function verify(Request $request): Response
    {
        $ok = $request->query('hub_mode') === 'subscribe' && config('services.meta.verify_token')
            && hash_equals((string) config('services.meta.verify_token'), (string) $request->query('hub_verify_token'));

        return $ok ? response((string) $request->query('hub_challenge'), 200) : response('Forbidden', 403);
    }

    public function receive(Request $request): Response
    {
        if (! app(MetaLeads::class)->validSignature($request->getContent(), $request->header('X-Hub-Signature-256'))) {
            Log::warning('WhatsApp webhook with a bad signature was ignored.');

            return response('Invalid signature', 403);
        }
        $this->handle($request->input('entry', []));

        return response('EVENT_RECEIVED', 200);
    }

    /** Also used when Meta sends WhatsApp events to the Lead Ads webhook URL. */
    public function handle(array $entries): void
    {
        foreach ($entries as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $v = $change['value'] ?? [];
                $names = collect($v['contacts'] ?? [])->mapWithKeys(fn ($c) => [(string) ($c['wa_id'] ?? '') => $c['profile']['name'] ?? null]);
                foreach ($v['messages'] ?? [] as $m) {
                    $this->incoming($m, $names[(string) ($m['from'] ?? '')] ?? null);
                }
                foreach ($v['statuses'] ?? [] as $s) {
                    $this->statusUpdate($s);
                }
            }
        }
    }

    private function incoming(array $m, ?string $profile): void
    {
        $phone = WhatsApp::phone($m['from'] ?? '') ?? (string) ($m['from'] ?? '');
        if (! $phone || WhatsAppMessage::where('wa_id', $m['id'] ?? '')->exists()) {
            return; // Meta may deliver the same event twice
        }
        $type = (string) ($m['type'] ?? 'text');
        $body = match ($type) {
            'text' => $m['text']['body'] ?? '',
            'button' => $m['button']['text'] ?? '',
            'interactive' => $m['interactive']['button_reply']['title'] ?? $m['interactive']['list_reply']['title'] ?? '',
            'image', 'video', 'document' => trim(($m[$type]['caption'] ?? '').' ['.$type.($type === 'document' ? ': '.($m['document']['filename'] ?? '') : '').']'),
            'location' => '📍 '.($m['location']['name'] ?? '').' '.($m['location']['latitude'] ?? '').','.($m['location']['longitude'] ?? ''),
            default => '['.$type.']',
        };
        [$leadId, $clientId] = $this->match($phone);
        $msg = WhatsAppMessage::create([
            'phone' => $phone, 'name' => $profile ? mb_substr($profile, 0, 120) : null, 'lead_id' => $leadId, 'client_id' => $clientId,
            'direction' => 'in', 'type' => $type, 'body' => $body, 'wa_id' => $m['id'] ?? null, 'status' => 'received',
        ]);

        // Tell whoever looks after this lead / client; otherwise the admins.
        $owner = $leadId ? Lead::whereKey($leadId)->value('assigned_to') : ($clientId ? Client::whereKey($clientId)->value('account_manager_id') : null);
        $to = $owner ? User::whereKey($owner)->get() : User::where('role', User::ROLE_ADMIN)->where('status', User::STATUS_ACTIVE)->get();
        $to = $to->filter(fn (User $u) => $u->hasPermission('whatsapp.view'));
        $this->notify->send($to, 'whatsapp_in', 'WhatsApp from '.$this->nameFor($phone, $msg), mb_strimwidth($body, 0, 160, '…'), '/whatsapp?phone='.$phone);
    }

    private function statusUpdate(array $s): void
    {
        $msg = WhatsAppMessage::where('wa_id', $s['id'] ?? '')->first();
        if (! $msg) {
            return;
        }
        $order = ['sent' => 1, 'delivered' => 2, 'read' => 3];
        $new = (string) ($s['status'] ?? '');
        if ($new === 'failed') {
            $e = $s['errors'][0] ?? [];
            $msg->update(['status' => 'failed', 'error' => mb_substr((string) ($e['error_data']['details'] ?? $e['title'] ?? $e['message'] ?? 'Not delivered'), 0, 250)]);
        } elseif (($order[$new] ?? 0) > ($order[$msg->status] ?? 0)) {
            $msg->update(['status' => $new]); // never step back (Meta's events can arrive out of order)
        }
    }

    // ---------- helpers ----------

    /** The lead / client / phone the request is about, after checking the user may see it. */
    private function contact(Request $request): array
    {
        $user = $request->user();
        $d = $request->validate(['lead_id' => ['nullable', 'integer'], 'client_id' => ['nullable', 'integer'], 'phone' => ['nullable', 'string', 'max:30']]);
        if (! empty($d['lead_id'])) {
            $lead = Lead::visibleTo($user)->find($d['lead_id']);
            abort_unless($lead, 404, 'Lead not found.');
            $phone = WhatsApp::phone($lead->phone);
            abort_unless($phone, 422, 'This lead has no valid mobile number.');

            return ['phone' => $phone, 'name' => $lead->full_name, 'lead_id' => $lead->id, 'client_id' => null];
        }
        if (! empty($d['client_id'])) {
            $client = Client::visibleTo($user)->find($d['client_id']);
            abort_unless($client, 404, 'Client not found.');
            $phone = WhatsApp::phone($client->phone);
            abort_unless($phone, 422, 'This client has no valid mobile number.');

            return ['phone' => $phone, 'name' => $client->company_name, 'lead_id' => null, 'client_id' => $client->id];
        }
        $phone = WhatsApp::phone($d['phone'] ?? '');
        abort_unless($phone, 422, 'Choose a lead, a client or a phone number.');
        [$leadId, $clientId] = $this->match($phone);
        abort_unless($this->maySee($user, $leadId, $clientId, $phone), 403, 'This conversation belongs to someone else’s lead or client.');

        return ['phone' => $phone, 'name' => $this->nameFor($phone), 'lead_id' => $leadId, 'client_id' => $clientId];
    }

    /** Leads / clients follow their own visibility; numbers we don't know are for admins and "all leads" staff. */
    private function maySee(User $user, ?int $leadId, ?int $clientId, string $phone): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        if ($leadId) {
            return Lead::visibleTo($user)->whereKey($leadId)->exists();
        }
        if ($clientId) {
            return Client::visibleTo($user)->whereKey($clientId)->exists();
        }

        return $user->leads_scope === 'all';
    }

    /** The newest lead, else the client, whose phone is this number. */
    private function match(string $phone): array
    {
        $tail = substr($phone, -10);
        $hit = fn ($rows) => $rows->first(fn ($r) => WhatsApp::phone($r->phone) === $phone);
        $lead = $hit(Lead::where('phone', 'like', '%'.substr($tail, -4))->orderByDesc('id')->limit(200)->get(['id', 'phone']));
        $client = $lead ? null : $hit(Client::where('phone', 'like', '%'.substr($tail, -4))->limit(200)->get(['id', 'phone']));

        return [$lead?->id, $client?->id];
    }

    private function nameFor(string $phone, ?WhatsAppMessage $m = null): string
    {
        if ($m?->lead) {
            return $m->lead->full_name;
        }
        if ($m?->client) {
            return $m->client->company_name;
        }
        [$leadId, $clientId] = $m ? [$m->lead_id, $m->client_id] : $this->match($phone);
        $name = $leadId ? Lead::whereKey($leadId)->value('full_name') : ($clientId ? Client::whereKey($clientId)->value('company_name') : null);

        return $name ?? WhatsAppMessage::where('phone', $phone)->whereNotNull('name')->latest('id')->value('name') ?? '+'.$phone;
    }
}
