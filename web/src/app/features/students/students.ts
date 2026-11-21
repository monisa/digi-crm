import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth/auth.service';
import { readErrors } from '../../core/http-errors';
import { DayNote, Person, StudentDay, StudentRow, StudentService, WaState, saveBlob } from '../../core/student.service';
import { WaTemplate, WhatsAppService } from '../../core/whatsapp.service';
import { Dialog } from '../../shared/dialog';
import { Sheet } from '../../shared/sheet';
import { Toast } from '../../shared/toast.service';

type Tab = 'review' | 'notes' | 'students';

/** Managers: approve students' days, upload notes per date, add students. */
@Component({
  selector: 'app-students',
  imports: [FormsModule, RouterLink, Sheet],
  template: `
    <div class="page-head">
      <div><h1>Students</h1><p>Approve students’ attendance, upload each day’s notes, and add students.</p></div>
      @if (tab() === 'notes' && canEdit()) { <div class="actions"><button class="btn" (click)="startNote()">+ Add day notes</button></div> }
      @if (tab() === 'students' && canEdit()) { <div class="actions"><button class="btn" (click)="adding.set(true)">+ Add student</button></div> }
    </div>

    <div class="tabs2" role="tablist">
      <button role="tab" [class.on]="tab() === 'review'" (click)="go('review')">To review@if (pending()) { <i class="n">{{ pending() }}</i> }</button>
      <button role="tab" [class.on]="tab() === 'notes'" (click)="go('notes')">Day notes</button>
      @if (canEdit()) { <button role="tab" [class.on]="tab() === 'students'" (click)="go('students')">Students &amp; settings</button> }
    </div>

    @switch (tab()) {
      @case ('review') {
        <div class="filters">
          <div class="seg2">
            @for (s of [['pending', 'Waiting'], ['approved', 'Approved'], ['rejected', 'Not approved'], ['all', 'All']]; track s[0]) {
              <button [class.on]="status === s[0]" (click)="status = s[0]; loadReq()">{{ s[1] }}</button>
            }
          </div>
          @if (seesAll()) {
            <select class="select-sm" [(ngModel)]="scope" (ngModelChange)="loadReq()" aria-label="Whose"><option value="mine">Sent to me</option><option value="all">Everyone’s</option></select>
          }
        </div>
        @if (wa() && !wa()!.ready && canEdit()) {
          <div class="tip">📵 Managers get these by email and in the bell. To send them on <b>WhatsApp</b> too, @if (!wa()!.connected) { connect WhatsApp Business in <a routerLink="/settings" [queryParams]="{ tab: 'integrations' }">Settings → Integrations</a>, then }choose a template in <button class="linkish" (click)="go('students')">Students &amp; settings</button>.</div>
        }
        <div class="card list">
          @for (r of reqs(); track r.id) {
            <div class="rq">
              <span class="av">{{ initials(r.student?.name ?? '?') }}</span>
              <div class="grow">
                <b>{{ r.student?.name }} · {{ nice(r.date) }}</b>
                <small>@if (scope === 'all') { To {{ r.reviewer?.name }} · }Sent {{ when(r.submitted_at) }}@if (r.note) { · “{{ r.note }}” }</small>
                @if (r.status !== 'pending') { <small class="dec" [attr.data-s]="r.status">{{ r.status === 'approved' ? '✓ Approved' : '✕ Not approved' }}@if (r.review_comment) { · “{{ r.review_comment }}” }</small> }
                @if (r.whatsapp && r.whatsapp !== 'sent' && r.status === 'pending' && canEdit()) { <small class="wa" [title]="r.whatsapp_error ?? ''">WhatsApp not sent: {{ r.whatsapp_error }}</small> }
              </div>
              @if (r.status === 'pending' && (r.reviewer?.id === me() || isAdmin())) {
                <div class="acts"><button class="btn-ghost sm" (click)="reject(r)">Reject</button><button class="btn sm" (click)="approve(r)">Approve</button></div>
              }
            </div>
          } @empty { <div class="empty-box">@if (loading()) { Loading… } @else if (status === 'pending') { <b>Nothing waiting</b>Requests sent to you appear here. } @else { <b>Nothing here</b> }</div> }
        </div>
      }

      @case ('notes') {
        <div class="filters">
          <button class="icon-btn" (click)="shiftNotes(-1)" aria-label="Previous month">‹</button>
          <b class="mlabel">{{ notesLabel() }}</b>
          <button class="icon-btn" (click)="shiftNotes(1)" aria-label="Next month">›</button>
        </div>
        <div class="card list">
          @for (g of noteGroups(); track g.date) {
            <div class="ng"><b class="nd">{{ nice(g.date) }}</b>
              @for (n of g.notes; track n.id) {
                <div class="nrow">
                  <span class="ic">{{ n.file ? '📄' : '📝' }}</span>
                  <div class="grow"><b>{{ n.title }}</b><small>@if (n.file) { {{ n.file.name }} · {{ size(n.file.size) }} · }@if (n.body) { {{ n.body.length }} characters of text · }by {{ n.by }}</small></div>
                  @if (n.file) { <button class="btn-ghost sm" (click)="download(n)">⬇</button> }
                  @if (canEdit()) { <button class="btn-ghost sm danger" (click)="removeNote(n)" aria-label="Delete">✕</button> }
                </div>
              }
            </div>
          } @empty { <div class="empty-box"><b>No notes this month</b>@if (canEdit()) { Press “Add day notes” to upload the notes for a date. } </div> }
        </div>
      }

      @case ('students') {
        <div class="card list">
          @for (s of students(); track s.id) {
            <div class="rq" [class.off]="s.status !== 'active'">
              <span class="av">{{ initials(s.name) }}</span>
              <div class="grow"><b>{{ s.name }}@if (s.status !== 'active') { <span class="badge">Inactive</span> }</b>
                <small>{{ s.email }}@if (s.phone) { · {{ s.phone }} } · {{ s.approved_days }} day(s) approved@if (s.pending) { · {{ s.pending }} waiting } · {{ s.last_login_at ? 'last login ' + when(s.last_login_at) : 'not logged in yet' }}</small></div>
              <button class="btn-ghost sm" (click)="toggle(s)">{{ s.status === 'active' ? 'Deactivate' : 'Activate' }}</button>
            </div>
          } @empty { <div class="empty-box"><b>No students yet</b>Press “Add student”; they get an email to set their password.</div> }
        </div>

        <div class="card set">
          <h3>Managers students can choose</h3>
          <p class="hint">Admins, plus anyone with <b>Students → Approve</b> in <a routerLink="/settings" [queryParams]="{ tab: 'users' }">Settings → Users &amp; access</a>.</p>
          <div class="chips">@for (r of reviewers(); track r.id) { <span class="chip">{{ r.name }} @if (r.whatsapp) { <i title="Has a mobile number">📱</i> } @else { <i title="No mobile number: WhatsApp can’t reach them">⚠️</i> }</span> }</div>

          <h3 class="mt">WhatsApp alert to the manager</h3>
          @if (!wa()?.connected) {
            <p class="hint">Connect WhatsApp Business first in <a routerLink="/settings" [queryParams]="{ tab: 'integrations' }">Settings → Integrations</a>. Until then managers get an email and a bell notification.</p>
          } @else {
            <p class="hint">WhatsApp only lets a business message someone first with an <b>approved template</b>. Create one in WhatsApp Manager (category <i>Utility</i>), for example:</p>
            <pre class="tpl">{{ tplExample }}</pre>
            <p class="hint">{{ tplLegend }} Fewer values are fine. Then choose it here:</p>
            <div class="trow">
              <select class="select-sm" [(ngModel)]="tpl" aria-label="WhatsApp template">
                <option [ngValue]="null">Don’t send WhatsApp</option>
                @for (t of templates(); track t.name) { <option [ngValue]="t.name">{{ t.name }} ({{ t.language }})</option> }
              </select>
              <button class="btn-ghost sm" (click)="loadTemplates(true)" title="Refresh from Meta">↻</button>
              <button class="btn sm" (click)="saveTpl()">Save</button>
            </div>
            @if (wa()?.ready) { <p class="good">✓ Managers get a WhatsApp message on their mobile when a student sends a day.</p> }
          }
        </div>
      }
    }

    @if (noteForm(); as f) {
      <app-sheet title="Add day notes" subtitle="Students approved for that day can download them" width="520px" (closed)="noteForm.set(null)">
        <form (ngSubmit)="saveNote()">
          <div class="row-2">
            <div class="field" [class.invalid]="nerr()['date']"><label for="n-d">Date <span class="req">*</span></label><input id="n-d" name="d" type="date" [(ngModel)]="f.date" />
              @if (nerr()['date']) { <div class="err">{{ nerr()['date'] }}</div> }</div>
            <div class="field" [class.invalid]="nerr()['title']"><label for="n-t">Title <span class="req">*</span></label><input id="n-t" name="t" [(ngModel)]="f.title" placeholder="e.g. SEO basics: keywords" />
              @if (nerr()['title']) { <div class="err">{{ nerr()['title'] }}</div> }</div>
          </div>
          <div class="field" [class.invalid]="nerr()['body']"><label for="n-b">Notes (text)</label><textarea id="n-b" name="b" rows="7" [(ngModel)]="f.body" placeholder="Type or paste the day’s notes, or attach a file below."></textarea>
            @if (nerr()['body']) { <div class="err">{{ nerr()['body'] }}</div> }</div>
          <label class="attach"><input type="file" (change)="pick($event)" accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.png,.jpg,.jpeg,.webp,.zip" />📎 {{ file()?.name ?? 'Attach a file (PDF, Word, PowerPoint…, up to 20 MB)' }}</label>
          @if (nerr()['file']) { <div class="err">{{ nerr()['file'] }}</div> }
          <div class="form-actions"><button type="button" class="btn-ghost" (click)="noteForm.set(null)">Cancel</button><button class="btn" type="submit" [disabled]="busy()">Upload</button></div>
        </form>
      </app-sheet>
    }

    @if (adding()) {
      <app-sheet title="Add student" subtitle="They get an email to set their password" width="460px" (closed)="adding.set(false)">
        <form (ngSubmit)="addStudent()">
          <div class="field" [class.invalid]="serr()['name']"><label for="a-n">Name <span class="req">*</span></label><input id="a-n" name="n" [(ngModel)]="ns.name" />
            @if (serr()['name']) { <div class="err">{{ serr()['name'] }}</div> }</div>
          <div class="field" [class.invalid]="serr()['email']"><label for="a-e">Email (login) <span class="req">*</span></label><input id="a-e" name="e" type="email" [(ngModel)]="ns.email" />
            @if (serr()['email']) { <div class="err">{{ serr()['email'] }}</div> }</div>
          <div class="field"><label for="a-p">Mobile</label><input id="a-p" name="p" type="tel" [(ngModel)]="ns.phone" /></div>
          <div class="form-actions"><button type="button" class="btn-ghost" (click)="adding.set(false)">Cancel</button><button class="btn" type="submit" [disabled]="busy()">Add &amp; send invite</button></div>
        </form>
      </app-sheet>
    }
  `,
  styles: `
    .tabs2 { display: flex; gap: 4px; border-bottom: 1px solid var(--line); margin-bottom: 16px; overflow-x: auto; }
    .tabs2 button { border: 0; background: none; padding: 10px 14px; font: inherit; font-weight: 600; color: var(--ink-3); cursor: pointer; border-bottom: 2px solid transparent; white-space: nowrap; }
    .tabs2 button.on { color: var(--ink); border-bottom-color: var(--brand); }
    .n { font-style: normal; font-size: 11px; font-weight: 700; margin-left: 6px; padding: 1px 7px; border-radius: 99px; background: var(--x-fdecec); color: var(--x-b42318); }
    .filters { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 12px; } .mlabel { min-width: 140px; text-align: center; }
    .tip { background: var(--x-fef4e6); color: var(--x-b54708); border-radius: 12px; padding: 10px 14px; font-size: 13.5px; margin-bottom: 12px; } .tip a, .linkish { color: inherit; font-weight: 700; text-decoration: underline; }
    .linkish { background: none; border: 0; font: inherit; cursor: pointer; padding: 0; }
    .list { padding: 8px; }
    .rq { display: flex; gap: 12px; align-items: center; padding: 12px 8px; border-bottom: 1px solid var(--line); animation: rise 0.3s var(--ease) both; }
    .rq:last-child { border-bottom: 0; } .rq.off { opacity: 0.6; }
    .av { width: 40px; height: 40px; border-radius: 50%; display: grid; place-items: center; flex: none; font-weight: 700; font-size: 13px; color: #fff; background: linear-gradient(135deg, #2f6fde, #5b8def); }
    .grow { flex: 1; min-width: 0; } .grow b { display: block; font-size: 14.5px; } .grow small { display: block; color: var(--ink-3); font-size: 12.5px; margin-top: 1px; }
    .dec[data-s='approved'] { color: var(--x-11803b) !important; font-weight: 600; } .dec[data-s='rejected'] { color: var(--x-b42318) !important; font-weight: 600; }
    .wa { color: var(--x-b54708) !important; }
    .acts { display: flex; gap: 6px; flex: none; } .sm { padding: 7px 12px; font-size: 13px; }
    .danger { color: #c0262d; }
    .ng { padding: 8px 6px 4px; border-bottom: 1px solid var(--line); } .ng:last-child { border-bottom: 0; }
    .nd { display: block; font-size: 12.5px; text-transform: uppercase; letter-spacing: 0.04em; color: var(--ink-3); margin-bottom: 4px; }
    .nrow { display: flex; gap: 10px; align-items: center; padding: 8px 0; } .ic { font-size: 20px; }
    .set { margin-top: 16px; } .set h3 { font-size: 15px; margin-bottom: 4px; } .mt { margin-top: 20px; }
    .hint { color: var(--ink-3); font-size: 13px; line-height: 1.55; } .hint a { color: var(--brand-deep); font-weight: 600; }
    .chips { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; } .chip { border: 1px solid var(--line); border-radius: 99px; padding: 5px 12px; font-size: 13px; } .chip i { font-style: normal; }
    .tpl { background: var(--bg); border-radius: 10px; padding: 10px 12px; font-size: 13px; white-space: pre-wrap; margin: 8px 0; }
    .trow { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 8px; } .trow select { min-width: 240px; }
    .good { color: var(--x-11803b); font-weight: 600; font-size: 13.5px; margin-top: 10px; }
    .attach { position: relative; display: inline-block; margin-top: 14px; border: 1px dashed var(--line); border-radius: 10px; padding: 10px 14px; font-size: 13px; cursor: pointer; }
    .attach input { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
    .err { color: var(--crit); font-size: 12.5px; margin-top: 6px; }
    @media (max-width: 700px) { .rq { flex-wrap: wrap; } .acts { width: 100%; justify-content: flex-end; } }
    @media (prefers-reduced-motion: reduce) { .rq { animation: none; } }
  `,
})
export class Students {
  private api = inject(StudentService);
  private waApi = inject(WhatsAppService);
  private toast = inject(Toast);
  private dialog = inject(Dialog);
  private auth = inject(AuthService);
  protected readonly me = computed(() => this.auth.user()?.id ?? 0);
  protected readonly isAdmin = computed(() => this.auth.user()?.role === 'admin');
  protected readonly canEdit = computed(() => this.auth.can('students.edit'));
  protected readonly tab = signal<Tab>('review');
  protected status = 'pending';
  protected scope = 'mine';
  protected readonly reqs = signal<StudentDay[]>([]);
  protected readonly pending = signal(0);
  protected readonly seesAll = signal(false);
  protected readonly wa = signal<WaState | null>(null);
  protected readonly loading = signal(true);
  // notes
  protected readonly notesMonth = signal(this.ym(new Date()));
  protected readonly notesList = signal<DayNote[]>([]);
  protected readonly noteForm = signal<{ date: string; title: string; body: string } | null>(null);
  protected readonly file = signal<File | null>(null);
  protected readonly nerr = signal<Record<string, string>>({});
  // students
  protected readonly students = signal<StudentRow[]>([]);
  protected readonly reviewers = signal<(Person & { whatsapp: boolean })[]>([]);
  protected readonly templates = signal<WaTemplate[]>([]);
  protected tpl: string | null = null;
  protected readonly tplExample = 'Hi {{1}}, {{2}} marked attendance for {{3}} and is waiting for your approval. Open the CRM: {{4}}';
  protected readonly tplLegend = '{{1}} = manager’s first name, {{2}} = student, {{3}} = date, {{4}} = link.';
  protected readonly adding = signal(false);
  protected ns = { name: '', email: '', phone: '' };
  protected readonly serr = signal<Record<string, string>>({});
  protected readonly busy = signal(false);

