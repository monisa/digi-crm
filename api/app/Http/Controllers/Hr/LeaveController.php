<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\LeaveService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LeaveController extends Controller
{
    use HrAccess;

    public function __construct(private LeaveService $leave) {}

    /** The signed-in employee's balances, requests and upcoming holidays. */
    public function summary(Request $request): JsonResponse
    {
        $year = (int) ($request->validate(['year' => ['nullable', 'integer', 'min:2020', 'max:2100']])['year'] ?? now()->year);
        $employee = $this->ownEmployee($request->user());

        return response()->json([
            'year' => $year,
            'balances' => $this->leave->balances($employee, $year),
            'requests' => $employee->leaveRequests()->with(['leaveType', 'approver', 'employee'])
                ->whereYear('from_date', $year)->orderByDesc('from_date')->get()->map->toApiArray(),
            'upcoming_holidays' => Holiday::whereDate('date', '>=', today())->orderBy('date')->limit(5)->get()->map->toApiArray(),
        ]);
    }

    /** One employee's balances and requests (their own, or anyone for HR managers). */
    public function forEmployee(Request $request, \App\Models\Employee $employee): JsonResponse
    {
        $this->authorizeEmployee($request->user(), $employee);
        $year = (int) ($request->validate(['year' => ['nullable', 'integer', 'min:2020', 'max:2100']])['year'] ?? now()->year);

        return response()->json([
            'year' => $year,
            'balances' => $this->leave->balances($employee, $year),
            'requests' => $employee->leaveRequests()->with(['leaveType', 'approver', 'employee'])
                ->whereYear('from_date', $year)->orderByDesc('from_date')->get()->map->toApiArray(),
        ]);
    }

    /** Preview how many leave days a date range will use. */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'half_day' => ['sometimes', 'boolean'],
        ]);

        return response()->json(['days' => $this->leave->workingDays(
            CarbonImmutable::parse($data['from_date']), CarbonImmutable::parse($data['to_date']), (bool) ($data['half_day'] ?? false),
        )]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'leave_type_id' => ['required', Rule::exists('leave_types', 'id')],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'half_day' => ['sometimes', 'boolean'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $request_ = $this->leave->request($this->ownEmployee($request->user()), $data);
        $notify = app(\App\Services\Notify::class);
        $request_->load('leaveType', 'employee');
        $notify->send($notify->staffWith('leave.approve'), 'leave_requested',
            $request_->employee->name.' requested '.$request_->days.' day(s) of '.strtolower($request_->leaveType->name),
            $request_->from_date->format('d M').($request_->from_date->ne($request_->to_date) ? ' – '.$request_->to_date->format('d M') : '').'. Reason: '.($request_->reason ?: '—'),
            '/leave', true, $request->user());

        return response()->json($request_->load(['leaveType', 'employee'])->toApiArray(), 201);
    }

    public function cancel(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        abort_unless($leaveRequest->employee?->user_id === $request->user()->id, 403);
        if ($leaveRequest->status !== 'pending') {
            throw ValidationException::withMessages(['status' => 'Only pending requests can be cancelled. Ask the admin to change approved leave.']);
        }
        $leaveRequest->update(['status' => 'cancelled']);

        return response()->json($leaveRequest->load(['leaveType', 'employee'])->toApiArray());
    }

    /** Requests for approvers. */
    public function approvals(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'cancelled', 'all'])]]);
        $status = $data['status'] ?? 'pending';

        $list = LeaveRequest::with(['employee', 'leaveType', 'approver'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderBy($status === 'pending' ? 'from_date' : 'updated_at', $status === 'pending' ? 'asc' : 'desc')
            ->limit(200)->get();

        return response()->json(['data' => $list->map->toApiArray()]);
    }

    public function decide(Request $request, LeaveRequest $leaveRequest, string $decision): JsonResponse
    {
        abort_unless(in_array($decision, ['approve', 'reject'], true), 404);
        $data = $request->validate(['comment' => [$decision === 'reject' ? 'required' : 'nullable', 'string', 'max:500']],
            ['comment.required' => 'Please give a reason for rejecting.']);

        if ($leaveRequest->status !== 'pending') {
            throw ValidationException::withMessages(['status' => 'This request has already been '.$leaveRequest->status.'.']);
        }
        abort_if($leaveRequest->employee?->user_id === $request->user()->id && ! $request->user()->isAdmin(), 403, 'You cannot approve your own leave.');

        $leaveRequest->update([
            'status' => $decision === 'approve' ? 'approved' : 'rejected',
            'approver_id' => $request->user()->id,
            'approver_comment' => $data['comment'] ?? null,
            'actioned_at' => now(),
        ]);

        $leaveRequest->load(['employee.user', 'leaveType', 'approver']);
        $ok = $leaveRequest->status === 'approved';
        app(\App\Services\Notify::class)->send($leaveRequest->employee?->user, 'leave_decided',
            'Your leave was '.($ok ? 'approved' : 'not approved'),
            $leaveRequest->leaveType->name.', '.$leaveRequest->from_date->format('d M').($leaveRequest->from_date->ne($leaveRequest->to_date) ? ' – '.$leaveRequest->to_date->format('d M') : '')
                .($leaveRequest->approver_comment ? '. Note: '.$leaveRequest->approver_comment : '.'),
            '/leave', true, $request->user());

        return response()->json($leaveRequest->toApiArray());
    }

    public function types(): JsonResponse
    {
        return response()->json(['data' => LeaveType::orderBy('id')->get(['id', 'code', 'name', 'yearly_quota', 'paid', 'active'])]);
    }

    public function updateType(Request $request, LeaveType $leaveType): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:60'],
            'yearly_quota' => ['sometimes', 'numeric', 'min:0', 'max:365'],
            'active' => ['sometimes', 'boolean'],
        ]);
        if (! $leaveType->paid) {
            unset($data['yearly_quota']); // loss of pay has no quota
        }
        $leaveType->update($data);

        return response()->json($leaveType->only(['id', 'code', 'name', 'yearly_quota', 'paid', 'active']));
    }
}
