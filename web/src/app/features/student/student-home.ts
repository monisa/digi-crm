import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute } from '@angular/router';
import { AuthService } from '../../core/auth/auth.service';
import { readErrors } from '../../core/http-errors';
import { DayNote, MyMonth, StudentDay, StudentService, saveBlob } from '../../core/student.service';
import { Dialog } from '../../shared/dialog';
import { Sheet } from '../../shared/sheet';
import { Toast } from '../../shared/toast.service';

interface Cell { date: string; day: number; inMonth: boolean; future: boolean; tooOld: boolean; today: boolean; sunday: boolean; rec: StudentDay | null; notes: number }

/** Student: a calendar of my days. Pick a day, send it to a mentor (and tell them on WhatsApp), then download that day's notes once approved. */
@Component({
  selector: 'app-student-home',
  imports: [FormsModule, Sheet],
  template: `
    <div class="page-head">
      <div><h1>Attendance</h1><p>Hi {{ first() }}! Tap a day you attended, choose your mentor and send it. Once it’s approved you can download that day’s notes.</p></div>
    </div>

    <div class="card cal">
      <div class="ch">
        <button class="icon-btn" (click)="shift(-1)" aria-label="Previous month">‹</button>
        <b>{{ monthLabel() }}</b>
        <button class="icon-btn" (click)="shift(1)" [disabled]="isCurrentMonth()" aria-label="Next month">›</button>
      </div>
      <div class="grid wd">@for (d of ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']; track d) { <span>{{ d }}</span> }</div>
      <div class="grid">
        @for (c of cells(); track c.date) {
          <button class="day" [class.out]="!c.inMonth" [class.today]="c.today" [class.sun]="c.sunday" [attr.data-s]="c.rec?.status ?? null"
            [disabled]="!c.inMonth || ((c.future || c.tooOld) && !c.rec)" (click)="open(c)"
            [attr.aria-label]="c.date + (c.rec ? ', ' + label[c.rec.status] : '')">
            <span class="n">{{ c.day }}</span>
            @if (c.rec) { <span class="st">{{ icon[c.rec.status] }}</span> }
            @if (c.rec?.status === 'approved' && c.notes) { <span class="nt" title="Notes to download">📄</span> }
          </button>
        }
      </div>
      <div class="legend">
        <span><i class="lg approved"></i> Approved</span><span><i class="lg pending"></i> Waiting</span><span><i class="lg rejected"></i> Not approved</span><span>📄 Notes ready</span>
      </div>
    </div>

    <h3 class="h">Recent</h3>
    <div class="card list">
      @for (r of data()?.recent ?? []; track r.id) {
        <button class="row" (click)="openDate(r.date)">
          <span class="badge" [attr.data-s]="r.status">{{ label[r.status] }}</span>
          <span class="grow"><b>{{ nice(r.date) }}</b><small>To {{ r.reviewer?.name ?? '—' }}@if (r.review_comment) { · “{{ r.review_comment }}” }</small></span>
          <span class="go">›</span>
        </button>
      } @empty { <p class="hint pad">Nothing sent yet. Tap a day in the calendar to start.</p> }
    </div>

    @if (sel(); as c) {
      <app-sheet [title]="nice(c.date)" [subtitle]="c.rec ? label[c.rec.status] : 'Send for approval'" width="480px" (closed)="sel.set(null)">
        @if (justSent(); as js) {
          <div class="wait"><span class="big">✅</span><b>Sent to {{ js.name }}</b><small>{{ js.name.split(' ')[0] }} also gets an email. Let them know on WhatsApp too:</small></div>
          <div class="form-actions">
            <button type="button" class="btn-ghost" (click)="sel.set(null)">Done</button>
            @if (waLink(js.id, c.date); as url) { <a class="btn wa" [href]="url" target="_blank" rel="noopener" (click)="sel.set(null)">💬 Message {{ js.name.split(' ')[0] }} on WhatsApp</a> }
          </div>
          @if (!waLink(js.id, c.date)) { <p class="hint">{{ js.name }} has no mobile number in the CRM, so WhatsApp can’t be opened. They’ll still get the email.</p> }
        } @else if (!c.rec || c.rec.status === 'rejected') {
          @if (c.rec?.status === 'rejected') {
            <div class="alert error" style="margin-top:12px">{{ c.rec!.reviewer?.name }} did not approve this day@if (c.rec!.review_comment) {: “{{ c.rec!.review_comment }}”}. You can send it again.</div>
          }
          <form (ngSubmit)="send(c)">
            <div class="field" [class.invalid]="err()['reviewer_id']">
              <label for="s-r">Send to (mentor) <span class="req">*</span></label>
              <select id="s-r" name="r" [(ngModel)]="reviewer">
                <option [ngValue]="0" disabled>Choose your mentor…</option>
                @for (p of data()?.reviewers ?? []; track p.id) { <option [ngValue]="p.id">{{ p.name }}</option> }
              </select>
              @if (err()['reviewer_id']) { <div class="err">{{ err()['reviewer_id'] }}</div> }
            </div>
            <div class="field"><label for="s-n">Note (optional)</label><textarea id="s-n" name="n" rows="3" maxlength="500" [(ngModel)]="note" placeholder="e.g. Attended the morning session"></textarea></div>
            @if (err()['date']) { <div class="err">{{ err()['date'] }}</div> }
            <div class="form-actions"><button type="button" class="btn-ghost" (click)="sel.set(null)">Cancel</button><button class="btn" type="submit" [disabled]="busy() || !reviewer">Send for approval</button></div>
          </form>
        } @else if (c.rec.status === 'pending') {
          <div class="wait"><span class="big">⏳</span><b>Waiting for {{ c.rec.reviewer?.name }}</b><small>Sent {{ when(c.rec.submitted_at) }}. You’ll get a notification when it’s checked.</small></div>
          <div class="form-actions">
            <button class="btn-ghost danger" (click)="takeBack(c.rec)">Take back</button>
            @if (waLink(c.rec.reviewer?.id ?? 0, c.date); as url) { <a class="btn wa" [href]="url" target="_blank" rel="noopener">💬 Remind on WhatsApp</a> }
            @else { <button class="btn" (click)="sel.set(null)">OK</button> }
          </div>
        } @else {
          <p class="ok">✓ Approved by {{ c.rec.reviewer?.name }}@if (c.rec.reviewed_at) { on {{ when(c.rec.reviewed_at) }} }@if (c.rec.review_comment) { · “{{ c.rec.review_comment }}” }</p>
          <h4>Notes for this day</h4>
          @for (n of notes(); track n.id) {
            <div class="note">
              <b>{{ n.title }}</b>
              @if (n.body) { <p class="pre">{{ n.body }}</p> }
              <div class="nacts">
                @if (n.file) { <button class="btn sm" (click)="download(n)">⬇ {{ n.file.name }}</button> }
                @if (n.body) { <button class="btn-ghost sm" (click)="saveText(n)">⬇ Save notes as text</button> }
              </div>
            </div>
          } @empty { <p class="hint">{{ notesLoading() ? 'Loading…' : 'No notes have been uploaded for this day yet. Check back later.' }}</p> }
        }
      </app-sheet>
    }
  `,
  styles: `
    .cal { max-width: 720px; }
    .ch { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; } .ch b { font-size: 16px; }
    .grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 6px; }
    .wd span { text-align: center; font-size: 11.5px; font-weight: 700; color: var(--ink-3); text-transform: uppercase; padding-bottom: 4px; }
    .day { position: relative; aspect-ratio: 1 / 0.9; border: 1px solid var(--line); border-radius: 12px; background: var(--card); cursor: pointer; font: inherit; color: var(--ink);
      display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 2px; transition: transform 0.15s var(--ease), border-color 0.15s; }
    .day:hover:not(:disabled) { border-color: var(--brand); transform: translateY(-2px); }
    .day:disabled { cursor: default; color: var(--ink-3); background: transparent; }
    .day.out { visibility: hidden; }
    .day.sun:not([data-s]) .n { color: var(--ink-3); }
    .day.today { box-shadow: inset 0 0 0 2px var(--brand); }
    .n { font-weight: 700; font-size: 15px; } .st { font-size: 13px; line-height: 1; } .nt { position: absolute; top: 4px; right: 6px; font-size: 12px; }
    .day[data-s='approved'] { background: var(--x-e8f7ee); border-color: transparent; color: var(--x-11803b); }
    .day[data-s='pending'] { background: var(--x-fef4e6); border-color: transparent; color: var(--x-b54708); }
    .day[data-s='rejected'] { background: var(--x-fdecec); border-color: transparent; color: var(--x-b42318); }
    .legend { display: flex; flex-wrap: wrap; gap: 14px; margin-top: 12px; font-size: 12.5px; color: var(--ink-2); }
    .lg { display: inline-block; width: 12px; height: 12px; border-radius: 4px; vertical-align: -1px; }
    .lg.approved { background: var(--x-e8f7ee); border: 1px solid var(--x-11803b); } .lg.pending { background: var(--x-fef4e6); border: 1px solid var(--x-b54708); } .lg.rejected { background: var(--x-fdecec); border: 1px solid var(--x-b42318); }
    .h { font-size: 15px; margin: 20px 0 8px; }
    .list { padding: 6px; max-width: 720px; }
    .row { display: flex; align-items: center; gap: 12px; width: 100%; border: 0; background: none; padding: 10px 8px; border-radius: 10px; cursor: pointer; font: inherit; color: inherit; text-align: left; border-bottom: 1px solid var(--line); }
    .row:last-child { border-bottom: 0; } .row:hover { background: var(--bg); }
    .grow { flex: 1; min-width: 0; } .grow b { display: block; font-size: 14px; } .grow small { color: var(--ink-3); font-size: 12.5px; }
    .go { color: var(--ink-3); font-size: 18px; }
    .badge[data-s='approved'] { background: var(--x-e8f7ee); color: var(--x-11803b); } .badge[data-s='pending'] { background: var(--x-fef4e6); color: var(--x-b54708); } .badge[data-s='rejected'] { background: var(--x-fdecec); color: var(--x-b42318); }
    .badge { min-width: 92px; text-align: center; }
    .pad { padding: 14px 8px; } .err { color: var(--crit); font-size: 12.5px; margin-top: 6px; }
    .wait { display: grid; justify-items: center; text-align: center; gap: 6px; padding: 26px 0 10px; } .wait .big { font-size: 40px; } .wait small { color: var(--ink-3); }
    .ok { margin: 14px 0 10px; color: var(--x-11803b); font-weight: 600; font-size: 14px; }
    h4 { font-size: 14px; margin: 14px 0 8px; }
    .note { border: 1px solid var(--line); border-radius: 12px; padding: 12px 14px; margin-bottom: 10px; } .note b { display: block; }
    .pre { white-space: pre-wrap; font-size: 14px; color: var(--ink-2); margin-top: 6px; line-height: 1.5; max-height: 260px; overflow: auto; }
    .nacts { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px; } .sm { padding: 7px 12px; font-size: 13px; }
    .btn-ghost.danger { color: #c0262d; }
    .btn.wa { background: #1fa855; box-shadow: 0 8px 20px rgba(31, 168, 85, 0.28); text-decoration: none; }
    .btn.wa:hover { background: #178a45; }
    .form-actions .btn.wa { flex: 1; justify-content: center; }
    @media (max-width: 560px) { .grid { gap: 4px; } .day { border-radius: 9px; } .n { font-size: 13.5px; } .nt { top: 1px; right: 2px; font-size: 9px; } .st { font-size: 11px; } }
    @media (prefers-reduced-motion: reduce) { .day { transition: none; } }
  `,
})
export class StudentHome {
  private api = inject(StudentService);
  private toast = inject(Toast);
  private dialog = inject(Dialog);
  private auth = inject(AuthService);
  protected readonly label: Record<string, string> = { approved: 'Approved', pending: 'Waiting', rejected: 'Not approved' };
  protected readonly icon: Record<string, string> = { approved: '✓', pending: '⏳', rejected: '✕' };
  protected readonly month = signal(this.iso(new Date()).slice(0, 7));
  protected readonly data = signal<MyMonth | null>(null);
  protected readonly sel = signal<Cell | null>(null);
  protected readonly notes = signal<DayNote[]>([]);
  protected readonly notesLoading = signal(false);
  protected readonly busy = signal(false);
  /** Shown right after sending: the mentor, so the student can tell them on WhatsApp. */
  protected readonly justSent = signal<{ id: number; name: string } | null>(null);
  protected readonly err = signal<Record<string, string>>({});
  protected reviewer = 0;
  protected note = '';
  protected readonly first = computed(() => (this.auth.user()?.name ?? '').split(' ')[0]);
  private wanted = inject(ActivatedRoute).snapshot.queryParamMap.get('date');

