<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\LeaveRequest;
use App\Models\Payment;
use App\Models\Ticket;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Company-wide figures for the admin dashboard.
 * Periods follow the Indian financial year (April–March).
 * Revenue = payments received; expenses = approved expenses (incl. GST).
 */
class DashboardOverview
{
    public const PERIODS = ['month', 'quarter', 'year'];

    private const OPEN_TICKET = ['open', 'in_progress', 'waiting'];

    public function build(string $period, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        [$from, $to, $label] = $this->range($period, $now);
        [$prevFrom, $prevTo] = $this->range($period, $from->subDay());

        // Compare like with like: if we are 10 days into this period, compare with
        // the first 10 days of the previous one.
        $elapsedDays = (int) $from->diffInDays(min($now->startOfDay(), $to));
        $prevTo = min($prevTo, $prevFrom->addDays($elapsedDays));

        $revenue = $this->revenue($from, $to);
        $prevRevenue = $this->revenue($prevFrom, $prevTo);
        $expenses = $this->expenses($from, $to);
        $prevExpenses = $this->expenses($prevFrom, $prevTo);

        return [
            'period' => [
                'key' => $period, 'label' => $label, 'from' => $from->toDateString(), 'to' => $to->toDateString(),
                'compare_from' => $prevFrom->toDateString(), 'compare_to' => $prevTo->toDateString(),
            ],
            'kpis' => [
                'clients' => [
                    'total' => Client::where('status', 'active')->count(),
                    'new' => Client::whereBetween('created_at', [$from, $to->endOfDay()])->count(),
                    'prev_new' => Client::whereBetween('created_at', [$prevFrom, $prevTo->endOfDay()])->count(),
                ],
                'employees' => [
                    'total' => Employee::where('status', 'active')->count(),
                    'on_leave_today' => LeaveRequest::where('status', 'approved')
                        ->whereDate('from_date', '<=', $now)->whereDate('to_date', '>=', $now)
                        ->distinct()->count('employee_id'),
                ],
                'revenue' => ['amount' => $revenue, 'prev' => $prevRevenue],
                'expenses' => ['amount' => $expenses, 'prev' => $prevExpenses],
                'net' => round($revenue - $expenses, 2),
                'outstanding' => [
                    'amount' => round((float) Invoice::whereIn('status', ['unpaid', 'partial'])->sum(DB::raw('total - amount_paid')), 2),
                    'overdue' => Invoice::whereIn('status', ['unpaid', 'partial'])->whereDate('due_date', '<', $now)->count(),
                ],
            ],
            'trend' => $this->trend($now),
            'funnel' => $this->funnel($from, $to),
            'uncontacted' => $this->uncontacted($now),
            'tickets' => $this->tickets(),
            'leave' => $this->leave(),
        ];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string} */
    private function range(string $period, CarbonImmutable $day): array
    {
        $fyStartYear = $day->month >= 4 ? $day->year : $day->year - 1;
        $fyLabel = sprintf('FY %d-%02d', $fyStartYear, ($fyStartYear + 1) % 100);

        return match ($period) {
            'year' => [
                CarbonImmutable::create($fyStartYear, 4, 1)->startOfDay(),
                CarbonImmutable::create($fyStartYear + 1, 3, 31)->startOfDay(),
                $fyLabel,
            ],
            'quarter' => (function () use ($day, $fyStartYear, $fyLabel) {
                $q = intdiv(($day->month + 8) % 12, 3); // Apr–Jun = 0 … Jan–Mar = 3
                $start = CarbonImmutable::create($fyStartYear, 4, 1)->addMonths($q * 3)->startOfDay();

                return [$start, $start->addMonths(3)->subDay(), 'Q'.($q + 1).' '.$fyLabel];
            })(),
            default => [$day->startOfMonth()->startOfDay(), $day->endOfMonth()->startOfDay(), $day->format('F Y')],
        };
    }

    private function revenue(CarbonImmutable $from, CarbonImmutable $to): float
    {
        return round((float) Payment::whereBetween('paid_on', [$from->toDateString(), $to->toDateString()])->sum('amount'), 2);
    }

    private function expenses(CarbonImmutable $from, CarbonImmutable $to): float
    {
        return round((float) Expense::where('status', 'approved')
            ->whereBetween('spent_on', [$from->toDateString(), $to->toDateString()])->sum('amount'), 2);
    }

