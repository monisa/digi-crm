<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\SalaryStructure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SalaryController extends Controller
{
    use HrAccess;

    public function show(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeEmployee($request->user(), $employee);

        // A salary set to start later still shows (marked as upcoming).
        $current = $employee->salaryOn()?->toApiArray();
        if (! $current && ($next = $employee->salaryStructures->last())) {
            $current = $next->toApiArray() + ['upcoming' => true];
        }

        return response()->json([
            'current' => $current,
            'history' => $employee->salaryStructures->map->toApiArray(),
            'components' => ['earnings' => SalaryStructure::EARNINGS, 'deductions' => SalaryStructure::DEDUCTIONS],
        ]);
    }

    public function store(Request $request, Employee $employee): JsonResponse
    {
        $money = ['nullable', 'numeric', 'min:0', 'max:9999999'];
        $data = $request->validate([
            'effective_from' => ['required', 'date', Rule::unique('salary_structures')->where('employee_id', $employee->id)],
            'basic' => ['required', 'numeric', 'min:1', 'max:9999999'],
            ...array_fill_keys(['hra', 'special_allowance', 'other_allowance', 'pf', 'esi', 'professional_tax', 'tds', 'other_deduction'], $money),
            'bank_account_last4' => ['nullable', 'digits:4'],
        ], ['effective_from.unique' => 'A salary already starts on this date. Pick another date.']);

        $structure = $employee->salaryStructures()->create(array_map(fn ($v) => $v ?? 0, $data) + ['bank_account_last4' => $data['bank_account_last4'] ?? null]);

        return response()->json($structure->toApiArray(), 201);
    }

    /** Move a salary's start date, e.g. so it also covers earlier months' payroll. */
    public function move(Request $request, Employee $employee, SalaryStructure $structure): JsonResponse
    {
        $this->authorizeEmployee($request->user(), $employee);
        abort_unless($structure->employee_id === $employee->id, 404);
        $data = $request->validate([
            'effective_from' => ['required', 'date', Rule::unique('salary_structures')->where('employee_id', $employee->id)->ignore($structure->id)],
        ], ['effective_from.unique' => 'A salary already starts on this date.']);
        $structure->update($data);

        return response()->json($structure->fresh()->toApiArray());
    }
}
