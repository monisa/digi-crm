<?php

namespace App\Http\Controllers\Leads;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Lead;
use App\Models\User;
use App\Services\Leads\LeadImporter;
use App\Services\Leads\LeadOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeadController extends Controller
{
    public function __construct(private LeadOptions $options) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string'],
            'call_status' => ['nullable', 'string', 'max:60'],
            'ad' => ['nullable', 'string', 'max:190'],
            'assigned' => ['nullable', 'string'], // user id | 'me' | 'none'
            'view' => ['nullable', Rule::in(['uncontacted', 'followups', 'today'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:500'],
        ]);
        $user = $request->user();
        $base = Lead::visibleTo($user);

        $leads = $this->filtered(clone $base, $filters, $user)
            ->with('assignee:id,name')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 50);

        $sla = now()->subMinutes(config('crm.lead_sla_minutes'));

        return response()->json([
            'data' => $leads->getCollection()->map->toApiArray(),
            'meta' => ['total' => $leads->total(), 'page' => $leads->currentPage(), 'last_page' => $leads->lastPage()],
            'counts' => [
                'all' => (clone $base)->count(),
                'uncontacted' => (clone $base)->where('status', 'new')->whereNull('first_contacted_at')->where('created_at', '<=', $sla)->count(),
                'followups' => (clone $base)->whereNotNull('next_followup_at')->where('next_followup_at', '<=', now()->endOfDay())
                    ->whereNotIn('status', ['won', 'lost'])->count(),
                'today' => (clone $base)->where('created_at', '>=', today())->count(),
                'by_status' => (clone $base)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            ],
            // Ad names for the filter, most leads first.
            'ads' => (clone $base)->whereNotNull('ad_name')->where('ad_name', '!=', '')
                ->selectRaw('ad_name, count(*) as n')->groupBy('ad_name')->orderByDesc('n')->limit(100)->pluck('n', 'ad_name'),
        ]);
    }

    public function show(Request $request, Lead $lead): JsonResponse
    {
        $this->authorizeLead($request->user(), $lead);

        return response()->json($lead->load(['assignee:id,name', 'activities'])->toApiArray(true));
    }

    public function store(Request $request, LeadImporter $importer): JsonResponse
    {
        $data = $this->validated($request);
        $data['phone'] = isset($data['phone']) ? $importer->phone($data['phone']) : null;
        // Staff who only see their own leads get the lead assigned to themselves.
        if (! $request->user()->isAdmin() && $request->user()->leads_scope !== 'all') {
            $data['assigned_to'] = $request->user()->id;
        }

        $lead = Lead::create([...$data, 'source' => 'manual', 'status' => $data['status'] ?? 'new', 'call_status' => $data['call_status'] ?? 'Not called']);
        $lead->log($request->user(), 'system', 'Lead added by hand');

        return response()->json($lead->load('assignee:id,name', 'activities')->toApiArray(true), 201);
    }

    public function update(Request $request, Lead $lead, LeadImporter $importer): JsonResponse
    {
        $user = $request->user();
        $this->authorizeLead($user, $lead);
        $data = $this->validated($request, partial: true);
        if (array_key_exists('phone', $data) && $data['phone']) {
            $data['phone'] = $importer->phone($data['phone']);
        }
        if (array_key_exists('assigned_to', $data) && ! $user->isAdmin() && $user->leads_scope !== 'all') {
            unset($data['assigned_to']); // only people who see all leads can reassign
        }

        $before = $lead->only(['status', 'assigned_to', 'call_status']);
        $lead->fill($data);
        if ($lead->isDirty('status') && $lead->status !== 'new' && ! $lead->first_contacted_at) {
            $lead->first_contacted_at = now();
        }
        $lead->save();

        if ($before['status'] !== $lead->status) {
            $statuses = config('crm.lead_statuses');
            $lead->log($user, 'status', 'Stage changed to '.$statuses[$lead->status].($lead->status === 'lost' && $lead->lost_reason ? " ({$lead->lost_reason})" : ''));
        }
        if ($before['assigned_to'] !== $lead->assigned_to) {
            $lead->log($user, 'assignment', $lead->assigned_to ? 'Assigned to '.User::find($lead->assigned_to)?->name : 'Unassigned');
            $this->notifyAssigned($lead, $user);
        }

        return response()->json($lead->fresh(['assignee:id,name', 'activities'])->toApiArray(true));
    }

    public function destroy(Request $request, Lead $lead): JsonResponse
    {
        $this->authorizeLead($request->user(), $lead);
        $lead->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Delete many leads: the ticked ones (ids), or every lead matching the
     * current filters (all = true). Only leads this person can see.
     */
    public function bulkDestroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'all' => ['sometimes', 'boolean'],
            'ids' => ['required_without:all', 'array', 'max:500'],
            'ids.*' => ['integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string'],
            'call_status' => ['nullable', 'string', 'max:60'],
            'ad' => ['nullable', 'string', 'max:190'],
            'assigned' => ['nullable', 'string'],
            'view' => ['nullable', Rule::in(['uncontacted', 'followups', 'today'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
        $user = $request->user();
        $q = Lead::visibleTo($user);
        $q = ($data['all'] ?? false) ? $this->filtered($q, $data, $user) : $q->whereIn('id', $data['ids']);

        $deleted = 0;
        $q->select('id')->chunkById(500, function ($chunk) use (&$deleted) {
            $deleted += Lead::whereIn('id', $chunk->pluck('id'))->delete(); // activities go with them (cascade)
        });

        return response()->json(['deleted' => $deleted]);
    }

    /** Log a call / WhatsApp / meeting / note; contacting a new lead moves it to "Contacted". */
    public function logActivity(Request $request, Lead $lead): JsonResponse
    {
        $user = $request->user();
        $this->authorizeLead($user, $lead);
        $data = $request->validate([
            'type' => ['required', Rule::in(['call', 'whatsapp', 'email', 'meeting', 'note'])],
            'outcome' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:2000'],
            'next_followup_at' => ['nullable', 'date'],
            'followup_status' => ['nullable', 'string', 'max:120'],
        ]);
        if (! ($data['note'] ?? null) && ! ($data['outcome'] ?? null)) {
            return response()->json(['message' => 'Add an outcome or a note.', 'errors' => ['note' => ['Add an outcome or a note.']]], 422);
        }

        DB::transaction(function () use ($lead, $user, $data) {
            $lead->log($user, $data['type'], $data['note'] ?? null, $data['outcome'] ?? null);
            if ($data['type'] !== 'note') {
                $lead->first_contacted_at ??= now();
                if ($lead->status === 'new') {
                    $lead->status = 'contacted';
                }
            }
            if ($data['type'] === 'call' && ($data['outcome'] ?? null)) {
                $lead->call_status = $data['outcome'];
            }
            if (array_key_exists('next_followup_at', $data)) {
                $lead->next_followup_at = $data['next_followup_at'];
            }
            if ($data['followup_status'] ?? null) {
                $lead->followup_status = $data['followup_status'];
            }
            $lead->save();
        });

        return response()->json($lead->fresh(['assignee:id,name', 'activities'])->toApiArray(true), 201);
    }

    public function bulkAssign(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
            'assigned_to' => ['nullable', Rule::exists('users', 'id')->where('status', 'active')],
        ]);
        $user = $request->user();
        abort_unless($user->isAdmin() || $user->leads_scope === 'all', 403, 'Only people who see all leads can reassign them.');

        $name = $data['assigned_to'] ? User::find($data['assigned_to'])->name : null;
        $leads = Lead::whereIn('id', $data['ids'])->get();
        foreach ($leads as $lead) {
            if ($lead->assigned_to !== $data['assigned_to']) {
                $lead->update(['assigned_to' => $data['assigned_to']]);
                $lead->log($user, 'assignment', $name ? "Assigned to $name" : 'Unassigned');
                $this->notifyAssigned($lead, $user);
            }
        }

        return response()->json(['updated' => $leads->count()]);
    }

    /** Win the lead and create a client record from it. */
    public function convert(Request $request, Lead $lead): JsonResponse
    {
        $user = $request->user();
        $this->authorizeLead($user, $lead);
        abort_if($lead->converted_client_id, 422, 'This lead is already a client.');
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:160'],
            'contact_name' => ['nullable', 'string', 'max:120'],
        ]);

        $client = DB::transaction(function () use ($lead, $user, $data) {
            $client = Client::create([
                'company_name' => $data['company_name'],
                'contact_name' => $data['contact_name'] ?? $lead->full_name,
                'email' => $lead->email,
                'phone' => $lead->phone,
                'status' => 'active',
                'city' => $lead->city,
                'account_manager_id' => $lead->assigned_to ?? $user->id,
                'source_lead_id' => $lead->id,
            ]);
            $lead->update(['status' => 'won', 'converted_client_id' => $client->id, 'first_contacted_at' => $lead->first_contacted_at ?? now()]);
            $lead->log($user, 'status', "Won: converted to client {$client->company_name}");

            return $client;
        });

        return response()->json(['client_id' => $client->id, 'lead' => $lead->fresh(['assignee:id,name', 'activities'])->toApiArray(true)]);
    }

    public function import(Request $request, LeadImporter $importer): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:5120', 'mimes:csv,txt']],
            ['file.mimes' => 'Please upload a CSV file (Google Sheets: File → Download → Comma-separated values).']);

        return response()->json($importer->import($request->file('file')->getRealPath(), $request->user()));
    }

    /** CSV in the same column order as the DigiFox lead sheet. */
    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();
        $query = $this->filtered(Lead::visibleTo($user), $request->all(), $user)->with('assignee:id,name')->orderByDesc('created_at');
        $statuses = config('crm.lead_statuses');

        return response()->streamDownload(function () use ($query, $statuses) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // so Excel reads ₹ and Malayalam names correctly
            fputcsv($out, ['Date', 'AD Name', 'Name', 'Phone Number', 'Email ID', 'Location (City)', 'Call Status', 'Status ', 'Package ', 'Requirement', 'Follow-up Status', 'Notes / Remarks', 'Assigned to'], escape: '\\');
            $query->chunk(500, function ($leads) use ($out, $statuses) {
                foreach ($leads as $l) {
                    fputcsv($out, [
                        $l->created_at?->format('d/m/Y'), $l->ad_name, $l->full_name, $l->phone, $l->email, $l->city, $l->call_status,
                        $statuses[$l->status] ?? $l->status, $l->package, $l->requirement,
                        trim(($l->followup_status ?? '').($l->next_followup_at ? ' '.$l->next_followup_at->format('d/m/Y') : '')),
                        $l->notes, $l->assignee?->name,
                    ], escape: '\\');
                }
            });
            fclose($out);
        }, 'digifox-leads-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function options(): JsonResponse
    {
        return response()->json([
            ...$this->options->all(),
            'assignees' => User::whereIn('role', ['admin', 'employee'])->where('status', 'active')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function saveOptions(Request $request): JsonResponse
    {
        $list = ['required', 'array', 'max:30'];
        $item = ['string', 'max:60', 'distinct'];
        $data = $request->validate([
            'call_statuses' => $list, 'call_statuses.*' => $item,
            'packages' => $list, 'packages.*' => $item,
            'followup_statuses' => $list, 'followup_statuses.*' => $item,
            'lost_reasons' => $list, 'lost_reasons.*' => $item,
            'auto_assign' => ['required', 'boolean'],
            'assign_pool' => ['present', 'array'],
            'assign_pool.*' => ['integer', Rule::exists('users', 'id')],
        ]);

        return response()->json($this->options->save($data));
    }

    private function filtered(Builder $q, array $f, User $user): Builder
    {
        $statuses = array_keys(config('crm.lead_statuses'));

        return $q
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q
                ->where('full_name', 'like', "%$s%")->orWhere('phone', 'like', '%'.$this->digitsLike($s).'%')
                ->orWhere('email', 'like', "%$s%")->orWhere('city', 'like', "%$s%")->orWhere('ad_name', 'like', "%$s%")))
            ->when(in_array($f['status'] ?? null, $statuses, true), fn ($q) => $q->where('status', $f['status']))
            ->when($f['call_status'] ?? null, fn ($q, $c) => $q->where('call_status', $c))
            ->when($f['ad'] ?? null, fn ($q, $a) => $a === '__none' ? $q->where(fn ($q) => $q->whereNull('ad_name')->orWhere('ad_name', '')) : $q->where('ad_name', $a))
            ->when($f['assigned'] ?? null, fn ($q, $a) => match ($a) {
                'me' => $q->where('assigned_to', $user->id),
                'none' => $q->whereNull('assigned_to'),
                default => $q->where('assigned_to', (int) $a),
            })
            ->when(($f['view'] ?? null) === 'uncontacted', fn ($q) => $q->where('status', 'new')->whereNull('first_contacted_at')
                ->where('created_at', '<=', now()->subMinutes(config('crm.lead_sla_minutes'))))
            ->when(($f['view'] ?? null) === 'followups', fn ($q) => $q->whereNotNull('next_followup_at')
                ->where('next_followup_at', '<=', now()->endOfDay())->whereNotIn('status', ['won', 'lost']))
            ->when(($f['view'] ?? null) === 'today', fn ($q) => $q->where('created_at', '>=', today()))
            ->when($f['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d));
    }

    /** "98470 12345" matches the stored "+91 98470 12345" too. */
    private function digitsLike(string $s): string
    {
        $d = preg_replace('/\D/', '', $s);
        if (strlen($d) < 5) {
            return $s; // not a phone number: plain text search
        }
        if (strlen($d) === 12 && str_starts_with($d, '91')) {
            $d = substr($d, 2);
        }

        return strlen($d) >= 5 ? substr($d, 0, 5).(strlen($d) > 5 ? '%'.substr($d, 5) : '') : $d;
    }

    private function notifyAssigned(Lead $lead, User $by): void
    {
        if ($lead->assigned_to) {
            app(\App\Services\Notify::class)->send(User::find($lead->assigned_to), 'lead_assigned',
                'Lead assigned to you: '.$lead->full_name, $by->name.' assigned this lead to you.', '/leads?open='.$lead->id, false, $by);
        }
    }

    private function authorizeLead(User $user, Lead $lead): void
    {
        abort_unless($user->isAdmin() || $user->leads_scope === 'all' || $lead->assigned_to === $user->id, 403, 'This lead is assigned to someone else.');
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'full_name' => [$req, 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'regex:/^[+0-9 ()-]{8,20}$/', ...($partial ? [] : ['required_without:email'])],
            'email' => ['nullable', 'email', 'max:190'],
            'city' => ['nullable', 'string', 'max:120'],
            'ad_name' => ['nullable', 'string', 'max:190'],
            'call_status' => ['nullable', 'string', 'max:60'],
            'status' => ['sometimes', Rule::in(array_keys(config('crm.lead_statuses')))],
            'package' => ['nullable', 'string', 'max:120'],
            'requirement' => ['nullable', 'string', 'max:2000'],
            'followup_status' => ['nullable', 'string', 'max:120'],
            'next_followup_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'lost_reason' => ['nullable', 'string', 'max:190'],
            'assigned_to' => ['nullable', Rule::exists('users', 'id')->where('status', 'active')],
        ], ['phone.required_without' => 'Enter a phone number or an email.']);
    }
}
