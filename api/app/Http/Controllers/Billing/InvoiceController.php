<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use App\Notifications\InvoiceIssued;
use App\Services\Billing\CompanyProfile;
use App\Services\Billing\InvoiceService;
use App\Services\Billing\UpiQr;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

class InvoiceController extends Controller
{
    public function __construct(private InvoiceService $invoices) {}

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in(['draft', 'unpaid', 'partial', 'paid', 'cancelled', 'overdue', 'open'])],
            'client_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:60'],
        ]);
        $user = $request->user();
        $base = Invoice::visibleTo($user);

        $list = (clone $base)->with('client:id,company_name')
            ->when($f['client_id'] ?? null, fn ($q, $id) => $q->where('client_id', $id))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('invoice_no', 'like', "%$s%")
                ->orWhereHas('client', fn ($c) => $c->where('company_name', 'like', "%$s%"))))
            ->when($f['status'] ?? null, fn ($q, $s) => match ($s) {
                'overdue' => $q->whereIn('status', ['unpaid', 'partial'])->whereDate('due_date', '<', today()),
                'open' => $q->whereIn('status', ['unpaid', 'partial']),
                default => $q->where('status', $s),
            })
            ->orderByRaw("case status when 'draft' then 0 else 1 end")->orderByDesc('issue_date')->orderByDesc('id')
            ->limit(300)->get();

        $open = (clone $base)->whereIn('status', ['unpaid', 'partial']);

        return response()->json([
            'data' => $list->map->toApiArray(),
            'summary' => [
                'outstanding' => round((float) (clone $open)->sum(DB::raw('total - amount_paid')), 2),
                'overdue' => round((float) (clone $open)->whereDate('due_date', '<', today())->sum(DB::raw('total - amount_paid')), 2),
                'overdue_count' => (clone $open)->whereDate('due_date', '<', today())->count(),
                'paid_this_month' => round((float) \App\Models\Payment::whereIn('invoice_id', (clone $base)->select('id'))
                    ->where('paid_on', '>=', today()->startOfMonth())->sum('amount'), 2),
                'pending_claims' => \App\Models\PaymentClaim::where('status', 'pending')->whereIn('invoice_id', (clone $base)->select('id'))->count(),
            ],
        ]);
    }

    public function show(Request $request, Invoice $invoice, UpiQr $upi, CompanyProfile $company): JsonResponse
    {
        $this->authorizeInvoice($request->user(), $invoice);
        $invoice->load(['items', 'client.manager', 'payments', 'claims.submitter']);
        $c = $company->get();

        return response()->json([
            ...$invoice->toApiArray(true),
            'pay' => [
                'online' => app(\App\Services\Billing\Razorpay::class)->configured(),
                'upi_id' => $c['upi_id'] ?: null,
                'upi_link' => $upi->link($invoice),
                'qr' => $upi->dataUri($invoice),
                'bank' => $c['bank_account_number'] ? [
                    'account_name' => $c['bank_account_name'] ?: $c['legal_name'], 'account_number' => $c['bank_account_number'],
                    'ifsc' => $c['bank_ifsc'], 'bank' => $c['bank_name'], 'branch' => $c['bank_branch'],
                ] : null,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $this->authorizeClientId($request->user(), $data['client_id']);
        $invoice = $this->invoices->saveDraft($data, null, $request->user());
        if ($request->boolean('issue')) {
            $invoice = $this->invoices->issue($invoice);
        }

        return response()->json($invoice->load('payments', 'claims')->toApiArray(true), 201);
    }

    public function update(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorizeInvoice($request->user(), $invoice);
        $data = $this->validated($request);
        $this->authorizeClientId($request->user(), $data['client_id']);
        $invoice = $this->invoices->saveDraft($data, $invoice, $request->user());
        if ($request->boolean('issue')) {
            $invoice = $this->invoices->issue($invoice);
        }

        return response()->json($invoice->load('payments', 'claims')->toApiArray(true));
    }

    public function issue(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorizeInvoice($request->user(), $invoice);

        return response()->json($this->invoices->issue($invoice)->load('payments', 'claims')->toApiArray(true));
    }

    public function destroy(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorizeInvoice($request->user(), $invoice);
        abort_unless($invoice->status === 'draft', 422, 'Only drafts can be deleted. Cancel issued invoices instead.');
        $invoice->delete();

        return response()->json(['deleted' => true]);
    }

    public function cancel(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorizeInvoice($request->user(), $invoice);
        abort_if($invoice->status === 'draft', 422, 'Delete the draft instead.');
        abort_if($invoice->amount_paid > 0, 422, 'This invoice has payments. Remove them before cancelling.');
        $invoice->update(['status' => 'cancelled']);

        return response()->json($invoice->fresh(['items', 'client', 'payments', 'claims'])->toApiArray(true));
    }

    public function pdf(Request $request, Invoice $invoice): Response
    {
        $this->authorizeInvoice($request->user(), $invoice);

        return $this->invoices->pdf($invoice)->download($this->invoices->fileName($invoice));
    }

    public function email(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorizeInvoice($request->user(), $invoice);
        abort_if(in_array($invoice->status, ['draft', 'cancelled'], true), 422, 'Issue the invoice before sending it.');
        $to = $request->validate(['to' => ['nullable', 'email']])['to'] ?? $invoice->client->email;
        abort_unless($to, 422, 'This client has no email address. Add one on the client profile.');

        Notification::route('mail', $to)->notify(new InvoiceIssued($invoice->load('client', 'items')));
        $invoice->update(['emailed_at' => now()]);

        return response()->json(['sent_to' => $to]);
    }

    /** Record a payment received (bank transfer, UPI, cash, cheque). */
    public function recordPayment(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorizeInvoice($request->user(), $invoice);
        abort_if(in_array($invoice->status, ['draft', 'cancelled'], true), 422, 'Payments can only be recorded on issued invoices.');
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:'.$invoice->balance()],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'mode' => ['required', Rule::in(['upi', 'bank_transfer', 'cash', 'cheque', 'card'])],
            'reference' => ['nullable', 'string', 'max:60'],
            'note' => ['nullable', 'string', 'max:500'],
        ], ['amount.max' => 'The amount is more than the balance due (Rs. '.number_format($invoice->balance(), 2).').']);

        DB::transaction(function () use ($invoice, $data, $request) {
            $invoice->payments()->create([...$data, 'client_id' => $invoice->client_id, 'recorded_by' => $request->user()->id]);
            $invoice->refreshPaidStatus();
        });

        return response()->json($invoice->fresh(['items', 'client', 'payments', 'claims'])->toApiArray(true), 201);
    }

    private function authorizeInvoice(User $user, Invoice $invoice): void
    {
        abort_unless(Invoice::visibleTo($user)->whereKey($invoice->id)->exists(), 404);
    }

    private function authorizeClientId(User $user, int $clientId): void
    {
        abort_unless(Client::visibleTo($user)->whereKey($clientId)->exists(), 403, 'This client is managed by someone else.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')],
            'issue_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'gst_type' => ['nullable', Rule::in(['intra', 'inter', 'export'])],
            'gst_rate' => ['nullable', Rule::in([0, 5, 12, 18, 28])],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.sac' => ['nullable', 'regex:/^\d{4,8}$/'],
            'items.*.qty' => ['required', 'numeric', 'min:0.01', 'max:100000'],
            'items.*.rate' => ['required', 'numeric', 'min:0', 'max:100000000'],
        ], ['items.required' => 'Add at least one line item.', 'items.*.description.required' => 'Each line needs a description.']);
    }
}
