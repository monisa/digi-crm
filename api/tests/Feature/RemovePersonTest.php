<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Lead;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RemovePersonTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        Sanctum::actingAs($this->admin);
    }

    public function test_only_inactive_people_can_be_deleted_and_their_records_stay_unnamed(): void
    {
        $u = User::factory()->create(['name' => 'Test User', 'role' => 'employee', 'status' => 'active']);
        $e = Employee::create(['emp_code' => 'DFP009', 'name' => 'Test User', 'user_id' => $u->id]);
        LeaveRequest::create(['employee_id' => $e->id, 'leave_type_id' => LeaveType::first()->id, 'from_date' => '2026-11-02', 'to_date' => '2026-11-02', 'days' => 1, 'status' => 'approved']);
        $lead = Lead::create(['full_name' => 'Customer', 'assigned_to' => $u->id]);
        Task::create(['title' => 'Old task', 'assigned_to' => $u->id]);

        $this->deleteJson("/v1/employees/{$e->id}")->assertOk();

        $this->assertModelMissing($u);
        $this->assertModelMissing($e);
        $this->assertSame(0, LeaveRequest::count());
        $this->assertNull($lead->fresh()->assigned_to); // the lead stays
        $this->assertSame(1, Task::count());
    }

    public function test_delete_a_test_login_but_never_yourself(): void
    {
        $test = User::factory()->client()->create(['status' => 'pending_onboarding']);
        $this->deleteJson("/v1/admin/users/{$test->id}")->assertOk();
        $this->assertModelMissing($test);

        $this->deleteJson("/v1/admin/users/{$this->admin->id}")->assertStatus(422)->assertJsonPath('message', 'You cannot delete your own account.');

        $emp = User::factory()->create(['role' => 'employee', 'status' => 'inactive']);
        Sanctum::actingAs($emp);
        $this->deleteJson("/v1/admin/users/{$this->admin->id}")->assertForbidden();
    }
}
