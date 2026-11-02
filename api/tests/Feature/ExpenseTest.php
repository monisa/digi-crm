<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Expense;
use App\Models\User;
use App\Services\DashboardOverview;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExpenseTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private User $admin;

    private User $priya;

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow('2026-11-20 10:00:00');
        $this->admin = User::factory()->admin()->create(['name' => 'Amit']);
        $this->priya = User::factory()->create(['name' => 'Priya']);
        $this->as($this->admin)->putJson("/v1/admin/users/{$this->priya->id}/permissions", ['overrides' => ['expenses.view' => true, 'expenses.create' => true]]);
        $this->priya->refresh();
    }

    private function as(User $u): static
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u);

        return $this;
    }

    private function add(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->post('/v1/expenses', ['spent_on' => '2026-11-05', 'category' => 'Software & tools', 'amount' => 1180, 'gst' => 180, 'vendor' => 'Canva', 'payment_mode' => 'card', ...$extra], ['Accept' => 'application/json']);
    }

    public function test_admin_expenses_count_immediately_and_staff_claims_wait_for_approval(): void
    {
        $this->as($this->admin)->add()->assertCreated()->assertJsonPath('status', 'approved');
        $claim = $this->as($this->priya)->add(['category' => 'Travel', 'amount' => 640, 'gst' => null, 'vendor' => 'Uber', 'reimbursable' => 1])
            ->assertCreated()->assertJsonPath('status', 'pending')->assertJsonPath('reimbursable', true)->json();

        // Priya sees only her own, and cannot approve
        $this->getJson('/v1/expenses')->assertJsonCount(1, 'data')->assertJsonPath('can_approve', false);
        $this->postJson("/v1/expenses/{$claim['id']}/approve")->assertForbidden();
        $this->assertSame('expense_submitted', $this->admin->fresh()->notifications->first()->data['type']);

        $this->as($this->admin)->getJson('/v1/expenses')->assertJsonCount(2, 'data')->assertJsonPath('summary.total', 1180)->assertJsonPath('summary.pending', 1);
        $this->postJson("/v1/expenses/{$claim['id']}/reject")->assertUnprocessable(); // reason needed
        $this->postJson("/v1/expenses/{$claim['id']}/approve")->assertOk()->assertJsonPath('status', 'approved');
        $this->assertSame('expense_decided', $this->priya->fresh()->notifications->first()->data['type']);

        $s = $this->getJson('/v1/expenses')->json('summary');
        $this->assertEquals([1820, 640], [$s['total'], $s['to_reimburse']]);
        $this->postJson("/v1/expenses/{$claim['id']}/reimburse")->assertOk();
        $this->assertEquals(0, $this->getJson('/v1/expenses')->json('summary.to_reimburse'));
    }

    public function test_staff_can_fix_a_rejected_claim_but_not_change_approved_ones(): void
    {
        $id = $this->as($this->priya)->add()->json('id');
        $this->as($this->admin)->postJson("/v1/expenses/{$id}/reject", ['note' => 'Personal subscription'])->assertOk();

        $this->as($this->priya)->post("/v1/expenses/{$id}", ['spent_on' => '2026-11-05', 'category' => 'Software & tools', 'amount' => 590, 'notes' => 'Team plan only'], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('status', 'pending')->assertJsonPath('amount', 590);

        $this->as($this->admin)->postJson("/v1/expenses/{$id}/approve");
        $this->as($this->priya)->post("/v1/expenses/{$id}", ['spent_on' => '2026-11-05', 'category' => 'Travel', 'amount' => 99999], ['Accept' => 'application/json'])->assertForbidden();
        $this->deleteJson("/v1/expenses/{$id}")->assertForbidden();
    }

    public function test_validation(): void
    {
        $this->as($this->admin)->add(['category' => 'Holidays', 'amount' => 0, 'gst' => 5000, 'spent_on' => '2026-12-31'])
            ->assertUnprocessable()->assertJsonValidationErrors(['category', 'amount', 'spent_on']);
        $this->add(['gst' => 5000])->assertUnprocessable()->assertJsonValidationErrors('gst');
        $this->add(['receipt' => UploadedFile::fake()->create('x.exe', 1)])->assertJsonValidationErrors('receipt');
    }

    public function test_receipts_are_private(): void
    {
        Storage::fake('local');
        $id = $this->as($this->priya)->add(['receipt' => UploadedFile::fake()->image('bill.jpg')])->assertJsonPath('receipt.name', 'bill.jpg')->json('id');

        $this->get("/v1/expenses/{$id}/receipt")->assertOk();
        $this->as($this->admin)->get("/v1/expenses/{$id}/receipt")->assertOk();
        $other = User::factory()->create();
        $this->as($this->admin)->putJson("/v1/admin/users/{$other->id}/permissions", ['overrides' => ['expenses.view' => true]]);
        $this->as($other->fresh())->get("/v1/expenses/{$id}/receipt", ['Accept' => 'application/json'])->assertNotFound();
        // Clients never reach expenses
        $this->as(User::factory()->client()->create())->getJson('/v1/expenses')->assertForbidden();
    }

    public function test_period_summary_by_category_and_export(): void
    {
        $this->as($this->admin);
        $this->add(['category' => 'Ads spend', 'amount' => 20000, 'gst' => 0, 'spent_on' => '2026-11-02']);
        $this->add(['category' => 'Ads spend', 'amount' => 5000, 'gst' => 0, 'spent_on' => '2026-11-15']);
        $this->add(['category' => 'Rent', 'amount' => 15000, 'gst' => 0, 'spent_on' => '2026-11-01']);
        $this->add(['category' => 'Rent', 'amount' => 15000, 'gst' => 0, 'spent_on' => '2026-10-01']); // last month

        $s = $this->getJson('/v1/expenses')->json('summary');
        $this->assertSame([['category' => 'Ads spend', 'total' => 25000, 'count' => 2], ['category' => 'Rent', 'total' => 15000, 'count' => 1]], $s['by_category']);
        $this->assertEquals([40000, 15000], [$s['total'], $s['previous']]);

        $csv = $this->get('/v1/expenses/export?from=2026-11-01&to=2026-11-30')->assertOk()->streamedContent();
        $this->assertStringContainsString('Date,Category,Vendor', $csv);
        $this->assertSame(4, substr_count($csv, "\n")); // header + 3 rows
    }

    public function test_publishing_payroll_creates_one_salaries_expense_per_month(): void
    {
        $e = Employee::create(['emp_code' => 'E1', 'name' => 'Priya', 'doj' => '2025-01-01']);
        $e->salaryStructures()->create(['effective_from' => '2026-04-01', 'basic' => 20000, 'hra' => 8000, 'special_allowance' => 2000, 'pf' => 2400]);

        $this->as($this->admin)->postJson('/v1/payslips/generate', ['year' => 2026, 'month' => 10]);
        $this->postJson('/v1/payslips/publish', ['year' => 2026, 'month' => 10]);
        $this->postJson('/v1/payslips/publish', ['year' => 2026, 'month' => 10]); // again: no duplicate

        $sal = Expense::where('source', 'payroll:2026-10')->get();
        $this->assertCount(1, $sal);
        $this->assertSame(['Salaries', 30000.0, 'approved', '2026-10-31'], [$sal[0]->category, $sal[0]->amount, $sal[0]->status, $sal[0]->spent_on->toDateString()]);

        // Counts on the dashboard, and cannot be edited by hand
        $o = app(DashboardOverview::class)->build('month', CarbonImmutable::parse('2026-10-31 12:00'));
        $this->assertEquals(30000, $o['kpis']['expenses']['amount']);
        $this->post("/v1/expenses/{$sal[0]->id}", ['spent_on' => '2026-10-31', 'category' => 'Salaries', 'amount' => 1], ['Accept' => 'application/json'])->assertUnprocessable();
    }

    public function test_admin_edits_categories(): void
    {
        $this->as($this->admin)->putJson('/v1/expense-categories', ['names' => ['Ads spend', 'Rent', 'Freelancers']])->assertOk();
        $active = collect($this->getJson('/v1/expense-categories')->json('data'))->where('active', true)->pluck('name')->sort()->values()->all();
        $this->assertSame(['Ads spend', 'Freelancers', 'Rent', 'Salaries'], $active);
        $this->as($this->priya)->putJson('/v1/expense-categories', ['names' => ['x']])->assertForbidden();
    }
}
