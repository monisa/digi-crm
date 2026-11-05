<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Lead;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StaffHomeTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function grant(User $u, array $keys): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->putJson("/v1/admin/users/{$u->id}/permissions", ['overrides' => array_fill_keys($keys, true)])->assertOk();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u->fresh());
    }

    public function test_employee_home_shows_uncontacted_leads_tickets_and_leave(): void
    {
        $priya = User::factory()->create(['name' => 'Priya', 'role' => 'employee', 'status' => 'active']);
        $other = User::factory()->create(['role' => 'employee', 'status' => 'active']);
        $emp = Employee::create(['emp_code' => 'DFP002', 'name' => 'Priya', 'user_id' => $priya->id]);
        Lead::create(['full_name' => 'Mine new', 'assigned_to' => $priya->id, 'created_at' => now()->subHours(3)]);
        Lead::create(['full_name' => 'Mine called', 'assigned_to' => $priya->id, 'first_contacted_at' => now(), 'status' => 'contacted']);
        Lead::create(['full_name' => 'Someone else', 'assigned_to' => $other->id]);
        $client = Client::create(['company_name' => 'Green Leaf']);
        Ticket::create(['client_id' => $client->id, 'subject' => 'Site down', 'status' => 'open', 'priority' => 'high', 'assigned_to' => $priya->id, 'awaiting_staff' => true]);
        LeaveRequest::create(['employee_id' => $emp->id, 'leave_type_id' => LeaveType::where('code', 'CL')->value('id'), 'from_date' => '2026-11-20', 'to_date' => '2026-11-20', 'days' => 1, 'status' => 'pending']);

        $this->grant($priya, ['leads.view', 'tickets.view']);
        $r = $this->getJson('/v1/dashboard/me')->assertOk();
        $this->assertSame(1, $r->json('leads.uncontacted'));
        $this->assertSame('Mine new', $r->json('leads.uncontacted_list.0.name'));
        $this->assertTrue($r->json('leads.uncontacted_list.0.late'));
        $this->assertSame(1, $r->json('tickets.needs_reply'));
        $this->assertSame('pending', $r->json('leave.mine.0.status'));
        $this->assertNull($r->json('leave.to_approve')); // not an approver
    }

    public function test_approvers_see_team_leave_waiting(): void
    {
        $lead = User::factory()->create(['role' => 'employee', 'status' => 'active']);
        $arjunUser = User::factory()->create(['role' => 'employee', 'status' => 'active']);
        $arjun = Employee::create(['emp_code' => 'DFP003', 'name' => 'Arjun', 'user_id' => $arjunUser->id]);
        LeaveRequest::create(['employee_id' => $arjun->id, 'leave_type_id' => LeaveType::where('code', 'SL')->value('id'), 'from_date' => '2026-11-12', 'to_date' => '2026-11-13', 'days' => 2, 'status' => 'pending', 'reason' => 'Fever']);

        $this->grant($lead, ['leave.approve']);
        $this->getJson('/v1/dashboard/me')->assertOk()->assertJsonPath('leave.to_approve.0.name', 'Arjun')->assertJsonPath('leave.to_approve.0.days', 2);
    }
}
