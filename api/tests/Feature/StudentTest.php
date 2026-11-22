<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\StudentAttendance;
use App\Models\User;
use App\Services\WhatsApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private User $admin;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow('2026-11-20 11:00:00');
        Storage::fake('local');
        $this->admin = User::factory()->admin()->create(['name' => 'Amit Naidu', 'phone' => '+91 90000 11111']);
        // An employee who may review students; WhatsApp number only on their employee record.
        $this->manager = User::factory()->create(['name' => 'Priya Raj', 'role' => 'employee', 'status' => 'active', 'phone' => null]);
        Employee::create(['emp_code' => 'DFP9', 'name' => 'Priya Raj', 'user_id' => $this->manager->id, 'mobile' => '98765 43210']);
        $this->as($this->admin)->putJson("/v1/admin/users/{$this->manager->id}/permissions", ['overrides' => ['students.view' => true, 'students.approve' => true]])->assertOk();
    }

    private function as(User $u): static
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u);

        return $this;
    }

    private function addStudent(): User
    {
        $id = $this->as($this->admin)->postJson('/v1/students', ['name' => 'Anu Thomas', 'email' => 'anu@student.test', 'phone' => '9000022222'])->assertCreated()->json('id');

        return User::find($id);
    }


    public function test_a_student_only_reaches_their_own_page(): void
    {
        $s = $this->addStudent();
        $this->as($s)->getJson('/v1/student/attendance')->assertOk()
            ->assertJsonPath('reviewers.0.name', 'Amit Naidu')->assertJsonPath('reviewers.1.name', 'Priya Raj');
        foreach (['/v1/leads', '/v1/clients', '/v1/tasks', '/v1/chat/conversations', '/v1/whatsapp/inbox', '/v1/students/requests', '/v1/attendance/me', '/v1/dashboard/me', '/v1/dashboard/overview'] as $url) {
            $this->getJson($url)->assertForbidden();
        }
        $this->as($this->manager)->getJson('/v1/student/attendance')->assertForbidden(); // staff don't use the student page
    }

    public function test_submit_to_a_mentor_approve_and_download_notes(): void
    {
        Http::preventStrayRequests(); // nothing is sent to WhatsApp by the CRM
        $s = $this->addStudent();

        // The student gets each mentor's WhatsApp number for the "Message on WhatsApp" button.
        $this->as($s)->getJson('/v1/student/attendance')->assertJsonPath('reviewers.1.name', 'Priya Raj')->assertJsonPath('reviewers.1.whatsapp', '919876543210')
            ->assertJsonPath('reviewers.0.whatsapp', '919000011111');

        // Manager uploads notes for the 18th.
        $this->as($this->admin)->post('/v1/students/notes', ['date' => '2026-11-18', 'title' => 'SEO basics', 'body' => 'Keywords, titles, meta.',
            'file' => UploadedFile::fake()->create('seo-basics.pdf', 120, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated();
        $note = \App\Models\ClassNote::first();

        // Student marks the 18th and chooses Priya; future days are refused.
        $this->as($s)->postJson('/v1/student/attendance', ['date' => '2026-11-25', 'reviewer_id' => $this->manager->id])->assertJsonValidationErrors('date');
        $id = $this->postJson('/v1/student/attendance', ['date' => '2026-11-18', 'reviewer_id' => $this->manager->id, 'note' => 'Attended the full class'])
            ->assertCreated()->assertJsonPath('reviewer.name', 'Priya Raj')->json('id');
        Http::assertNothingSent();
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->manager->id, 'data->type' => 'student_review']);
        $this->postJson('/v1/student/attendance', ['date' => '2026-11-18', 'reviewer_id' => $this->manager->id])->assertJsonValidationErrors('date'); // already waiting

        // Notes stay closed until approved.
        $this->getJson('/v1/student/notes/2026-11-18')->assertForbidden();
        $this->get("/v1/class-notes/{$note->id}/file")->assertForbidden();

        // Priya sees it in her list and approves; Amit (admin) could too, a non-reviewer employee cannot.
        $this->as($this->manager)->getJson('/v1/students/requests')->assertJsonCount(1, 'data')->assertJsonPath('data.0.student.name', 'Anu Thomas');
        $other = User::factory()->create(['role' => 'employee', 'status' => 'active']);
        $this->as($other)->postJson("/v1/students/requests/$id/approve")->assertForbidden();
        $this->as($this->manager)->postJson("/v1/students/requests/$id/approve")->assertOk()->assertJsonPath('status', 'approved');
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $s->id, 'data->type' => 'student_decided']);

        // Now the student can read and download the day's notes.
        $this->as($s)->getJson('/v1/student/notes/2026-11-18')->assertOk()->assertJsonPath('data.0.title', 'SEO basics')->assertJsonPath('data.0.file.name', 'seo-basics.pdf');
        $this->get("/v1/class-notes/{$note->id}/file")->assertOk()->assertDownload('seo-basics.pdf');
        $this->getJson('/v1/student/attendance?month=2026-11')->assertJsonPath('notes.2026-11-18', 1);
    }

    public function test_rejected_days_can_be_sent_again(): void
    {
        $s = $this->addStudent();
        $id = $this->as($s)->postJson('/v1/student/attendance', ['date' => '2026-11-19', 'reviewer_id' => $this->manager->id])->assertCreated()->json('id');
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->manager->id, 'data->type' => 'student_review']);
        $this->as($this->manager)->postJson("/v1/students/requests/$id/reject")->assertJsonValidationErrors('comment');
        $this->postJson("/v1/students/requests/$id/reject", ['comment' => 'You left at 11 am'])->assertOk();
        $this->as($s)->postJson('/v1/student/attendance', ['date' => '2026-11-19', 'reviewer_id' => $this->admin->id])->assertCreated()->assertJsonPath('status', 'pending');
        $this->assertSame(1, StudentAttendance::count());

        // Deactivated students are signed out and can't log in.
        $this->as($this->admin)->patchJson("/v1/students/{$s->id}", ['status' => 'inactive'])->assertOk();
        $this->assertSame('inactive', $s->fresh()->status);
    }

    public function test_new_students_get_an_invite_email(): void
    {
        Notification::fake();
        $id = $this->as($this->admin)->postJson('/v1/students', ['name' => 'Ravi', 'email' => 'ravi@student.test'])->assertCreated()->json('id');
        Notification::assertSentTo(User::find($id), \App\Notifications\EmployeeInvite::class);
        $this->postJson('/v1/students', ['name' => 'Ravi 2', 'email' => 'ravi@student.test'])->assertJsonValidationErrors('email');
        $this->as($this->manager)->postJson('/v1/students', ['name' => 'X', 'email' => 'x@student.test'])->assertForbidden(); // needs Students → edit
    }
}