    /** Last 12 calendar months, oldest first. */
    private function trend(CarbonImmutable $now): array
    {
        $start = $now->startOfMonth()->subMonths(11);
        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $m = $start->addMonths($i);
            $months[$m->format('Y-m')] = ['month' => $m->format('Y-m'), 'label' => $m->format('M'), 'revenue' => 0.0, 'expenses' => 0.0];
        }

        Payment::where('paid_on', '>=', $start->toDateString())->get(['paid_on', 'amount'])
            ->each(function (Payment $p) use (&$months) {
                $key = $p->paid_on->format('Y-m');
                if (isset($months[$key])) {
                    $months[$key]['revenue'] += $p->amount;
                }
            });
        Expense::where('status', 'approved')->where('spent_on', '>=', $start->toDateString())->get(['spent_on', 'amount'])
            ->each(function (Expense $e) use (&$months) {
                $key = $e->spent_on->format('Y-m');
                if (isset($months[$key])) {
                    $months[$key]['expenses'] += $e->amount;
                }
            });

        return array_values(array_map(fn ($m) => [...$m, 'revenue' => round($m['revenue'], 2), 'expenses' => round($m['expenses'], 2)], $months));
    }

    /** Leads created in the period, by how far they have progressed. */
    private function funnel(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $counts = Lead::whereBetween('created_at', [$from, $to->endOfDay()])
            ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $at = fn (array $statuses) => (int) collect($statuses)->sum(fn ($s) => $counts[$s] ?? 0);

        return [
            'stages' => [
                ['key' => 'new', 'label' => 'New leads', 'count' => (int) $counts->sum()],
                ['key' => 'contacted', 'label' => 'Contacted', 'count' => $at(['contacted', 'qualified', 'proposal', 'won', 'lost'])],
                ['key' => 'qualified', 'label' => 'Qualified', 'count' => $at(['qualified', 'proposal', 'won'])],
                ['key' => 'proposal', 'label' => 'Proposal sent', 'count' => $at(['proposal', 'won'])],
                ['key' => 'won', 'label' => 'Won', 'count' => $at(['won'])],
            ],
            'lost' => $at(['lost']),
        ];
    }

    private function uncontacted(CarbonImmutable $now): array
    {
        $sla = config('crm.lead_sla_minutes');
        $query = Lead::where('status', 'new')->whereNull('first_contacted_at')
            ->where('created_at', '<=', $now->subMinutes($sla));

        return [
            'count' => (clone $query)->count(),
            'sla_minutes' => $sla,
            'items' => $query->with('assignee:id,name')->orderBy('created_at')->limit(5)->get()
                ->map(fn (Lead $l) => [
                    'id' => $l->id,
                    'name' => $l->full_name,
                    'campaign' => $l->campaign_name,
                    'platform' => $l->platform,
                    'assignee' => $l->assignee?->name,
                    'waiting_minutes' => (int) $l->created_at->diffInMinutes($now),
                ]),
        ];
    }

    private function tickets(): array
    {
        $open = Ticket::whereIn('status', self::OPEN_TICKET);

        return [
            'open' => (clone $open)->count(),
            'high' => (clone $open)->where('priority', 'high')->count(),
            'items' => $open->with('client:id,company_name')
                ->orderByRaw("case priority when 'high' then 0 when 'medium' then 1 else 2 end")
                ->latest()->limit(4)->get()
                ->map(fn (Ticket $t) => [
                    'id' => $t->id,
                    'subject' => $t->subject,
                    'client' => $t->client?->company_name,
                    'priority' => $t->priority,
                    'status' => $t->status,
                ]),
        ];
    }

    private function leave(): array
    {
        $pending = LeaveRequest::where('status', 'pending');

        return [
            'pending' => (clone $pending)->count(),
            'items' => $pending->with(['employee:id,name', 'leaveType:id,code,name'])->orderBy('from_date')->limit(4)->get()
                ->map(fn (LeaveRequest $r) => [
                    'id' => $r->id,
                    'employee' => $r->employee?->name,
                    'type' => $r->leaveType?->name,
                    'from' => $r->from_date->toDateString(),
                    'to' => $r->to_date->toDateString(),
                    'days' => $r->days,
                ]),
        ];
    }
}