  constructor() {
    this.loadReq();
  }

  go(t: Tab): void {
    this.tab.set(t);
    if (t === 'review') this.loadReq();
    if (t === 'notes') this.loadNotes();
    if (t === 'students') this.loadStudents();
  }

  loadReq(): void {
    this.loading.set(true);
    this.api.requests(this.status, this.scope).subscribe((r) => {
      this.reqs.set(r.data);
      this.pending.set(r.counts.pending);
      this.seesAll.set(r.sees_all);
      this.wa.set(r.whatsapp);
      this.loading.set(false);
    });
  }

  approve(r: StudentDay): void {
    this.api.decide(r.id, 'approve').subscribe({
      next: () => {
        this.toast.show(`Approved. ${r.student?.name} can now download that day’s notes.`);
        this.loadReq();
      },
      error: (e) => this.toast.show(readErrors(e).message || 'Could not approve.'),
    });
  }

  async reject(r: StudentDay): Promise<void> {
    const why = await this.dialog.prompt({ title: `Not approve ${this.nice(r.date)}?`, label: 'Reason (the student sees this)', required: true, confirmText: 'Reject',
      suggestions: ['Not present that day', 'Left early', 'Wrong date'] });
    if (!why) return;
    this.api.decide(r.id, 'reject', why).subscribe({ next: () => this.loadReq(), error: (e) => this.toast.show(readErrors(e).message || 'Could not save.') });
  }

