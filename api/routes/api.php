<?php

use App\Http\Controllers\Admin\PermissionController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\Hr\EmployeeController;
use App\Http\Controllers\Hr\HolidayController;
use App\Http\Controllers\Hr\LeaveController;
use App\Http\Controllers\Hr\PayslipController;
use App\Http\Controllers\Hr\SalaryController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PasswordResetController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'app' => config('app.name'),
    'time' => now()->toIso8601String(),
]));

// Public auth endpoints (rate-limited per IP on top of the per-account login lockout).
Route::prefix('auth')->middleware('throttle:20,1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/config', [GoogleAuthController::class, 'config']);
    Route::post('/google', [GoogleAuthController::class, 'login']);
    Route::post('/forgot-password', [PasswordResetController::class, 'forgot']);
    Route::post('/reset-password', [PasswordResetController::class, 'reset']);
});

// Signed-in, any status (so pending users can see who they are, onboard and log out).
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/onboarding', [OnboardingController::class, 'store']);
});

// Signed-in and active.
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/dashboard/overview', [DashboardController::class, 'overview'])->middleware('permission:overview.view');

    // Employees: everyone with employees.view sees themselves; employees.edit (or admin) sees everyone.
    Route::get('/employees', [EmployeeController::class, 'index'])->middleware('permission:employees.view');
    Route::get('/employees/me', [EmployeeController::class, 'me']);
    Route::post('/employees', [EmployeeController::class, 'store'])->middleware('permission:employees.create');
    Route::get('/employees/{employee}', [EmployeeController::class, 'show'])->middleware('permission:employees.view');
    Route::put('/employees/{employee}', [EmployeeController::class, 'update'])->middleware('permission:employees.edit');
    Route::get('/employees/{employee}/salary', [SalaryController::class, 'show'])->middleware('permission:employees.view');
    Route::post('/employees/{employee}/salary', [SalaryController::class, 'store'])->middleware('permission:employees.edit');
    Route::get('/employees/{employee}/leave', [LeaveController::class, 'forEmployee'])->middleware('permission:employees.view');
    Route::get('/employees/{employee}/payslips', [PayslipController::class, 'forEmployee'])->middleware('permission:employees.view');

    Route::get('/payslips', [PayslipController::class, 'month'])->middleware('permission:employees.edit');
    Route::post('/payslips/generate', [PayslipController::class, 'generate'])->middleware('permission:employees.edit');
    Route::post('/payslips/publish', [PayslipController::class, 'publish'])->middleware('permission:employees.edit');
    Route::get('/payslips/{payslip}/pdf', [PayslipController::class, 'download'])->middleware('permission:employees.view');

    // Leave
    Route::get('/leave/summary', [LeaveController::class, 'summary'])->middleware('permission:leave.view');
    Route::post('/leave/preview', [LeaveController::class, 'preview'])->middleware('permission:leave.view');
    Route::post('/leave/requests', [LeaveController::class, 'store'])->middleware('permission:leave.create');
    Route::post('/leave/requests/{leaveRequest}/cancel', [LeaveController::class, 'cancel'])->middleware('permission:leave.view');
    Route::get('/leave/approvals', [LeaveController::class, 'approvals'])->middleware('permission:leave.approve');
    Route::post('/leave/requests/{leaveRequest}/{decision}', [LeaveController::class, 'decide'])
        ->whereIn('decision', ['approve', 'reject'])->middleware('permission:leave.approve');
    Route::get('/leave/types', [LeaveController::class, 'types'])->middleware('permission:leave.view');
    Route::put('/leave/types/{leaveType}', [LeaveController::class, 'updateType'])->middleware('permission:leave.edit');

    // Public holidays: everyone can see them; leave.edit (admin) manages them.
    Route::get('/holidays', [HolidayController::class, 'index']);
    Route::post('/holidays', [HolidayController::class, 'store'])->middleware('permission:leave.edit');
    Route::post('/holidays/fixed', [HolidayController::class, 'addFixed'])->middleware('permission:leave.edit');
    Route::put('/holidays/{holiday}', [HolidayController::class, 'update'])->middleware('permission:leave.edit');
    Route::delete('/holidays/{holiday}', [HolidayController::class, 'destroy'])->middleware('permission:leave.edit');

    Route::prefix('admin')->group(function () {
        Route::get('/permissions', [PermissionController::class, 'index'])->middleware('permission:settings.view');
        Route::put('/roles/{role}/permissions', [PermissionController::class, 'updateRole'])->middleware('permission:settings.edit');

        Route::get('/users', [UserController::class, 'index'])->middleware('permission:settings.view');
        Route::patch('/users/{user}', [UserController::class, 'update'])->middleware('permission:settings.edit');
        Route::get('/users/{user}/permissions', [UserController::class, 'permissions'])->middleware('permission:settings.view');
        Route::put('/users/{user}/permissions', [UserController::class, 'updatePermissions'])->middleware('permission:settings.edit');
    });
});