  constructor() {
    if (this.wanted) this.month.set(this.wanted.slice(0, 7));
    this.load();
  }

  load(): void {
    this.api.month(this.month()).subscribe((d) => {
      this.data.set(d);
      if (this.wanted) {
        this.openDate(this.wanted);
        this.wanted = null;
      }
    });
  }

  shift(n: number): void {
    const [y, m] = this.month().split('-').map(Number);
    const d = new Date(y!, m! - 1 + n, 1);
    this.month.set(`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`);
    this.load();
  }

  protected readonly isCurrentMonth = computed(() => this.month() >= this.iso(new Date()).slice(0, 7));
  protected readonly monthLabel = computed(() => new Date(this.month() + '-01T00:00').toLocaleDateString('en-IN', { month: 'long', year: 'numeric' }));

  /** Weeks starting on Monday. */
  protected readonly cells = computed((): Cell[] => {
    const d = this.data();
    const [y, m] = this.month().split('-').map(Number);
    const first = new Date(y!, m! - 1, 1);
    const lead = (first.getDay() + 6) % 7;
    const days = new Date(y!, m!, 0).getDate();
    const today = this.iso(new Date());
    const byDate = new Map((d?.data ?? []).map((r) => [r.date, r]));
    const out: Cell[] = [];
    for (let i = -lead; i < Math.ceil((lead + days) / 7) * 7 - lead; i++) {
      const dt = new Date(y!, m! - 1, i + 1);
      const iso = this.iso(dt);
      out.push({ date: iso, day: dt.getDate(), inMonth: i >= 0 && i < days, future: iso > today, tooOld: !!d && iso < d.earliest, today: iso === today,
        sunday: dt.getDay() === 0, rec: byDate.get(iso) ?? null, notes: d?.notes[iso] ?? 0 });
    }
    return out;
  });