  // notes
  protected readonly notesLabel = computed(() => new Date(this.notesMonth() + '-01T00:00').toLocaleDateString('en-IN', { month: 'long', year: 'numeric' }));
  protected readonly noteGroups = computed(() => {
    const m = new Map<string, DayNote[]>();
    for (const n of this.notesList()) m.set(n.date, [...(m.get(n.date) ?? []), n]);
    return [...m.entries()].map(([date, notes]) => ({ date, notes }));
  });

  loadNotes(): void {
    this.api.noteList(this.notesMonth()).subscribe((r) => this.notesList.set(r.data));
  }

  shiftNotes(n: number): void {
    const [y, m] = this.notesMonth().split('-').map(Number);
    this.notesMonth.set(this.ym(new Date(y!, m! - 1 + n, 1)));
    this.loadNotes();
  }

  startNote(): void {
    this.file.set(null);
    this.nerr.set({});
    const t = new Date();
    this.noteForm.set({ date: `${this.ym(t)}-${String(t.getDate()).padStart(2, '0')}`, title: '', body: '' });
  }

  pick(e: Event): void {
    this.file.set((e.target as HTMLInputElement).files?.[0] ?? null);
  }

  saveNote(): void {
    const f = this.noteForm()!;
    const fd = new FormData();
    fd.append('date', f.date);
    fd.append('title', f.title.trim());
    if (f.body.trim()) fd.append('body', f.body.trim());
    if (this.file()) fd.append('file', this.file()!);
    this.busy.set(true);
    this.nerr.set({});
    this.api.addNote(fd).subscribe({
      next: (n) => {
        this.busy.set(false);
        this.noteForm.set(null);
        this.toast.show('Notes uploaded.');
        this.notesMonth.set(n.date.slice(0, 7));
        this.loadNotes();
      },
      error: (e) => {
        this.busy.set(false);
        const r = readErrors(e);
        this.nerr.set(r.fields);
        if (r.message && !Object.keys(r.fields).length) this.toast.show(r.message);
      },
    });
  }

