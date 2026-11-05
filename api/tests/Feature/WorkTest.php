<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Client;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Setting;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow('2026-11-09 09:30:00'); // Monday
        $this->admin = User::factory()->admin()->create(['name' => 'Amit']);
    }

    private function as(User $u): static
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u);

        return $this;
    }

    private function staff(string $name = 'Priya'): User
    {
        return User::factory()->create(['name' => $name, 'role' => 'employee', 'status' => 'active']);
    }

    // ---------- Tasks ----------

    public function test_admin_assigns_a_task_and_the_employee_finishes_it(): void
    {
        $priya = $this->staff();
        $client = Client::create(['company_name' => 'Green Leaf']);
        $id = $this->as($this->admin)->postJson('/v1/tasks', ['title' => 'Post 3 Instagram stories', 'assigned_to' => $priya->id, 'client_id' => $client->id, 'priority' => 'high'])
            ->assertCreated()->assertJsonPath('due_date', '2026-11-09')->assertJsonPath('client.company_name', 'Green Leaf')->json('id');
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $priya->id]);

        $this->as($priya)->getJson('/v1/tasks')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('counts.today', 1)->assertJsonPath('can_manage', false);
        $this->patchJson("/v1/tasks/$id", ['status' => 'done', 'add_minutes' => 45])->assertOk()->assertJsonPath('status', 'done')->assertJsonPath('minutes_spent', 45);
        // The assignee cannot rewrite or delete a task given to them.
        $this->patchJson("/v1/tasks/$id", ['title' => 'Something else'])->assertOk()->assertJsonPath('title', 'Post 3 Instagram stories');
        $this->deleteJson("/v1/tasks/$id")->assertForbidden();
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->admin->id]);
    }

    public function test_employees_only_add_tasks_for_themselves_and_see_their_own(): void
    {
        $priya = $this->staff();
        $arjun = $this->staff('Arjun');
        Task::create(['title' => 'Arjun work', 'assigned_to' => $arjun->id, 'due_date' => today()]);

        $this->as($priya)->postJson('/v1/tasks', ['title' => 'For Arjun', 'assigned_to' => $arjun->id])->assertForbidden();
        $mine = $this->postJson('/v1/tasks', ['title' => 'Call client back'])->assertCreated()->assertJsonPath('assigned_to.id', $priya->id)->json('id');
        $this->getJson('/v1/tasks?view=all')->assertJsonCount(1, 'data');
        $this->deleteJson("/v1/tasks/$mine")->assertOk();

        $this->as($this->admin)->getJson('/v1/tasks?view=all&assigned='.$arjun->id)->assertJsonCount(1, 'data')->assertJsonPath('can_manage', true);
    }

    public function test_views_today_overdue_upcoming(): void
    {
        $priya = $this->staff();
        foreach (['2026-11-07' => 'Late one', '2026-11-09' => 'Today one', '2026-11-12' => 'Later one'] as $d => $t) {
            Task::create(['title' => $t, 'assigned_to' => $priya->id, 'due_date' => $d]);
        }
        $r = $this->as($priya)->getJson('/v1/tasks?view=today')->assertJsonCount(2, 'data');
        $this->assertTrue(collect($r->json('data'))->firstWhere('title', 'Late one')['overdue']);
        $this->getJson('/v1/tasks?view=upcoming')->assertJsonCount(1, 'data')->assertJsonPath('counts.overdue', 1);
    }

    public function test_repeating_tasks_get_a_copy_each_working_day(): void
    {
        $priya = $this->staff();
        $this->as($this->admin)->postJson('/v1/tasks', ['title' => 'Daily report', 'assigned_to' => $priya->id, 'repeat' => 'daily'])->assertCreated();
        Artisan::call('crm:repeat-tasks');
        $this->assertSame(1, Task::count()); // today's is the first one

        Date::setTestNow('2026-11-10 00:10:00');
        Artisan::call('crm:repeat-tasks');
        Artisan::call('crm:repeat-tasks'); // twice: still one copy
        $this->assertSame(2, Task::count());
        $this->assertSame('2026-11-10', Task::latest('id')->first()->due_date->toDateString());

        Date::setTestNow('2026-11-15 00:10:00'); // Sunday: off
        Artisan::call('crm:repeat-tasks');
        $this->assertSame(2, Task::count());
    }

    public function test_ticket_becomes_a_task_and_is_assigned(): void
    {
        $priya = $this->staff();
        $client = Client::create(['company_name' => 'Green Leaf']);
        $ticket = Ticket::create(['client_id' => $client->id, 'subject' => 'Website is down', 'status' => 'open', 'priority' => 'high']);

        $this->as($this->admin)->postJson("/v1/tickets/{$ticket->id}/task", ['assigned_to' => $priya->id])->assertCreated()
            ->assertJsonPath('title', 'Ticket: Website is down')->assertJsonPath('priority', 'urgent')->assertJsonPath('ticket.id', $ticket->id);
        $this->assertSame($priya->id, $ticket->fresh()->assigned_to);
    }

    // ---------- Attendance ----------

    private function office(): void
    {
        Setting::put('attendance', ['office_lat' => 10.0159, 'office_lng' => 76.3419, 'radius_m' => 200, 'late_after' => '10:00', 'half_day_hours' => 4, 'require_location' => true]);
    }

    public function test_check_in_at_office_then_check_out(): void
    {
        $this->office();
        $priya = $this->staff();
        $this->as($priya)->postJson('/v1/attendance/check-in', [])->assertStatus(422)->assertJsonValidationErrors('location');
        $this->postJson('/v1/attendance/check-in', ['lat' => 10.0162, 'lng' => 76.3421, 'accuracy' => 20])->assertCreated()
            ->assertJsonPath('mode', 'office')->assertJsonPath('late', false);
        $this->postJson('/v1/attendance/check-in', ['lat' => 10.0162, 'lng' => 76.3421])->assertStatus(422);

        Date::setTestNow('2026-11-09 18:45:00');
        $this->postJson('/v1/attendance/check-out', ['lat' => 10.0162, 'lng' => 76.3421])->assertOk()->assertJsonPath('minutes', 555);
        $this->getJson('/v1/attendance/me')->assertJsonPath('today.minutes', 555);
    }

    public function test_far_from_office_must_pick_field_or_wfh_and_late_is_marked(): void
    {
        $this->office();
        Date::setTestNow('2026-11-09 10:20:00');
        $priya = $this->staff();
        $far = ['lat' => 9.9312, 'lng' => 76.2673]; // ~12 km away
        $this->as($priya)->postJson('/v1/attendance/check-in', $far + ['mode' => 'office'])->assertStatus(422)->assertJsonValidationErrors('mode');
        $this->postJson('/v1/attendance/check-in', $far)->assertStatus(422)->assertJsonValidationErrors('mode'); // asked to choose
        $this->postJson('/v1/attendance/check-in', $far + ['mode' => 'field', 'note' => 'Client visit'])->assertCreated()
            ->assertJsonPath('mode', 'field')->assertJsonPath('late', true);
    }

    public function test_register_and_timesheet_with_leave_and_corrections(): void
    {
        $this->office();
        $priya = $this->staff();
        $arjun = $this->staff('Arjun');
        $emp = Employee::create(['emp_code' => 'DFP002', 'name' => 'Arjun', 'user_id' => $arjun->id]);
        LeaveRequest::create(['employee_id' => $emp->id, 'leave_type_id' => LeaveType::where('code', 'CL')->value('id'), 'from_date' => '2026-11-09', 'to_date' => '2026-11-09', 'days' => 1, 'status' => 'approved']);
        $this->as($priya)->postJson('/v1/attendance/check-in', ['lat' => 10.0162, 'lng' => 76.3421])->assertCreated();

        $this->getJson('/v1/attendance/register')->assertForbidden();
        $r = $this->as($this->admin)->getJson('/v1/attendance/register')->assertOk();
        $by = collect($r->json('rows'))->keyBy('user.name');
        $this->assertSame('present', $by['Priya']['status']);
        $this->assertSame('leave', $by['Arjun']['status']);
        $this->assertSame('absent', $by['Amit']['status']);

        // Admin fixes Friday for Priya (forgot to check in).
        $this->postJson('/v1/attendance', ['user_id' => $priya->id, 'date' => '2026-11-06', 'check_in' => '09:40', 'check_out' => '18:10', 'mode' => 'field'])->assertOk();
        $t = $this->getJson("/v1/attendance/timesheet?user_id={$priya->id}&year=2026&month=11")->assertOk();
        $days = collect($t->json('days'))->keyBy('date');
        $this->assertSame('present', $days['2026-11-06']['status']);
        $this->assertSame('weekly_off', $days['2026-11-08']['status']);
        $this->assertSame(2, $t->json('totals.present'));

        // Employees see only their own.
        $this->as($priya)->getJson("/v1/attendance/timesheet?user_id={$arjun->id}&year=2026&month=11")->assertForbidden();
        $this->getJson('/v1/attendance/timesheet?year=2026&month=11')->assertOk();

        $csv = $this->as($this->admin)->get('/v1/attendance/export?year=2026&month=11')->assertOk()->streamedContent();
        $this->assertStringContainsString('Priya,2026-11-06,present,09:40,18:10,8.5', $csv);
    }

    public function test_forgotten_check_out_is_closed_overnight_and_clients_are_blocked(): void
    {
        $priya = $this->staff();
        $this->as($priya)->postJson('/v1/attendance/check-in', ['mode' => 'wfh'])->assertCreated();
        Date::setTestNow('2026-11-10 00:20:00');
        app(\App\Services\AttendanceService::class)->closeOpenDays();
        $this->assertTrue(Attendance::first()->auto_closed);

        $client = User::factory()->client()->create(['client_id' => Client::create(['company_name' => 'X'])->id]);
        $this->as($client)->getJson('/v1/tasks')->assertForbidden();
        $this->postJson('/v1/attendance/check-in', ['mode' => 'wfh'])->assertForbidden();
    }
}