  openDate(date: string): void {
    if (date.slice(0, 7) !== this.month()) {
      this.wanted = date;
      this.month.set(date.slice(0, 7));
      this.load();
      return;
    }
    const c = this.cells().find((x) => x.date === date);
    if (c) this.open(c);
  }

  open(c: Cell): void {
    this.err.set({});
    this.justSent.set(null);
    this.note = '';
    const reviewers = this.data()?.reviewers ?? [];
    this.reviewer = c.rec?.reviewer?.id ?? (reviewers.length === 1 ? reviewers[0]!.id : this.reviewer);
    this.sel.set(c);
    if (c.rec?.status === 'approved') {
      this.notes.set([]);
      this.notesLoading.set(true);
      this.api.notes(c.date).subscribe({ next: (r) => { this.notes.set(r.data); this.notesLoading.set(false); }, error: () => this.notesLoading.set(false) });
    }
  }

  send(c: Cell): void {
    this.busy.set(true);
    this.err.set({});
    this.api.submit(c.date, this.reviewer, this.note.trim()).subscribe({
      next: (r) => {
        this.busy.set(false);
        this.justSent.set({ id: r.reviewer?.id ?? 0, name: r.reviewer?.name ?? 'your mentor' });
        this.load();
      },
      error: (e) => {
        this.busy.set(false);
        const r = readErrors(e);
        this.err.set(r.fields);
        if (r.message && !Object.keys(r.fields).length) this.toast.show(r.message);
      },
    });
  }