  async removeNote(n: DayNote): Promise<void> {
    if (!(await this.dialog.confirm({ title: `Delete “${n.title}”?`, message: 'Students will no longer be able to download it.', confirmText: 'Delete', danger: true }))) return;
    this.api.deleteNote(n.id).subscribe(() => this.loadNotes());
  }

  download(n: DayNote): void {
    this.api.file(n.id).subscribe((b) => saveBlob(b, n.file!.name));
  }

  // students
  loadStudents(): void {
    this.api.students().subscribe((r) => {
      this.students.set(r.data);
      this.reviewers.set(r.reviewers);
      this.wa.set(r.whatsapp);
      this.tpl = r.settings.wa_template;
      if (r.whatsapp.connected && !this.templates().length) this.loadTemplates(false);
    });
  }

  loadTemplates(fresh: boolean): void {
    this.waApi.templates(fresh).subscribe({ next: (r) => this.templates.set(r.data), error: (e) => this.toast.show(readErrors(e).message || 'Could not load the WhatsApp templates.') });
  }

  saveTpl(): void {
    this.api.saveSettings(this.tpl).subscribe({
      next: () => {
        this.toast.show(this.tpl ? 'Managers will get WhatsApp alerts.' : 'WhatsApp alerts switched off.');
        this.loadStudents();
      },
      error: (e) => {
        const r = readErrors(e);
        this.toast.show(Object.values(r.fields)[0] || r.message || 'Could not save.');
      },
    });
  }

