import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { Subject, debounceTime } from 'rxjs';
import { AuthService } from '../../core/auth/auth.service';
import { BillingService, Client } from '../../core/billing.service';
import { readErrors } from '../../core/http-errors';
import { LeadsService } from '../../core/leads.service';
import { PRIORITY_LABEL, REPEAT_LABEL, Task, TaskList, TaskPriority, TaskRepeat, TaskStatus, TeamRow, WorkService } from '../../core/work.service';
import { Dialog } from '../../shared/dialog';
import { Sheet } from '../../shared/sheet';
import { Toast } from '../../shared/toast.service';

type View = 'today' | 'upcoming' | 'overdue' | 'review' | 'done' | 'team';
interface TaskForm {
  id?: number; title: string; description: string; assigned_to: number | null; due_date: string | null;
  priority: TaskPriority; repeat: TaskRepeat; client_id: number | null; series_id?: number | null;
}

@Component({
  selector: 'app-tasks',
  imports: [FormsModule, RouterLink, Sheet],
  template: `
    <div class="page-head">
      <div><h1>Tasks</h1><p>{{ greeting() }}</p></div>
      <div class="actions"><button class="btn" (click)="startNew()">+ New task</button></div>
    </div>

    <div class="kchips" [class.six]="seesTeam()">
      <button class="kc" [class.on]="view === 'today'" (click)="setView('today')"><b>{{ counts().today }}</b><span>To do today</span></button>
      <button class="kc warn" [class.on]="view === 'overdue'" (click)="setView('overdue')"><b>{{ counts().overdue }}</b><span>Overdue</span></button>
      <button class="kc" [class.on]="view === 'upcoming'" (click)="setView('upcoming')"><b>{{ counts().upcoming }}</b><span>Upcoming</span></button>
      <button class="kc rev" [class.on]="view === 'review'" (click)="setView('review')"><b>{{ counts().review }}</b><span>{{ canApprove() ? 'To check' : 'In review' }}</span></button>
      <button class="kc ok" [class.on]="view === 'done'" (click)="setView('done')"><b>{{ counts().done_today }}</b><span>Done today</span></button>
      @if (seesTeam()) { <button class="kc team" [class.on]="view === 'team'" (click)="setView('team')"><b>👥</b><span>Team</span></button> }
    </div>

    @if (view === 'team') {
      <div class="teamgrid">
        @for (p of team(); track p.user.id) {
          <div class="card pc">
            <div class="ph"><span class="who big">{{ initials(p.user.name) }}</span><div><b>{{ p.user.name }}</b>
              <small>{{ p.in_progress }} in progress · {{ p.todo }} to do@if (p.review) { · {{ p.review }} in review }@if (p.overdue) { · <span class="late">{{ p.overdue }} overdue</span> }</small></div></div>
            @for (w of p.working_on; track w.id) {
              <div class="wo"><span class="dot"></span><span class="wt">{{ w.title }}@if (w.client) { <i>· {{ w.client }}</i> }</span>@if (w.minutes) { <small>⏱ {{ hrs(w.minutes) }}</small> }</div>
            } @empty { <p class="hint">Not working on anything right now.</p> }
            <div class="pf"><span>✓ {{ p.done_today }} done today@if (p.minutes_done_today) { · {{ hrs(p.minutes_done_today) }} }</span>
              <button class="linkish" (click)="assigned = '' + p.user.id; setView('today')">See tasks →</button></div>
          </div>
        } @empty { <div class="card empty-box">Loading…</div> }
      </div>
    } @else {
    <div class="card">
      <div class="toolbar">
        <input class="input-sm grow" type="search" placeholder="Search tasks" [(ngModel)]="search" (ngModelChange)="search$.next()" />
        @if (seesTeam()) {
          <select class="select-sm" [(ngModel)]="assigned" (ngModelChange)="load()" aria-label="Whose tasks">
            <option value="me">My tasks</option><option value="">Whole team</option><option value="none">Unassigned</option>
            @for (u of staff(); track u.id) { <option [value]="u.id">{{ u.name }}</option> }
          </select>
        }
      </div>

      <form class="quick" (ngSubmit)="quickAdd()">
        <input class="input-sm" name="q" [(ngModel)]="quick" placeholder="Add a task for today and press Enter…" maxlength="200" autocomplete="off" />
        @if (quick.trim()) { <button class="btn sm" type="submit">Add</button> }
      </form>

      @for (t of list(); track t.id; let i = $index) {
        <div class="trow" [class.done]="t.status === 'done'" [style.animation-delay.s]="i * 0.015" (click)="openTask(t)">
          <button class="tick" [class.on]="t.status === 'done'" [class.rv]="t.status === 'review'" (click)="toggleDone(t, $event)"
            [attr.aria-label]="t.status === 'done' ? 'Approved' : canApprove() ? 'Approve' : 'Send for review'" [title]="t.status === 'done' ? 'Approved' + (t.reviewed_by ? ' by ' + t.reviewed_by.name : '') : t.status === 'review' ? (canApprove() ? 'Approve' : 'Waiting for review') : canApprove() ? 'Mark done' : 'Send for review'">{{ t.status === 'review' ? '⏳' : '✓' }}</button>
          <div class="main">
            <b>{{ t.title }}</b>
            <div class="meta">
              @if (t.priority === 'urgent' || t.priority === 'high') { <span class="pri" [class.u]="t.priority === 'urgent'">{{ prio[t.priority] }}</span> }
              @if (t.status === 'in_progress') { <span class="ip">In progress</span> }
              @if (t.status === 'review') { <span class="rvw">Waiting for review</span> }
              @if (t.review_note && t.status !== 'review' && t.status !== 'done') { <span class="late">↩ Sent back: {{ t.review_note }}</span> }
              @if (t.status === 'done' && t.reviewed_by) { <span class="okk">✓ Checked by {{ t.reviewed_by.name }}</span> }
              @if (t.client) { <span class="cl">{{ t.client.company_name }}</span> }
              @if (t.ticket) { <span class="tk">🎫 Ticket</span> }
              @if (t.repeat !== 'none') { <span class="rp">↻ {{ t.repeat }}</span> }
              @if (t.due_date) { <span [class.late]="t.overdue">{{ t.overdue ? 'Overdue · ' : '' }}{{ due(t.due_date) }}</span> }
              @if (t.minutes_spent) { <span>⏱ {{ hrs(t.minutes_spent) }}</span> }
            </div>
          </div>
          @if (t.status === 'review' && canApprove() && t.assigned_to?.id !== me()) {
            <span class="rvb" (click)="$event.stopPropagation()">
              <button class="btn sm" (click)="approve(t)">Approve</button>
              <button class="btn-ghost sm" (click)="sendBack(t)">Send back</button>
            </span>
          }
          @if (assigned !== 'me' && t.assigned_to) { <span class="who" [title]="t.assigned_to.name">{{ initials(t.assigned_to.name) }}</span> }
        </div>
      } @empty {
        <div class="empty-box">@if (loading()) { Loading… } @else if (view === 'done') { <b>Nothing finished yet</b> } @else if (view === 'review') { <b>Nothing waiting for review</b> } @else { <b>All clear 🎉</b>No tasks here. Add one above. }</div>
      }
    </div>
    }

    <!-- New / edit -->
    @if (form(); as f) {
      <app-sheet [title]="f.id ? 'Edit task' : 'New task'" (closed)="form.set(null)">
        <form (ngSubmit)="save()">
          @if (formErr()) { <div class="alert error">{{ formErr() }}</div> }
          <div class="field compact"><label for="t-ti">Task <span class="req">*</span></label><input id="t-ti" name="ti" [(ngModel)]="f.title" maxlength="200" required /></div>
          <div class="field compact"><label for="t-de">Details</label><textarea id="t-de" name="de" rows="3" [(ngModel)]="f.description"></textarea></div>
          <div class="row-2">
            @if (canManage()) {
              <div class="field compact"><label for="t-as">Assign to</label>
                <select id="t-as" name="as" [(ngModel)]="f.assigned_to">@for (u of staff(); track u.id) { <option [ngValue]="u.id">{{ u.id === me() ? 'Me' : u.name }}</option> }</select></div>
            }
            <div class="field compact"><label for="t-du">Due</label><input id="t-du" name="du" type="date" [(ngModel)]="f.due_date" /></div>
            <div class="field compact"><label for="t-pr">Priority</label>
              <select id="t-pr" name="pr" [(ngModel)]="f.priority">@for (p of prios; track p[0]) { <option [value]="p[0]">{{ p[1] }}</option> }</select></div>
            <div class="field compact"><label for="t-rp">Repeat</label>
              <select id="t-rp" name="rp" [(ngModel)]="f.repeat" [disabled]="!!f.series_id">@for (r of repeats; track r[0]) { <option [value]="r[0]">{{ r[1] }}</option> }</select></div>
          </div>
          @if (clients().length) {
            <div class="field compact"><label for="t-cl">Client (optional)</label>
              <select id="t-cl" name="cl" [(ngModel)]="f.client_id"><option [ngValue]="null">—</option>@for (c of clients(); track c.id) { <option [ngValue]="c.id">{{ c.company_name }}</option> }</select></div>
          }
          @if (f.repeat !== 'none') { <p class="hint">A new copy appears each {{ f.repeat === 'daily' ? 'working day (not Sundays or holidays)' : f.repeat === 'weekly' ? 'week on the same day' : 'month on the same date' }}.</p> }
          <div class="form-actions"><button type="button" class="btn-ghost" (click)="form.set(null)">Cancel</button><button class="btn" type="submit" [disabled]="busy()">{{ f.id ? 'Save' : 'Add task' }}</button></div>
        </form>
      </app-sheet>
    }

    <!-- Detail -->
    @if (detail(); as t) {
      <app-sheet [title]="t.title" [subtitle]="(t.assigned_to ? 'For ' + t.assigned_to.name : 'Unassigned') + (t.created_by ? ' · from ' + t.created_by.name : '')" (closed)="detail.set(null)">
        @if (t.review_note && t.status !== 'done' && t.status !== 'review') { <div class="alert error" style="margin-top:12px">↩ Sent back{{ t.reviewed_by ? ' by ' + t.reviewed_by.name : '' }}: {{ t.review_note }}</div> }
        <div class="seg" role="group" aria-label="Status">
          @for (s of statuses(); track s[0]) { <button [class.on]="t.status === s[0]" (click)="setStatus(t, s[0])" [disabled]="t.status === 'done' && !canApprove()">{{ s[1] }}</button> }
        </div>
        @if (t.status === 'review' && canApprove() && t.assigned_to?.id !== me()) {
          <div class="form-actions" style="margin-top:0"><button class="btn-ghost" (click)="sendBack(t)">↩ Send back</button><button class="btn" (click)="approve(t)">✓ Approve</button></div>
        }
        @if (t.status === 'done') { <p class="hint">✓ Approved@if (t.reviewed_by) { by {{ t.reviewed_by.name }} }@if (t.reviewed_at) { on {{ when(t.reviewed_at) }} }.</p> }
        @if (t.description) { <p class="desc">{{ t.description }}</p> }
        <dl class="dl">
          <div><dt>Due</dt><dd [class.late]="t.overdue">{{ t.due_date ? due(t.due_date) : 'No date' }}</dd></div>
          <div><dt>Priority</dt><dd>{{ prio[t.priority] }}</dd></div>
          @if (t.client) { <div><dt>Client</dt><dd><a [routerLink]="['/clients', t.client.id]">{{ t.client.company_name }}</a></dd></div> }
          @if (t.ticket) { <div><dt>Ticket</dt><dd><a [routerLink]="['/tickets', t.ticket.id]">{{ t.ticket.subject }}</a></dd></div> }
          @if (t.repeat !== 'none') { <div><dt>Repeats</dt><dd>{{ rep[t.repeat] }}</dd></div> }
          <div><dt>Time spent</dt><dd>{{ t.minutes_spent ? hrs(t.minutes_spent) : '—' }}</dd></div>
        </dl>
        <div class="logt"><span>Log time</span>
          @for (m of [15, 30, 60, 120]; track m) { <button class="btn-ghost sm" (click)="logTime(t, m)">+{{ m < 60 ? m + 'm' : m / 60 + 'h' }}</button> }
        </div>
        @if (canEdit(t)) {
          <div class="form-actions">
            <button class="btn-ghost danger" (click)="remove(t)">Delete</button>
            <button class="btn-ghost" (click)="editTask(t)">Edit</button>
          </div>
        }
      </app-sheet>
    }
  `,
  styles: `
    .kchips { display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; margin-bottom: 16px; }
    .kc { text-align: left; padding: 14px 16px; border-radius: 16px; border: 1px solid var(--line); background: var(--card); cursor: pointer; font: inherit; transition: transform 0.2s var(--ease), border-color 0.2s; }
    .kc:hover { transform: translateY(-2px); } .kc.on { border-color: var(--brand); box-shadow: 0 0 0 3px rgba(237, 143, 33, 0.15); }
    .kc b { display: block; font-size: 24px; } .kc span { font-size: 12.5px; color: var(--ink-3); }
    .kc.warn b { color: #c0262d; } .kc.ok b { color: #11803b; } .kc.rev b { color: #7a4dd8; }
    .kchips.six { grid-template-columns: repeat(6, 1fr); }
    .rvw { color: #7a4dd8; font-weight: 600; } .okk { color: #11803b; font-weight: 600; }
    .tick.rv { border-color: #7a4dd8; color: #7a4dd8; font-size: 12px; }
    .rvb { display: flex; gap: 6px; flex: none; }
    .teamgrid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 14px; }
    .pc { display: flex; flex-direction: column; gap: 8px; animation: rise 0.35s var(--ease) both; }
    .ph { display: flex; gap: 12px; align-items: center; } .ph b { display: block; } .ph small { color: var(--ink-3); font-size: 12.5px; }
    .who.big { width: 40px; height: 40px; border-radius: 12px; font-size: 13px; }
    .wo { display: flex; align-items: center; gap: 8px; font-size: 13.5px; background: var(--bg); border-radius: 10px; padding: 8px 10px; }
    .wo .dot { width: 8px; height: 8px; border-radius: 50%; background: #2f6fde; box-shadow: 0 0 0 3px rgba(47, 111, 222, 0.18); flex: none; animation: pulse 1.6s infinite; }
    @keyframes pulse { 50% { box-shadow: 0 0 0 6px rgba(47, 111, 222, 0.05); } }
    .wt { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; } .wt i { font-style: normal; color: var(--ink-3); }
    .wo small { color: var(--ink-3); flex: none; }
    .pf { display: flex; justify-content: space-between; align-items: center; font-size: 12.5px; color: #11803b; margin-top: auto; padding-top: 6px; border-top: 1px dashed var(--line); }
    .linkish { background: none; border: 0; color: var(--brand-deep); font: inherit; font-weight: 600; cursor: pointer; }
    .toolbar { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 10px; } .grow { flex: 1; min-width: 200px; }
    .quick { display: flex; gap: 8px; margin-bottom: 6px; } .quick .input-sm { flex: 1; border-style: dashed; }
    .trow { display: flex; align-items: center; gap: 12px; padding: 12px 6px; border-top: 1px solid var(--line); cursor: pointer; animation: rise 0.35s var(--ease) both; transition: background 0.2s; border-radius: 10px; }
    .trow:hover { background: var(--bg); }
    .tick { width: 26px; height: 26px; flex: none; border-radius: 50%; border: 2px solid #c9ccd4; background: var(--card); color: transparent; cursor: pointer; font-weight: 800; font-size: 13px; transition: all 0.25s var(--ease); }
    .tick:hover { border-color: #16a34a; color: #16a34a; }
    .tick.on { background: #16a34a; border-color: #16a34a; color: #fff; transform: scale(1.05); }
    .main { flex: 1; min-width: 0; } .main b { display: block; font-weight: 600; }
    .done .main b { text-decoration: line-through; color: var(--ink-3); }
    .meta { display: flex; flex-wrap: wrap; gap: 6px 10px; margin-top: 4px; font-size: 12.5px; color: var(--ink-3); }
    .meta .late, .late { color: #c0262d; font-weight: 600; }
    .pri { color: #b54708; font-weight: 700; } .pri.u { color: #c0262d; }
    .ip { color: #2f6fde; font-weight: 600; } .cl { color: var(--ink-2); font-weight: 600; } .rp { color: #16a394; }
    .who { width: 30px; height: 30px; border-radius: 10px; display: grid; place-items: center; background: #16181d; color: var(--brand); font-size: 11.5px; font-weight: 700; flex: none; }
    .seg { display: flex; gap: 4px; background: var(--bg); padding: 4px; border-radius: 12px; margin: 12px 0 16px; }
    .seg button { flex: 1; border: 0; background: none; padding: 9px; border-radius: 9px; font: inherit; font-weight: 600; font-size: 13.5px; color: var(--ink-3); cursor: pointer; }
    .seg button.on { background: var(--card); color: var(--ink); box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08); }
    .desc { white-space: pre-line; color: var(--ink-2); line-height: 1.6; margin-bottom: 14px; }
    .logt { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin: 16px 0; font-size: 13px; color: var(--ink-3); }
    .sm { padding: 6px 10px; font-size: 12.5px; }
    .danger { color: #c0262d; }
    @media (max-width: 1100px) { .kchips, .kchips.six { grid-template-columns: repeat(3, 1fr); } }
    @media (max-width: 860px) { .kchips, .kchips.six { grid-template-columns: repeat(2, 1fr); } .rvb { flex-direction: column; } }
  `,
})
export class Tasks {
  private api = inject(WorkService);
  private toast = inject(Toast);
  private dialog = inject(Dialog);
  protected readonly auth = inject(AuthService);
  protected readonly prio = PRIORITY_LABEL;
  protected readonly rep = REPEAT_LABEL;
  protected readonly prios = Object.entries(PRIORITY_LABEL) as [TaskPriority, string][];
  protected readonly repeats = Object.entries(REPEAT_LABEL) as [TaskRepeat, string][];
  /** Employees go up to Review; only managers see (and set) Done. */
  protected readonly statuses = computed((): [TaskStatus, string][] => [
    ['todo', 'To do'], ['in_progress', 'In progress'], ['review', 'Review'], ...(this.canApprove() ? [['done', 'Done'] as [TaskStatus, string]] : []),
  ]);
  protected readonly team = signal<TeamRow[]>([]);

