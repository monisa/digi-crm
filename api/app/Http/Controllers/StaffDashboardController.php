<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Expense;
use App\Models\Holiday;
use App\Models\Lead;
use App\Models\LeaveRequest;
use App\Models\Ticket;
use App\Services\LeaveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** A team member's own home page: their day at a glance. Only shows what they have access to. */
class StaffDashboardController extends Controller
{
    public function __invoke(Request $request, LeaveService $leave): JsonResponse
    {
        $user = $request->user();
        abort_if($user->isClient(), 404);
        $employee = $user->employee;
        $data = ['name' => $user->name];

        if ($user->hasPermission('leads.view')) {
            $mine = Lead::where('assigned_to', $user->id);
            $sla = now()->subMinutes(config('crm.lead_sla_minutes'));
            $data['leads'] = [
                'open' => (clone $mine)->whereNotIn('status', ['won', 'lost'])->count(),
                'uncontacted' => (clone $mine)->where('status', 'new')->whereNull('first_contacted_at')->where('created_at', '<=', $sla)->count(),
                'won_this_month' => (clone $mine)->where('status', 'won')->where('updated_at', '>=', today()->startOfMonth())->count(),
                'followups' => (clone $mine)->whereNotNull('next_followup_at')->where('next_followup_at', '<=', now()->endOfDay())
                    ->whereNotIn('status', ['won', 'lost'])->orderBy('next_followup_at')->limit(6)->get()
                    ->map(fn (Lead $l) => ['id' => $l->id, 'name' => $l->full_name, 'phone' => $l->phone, 'at' => $l->next_followup_at->toIso8601String(), 'overdue' => $l->next_followup_at->isPast()]),
                'new' => (clone $mine)->where('status', 'new')->whereNull('first_contacted_at')->latest()->limit(4)->get()
                    ->map(fn (Lead $l) => ['id' => $l->id, 'name' => $l->full_name, 'phone' => $l->phone, 'ad' => $l->ad_name, 'at' => $l->created_at->toIso8601String()]),
            ];
        }

        if ($user->hasPermission('tickets.view')) {
            $tickets = Ticket::visibleTo($user)->whereIn('status', Ticket::OPEN)->where(fn ($q) => $q->where('assigned_to', $user->id)->orWhereNull('assigned_to'));
            $data['tickets'] = [
                'open' => (clone $tickets)->count(),
                'needs_reply' => (clone $tickets)->where('awaiting_staff', true)->count(),
                'items' => (clone $tickets)->with('client:id,company_name')->orderByDesc('awaiting_staff')->latest('updated_at')->limit(4)->get()->map(fn (Ticket $t) => $t->toApiArray($user)),
            ];
        }

        if ($employee) {
            $balances = collect($leave->balances($employee, now()->year))->filter(fn ($b) => $b['allotted'] !== null)->values();
            $next = LeaveRequest::with('leaveType')->where('employee_id', $employee->id)->whereIn('status', ['approved', 'pending'])
                ->whereDate('to_date', '>=', today())->orderBy('from_date')->first();
            $slip = $employee->payslips()->where('status', 'published')->first();
            $data['me'] = [
                'employee_id' => $employee->id,
                'designation' => $employee->designation,
                'leave' => $balances,
                'next_leave' => $next ? ['from' => $next->from_date->toDateString(), 'to' => $next->to_date->toDateString(), 'type' => $next->leaveType->name, 'status' => $next->status] : null,
                'payslip' => $slip ? ['id' => $slip->id, 'label' => $slip->periodLabel(), 'net' => $slip->net, 'employee' => ['emp_code' => $employee->emp_code], 'year' => $slip->year, 'month' => $slip->month] : null,
            ];
        }

        if ($user->hasPermission('expenses.view')) {
            $data['expenses'] = [
                'pending' => Expense::where('created_by', $user->id)->where('status', 'pending')->count(),
                'to_reimburse' => round((float) Expense::where('created_by', $user->id)->where('status', 'approved')->where('reimbursable', true)->whereNull('reimbursed_at')->sum('amount'), 2),
            ];
        }

        $data['holidays'] = Holiday::whereDate('date', '>=', today())->where('optional', false)->orderBy('date')->limit(3)->get()->map->toApiArray();
        // Team birthdays in the next 30 days (day and month only, never the year).
        $data['birthdays'] = Employee::where('status', 'active')->whereNotNull('dob')->get()
            ->map(function (Employee $e) {
                $next = $e->dob->copy()->year(now()->year);
                if ($next->lt(today())) {
                    $next->addYear();
                }

                return ['name' => $e->name, 'date' => $next->toDateString(), 'today' => $next->isToday()];
            })
            ->filter(fn ($b) => $b['date'] <= today()->addDays(30)->toDateString())->sortBy('date')->take(5)->values();

        return response()->json($data);
    }
}