  addStudent(): void {
    this.busy.set(true);
    this.serr.set({});
    this.api.addStudent({ name: this.ns.name.trim(), email: this.ns.email.trim(), phone: this.ns.phone.trim() }).subscribe({
      next: (s) => {
        this.busy.set(false);
        this.adding.set(false);
        this.ns = { name: '', email: '', phone: '' };
        this.toast.show(`${s.name} added. An email with a password link was sent.`);
        this.loadStudents();
      },
      error: (e) => {
        this.busy.set(false);
        const r = readErrors(e);
        this.serr.set(r.fields);
        if (r.message && !Object.keys(r.fields).length) this.toast.show(r.message);
      },
    });
  }

  async toggle(s: StudentRow): Promise<void> {
    const off = s.status === 'active';
    if (off && !(await this.dialog.confirm({ title: `Deactivate ${s.name}?`, message: 'They are signed out and can’t log in until you activate them again. Their days and notes stay.', confirmText: 'Deactivate', danger: true }))) return;
    this.api.updateStudent(s.id, { status: off ? 'inactive' : 'active' }).subscribe(() => this.loadStudents());
  }

  initials(n: string): string {
    return n.split(/\s+/).filter(Boolean).map((w) => w[0]).slice(0, 2).join('').toUpperCase();
  }

  nice(d: string): string {
    return new Date(d + 'T00:00').toLocaleDateString('en-IN', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
  }

  when(iso: string): string {
    return new Date(iso).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' });
  }

  size(n: number): string {
    return n > 1048576 ? `${(n / 1048576).toFixed(1)} MB` : `${Math.ceil(n / 1024)} KB`;
  }

  private ym(d: Date): string {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
  }
}
