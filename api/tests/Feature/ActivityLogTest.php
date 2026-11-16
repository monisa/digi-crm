<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\Lead;
use App\Models\SalaryStructure;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create(['name' => 'Amit']);
    }

    private function as(User $u): static
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u);

        return $this;
    }

    public function test_changes_are_logged_with_who_what_and_names(): void
    {
        $priya = User::factory()->create(['name' => 'Priya', 'role' => 'employee', 'status' => 'active']);
        $lead = Lead::create(['full_name' => 'Rahul Nair', 'phone' => '9876543210', 'status' => 'new', 'source' => 'meta']);
        $this->assertDatabaseHas('activity_log', ['action' => 'created', 'subject_type' => 'lead', 'label' => 'Rahul Nair', 'user_id' => null]);

        $this->as($this->admin)->patchJson("/v1/leads/{$lead->id}", ['status' => 'contacted', 'assigned_to' => $priya->id])->assertOk();
        $row = ActivityLog::where('action', 'updated')->where('subject_id', $lead->id)->latest('id')->first();
        $this->assertSame('Amit', $row->user_name);
        $this->assertSame(['new', 'contacted'], $row->changes['status']);
        $this->assertSame([null, 'Priya'], $row->changes['assigned_to']); // a name, not an id

        // Nothing logged when nothing really changed.
        $n = ActivityLog::count();
        $lead->update(['sla_alerted_at' => now()]);
        $this->assertSame($n, ActivityLog::count());

        $r = $this->getJson('/v1/settings/activity?type=lead')->assertOk()->assertJsonPath('data.0.label', 'Rahul Nair')->assertJsonPath('data.0.user.name', 'Amit');
        $this->assertContains('contacted', collect($r->json('data'))->pluck('changes.status.1'));
        $this->getJson('/v1/settings/activity?user_id='.$this->admin->id.'&search=Rahul')->assertJsonCount(1, 'data');

        // Employees can't read the log.
        $this->as($priya)->getJson('/v1/settings/activity')->assertForbidden();
    }

    public function test_secrets_and_salaries_are_never_stored(): void
    {
        $u = User::factory()->create(['name' => 'Arjun', 'role' => 'employee', 'status' => 'active']);
        $u->update(['password' => 'a-new-secret-pass']);
        $row = ActivityLog::where('subject_type', 'user')->where('action', 'updated')->latest('id')->first();
        $this->assertSame(['•••', 'changed'], $row->changes['password']);

        $emp = Employee::create(['user_id' => $u->id, 'name' => 'Arjun', 'emp_code' => 'E9', 'status' => 'active']);
        $s = SalaryStructure::create(['employee_id' => $emp->id, 'effective_from' => '2026-04-01', 'basic' => 20000, 'hra' => 8000]);
        $s->update(['basic' => 25000]);
        $row = ActivityLog::where('subject_type', 'salary_structure')->where('action', 'updated')->first();
        $this->assertSame('Salary · Arjun', $row->label);
        $this->assertSame(['•••', 'changed'], $row->changes['basic']);
        $this->assertStringNotContainsString('25000', json_encode(ActivityLog::all()->toArray()));
        $this->assertStringNotContainsString('a-new-secret-pass', json_encode(ActivityLog::all()->toArray()));
    }

    public function test_logins_bulk_deletes_and_permission_changes(): void
    {
        $u = User::factory()->create(['name' => 'Neha', 'email' => 'neha@digifox.test', 'role' => 'employee', 'status' => 'active']);
        $this->postJson('/v1/auth/login', ['email' => 'neha@digifox.test', 'password' => 'wrong'])->assertStatus(422);
        $this->postJson('/v1/auth/login', ['email' => 'neha@digifox.test', 'password' => 'password'])->assertOk();
        $this->assertDatabaseHas('activity_log', ['action' => 'login_failed', 'label' => 'neha@digifox.test']);
        $this->assertDatabaseHas('activity_log', ['action' => 'login', 'user_id' => $u->id]);

        Lead::create(['full_name' => 'A', 'phone' => '1', 'status' => 'new']);
        Lead::create(['full_name' => 'B', 'phone' => '2', 'status' => 'new']);
        $this->as($this->admin)->postJson('/v1/leads/bulk-delete', ['all' => true])->assertOk();
        $this->assertDatabaseHas('activity_log', ['action' => 'deleted', 'subject_type' => 'lead', 'label' => '2 leads (delete all)']);

        $this->putJson("/v1/admin/users/{$u->id}/permissions", ['overrides' => ['tasks.approve' => true]])->assertOk();
        $row = ActivityLog::where('action', 'permissions')->first();
        $this->assertSame(['role default', 'allowed'], $row->changes['tasks.approve']);
    }
}
