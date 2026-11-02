<?php

use App\Http\Controllers\Admin\PermissionController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Billing\ClientAssetsController;
use App\Http\Controllers\Billing\ClientController;
use App\Http\Controllers\Billing\CompanyController;
use App\Http\Controllers\Billing\InvoiceController;
use App\Http\Controllers\Billing\PaymentClaimController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Finance\ExpenseController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\Hr\EmployeeController;
use App\Http\Controllers\Hr\HolidayController;
use App\Http\Controllers\Hr\LeaveController;
use App\Http\Controllers\Hr\PayslipController;
use App\Http\Controllers\Hr\SalaryController;
use App\Http\Controllers\Leads\LeadController;
use App\Http\Controllers\Leads\MetaController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\Portal\CampaignController;
use App\Http\Controllers\Portal\ClientDashboardController;
use App\Http\Controllers\Portal\TicketController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'app' => config('app.name'),
    'time' => now()->toIso8601String(),
]));

// Meta Lead Ads webhook (verified by signature, not by login).
Route::get('/webhooks/meta', [MetaController::class, 'verify']);
Route::post('/webhooks/meta', [MetaController::class, 'receive'])->middleware('throttle:600,1');

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
    Route::get('/onboarding', [OnboardingController::class, 'show']);
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

    // Leads: everyone with leads.view sees the leads assigned to them; admins and
    // "all leads" users see everything.
    Route::get('/leads/options', [LeadController::class, 'options'])->middleware('permission:leads.view');
    Route::put('/leads/options', [LeadController::class, 'saveOptions'])->middleware('permission:settings.edit');
    Route::get('/leads/export', [LeadController::class, 'export'])->middleware('permission:leads.export');
    Route::post('/leads/import', [LeadController::class, 'import'])->middleware('permission:leads.create');
    Route::post('/leads/assign', [LeadController::class, 'bulkAssign'])->middleware('permission:leads.edit');
    Route::get('/leads', [LeadController::class, 'index'])->middleware('permission:leads.view');
    Route::post('/leads', [LeadController::class, 'store'])->middleware('permission:leads.create');
    Route::get('/leads/{lead}', [LeadController::class, 'show'])->middleware('permission:leads.view');
    Route::patch('/leads/{lead}', [LeadController::class, 'update'])->middleware('permission:leads.edit');
    Route::delete('/leads/{lead}', [LeadController::class, 'destroy'])->middleware('permission:leads.delete');
    Route::post('/leads/{lead}/activities', [LeadController::class, 'logActivity'])->middleware('permission:leads.view');
    Route::post('/leads/{lead}/convert', [LeadController::class, 'convert'])->middleware('permission:leads.edit');

    Route::get('/integrations/meta', [MetaController::class, 'index'])->middleware('permission:settings.view');
    Route::post('/integrations/meta/pages', [MetaController::class, 'store'])->middleware('permission:settings.edit');
    Route::delete('/integrations/meta/pages/{metaPage}', [MetaController::class, 'destroy'])->middleware('permission:settings.edit');
    Route::post('/integrations/meta/sync', [MetaController::class, 'sync'])->middleware('permission:settings.edit');

    // Clients (staff): admins and "all clients" staff see all, others the clients they manage.
    Route::middleware('staff')->group(function () {
        Route::get('/clients', [ClientController::class, 'index'])->middleware('permission:clients.view');
        Route::post('/clients', [ClientController::class, 'store'])->middleware('permission:clients.create');
        Route::get('/clients/{client}', [ClientController::class, 'show'])->middleware('permission:clients.view');
        Route::put('/clients/{client}', [ClientController::class, 'update'])->middleware('permission:clients.edit');
        Route::post('/clients/{client}/invite', [ClientController::class, 'invite'])->middleware('permission:clients.edit');
        Route::get('/clients/{client}/files', [ClientAssetsController::class, 'files'])->middleware('permission:clients.view');
        Route::get('/client-files/{file}', [ClientAssetsController::class, 'download'])->middleware('permission:clients.view');
        // Clients → "approve" = may reveal shared passwords (admins always can).
        Route::post('/clients/{client}/credentials', [ClientAssetsController::class, 'credentials'])->middleware('permission:clients.approve');

        Route::get('/settings/company', [CompanyController::class, 'show'])->middleware('permission:settings.view');
        Route::put('/settings/company', [CompanyController::class, 'update'])->middleware('permission:settings.edit');
    });

    // Invoices: client logins see their own issued invoices; staff create and manage them.
    Route::get('/invoices', [InvoiceController::class, 'index'])->middleware('permission:invoices.view');
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->middleware('permission:invoices.view');
    Route::get('/invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->middleware('permission:invoices.view');
    Route::post('/invoices/{invoice}/claims', [PaymentClaimController::class, 'store'])->middleware('permission:invoices.view');
    Route::middleware('staff')->group(function () {
        Route::post('/invoices', [InvoiceController::class, 'store'])->middleware('permission:invoices.create');
        Route::put('/invoices/{invoice}', [InvoiceController::class, 'update'])->middleware('permission:invoices.edit');
        Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy'])->middleware('permission:invoices.delete');
        Route::post('/invoices/{invoice}/issue', [InvoiceController::class, 'issue'])->middleware('permission:invoices.create');
        Route::post('/invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->middleware('permission:invoices.edit');
        Route::post('/invoices/{invoice}/email', [InvoiceController::class, 'email'])->middleware('permission:invoices.edit');
        Route::post('/invoices/{invoice}/payments', [InvoiceController::class, 'recordPayment'])->middleware('permission:invoices.approve');
        Route::get('/payment-claims', [PaymentClaimController::class, 'index'])->middleware('permission:invoices.approve');
        Route::post('/payment-claims/{claim}/{decision}', [PaymentClaimController::class, 'decide'])
            ->whereIn('decision', ['confirm', 'reject'])->middleware('permission:invoices.approve');
    });

    // Notifications (everyone sees only their own)
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/notifications/read', [NotificationController::class, 'markRead']);
    Route::get('/notifications/preferences', [NotificationController::class, 'preferences']);
    Route::put('/notifications/preferences', [NotificationController::class, 'savePreferences']);

    // Expenses (staff only)
    Route::middleware('staff')->group(function () {
        Route::get('/expenses', [ExpenseController::class, 'index'])->middleware('permission:expenses.view');
        Route::get('/expenses/export', [ExpenseController::class, 'export'])->middleware('permission:expenses.export');
        Route::get('/expense-categories', [ExpenseController::class, 'categories'])->middleware('permission:expenses.view');
        Route::put('/expense-categories', [ExpenseController::class, 'saveCategories'])->middleware('permission:expenses.approve');
        Route::post('/expenses', [ExpenseController::class, 'store'])->middleware('permission:expenses.create');
        Route::post('/expenses/{expense}', [ExpenseController::class, 'update'])->middleware('permission:expenses.create'); // POST: may carry a new receipt file
        Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy'])->middleware('permission:expenses.create');
        Route::get('/expenses/{expense}/receipt', [ExpenseController::class, 'receipt'])->middleware('permission:expenses.view');
        Route::post('/expenses/{expense}/reimburse', [ExpenseController::class, 'reimburse'])->middleware('permission:expenses.approve');
        Route::post('/expenses/{expense}/{decision}', [ExpenseController::class, 'decide'])
            ->whereIn('decision', ['approve', 'reject'])->middleware('permission:expenses.approve');
    });

    // Client dashboard (client logins)
    Route::get('/dashboard/client', ClientDashboardController::class)->middleware('permission:dashboard.view');

    // Campaigns: clients see their own; staff manage those of clients they can see.
    Route::get('/campaigns', [CampaignController::class, 'index'])->middleware('permission:campaigns.view');
    Route::get('/campaigns/{campaign}', [CampaignController::class, 'show'])->middleware('permission:campaigns.view');
    Route::get('/campaign-files/{file}', [CampaignController::class, 'download'])->middleware('permission:campaigns.view');
    Route::middleware('staff')->group(function () {
        Route::post('/campaigns', [CampaignController::class, 'store'])->middleware('permission:campaigns.create');
        Route::put('/campaigns/{campaign}', [CampaignController::class, 'update'])->middleware('permission:campaigns.edit');
        Route::delete('/campaigns/{campaign}', [CampaignController::class, 'destroy'])->middleware('permission:campaigns.delete');
        Route::post('/campaigns/{campaign}/files', [CampaignController::class, 'upload'])->middleware('permission:campaigns.edit');
        Route::delete('/campaign-files/{file}', [CampaignController::class, 'deleteFile'])->middleware('permission:campaigns.edit');
    });

    // Tickets: clients raise and reply to their own; staff reply, add internal notes, assign.
    Route::get('/tickets', [TicketController::class, 'index'])->middleware('permission:tickets.view');
    Route::post('/tickets', [TicketController::class, 'store'])->middleware('permission:tickets.create');
    Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->middleware('permission:tickets.view');
    Route::post('/tickets/{ticket}/messages', [TicketController::class, 'reply'])->middleware('permission:tickets.view');
    Route::patch('/tickets/{ticket}', [TicketController::class, 'update'])->middleware('permission:tickets.view');
    Route::get('/ticket-files/{message}', [TicketController::class, 'attachment'])->middleware('permission:tickets.view');

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