  async takeBack(r: StudentDay): Promise<void> {
    if (!(await this.dialog.confirm({ title: 'Take back this request?', message: `${r.reviewer?.name} won’t need to check it any more. You can send it again later.`, confirmText: 'Take back' }))) return;
    this.api.cancel(r.id).subscribe(() => {
      this.sel.set(null);
      this.load();
    });
  }

  /** Opens WhatsApp on this phone / laptop with the message already written to the mentor. */
  waLink(mentorId: number, date: string): string | null {
    const m = this.data()?.reviewers.find((r) => r.id === mentorId);
    if (!m?.whatsapp) return null;
    const text = `Hi ${m.name.split(' ')[0]}, this is ${this.auth.user()?.name}. I attended on ${this.nice(date)} and have sent it to you for approval in the DigiFox CRM. Please approve it here: ${location.origin}/students`;
    return `https://wa.me/${m.whatsapp}?text=${encodeURIComponent(text)}`;
  }

  download(n: DayNote): void {
    this.api.file(n.id).subscribe({ next: (b) => saveBlob(b, n.file!.name), error: () => this.toast.show('Could not download the file.') });
  }

  saveText(n: DayNote): void {
    saveBlob(new Blob([`${n.title}\n${this.nice(n.date)}\n\n${n.body}\n`], { type: 'text/plain' }), `notes-${n.date}.txt`);
  }

  nice(d: string): string {
    return new Date(d + 'T00:00').toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'short', year: 'numeric' });
  }

  when(iso: string): string {
    return new Date(iso).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' });
  }

  private iso(d: Date): string {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  }
}
