<?php

namespace App\Http\Controllers\Students;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\ClassNote;
use App\Models\Employee;
use App\Models\StudentAttendance;
use App\Models\User;
use App\Notifications\EmployeeInvite;
use App\Services\Notify;
use App\Services\WhatsApp;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Students: pick a date, send it to a mentor for approval (and tell them on WhatsApp from their
 * own phone); once approved they can download that day's class notes. Mentors approve, upload
 * notes and add students.
 */
class StudentController extends Controller
{
    /** How far back a student may mark attendance. */
    private const BACKDATE_DAYS = 60;

    public function __construct(private Notify $notify) {}

    // ---------- Student ----------

    /** A month: my requests, the reviewers I can choose, and which approved days have notes. */
    public function mine(Request $request): JsonResponse
    {
        $me = $this->student($request);
        $month = CarbonImmutable::parse(($request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? now()->format('Y-m')).'-01');
        $rows = StudentAttendance::with('reviewer:id,name')->where('student_id', $me->id)
            ->whereBetween('date', [$month->toDateString(), $month->endOfMonth()->toDateString().' 23:59:59'])->orderBy('date')->get();
        $approved = $rows->where('status', 'approved')->map(fn ($r) => $r->date->toDateString())->values();
        // How many notes each approved day has (only approved days are shown to the student).
        $notes = ClassNote::whereBetween('date', [$month->toDateString(), $month->endOfMonth()->toDateString().' 23:59:59'])->get(['id', 'date'])
            ->groupBy(fn ($n) => $n->date->toDateString())->filter(fn ($g, $d) => $approved->contains($d))->map->count();

        return response()->json([
            'month' => $month->format('Y-m'),
            'data' => $rows->map->toApiArray(),
            'notes' => $notes,
            // whatsapp = the mentor's mobile in international digits, for the "Message on WhatsApp" button.
            'reviewers' => $this->reviewers()->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'whatsapp' => $this->mobileOf($u)])->values(),
            'earliest' => today()->subDays(self::BACKDATE_DAYS)->toDateString(),
            'recent' => StudentAttendance::with('reviewer:id,name')->where('student_id', $me->id)->latest('date')->limit(10)->get()->map->toApiArray(),
        ]);
    }

    public function submit(Request $request): JsonResponse
    {
        $me = $this->student($request);
        $d = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:'.today()->subDays(self::BACKDATE_DAYS)->toDateString()],
            'reviewer_id' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'date.before_or_equal' => 'You can only mark today or an earlier day.',
            'date.after_or_equal' => 'You can mark up to '.self::BACKDATE_DAYS.' days back only.',
        ]);
        $reviewer = $this->reviewers()->firstWhere('id', (int) $d['reviewer_id']);
        if (! $reviewer) {
            throw ValidationException::withMessages(['reviewer_id' => 'Choose a mentor from the list.']);
        }
        $row = StudentAttendance::where('student_id', $me->id)->whereDate('date', $d['date'])->first();
        if ($row && $row->status !== 'rejected') {
            throw ValidationException::withMessages(['date' => $row->status === 'approved' ? 'This day is already approved.' : 'This day is already waiting for '.$row->reviewer?->name.'.']);
        }
        $row ??= new StudentAttendance(['student_id' => $me->id, 'date' => $d['date']]);
        $row->fill(['reviewer_id' => $reviewer->id, 'status' => 'pending', 'note' => $d['note'] ?? null, 'review_comment' => null, 'reviewed_at' => null])->save();

        $this->tellReviewer($row->load('student', 'reviewer'));

        return response()->json($row->fresh(['reviewer:id,name'])->toApiArray(), 201);
    }

    /** Take back a request that is still waiting. */
    public function cancel(Request $request, StudentAttendance $attendance): JsonResponse
    {
        $me = $this->student($request);
        abort_unless($attendance->student_id === $me->id, 404);
        abort_unless($attendance->status === 'pending', 422, 'Only a request that is still waiting can be taken back.');
        $attendance->delete();

        return response()->json(['deleted' => true]);
    }

    /** That day's notes, once the day is approved. */
    public function notes(Request $request, string $date): JsonResponse
    {
        $me = $this->student($request);
        $this->mustBeApproved($me, $date);

        return response()->json(['data' => ClassNote::with('uploader:id,name')->whereDate('date', $date)->orderBy('id')->get()->map->toApiArray()]);
    }

    public function download(Request $request, ClassNote $note): StreamedResponse
    {
        $user = $request->user();
        if ($user->isStudent()) {
            $this->mustBeApproved($user, $note->date->toDateString());
        } else {
            abort_unless($user->hasPermission('students.view'), 403);
        }
        abort_unless($note->file_path && Storage::disk('local')->exists($note->file_path), 404);

        return Storage::disk('local')->download($note->file_path, $note->file_name, ['Content-Type' => 'application/octet-stream']);
    }

    // ---------- Mentors ----------

    /** Requests to review: mine (everyone's for admins / Students-edit), by status. */
    public function requests(Request $request): JsonResponse
    {
        $user = $request->user();
        $f = $request->validate(['status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'all'])], 'scope' => ['nullable', Rule::in(['mine', 'all'])]]);
        $seesAll = $user->isAdmin() || $user->hasPermission('students.edit');
        $scopeAll = $seesAll && ($f['scope'] ?? 'mine') === 'all';
        $base = StudentAttendance::with(['student:id,name', 'reviewer:id,name'])->when(! $scopeAll, fn ($q) => $q->where('reviewer_id', $user->id));
        $status = $f['status'] ?? 'pending';

        return response()->json([
            'data' => (clone $base)->when($status !== 'all', fn ($q) => $q->where('status', $status))
                ->orderBy($status === 'pending' ? 'date' : 'updated_at', $status === 'pending' ? 'asc' : 'desc')->limit(300)->get()->map->toApiArray(),
            'counts' => ['pending' => (clone $base)->where('status', 'pending')->count()],
            'sees_all' => $seesAll,
            'can_edit' => $user->hasPermission('students.edit'),
        ]);
    }

    public function decide(Request $request, StudentAttendance $attendance, string $decision): JsonResponse
    {
        $user = $request->user();
        abort_unless(in_array($decision, ['approve', 'reject'], true), 404);
        abort_unless($attendance->reviewer_id === $user->id || $user->isAdmin(), 403, 'This request was sent to '.$attendance->reviewer?->name.'.');
        abort_unless($attendance->status === 'pending', 422, 'This request was already '.$attendance->status.'.');
        $d = $request->validate(['comment' => [$decision === 'reject' ? 'required' : 'nullable', 'string', 'max:500']], ['comment.required' => 'Say why, so the student knows what to do.']);

        $attendance->update(['status' => $decision === 'approve' ? 'approved' : 'rejected', 'review_comment' => $d['comment'] ?? null, 'reviewed_at' => now(), 'reviewer_id' => $attendance->reviewer_id ?? $user->id]);
        $date = $attendance->date->format('D, j M');
        $hasNotes = ClassNote::whereDate('date', $attendance->date)->exists();
        $this->notify->send($attendance->student, 'student_decided',
            $decision === 'approve' ? "Attendance approved: $date" : "Attendance not approved: $date",
            $decision === 'approve' ? $user->name.' approved it.'.($hasNotes ? ' You can now download that day’s notes.' : '') : $user->name.': '.$d['comment'],
            '/student?date='.$attendance->date->toDateString(), true, $user);

        return response()->json($attendance->fresh(['student:id,name', 'reviewer:id,name'])->toApiArray());
    }

    // Day notes

    public function noteList(Request $request): JsonResponse
    {
        $month = CarbonImmutable::parse(($request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? now()->format('Y-m')).'-01');

        return response()->json(['data' => ClassNote::with('uploader:id,name')->whereBetween('date', [$month->toDateString(), $month->endOfMonth()->toDateString().' 23:59:59'])
            ->orderByDesc('date')->orderBy('id')->get()->map->toApiArray()]);
    }

    public function addNote(Request $request): JsonResponse
    {
        $d = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'title' => ['required', 'string', 'max:160'],
            'body' => ['nullable', 'string', 'max:20000', 'required_without:file'],
            'file' => ['nullable', 'file', 'max:20480', 'mimes:pdf,doc,docx,ppt,pptx,xls,xlsx,txt,png,jpg,jpeg,webp,zip'],
        ], ['body.required_without' => 'Write the notes or attach a file.', 'file.mimes' => 'Attach a PDF, Word, PowerPoint, Excel, image, text or ZIP file.']);
        $file = $request->file('file');
        $note = ClassNote::create([
            'date' => $d['date'], 'title' => $d['title'], 'body' => $d['body'] ?? null, 'uploaded_by' => $request->user()->id,
            'file_path' => $file?->store('class-notes', 'local'), 'file_name' => $file ? mb_substr($file->getClientOriginalName(), 0, 250) : null,
            'file_mime' => $file?->getClientMimeType(), 'file_size' => $file?->getSize(),
        ]);
        ActivityLog::record('created', 'Day notes '.$note->date->format('j M').': '.$note->title, $note);

        return response()->json($note->load('uploader:id,name')->toApiArray(), 201);
    }

    public function deleteNote(ClassNote $note): JsonResponse
    {
        if ($note->file_path) {
            Storage::disk('local')->delete($note->file_path);
        }
        ActivityLog::record('deleted', 'Day notes '.$note->date->format('j M').': '.$note->title, $note);
        $note->delete();

        return response()->json(['deleted' => true]);
    }

    // Students and settings

    public function students(): JsonResponse
    {
        $stats = StudentAttendance::selectRaw("student_id, SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) approved, SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) pending")->groupBy('student_id')->get()->keyBy('student_id');

        return response()->json([
            'data' => User::where('role', User::ROLE_STUDENT)->orderBy('name')->get(['id', 'name', 'email', 'phone', 'status', 'last_login_at'])
                ->map(fn (User $u) => [
                    'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'phone' => $u->phone, 'status' => $u->status,
                    'last_login_at' => $u->last_login_at?->toIso8601String(),
                    'approved_days' => (int) ($stats[$u->id]->approved ?? 0), 'pending' => (int) ($stats[$u->id]->pending ?? 0),
                ]),
            'reviewers' => $this->reviewers()->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'whatsapp' => (bool) $this->mobileOf($u)])->values(),
        ]);
    }

    public function addStudent(Request $request): JsonResponse
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:30'],
        ], ['email.unique' => 'Someone already has a login with this email.']);
        $user = User::create([
            'name' => $d['name'], 'email' => Str::lower($d['email']), 'phone' => $d['phone'] ?? null,
            'password' => Str::password(32), 'role' => User::ROLE_STUDENT, 'status' => User::STATUS_ACTIVE,
        ]);
        $user->notify(new EmployeeInvite(Password::createToken($user)));

        return response()->json(['id' => $user->id, 'name' => $user->name], 201);
    }

    public function updateStudent(Request $request, User $student): JsonResponse
    {
        abort_unless($student->isStudent(), 404);
        $d = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'status' => ['sometimes', Rule::in([User::STATUS_ACTIVE, User::STATUS_INACTIVE])],
        ]);
        $student->update($d);
        if (($d['status'] ?? null) === User::STATUS_INACTIVE) {
            $student->tokens()->delete(); // signed out everywhere
        }

        return response()->json(['ok' => true]);
    }


    // ---------- helpers ----------

    private function student(Request $request): User
    {
        $u = $request->user();
        abort_unless($u->isStudent(), 403, 'This page is for students.');

        return $u;
    }

    /** Mentors a student can send a day to: active staff with Students → approve (admins always). */
    private function reviewers()
    {
        return User::whereIn('role', [User::ROLE_ADMIN, User::ROLE_EMPLOYEE])->where('status', User::STATUS_ACTIVE)->orderBy('name')
            ->get(['id', 'name', 'email', 'phone', 'role'])->filter(fn (User $u) => $u->hasPermission('students.approve'))->values();
    }

    private function mustBeApproved(User $student, string $date): void
    {
        abort_unless(StudentAttendance::where('student_id', $student->id)->whereDate('date', $date)->where('status', 'approved')->exists(), 403,
            'The notes open once your mentor approves your attendance for that day.');
    }

    /** The mentor's WhatsApp number: their login's mobile, else their employee record's. */
    private function mobileOf(User $u): ?string
    {
        return WhatsApp::phone($u->phone) ?? WhatsApp::phone(Employee::where('user_id', $u->id)->value('mobile'));
    }



    /** The mentor gets a bell + email; the student messages them on WhatsApp from their own phone. */
    private function tellReviewer(StudentAttendance $row): void
    {
        $date = $row->date->format('D, j M Y');
        $this->notify->send($row->reviewer, 'student_review', $row->student->name.' marked attendance for '.$date,
            ($row->note ? '“'.$row->note.'” ' : '').'Please approve or reject it in the CRM.', '/students', true, $row->student);
    }
}