  protected view: View = 'today';
  protected assigned = 'me';
  protected search = '';
  protected quick = '';
  protected readonly search$ = new Subject<void>();
  protected readonly result = signal<TaskList | null>(null);
  protected readonly list = computed(() => this.result()?.data ?? []);
  protected readonly counts = computed(() => this.result()?.counts ?? { today: 0, overdue: 0, upcoming: 0, done_today: 0, review: 0 });
  protected readonly canManage = computed(() => this.result()?.can_manage ?? this.auth.can('tasks.edit'));
  protected readonly canApprove = computed(() => this.result()?.can_approve ?? this.auth.can('tasks.approve'));
  protected readonly seesTeam = computed(() => this.result()?.sees_team ?? (this.auth.can('tasks.edit') || this.auth.can('tasks.approve')));
  protected readonly loading = signal(true);
  protected readonly busy = signal(false);
  protected readonly staff = signal<{ id: number; name: string }[]>([]);
  protected readonly clients = signal<Client[]>([]);
  protected readonly me = computed(() => this.auth.user()?.id ?? 0);
  protected readonly detail = signal<Task | null>(null);
  protected readonly form = signal<TaskForm | null>(null);
  protected readonly formErr = signal('');

  constructor() {
    this.search$.pipe(debounceTime(300)).subscribe(() => this.load());
    // Managers and the super admin start on the whole team; employees on their own tasks.
    if (this.auth.can('tasks.edit') || this.auth.can('tasks.approve')) this.assigned = '';
    if (this.auth.can('tasks.edit') || this.auth.can('tasks.approve')) inject(LeadsService).options().subscribe((o) => this.staff.set(o.assignees));
    if (this.auth.can('clients.view')) inject(BillingService).clients({ status: 'active' }).subscribe((r) => this.clients.set(r.data));
    const v = inject(ActivatedRoute).snapshot.queryParamMap.get('view') as View | null;
    if (v) this.view = v;
    this.load();
  }

