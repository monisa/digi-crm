<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Services\Notify;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Expenses. Approvers (expenses.approve, admins) add expenses that count straight
 * away and approve other people's claims; everyone else's go to "pending".
 * Only approved expenses count on the dashboard.
 */
class ExpenseController extends Controller
{
    public function __construct(private Notify $notify) {}

    public function index(Request $request): JsonResponse
    {
        $f = $this->filters($request);
        $user = $request->user();
        $base = Expense::visibleTo($user);
        [$from, $to] = [CarbonImmutable::parse($f['from']), CarbonImmutable::parse($f['to'])];

        $list = $this->filtered(clone $base, $f)->with(['creator:id,name', 'approver:id,name', 'client:id,company_name'])
            ->orderByDesc('spent_on')->orderByDesc('id')->limit(500)->get();

        $approvedInRange = $this->between((clone $base)->where('status', 'approved'), $from->toDateString(), $to->toDateString());
        // Compare with the previous period: the whole previous month when a whole month is shown.
        if ($from->day === 1 && $to->isSameDay($from->endOfMonth())) {
            $prevFrom = $from->subMonthNoOverflow();
            $prevTo = $prevFrom->endOfMonth();
        } else {
            $prevTo = $from->subDay();
            $prevFrom = $prevTo->subDays((int) $from->diffInDays($to));
        }

        return response()->json([
            'data' => $list->map->toApiArray(),
            'summary' => [
                'total' => round((float) (clone $approvedInRange)->sum('amount'), 2),
                'gst' => round((float) (clone $approvedInRange)->sum('gst'), 2),
                'previous' => round((float) $this->between((clone $base)->where('status', 'approved'), $prevFrom->toDateString(), $prevTo->toDateString())->sum('amount'), 2),
                'by_category' => (clone $approvedInRange)->selectRaw('category, sum(amount) as total, count(*) as n')->groupBy('category')
                    ->orderByDesc('total')->get()->map(fn ($r) => ['category' => $r->category, 'total' => round((float) $r->total, 2), 'count' => (int) $r->n]),
                'pending' => (clone $base)->where('status', 'pending')->count(),
                'to_reimburse' => round((float) (clone $base)->where('status', 'approved')->where('reimbursable', true)->whereNull('reimbursed_at')->sum('amount'), 2),
            ],
            'categories' => ExpenseCategory::where('active', true)->orderBy('sort')->pluck('name'),
            'can_approve' => $user->hasPermission('expenses.approve'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $this->validated($request);
        $approver = $user->hasPermission('expenses.approve');

        $expense = Expense::create([
            ...collect($data)->except('receipt')->all(),
            'created_by' => $user->id,
            'status' => $approver ? 'approved' : 'pending',
            'approved_by' => $approver ? $user->id : null,
            'approved_at' => $approver ? now() : null,
            ...$this->storeReceipt($request),
        ]);

        if (! $approver) {
            $this->notify->send($this->notify->staffWith('expenses.approve'), 'expense_submitted',
                $user->name.' submitted an expense of Rs. '.number_format($expense->amount, 2),
                $expense->category.($expense->vendor ? ' · '.$expense->vendor : '').' · '.$expense->spent_on->format('d M').($expense->reimbursable ? ' · to reimburse' : ''),
                '/expenses?tab=approvals', true, $user);
        }

        return response()->json($expense->load(['creator:id,name', 'approver:id,name', 'client:id,company_name'])->toApiArray(), 201);
    }

    public function update(Request $request, Expense $expense): JsonResponse
    {
        $user = $request->user();
        $this->authorizeExpense($user, $expense);
        abort_if($expense->source, 422, 'Salary expenses come from payroll. Change the payslips instead.');
        $approver = $user->hasPermission('expenses.approve');
        abort_unless($approver || ($expense->created_by === $user->id && $expense->status !== 'approved'), 403, 'Approved expenses can only be changed by an approver.');

        $data = $this->validated($request);
        $expense->update([
            ...collect($data)->except('receipt')->all(),
            ...($request->hasFile('receipt') ? $this->storeReceipt($request, $expense) : []),
            // A rejected claim that is corrected goes back for approval.
            ...(! $approver && $expense->status === 'rejected' ? ['status' => 'pending', 'review_note' => null] : []),
        ]);

        return response()->json($expense->fresh(['creator:id,name', 'approver:id,name', 'client:id,company_name'])->toApiArray());
    }

    public function destroy(Request $request, Expense $expense): JsonResponse
    {
        $user = $request->user();
        $this->authorizeExpense($user, $expense);
        abort_if($expense->source, 422, 'Salary expenses come from payroll.');
        abort_unless($user->hasPermission('expenses.approve') || ($expense->created_by === $user->id && $expense->status !== 'approved'), 403);
        if ($expense->receipt_path) {
            Storage::disk('local')->delete($expense->receipt_path);
        }
        $expense->delete();

        return response()->json(['deleted' => true]);
    }

    public function decide(Request $request, Expense $expense, string $decision): JsonResponse
    {
        abort_unless($expense->status === 'pending', 422, 'This expense is not waiting for approval.');
        abort_if($expense->created_by === $request->user()->id && ! $request->user()->isAdmin(), 403, 'You cannot approve your own claim.');
        $data = $request->validate(['note' => [$decision === 'reject' ? 'required' : 'nullable', 'string', 'max:500']],
            ['note.required' => 'Please say why it is rejected.']);

        $expense->update([
            'status' => $decision === 'approve' ? 'approved' : 'rejected',
            'approved_by' => $request->user()->id, 'approved_at' => now(), 'review_note' => $data['note'] ?? null,
        ]);
        $ok = $expense->status === 'approved';
        $this->notify->send($expense->creator, 'expense_decided',
            'Your expense of Rs. '.number_format($expense->amount, 2).' was '.($ok ? 'approved' : 'rejected'),
            $expense->category.' · '.$expense->spent_on->format('d M').($expense->review_note ? '. Note: '.$expense->review_note : '')
                .($ok && $expense->reimbursable ? '. It will be reimbursed with the next payment run.' : ''),
            '/expenses', true, $request->user());

        return response()->json($expense->fresh(['creator:id,name', 'approver:id,name', 'client:id,company_name'])->toApiArray());
    }

    public function reimburse(Request $request, Expense $expense): JsonResponse
    {
        abort_unless($expense->status === 'approved' && $expense->reimbursable && ! $expense->reimbursed_at, 422, 'Nothing to reimburse on this expense.');
        $expense->update(['reimbursed_at' => now()]);

        return response()->json($expense->fresh(['creator:id,name', 'approver:id,name', 'client:id,company_name'])->toApiArray());
    }

    public function receipt(Request $request, Expense $expense): StreamedResponse
    {
        $this->authorizeExpense($request->user(), $expense);
        abort_unless($expense->receipt_path, 404);

        return Storage::disk('local')->download($expense->receipt_path, $expense->receipt_name, ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** CSV for the accountant: approved expenses in the chosen period. */
    public function export(Request $request): StreamedResponse
    {
        $f = $this->filters($request);
        $rows = $this->filtered(Expense::visibleTo($request->user()), $f)->with(['creator:id,name', 'client:id,company_name'])->orderBy('spent_on')->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Date', 'Category', 'Vendor', 'Amount (incl. GST)', 'GST', 'Payment mode', 'Client', 'Notes', 'Status', 'Added by', 'Reimbursable', 'Reimbursed on', 'Receipt'], escape: '\\');
            foreach ($rows as $e) {
                fputcsv($out, [$e->spent_on->format('d/m/Y'), $e->category, $e->vendor, $e->amount, $e->gst, $e->payment_mode, $e->client?->company_name, $e->notes,
                    $e->status, $e->creator?->name, $e->reimbursable ? 'Yes' : 'No', $e->reimbursed_at?->format('d/m/Y'), $e->receipt_name ? 'Yes' : 'No'], escape: '\\');
            }
            fclose($out);
        }, 'digifox-expenses-'.$f['from'].'-to-'.$f['to'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function categories(): JsonResponse
    {
        return response()->json(['data' => ExpenseCategory::orderBy('sort')->get(['id', 'name', 'active'])]);
    }

    public function saveCategories(Request $request): JsonResponse
    {
        $data = $request->validate(['names' => ['required', 'array', 'min:1', 'max:40'], 'names.*' => ['required', 'string', 'max:60', 'distinct']]);
        $keep = array_values($data['names']);
        foreach ($keep as $i => $name) {
            ExpenseCategory::updateOrCreate(['name' => $name], ['active' => true, 'sort' => $i]);
        }
        // Hide removed categories (old expenses keep their category name).
        ExpenseCategory::whereNotIn('name', [...$keep, 'Salaries'])->update(['active' => false]);
        ExpenseCategory::updateOrCreate(['name' => 'Salaries'], ['active' => true]);

        return $this->categories();
    }

    private function storeReceipt(Request $request, ?Expense $old = null): array
    {
        $file = $request->file('receipt');
        if (! $file) {
            return [];
        }
        if ($old?->receipt_path) {
            Storage::disk('local')->delete($old->receipt_path);
        }

        return ['receipt_name' => mb_substr($file->getClientOriginalName(), 0, 250), 'receipt_path' => $file->store('receipts/'.now()->format('Y-m'), 'local')];
    }

    private function authorizeExpense(User $user, Expense $expense): void
    {
        abort_unless(Expense::visibleTo($user)->whereKey($expense->id)->exists(), 404);
    }

    /** Default period: this month. */
    private function filters(Request $request): array
    {
        $f = $request->validate([
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'category' => ['nullable', 'string', 'max:60'],
            'status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'to_reimburse'])],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $f['from'] ??= today()->startOfMonth()->toDateString();
        $f['to'] ??= today()->endOfMonth()->toDateString();

        return $f;
    }

    private function filtered(Builder $q, array $f): Builder
    {
        $pendingOrReimburse = in_array($f['status'] ?? null, ['pending', 'to_reimburse'], true);

        return $q
            // Pending claims and unpaid reimbursements show whatever their date.
            ->when(! $pendingOrReimburse, fn ($q) => $this->between($q, $f['from'], $f['to']))
            ->when($f['category'] ?? null, fn ($q, $c) => $q->where('category', $c))
            ->when(($f['status'] ?? null) === 'to_reimburse', fn ($q) => $q->where('status', 'approved')->where('reimbursable', true)->whereNull('reimbursed_at'))
            ->when(in_array($f['status'] ?? null, ['pending', 'approved', 'rejected'], true), fn ($q) => $q->where('status', $f['status']))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('vendor', 'like', "%$s%")->orWhere('notes', 'like', "%$s%")));
    }

    /** Inclusive date range that also works when dates are stored with a time part. */
    private function between(Builder $q, string $from, string $to): Builder
    {
        return $q->whereDate('spent_on', '>=', $from)->whereDate('spent_on', '<=', $to);
    }

    private function validated(Request $request): array
    {
        $data = $this->rules($request);
        $data['gst'] = $data['gst'] ?? 0;
        $data['reimbursable'] = $request->boolean('reimbursable');

        return $data;
    }

    private function rules(Request $request): array
    {
        return $request->validate([
            'spent_on' => ['required', 'date', 'before_or_equal:today'],
            'category' => ['required', 'string', 'max:60', Rule::exists('expense_categories', 'name')->where('active', true)],
            'amount' => ['required', 'numeric', 'min:1', 'max:100000000'],
            'gst' => ['nullable', 'numeric', 'min:0', 'lte:amount'],
            'payment_mode' => ['nullable', Rule::in(['upi', 'bank_transfer', 'card', 'cash', 'cheque'])],
            'vendor' => ['nullable', 'string', 'max:160'],
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')],
            'notes' => ['nullable', 'string', 'max:1000'],
            'reimbursable' => ['sometimes', 'boolean'],
            'receipt' => ['nullable', 'file', 'max:10240', 'mimes:pdf,png,jpg,jpeg,webp,heic'],
        ], [
            'category.exists' => 'Choose a category from the list.',
            'spent_on.before_or_equal' => 'The date cannot be in the future.',
            'gst.lte' => 'GST cannot be more than the total amount.',
            'receipt.mimes' => 'Upload a photo (JPG, PNG, HEIC) or PDF of the receipt.',
        ]);
    }
}
