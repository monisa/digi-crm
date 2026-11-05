<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Payslip;
use App\Models\User;
use App\Notifications\EmployeeInvite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HrTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow('2026-11-02 10:00:00'); // a Monday
        $this->admin = User::factory()->admin()->create();
    }

    private function staff(string $name = 'Priya Raj'): array
    {
        $user = User::factory()->create(['name' => $name]);
        $employee = Employee::create(['emp_code' => 'DFP'.str_pad((string) $user->id, 3, '0', STR_PAD_LEFT), 'name' => $name, 'user_id' => $user->id, 'doj' => '2025-01-01']);

        return [$user, $employee];
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);

        return $this;
    }

    private function type(string $code): LeaveType
    {
        return LeaveType::where('code', $code)->first();
    }

    // ---------- Employees ----------

    public function test_admin_adds_employee_with_login_invite(): void
    {
        Notification::fake();

        $res = $this->as($this->admin)->postJson('/v1/employees', [
            'name' => 'Meera Nair', 'office_email' => 'Meera@DigiFox.test', 'mobile' => '+91 98470 11111',
            'designation' => 'SEO Analyst', 'dob' => '1996-04-12', 'doj' => '2026-11-01', 'address' => 'Kakkanad', 'create_login' => true,
        ])->assertCreated()->assertJsonPath('emp_code', 'DFP001')->assertJsonPath('has_login', true);

        $user = User::where('email', 'meera@digifox.test')->first();
        $this->assertSame('employee', $user->role);
        $this->assertSame($res->json('id'), $user->employee->id);
        Notification::assertSentTo($user, EmployeeInvite::class);
    }

    public function test_employees_only_see_themselves(): void
    {
        [$priya, $pe] = $this->staff();
        [, $other] = $this->staff('Arjun');

        $this->as($priya)->getJson('/v1/employees')->assertOk()->assertJsonPath('scope', 'own')->assertJsonCount(1, 'data');
        $this->getJson("/v1/employees/{$pe->id}")->assertOk();
        $this->getJson("/v1/employees/{$other->id}")->assertForbidden();
        $this->getJson("/v1/employees/{$other->id}/salary")->assertForbidden();
        $this->getJson("/v1/employees/{$other->id}/leave")->assertForbidden();
        $this->getJson("/v1/employees/{$pe->id}/leave")->assertOk()->assertJsonCount(3, 'balances');
        $this->postJson('/v1/employees', ['name' => 'X'])->assertForbidden();

        $this->as($this->admin)->getJson('/v1/employees')->assertJsonPath('scope', 'all')->assertJsonCount(2, 'data');
    }

    public function test_deactivating_employee_switches_off_their_login(): void
    {
        [$priya, $pe] = $this->staff();
        $priya->createToken('phone');

        $this->as($this->admin)->putJson("/v1/employees/{$pe->id}", ['name' => 'Priya Raj', 'status' => 'inactive'])->assertOk();

        $this->assertSame('inactive', $priya->fresh()->status);
        $this->assertSame(0, $priya->tokens()->count());
    }

    // ---------- Holidays & leave days ----------

    public function test_leave_days_skip_sundays_and_public_holidays(): void
    {
        [$priya] = $this->staff();
        Holiday::create(['date' => '2026-11-04', 'name' => 'Test holiday']);

        // Mon 2 Nov – Mon 9 Nov = 7 working days, minus Sunday 8th and holiday 4th = 6
        $this->as($priya)->postJson('/v1/leave/preview', ['from_date' => '2026-11-02', 'to_date' => '2026-11-09'])
            ->assertJsonPath('days', 6);
        $this->postJson('/v1/leave/preview', ['from_date' => '2026-11-03', 'to_date' => '2026-11-03', 'half_day' => true])
            ->assertJsonPath('days', 0.5);
    }

    public function test_admin_manages_holidays_and_everyone_can_see_them(): void
    {
        [$priya] = $this->staff();

        $this->as($this->admin)->postJson('/v1/holidays/fixed', ['year' => 2027])->assertJsonPath('added', 5);
        $this->postJson('/v1/holidays/fixed', ['year' => 2027])->assertJsonPath('added', 0);
        $this->postJson('/v1/holidays', ['date' => '2027-08-25', 'name' => 'Thiruvonam'])->assertCreated();
        $this->postJson('/v1/holidays', ['date' => '2027-08-25', 'name' => 'Dup'])->assertUnprocessable();

        $this->as($priya)->getJson('/v1/holidays?year=2027')->assertOk()->assertJsonCount(6, 'data')
            ->assertJsonPath('data.0.name', 'Republic Day');
        $this->postJson('/v1/holidays', ['date' => '2027-09-01', 'name' => 'Nope'])->assertForbidden();
    }

    // ---------- Leave requests ----------

    public function test_casual_leave_quota_is_four_days_and_sick_six(): void
    {
        [$priya] = $this->staff();

        $balances = collect($this->as($priya)->getJson('/v1/leave/summary')->assertOk()->json('balances'))->keyBy('type.code');
        $this->assertEquals(4, $balances['CL']['allotted']);
        $this->assertEquals(6, $balances['SL']['allotted']);
        $this->assertNull($balances['LOP']['allotted']);
    }

    public function test_request_cannot_exceed_balance_but_loss_of_pay_can(): void
    {
        [$priya] = $this->staff();
        $this->as($priya);

        // Mon 9 – Sat 14 Nov = 6 working days > 4 CL
        $this->postJson('/v1/leave/requests', ['leave_type_id' => $this->type('CL')->id, 'from_date' => '2026-11-09', 'to_date' => '2026-11-14', 'reason' => 'Trip'])
            ->assertUnprocessable()->assertJsonValidationErrors('leave_type_id');

        $this->postJson('/v1/leave/requests', ['leave_type_id' => $this->type('CL')->id, 'from_date' => '2026-11-09', 'to_date' => '2026-11-12', 'reason' => 'Trip'])
            ->assertCreated()->assertJsonPath('days', 4)->assertJsonPath('status', 'pending');

        // Pending days count against the balance
        $this->postJson('/v1/leave/requests', ['leave_type_id' => $this->type('CL')->id, 'from_date' => '2026-11-20', 'to_date' => '2026-11-20', 'reason' => 'x'])
            ->assertUnprocessable();
        $this->postJson('/v1/leave/requests', ['leave_type_id' => $this->type('LOP')->id, 'from_date' => '2026-11-20', 'to_date' => '2026-11-21', 'reason' => 'x'])
            ->assertCreated();
    }

    public function test_overlapping_backdated_and_holiday_only_requests_are_refused(): void
    {
        [$priya] = $this->staff();
        $this->as($priya);
        $sl = $this->type('SL')->id;

        $this->postJson('/v1/leave/requests', ['leave_type_id' => $sl, 'from_date' => '2026-11-03', 'to_date' => '2026-11-04', 'reason' => 'Fever'])->assertCreated();
        $this->postJson('/v1/leave/requests', ['leave_type_id' => $sl, 'from_date' => '2026-11-04', 'to_date' => '2026-11-05', 'reason' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('from_date');
        $this->postJson('/v1/leave/requests', ['leave_type_id' => $sl, 'from_date' => '2026-09-01', 'to_date' => '2026-09-01', 'reason' => 'x'])
            ->assertUnprocessable();
        $this->postJson('/v1/leave/requests', ['leave_type_id' => $sl, 'from_date' => '2026-11-08', 'to_date' => '2026-11-08', 'reason' => 'Sunday'])
            ->assertUnprocessable();
    }

    public function test_admin_approves_or_rejects_and_employee_can_cancel_pending(): void
    {
        [$priya] = $this->staff();
        $cl = $this->type('CL')->id;
        $a = $this->as($priya)->postJson('/v1/leave/requests', ['leave_type_id' => $cl, 'from_date' => '2026-11-10', 'to_date' => '2026-11-10', 'reason' => 'x'])->json('id');
        $b = $this->postJson('/v1/leave/requests', ['leave_type_id' => $cl, 'from_date' => '2026-11-11', 'to_date' => '2026-11-11', 'reason' => 'x'])->json('id');
        $c = $this->postJson('/v1/leave/requests', ['leave_type_id' => $cl, 'from_date' => '2026-11-12', 'to_date' => '2026-11-12', 'reason' => 'x'])->json('id');

        // Employees cannot approve
        $this->postJson("/v1/leave/requests/{$a}/approve")->assertForbidden();
        $this->postJson("/v1/leave/requests/{$c}/cancel")->assertOk()->assertJsonPath('status', 'cancelled');

        $this->as($this->admin)->getJson('/v1/leave/approvals')->assertJsonCount(2, 'data');
        $this->postJson("/v1/leave/requests/{$a}/approve")->assertOk()->assertJsonPath('status', 'approved')->assertJsonPath('approver', $this->admin->name);
        $this->postJson("/v1/leave/requests/{$b}/reject")->assertUnprocessable(); // reason required
        $this->postJson("/v1/leave/requests/{$b}/reject", ['comment' => 'Client launch that day'])->assertOk();
        $this->postJson("/v1/leave/requests/{$a}/reject", ['comment' => 'late'])->assertUnprocessable(); // already decided

        $this->as($priya)->postJson("/v1/leave/requests/{$a}/cancel")->assertUnprocessable();
        $cl = collect($this->getJson('/v1/leave/summary')->json('balances'))->firstWhere('type.code', 'CL');
        $this->assertEquals(['used' => 1, 'pending' => 0, 'remaining' => 3], ['used' => $cl['used'], 'pending' => $cl['pending'], 'remaining' => $cl['remaining']]);
    }

    public function test_admin_changes_leave_quota(): void
    {
        $this->as($this->admin)->putJson('/v1/leave/types/'.$this->type('CL')->id, ['yearly_quota' => 8])->assertOk()->assertJsonPath('yearly_quota', 8);
        [$priya] = $this->staff();
        $this->as($priya)->putJson('/v1/leave/types/'.$this->type('CL')->id, ['yearly_quota' => 50])->assertForbidden();
    }

    // ---------- Salary & payslips ----------

    private function salary(Employee $e, array $extra = []): void
    {
        $this->as($this->admin)->postJson("/v1/employees/{$e->id}/salary", [
            'effective_from' => '2026-04-01', 'basic' => 15000, 'hra' => 6000, 'special_allowance' => 9000,
            'pf' => 1800, 'professional_tax' => 200, 'bank_account_last4' => '4321', ...$extra,
        ])->assertCreated()->assertJsonPath('gross', 30000)->assertJsonPath('net', 28000);
    }

    public function test_payslip_generation_with_loss_of_pay(): void
    {
        [$priya, $pe] = $this->staff();
        [, $arjun] = $this->staff('Arjun');
        $this->salary($pe);
        // Arjun has no salary yet -> skipped

        LeaveRequest::create(['employee_id' => $pe->id, 'leave_type_id' => $this->type('LOP')->id, 'from_date' => '2026-10-29', 'to_date' => '2026-11-02', 'days' => 4, 'status' => 'approved']);

        $res = $this->as($this->admin)->postJson('/v1/payslips/generate', ['year' => 2026, 'month' => 10])->assertOk();
        $this->assertSame(1, $res->json('generated'));
        $this->assertSame('No salary details', $res->json('skipped.0.reason'));

        $slip = Payslip::first();
        // LOP in October: Thu 29, Fri 30, Sat 31 = 3 days (Sunday 1 Nov is outside the month anyway)
        $this->assertEquals(3, $slip->lop_days);
        $this->assertEquals(round(30000 * 28 / 31, 2), $slip->gross);
        $this->assertEquals(2000, $slip->total_deductions);
        $this->assertSame('draft', $slip->status);

        // Employees do not see drafts
        $this->as($priya)->getJson("/v1/employees/{$pe->id}/payslips")->assertJsonCount(0, 'data');
        $this->getJson("/v1/payslips/{$slip->id}/pdf")->assertNotFound();

        $this->as($this->admin)->postJson('/v1/payslips/publish', ['year' => 2026, 'month' => 10])->assertJsonPath('published', 1);
        // Published payslips are not regenerated
        $this->postJson('/v1/payslips/generate', ['year' => 2026, 'month' => 10])->assertJsonPath('skipped.0.reason', 'Already published');

        $this->as($priya)->getJson("/v1/employees/{$pe->id}/payslips")->assertJsonCount(1, 'data')->assertJsonPath('data.0.label', 'October 2026');
        $pdf = $this->get("/v1/payslips/{$slip->id}/pdf")->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertStringContainsString('Payslip-', $pdf->headers->get('content-disposition'));
    }

    public function test_employee_joining_mid_month_is_paid_from_joining_date(): void
    {
        $e = Employee::create(['emp_code' => 'DFP050', 'name' => 'New Joiner', 'doj' => '2026-10-11']);
        $this->salary($e);

        $this->as($this->admin)->postJson('/v1/payslips/generate', ['year' => 2026, 'month' => 10]);
        $this->assertEquals(10, Payslip::first()->lop_days);
    }

    public function test_cannot_generate_for_future_month_or_see_others_payslips(): void
    {
        [$priya, $pe] = $this->staff();
        [, $arjun] = $this->staff('Arjun');
        $this->salary($arjun);
        $this->as($this->admin)->postJson('/v1/payslips/generate', ['year' => 2026, 'month' => 12])->assertUnprocessable();
        $this->postJson('/v1/payslips/generate', ['year' => 2026, 'month' => 10]);
        $this->postJson('/v1/payslips/publish', ['year' => 2026, 'month' => 10]);

        $slip = Payslip::first();
        $this->as($priya)->get("/v1/payslips/{$slip->id}/pdf")->assertForbidden();
        $this->postJson('/v1/payslips/generate', ['year' => 2026, 'month' => 10])->assertForbidden();
    }

    public function test_salary_starting_later_is_explained_and_can_be_moved_back(): void
    {
        [, $pe] = $this->staff();
        $id = $this->as($this->admin)->postJson("/v1/employees/{$pe->id}/salary", ['effective_from' => '2026-12-01', 'basic' => 20000])->assertCreated()->json('id');

        // The profile still shows it, marked upcoming.
        $this->getJson("/v1/employees/{$pe->id}/salary")->assertJsonPath('current.upcoming', true)->assertJsonPath('current.basic', 20000);

        $r = $this->postJson('/v1/payslips/generate', ['year' => 2026, 'month' => 10])->assertOk();
        $this->assertSame(0, $r->json('generated'));
        $this->assertSame('Salary starts 1 Dec 2026', $r->json('skipped.0.reason'));
        $this->assertSame($id, $r->json('skipped.0.salary_id'));

        $this->patchJson("/v1/employees/{$pe->id}/salary/{$id}", ['effective_from' => '2026-10-01'])->assertOk();
        $this->postJson('/v1/payslips/generate', ['year' => 2026, 'month' => 10])->assertJsonPath('generated', 1);
    }
}
