<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Payslip;
use App\Services\PayslipGenerator;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PayslipController extends Controller
{
    use HrAccess;

    /** One employee's payslips. Employees only see published ones. */
    public function forEmployee(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeEmployee($request->user(), $employee);
        $manager = $this->managesEmployees($request->user());

        $slips = $employee->payslips()->with('employee')
            ->when(! $manager, fn ($q) => $q->where('status', 'published'))->get();

        return response()->json(['data' => $slips->map->toApiArray()]);
    }

    /** All payslips for one month (payroll view). */
    public function month(Request $request): JsonResponse
    {
        ['year' => $year, 'month' => $month] = $this->period($request);
        $slips = Payslip::with('employee')->where(compact('year', 'month'))->get()->sortBy('employee.emp_code')->values();

        return response()->json([
            'data' => $slips->map->toApiArray(),
            'totals' => ['gross' => round($slips->sum('gross'), 2), 'net' => round($slips->sum('net'), 2), 'count' => $slips->count()],
        ]);
    }

    public function generate(Request $request, PayslipGenerator $generator): JsonResponse
    {
        ['year' => $year, 'month' => $month] = $this->period($request);
        abort_if(now()->startOfMonth()->lt(now()->setDate($year, $month, 1)->startOfMonth()), 422, 'You cannot generate payslips for a future month.');

        return response()->json($generator->generate($year, $month, $request->user()));
    }

    public function publish(Request $request): JsonResponse
    {
        ['year' => $year, 'month' => $month] = $this->period($request);
        $count = Payslip::where(compact('year', 'month'))->where('status', 'draft')
            ->update(['status' => 'published', 'published_at' => now()]);

        return response()->json(['published' => $count]);
    }

    public function download(Request $request, Payslip $payslip): Response
    {
        $payslip->load('employee');
        $this->authorizeEmployee($request->user(), $payslip->employee);
        abort_if($payslip->status !== 'published' && ! $this->managesEmployees($request->user()), 404);

        $salary = $payslip->employee->salaryOn(now()->setDate($payslip->year, $payslip->month, 1)->endOfMonth());
        $logo = public_path('payslip-logo.png');

        $pdf = Pdf::loadView('pdf.payslip', [
            'p' => $payslip,
            'e' => $payslip->employee,
            'company' => config('crm.company'),
            'accountLast4' => $salary?->bank_account_last4,
            'logo' => is_file($logo) ? 'data:image/png;base64,'.base64_encode(file_get_contents($logo)) : null,
        ])->setPaper('a4');

        $name = sprintf('Payslip-%s-%04d-%02d.pdf', $payslip->employee->emp_code, $payslip->year, $payslip->month);

        return $pdf->download($name);
    }

    /** @return array{year: int, month: int} */
    private function period(Request $request): array
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        return ['year' => (int) $data['year'], 'month' => (int) $data['month']];
    }
}