  greeting(): string {
    const c = this.counts();
    if (this.loading()) return 'Your work for today.';
    const who = this.assigned === '' ? 'Whole team: ' : this.assigned === 'me' ? '' : (this.staff().find((u) => '' + u.id === this.assigned)?.name ?? 'Unassigned') + ': ';
    if (!c.today) return who ? `${who}nothing left for today.` : 'Nothing left for today. Nice work!';
    return `${who}${c.today} to do today${c.overdue ? `, ${c.overdue} overdue` : ''}.`;
  }

  load(): void {
    this.loading.set(true);
    if (this.view === 'team') this.api.team().subscribe((r) => this.team.set(r.data));
    this.api.tasks({ view: this.view === 'team' ? 'today' : this.view, assigned: this.assigned, search: this.search }).subscribe({
      next: (r) => {
        this.result.set(r);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }

  setView(v: View): void {
    this.view = v;
    this.load();
  }

  quickAdd(): void {
    const title = this.quick.trim();
    if (!title) return;
    this.quick = '';
    this.api.createTask({ title }).subscribe({
      next: () => {
        this.toast.show('Task added.');
        if (this.view !== 'today') this.view = 'today';
        this.load();
      },
      error: (e) => this.toast.show(readErrors(e).message || 'Could not add the task.'),
    });
  }

  startNew(): void {
    this.formErr.set('');
    this.form.set({ title: '', description: '', assigned_to: this.me(), due_date: this.iso(new Date()), priority: 'normal', repeat: 'none', client_id: null });
  }

  editTask(t: Task): void {
    this.detail.set(null);
    this.formErr.set('');
    this.form.set({ id: t.id, title: t.title, description: t.description ?? '', assigned_to: t.assigned_to?.id ?? null, due_date: t.due_date,
      priority: t.priority, repeat: t.repeat, client_id: t.client?.id ?? null, series_id: t.series_id });
  }

  save(): void {
    const f = this.form()!;
    const body: Record<string, unknown> = { title: f.title, description: f.description || null, due_date: f.due_date || null, priority: f.priority, client_id: f.client_id };
    if (this.canManage()) body['assigned_to'] = f.assigned_to;
    if (!f.series_id) body['repeat'] = f.repeat;
    this.busy.set(true);
    (f.id ? this.api.updateTask(f.id, body) : this.api.createTask(body)).subscribe({
      next: () => {
        this.busy.set(false);
        this.form.set(null);
        this.toast.show(f.id ? 'Task saved.' : 'Task added.');
        this.load();
      },
      error: (e) => {
        this.busy.set(false);
        const r = readErrors(e);
        this.formErr.set(Object.values(r.fields)[0] || r.message);
      },
    });
  }

  openTask(t: Task): void {
    this.detail.set(t);
  }

  /** The round button: employees send for review; managers approve (or finish their own). */
  toggleDone(t: Task, ev: Event): void {
    ev.stopPropagation();
    if (t.status === 'done') return;
    if (t.status === 'review') {
      if (this.canApprove() && t.assigned_to?.id !== this.me()) this.approve(t);
      else this.toast.show('Waiting for a manager to check it.');
      return;
    }
    this.setStatus(t, this.canApprove() && t.assigned_to?.id === this.me() ? 'done' : 'review');
  }

  async setStatus(t: Task, status: TaskStatus): Promise<void> {
    if (status === t.status) return;
    const body: Record<string, unknown> = { status };
    if (status === 'review' || (status === 'done' && t.status !== 'review')) {
      // Time spent is required before a task is checked.
      const mins = await this.askTime(t, status);
      if (mins === null) return;
      if (mins > 0) body['add_minutes'] = mins;
    }
    this.patch(t, body, status === 'review' ? 'Sent for review.' : status === 'done' ? 'Done ✓' : '');
  }

  approve(t: Task): void {
    this.patch(t, { status: 'done' }, `Approved ✓ ${t.assigned_to?.name ?? ''} will be told.`);
  }

  async sendBack(t: Task): Promise<void> {
    const note = await this.dialog.prompt({
      title: 'Send back for changes', label: 'What needs to change?', confirmText: 'Send back', required: true,
      message: `${t.assigned_to?.name ?? 'They'} will see your note and can send it again when done.`,
    });
    if (!note) return;
    this.patch(t, { status: 'in_progress', note }, 'Sent back.');
  }

  /** Ask how long the task took. Returns minutes to add, 0 if enough is already logged, or null if cancelled. */
  private async askTime(t: Task, status: TaskStatus): Promise<number | null> {
    const have = t.minutes_spent;
    const v = await this.dialog.prompt({
      title: 'How long did this take?',
      message: have ? `Already logged: ${this.hrs(have)}. Add more if you spent extra time, or leave it empty.` : 'Time spent is needed before the task is checked. E.g. 45m, 1h 30m or 2h.',
      label: 'Time spent', suggestions: ['15m', '30m', '45m', '1h', '1h 30m', '2h', '3h', '4h'],
      confirmText: status === 'done' ? 'Mark done' : 'Send for review', required: !have,
    });
    if (v === null) return null;
    if (!v.trim()) return 0;
    const m = this.parseTime(v);
    if (!m) {
      this.toast.show('Write the time like 45m, 1h 30m or 2h.');
      return null;
    }
    return m;
  }

  private parseTime(v: string): number {
    const s = v.toLowerCase().replace(/\s+/g, '');
    const h = s.match(/([\d.]+)h/);
    const mm = s.match(/(\d+)m/);
    if (h || mm) return Math.round((h ? parseFloat(h[1]!) * 60 : 0) + (mm ? parseInt(mm[1]!, 10) : 0));
    const n = parseFloat(s);
    return isNaN(n) ? 0 : n <= 12 ? Math.round(n * 60) : Math.round(n); // "2" = 2 hours, "90" = 90 minutes
  }

  when(iso: string): string {
    return new Date(iso).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
  }

  logTime(t: Task, minutes: number): void {
    this.patch(t, { add_minutes: minutes }, `Logged ${this.hrs(minutes)}.`);
  }

  private patch(t: Task, body: Record<string, unknown>, msg: string): void {
    this.result.update((r) => (r ? { ...r, data: r.data.map((x) => (x.id === t.id && body['status'] ? { ...x, status: body['status'] as TaskStatus } : x)) } : r));
    this.api.updateTask(t.id, body).subscribe({
      next: (u) => {
        if (this.detail()?.id === u.id) this.detail.set(u);
        if (msg) this.toast.show(msg);
        this.load();
      },
      error: (e) => {
        this.toast.show(readErrors(e).message || 'Could not update.');
        this.load();
      },
    });
  }

  canEdit(t: Task): boolean {
    return this.canManage() || t.created_by?.id === this.me();
  }

  async remove(t: Task): Promise<void> {
    const ok = await this.dialog.confirm({
      title: 'Delete this task?',
      message: t.repeat !== 'none' && !t.series_id ? 'This is a repeating task: all its copies are deleted too.' : 'This cannot be undone.',
      confirmText: 'Delete', danger: true,
    });
    if (!ok) return;
    this.api.deleteTask(t.id).subscribe(() => {
      this.detail.set(null);
      this.toast.show('Task deleted.');
      this.load();
    });
  }

  due(d: string): string {
    const today = this.iso(new Date());
    const tm = new Date();
    tm.setDate(tm.getDate() + 1);
    if (d === today) return 'Today';
    if (d === this.iso(tm)) return 'Tomorrow';
    return new Date(d + 'T00:00').toLocaleDateString('en-IN', { day: 'numeric', month: 'short' });
  }
  hrs(min: number): string {
    return min < 60 ? `${min}m` : `${Math.floor(min / 60)}h${min % 60 ? ' ' + (min % 60) + 'm' : ''}`;
  }
  initials(n: string): string {
    return n.split(/\s+/).map((w) => w[0]).slice(0, 2).join('').toUpperCase();
  }
  private iso(d: Date): string {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  }
}
