<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\Lead;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Payment;
use App\Models\Ticket;
use App\Models\User;
use App\Services\DashboardOverview;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow('2026-10-15 12:00:00');
    }

    private function overview(string $period = 'month'): array
    {
        return app(DashboardOverview::class)->build($period, CarbonImmutable::now());
    }

    public function test_only_users_with_overview_permission_can_see_it(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/v1/dashboard/overview')->assertForbidden();

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/v1/dashboard/overview?period=quarter')->assertOk()->assertJsonPath('period.label', 'Q3 FY 2026-27');
        $this->getJson('/v1/dashboard/overview?period=decade')->assertUnprocessable();
    }

    public function test_clients_can_never_get_the_overview(): void
    {
        $client = User::factory()->client()->create();
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->putJson("/v1/admin/users/{$client->id}/permissions", ['overrides' => ['overview.view' => true]]);

        $this->assertFalse($client->fresh()->hasPermission('overview.view'));
    }

    public function test_periods_follow_the_indian_financial_year(): void
    {
        $y = $this->overview('year')['period'];
        $this->assertSame(['2026-04-01', '2027-03-31', 'FY 2026-27'], [$y['from'], $y['to'], $y['label']]);

        Date::setTestNow('2027-02-10');
        $q = $this->overview('quarter')['period'];
        $this->assertSame(['2027-01-01', '2027-03-31', 'Q4 FY 2026-27'], [$q['from'], $q['to'], $q['label']]);
    }

    public function test_revenue_and_expenses_compare_with_the_same_point_last_period(): void
    {
        $client = Client::create(['company_name' => 'Green Leaf']);
        Payment::create(['client_id' => $client->id, 'amount' => 50000, 'paid_on' => '2026-10-05']);
        Payment::create(['client_id' => $client->id, 'amount' => 30000, 'paid_on' => '2026-09-10']); // counts for comparison
        Payment::create(['client_id' => $client->id, 'amount' => 99999, 'paid_on' => '2026-09-25']); // after the same point
        Expense::create(['spent_on' => '2026-10-02', 'category' => 'Rent', 'amount' => 20000]);
        Expense::create(['spent_on' => '2026-10-03', 'category' => 'Misc', 'amount' => 5000, 'status' => 'pending']); // not approved

        $o = $this->overview();

        $this->assertSame('October 2026', $o['period']['label']);
        $this->assertSame('2026-09-15', $o['period']['compare_to']);
        $this->assertEquals(50000, $o['kpis']['revenue']['amount']);
        $this->assertEquals(30000, $o['kpis']['revenue']['prev']);
        $this->assertEquals(20000, $o['kpis']['expenses']['amount']);
        $this->assertEquals(30000, $o['kpis']['net']);
    }

    public function test_trend_has_twelve_months_ending_this_month(): void
    {
        Expense::create(['spent_on' => '2026-10-01', 'category' => 'Rent', 'amount' => 1000]);
        Expense::create(['spent_on' => '2025-10-31', 'category' => 'Rent', 'amount' => 999]); // 13 months ago: excluded

        $trend = $this->overview()['trend'];

        $this->assertCount(12, $trend);
        $this->assertSame('2025-11', $trend[0]['month']);
        $this->assertSame(['month' => '2026-10', 'label' => 'Oct', 'revenue' => 0.0, 'expenses' => 1000.0], $trend[11]);
    }

    public function test_funnel_counts_how_far_leads_progressed(): void
    {
        foreach (['new', 'contacted', 'qualified', 'proposal', 'won', 'lost', 'won'] as $s) {
            Lead::create(['full_name' => 'X', 'status' => $s]);
        }

        $stages = collect($this->overview()['funnel']['stages'])->pluck('count', 'key')->all();

        $this->assertSame(['new' => 7, 'contacted' => 6, 'qualified' => 4, 'proposal' => 3, 'won' => 2], $stages);
        $this->assertSame(1, $this->overview()['funnel']['lost']);
    }

    public function test_uncontacted_leads_respect_the_response_time(): void
    {
        $priya = User::factory()->create(['name' => 'Priya']);
        Lead::create(['full_name' => 'Old lead', 'status' => 'new', 'assigned_to' => $priya->id, 'created_at' => now()->subHours(2)]);
        Lead::create(['full_name' => 'Fresh lead', 'status' => 'new', 'created_at' => now()->subMinutes(10)]);
        Lead::create(['full_name' => 'Called', 'status' => 'contacted', 'created_at' => now()->subHours(3)]);

        $u = $this->overview()['uncontacted'];

        $this->assertSame(1, $u['count']);
        $this->assertSame('Old lead', $u['items'][0]['name']);
        $this->assertSame('Priya', $u['items'][0]['assignee']);
        $this->assertSame(120, $u['items'][0]['waiting_minutes']);
    }

    public function test_tickets_leave_and_people_counts(): void
    {
        $client = Client::create(['company_name' => 'Malabar Spices']);
        Ticket::create(['client_id' => $client->id, 'subject' => 'Ads down', 'priority' => 'high']);
        Ticket::create(['client_id' => $client->id, 'subject' => 'Report', 'priority' => 'low', 'status' => 'waiting']);
        Ticket::create(['client_id' => $client->id, 'subject' => 'Done', 'status' => 'closed']);

        $type = LeaveType::where('code', 'CL')->first();
        $a = Employee::create(['emp_code' => 'E1', 'name' => 'Arjun']);
        $b = Employee::create(['emp_code' => 'E2', 'name' => 'Meera']);
        Employee::create(['emp_code' => 'E3', 'name' => 'Left', 'status' => 'inactive']);
        LeaveRequest::create(['employee_id' => $a->id, 'leave_type_id' => $type->id, 'from_date' => '2026-10-14', 'to_date' => '2026-10-16', 'days' => 3, 'status' => 'approved']);
        LeaveRequest::create(['employee_id' => $b->id, 'leave_type_id' => $type->id, 'from_date' => '2026-10-20', 'to_date' => '2026-10-20', 'days' => 1]);

        $o = $this->overview();

        $this->assertSame(2, $o['tickets']['open']);
        $this->assertSame(1, $o['tickets']['high']);
        $this->assertSame('Ads down', $o['tickets']['items'][0]['subject']);
        $this->assertSame(1, $o['leave']['pending']);
        $this->assertSame('Meera', $o['leave']['items'][0]['employee']);
        $this->assertSame(['total' => 2, 'on_leave_today' => 1], $o['kpis']['employees']);
        $this->assertSame(1, $o['kpis']['clients']['total']);
    }

    public function test_demo_seeder_refuses_production(): void
    {
        $this->app['env'] = 'production';
        $this->expectException(\RuntimeException::class);
        (new \Database\Seeders\DemoDataSeeder)->run();
    }
}
