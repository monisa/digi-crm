<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\EmployeeInvite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    use HrAccess;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $this->managesEmployees($user)) {
            $own = $user->employee;

            return response()->json(['data' => $own ? [$own->load('manager')->toApiArray()] : [], 'scope' => 'own']);
        }

        $request->validate(['status' => ['nullable', Rule::in(['active', 'inactive'])], 'search' => ['nullable', 'string', 'max:100']]);

        $list = Employee::with('manager')
            ->when($request->status ?? 'active', fn ($q, $s) => $q->where('status', $s))
            ->when($request->search, fn ($q, $s) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%$s%")->orWhere('emp_code', 'like', "%$s%")
                ->orWhere('designation', 'like', "%$s%")->orWhere('office_email', 'like', "%$s%")))
            ->orderBy('name')->get();

        return response()->json(['data' => $list->map->toApiArray(), 'scope' => 'all']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->ownEmployee($request->user())->load('manager')->toApiArray());
    }

    public function show(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeEmployee($request->user(), $employee);

        return response()->json($employee->load('manager')->toApiArray());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['emp_code'] ??= $this->nextCode();

        $employee = DB::transaction(function () use ($data, $request) {
            $employee = Employee::create(collect($data)->except('create_login')->all());

            if ($request->boolean('create_login')) {
                $user = User::create([
                    'name' => $employee->name,
                    'email' => Str::lower($employee->office_email),
                    'phone' => $employee->mobile,
                    'password' => Str::password(32),
                    'role' => User::ROLE_EMPLOYEE,
                    'status' => User::STATUS_ACTIVE,
                ]);
                $employee->update(['user_id' => $user->id]);
                $user->notify(new EmployeeInvite(Password::createToken($user)));
            }

            return $employee;
        });

        return response()->json($employee->load('manager')->toApiArray(), 201);
    }

    public function update(Request $request, Employee $employee): JsonResponse
    {
        $data = $this->validated($request, $employee);
        $employee->update(collect($data)->except('create_login')->all());

        // Keep the login's name in step, and switch the login off when someone leaves.
        if ($employee->user) {
            $employee->user->forceFill([
                'name' => $employee->name,
                'status' => $employee->status === 'inactive' ? User::STATUS_INACTIVE : $employee->user->status,
            ])->save();
            if ($employee->status === 'inactive') {
                $employee->user->tokens()->delete();
            }
        }

        return response()->json($employee->fresh('manager')->toApiArray());
    }

    private function validated(Request $request, ?Employee $employee = null): array
    {
        $loginEmailRule = $request->boolean('create_login') ? ['required', Rule::unique('users', 'email')] : [];

        return $request->validate([
            'emp_code' => ['nullable', 'string', 'max:30', Rule::unique('employees', 'emp_code')->ignore($employee)],
            'name' => ['required', 'string', 'max:120'],
            'office_email' => ['nullable', 'email', 'max:190', ...$loginEmailRule],
            'mobile' => ['nullable', 'string', 'regex:/^\+?[0-9 ]{8,16}$/'],
            'designation' => ['nullable', 'string', 'max:120'],
            'department' => ['nullable', 'string', 'max:120'],
            'dob' => ['nullable', 'date', 'before:-15 years'],
            'doj' => ['nullable', 'date'],
            'address' => ['nullable', 'string', 'max:500'],
            'reporting_manager_id' => ['nullable', Rule::exists('employees', 'id'), Rule::notIn([$employee?->id])],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'create_login' => ['sometimes', 'boolean'],
        ], ['dob.before' => 'Please check the date of birth.', 'office_email.required' => 'An office email is needed to create a login.']);
    }

    private function nextCode(): string
    {
        $last = Employee::where('emp_code', 'like', 'DFP%')->orderByDesc('emp_code')->value('emp_code');
        $n = $last ? (int) substr($last, 3) + 1 : 1;

        return sprintf('DFP%03d', $n);
    }
}
