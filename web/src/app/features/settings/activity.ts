import { HttpClient } from '@angular/common/http';
import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Subject, debounceTime } from 'rxjs';
import { API_BASE } from '../../core/api.config';

interface Entry {
  id: number;
  at: string;
  user: { id: number | null; name: string } | null;
  action: string;
  type: string | null;
  subject_id: number | null;
  label: string | null;
  changes: Record<string, [string | null, string | null]> | { added?: string[]; removed?: string[] };
  ip: string | null;
}
interface Page {
  data: Entry[];
  meta: { current_page: number; last_page: number; total: number };
  people: { id: number; name: string }[];
  types: string[];
  keep_days: number;
}

const TYPE: Record<string, string> = {
  lead: 'lead', client: 'client', invoice: 'invoice', payment: 'payment', expense: 'expense', ticket: 'ticket', task: 'task',
  campaign: 'campaign', leave_request: 'leave request', employee: 'employee', user: 'login', salary_structure: 'salary',
  holiday: 'holiday', role: 'role', backup: 'backup', settings: 'settings', login: 'sign-in',
};
const FIELD: Record<string, string> = {
  full_name: 'Name', assigned_to: 'Assigned to', call_status: 'Call status', next_followup_at: 'Next follow-up', followup_status: 'Follow-up',
  first_contacted_at: 'First contacted', lost_reason: 'Lost reason', account_manager_id: 'Account manager', due_date: 'Due date',
  minutes_spent: 'Time spent (min)', completed_at: 'Completed', reviewed_by: 'Checked by', review_note: 'Review note', amount_paid: 'Amount paid',
  invoice_no: 'Invoice no.', issue_date: 'Issue date', paid_on: 'Paid on', spent_on: 'Spent on', payment_mode: 'Payment mode',
  from_date: 'From', to_date: 'To', approver_id: 'Approved by', leave_type_id: 'Leave type', converted_client_id: 'Converted to client',
};

/** Settings → Activity log (admins): who did what, and when. */
@Component({
  selector: 'app-activity',
  imports: [FormsModule],
  template: `
    <div class="card">
      <div class="top">
        <span class="ic">🕑</span>
        <div><h3>Activity log</h3><p class="hint">Who added, changed or deleted what, plus sign-ins and permission changes. Kept for {{ keep() }} days. Passwords and salary amounts are never shown.</p></div>
      </div>

      <div class="filters">
        <input class="input-sm grow" type="search" placeholder="Search name, lead, client…" [(ngModel)]="f.search" (ngModelChange)="search$.next()" />
        <select class="select-sm" [(ngModel)]="f.user_id" (ngModelChange)="reload()" aria-label="Person">
          <option value="">Everyone</option>
          @for (p of people(); track p.id) { <option [value]="p.id">{{ p.name }}</option> }
        </select>
        <select class="select-sm" [(ngModel)]="f.type" (ngModelChange)="reload()" aria-label="What">
          <option value="">Everything</option>
          @for (t of types(); track t) { <option [value]="t">{{ typeName(t, true) }}</option> }
        </select>
        <label class="dt">From <input class="input-sm" type="date" [(ngModel)]="f.from" (ngModelChange)="reload()" /></label>
        <label class="dt">To <input class="input-sm" type="date" [(ngModel)]="f.to" (ngModelChange)="reload()" /></label>
        @if (filtered()) { <button class="btn-ghost sm" (click)="clear()">Clear</button> }
      </div>

      @for (e of list(); track e.id; let i = $index) {
        @if (day(i); as d) { <div class="day">{{ d }}</div> }
        <div class="ev" [class.bad]="e.action === 'login_failed' || e.action === 'deleted'">
          <span class="av" [class.sys]="!e.user" [class.lock]="e.type === 'login' || e.action === 'permissions' || e.action === 'viewed_passwords'">{{ icon(e) }}</span>
          <div class="body">
            <p>@if (e.action === 'login_failed') { Wrong password typed for <b class="lbl">{{ e.label }}</b> }
              @else { <b>{{ e.user?.name ?? 'System' }}</b> {{ sentence(e) }} @if (e.label && e.label !== e.user?.name) { <b class="lbl">{{ e.label }}</b> } }</p>
            @if (changeList(e); as ch) {
              @if (ch.length) {
                <div class="chs">
                  @for (c of (open().has(e.id) ? ch : ch.slice(0, 4)); track c.k) {
                    <span class="ch"><i>{{ c.k }}</i>@if (c.from !== undefined) { <s>{{ c.from || '—' }}</s> → } <em>{{ c.to || '—' }}</em></span>
                  }
                  @if (ch.length > 4 && !open().has(e.id)) { <button class="more" (click)="toggle(e.id)">+{{ ch.length - 4 }} more</button> }
                </div>
              }
            }
          </div>
          <small class="tm" [title]="e.ip ? 'IP ' + e.ip : ''">{{ time(e.at) }}</small>
        </div>
      } @empty { <p class="hint pad">{{ loading() ? 'Loading…' : filtered() ? 'Nothing matches these filters.' : 'Nothing recorded yet. Changes made from now on will show here.' }}</p> }

      @if (meta().last_page > 1) {
        <div class="pager">
          <button class="btn-ghost sm" [disabled]="meta().current_page <= 1" (click)="go(meta().current_page - 1)">← Newer</button>
          <span>Page {{ meta().current_page }} of {{ meta().last_page }} · {{ meta().total }} entries</span>
          <button class="btn-ghost sm" [disabled]="meta().current_page >= meta().last_page" (click)="go(meta().current_page + 1)">Older →</button>
        </div>
      }
    </div>
  `,
  styles: `
    .top { display: flex; gap: 14px; align-items: flex-start; margin-bottom: 14px; }
    .ic { width: 44px; height: 44px; border-radius: 12px; display: grid; place-items: center; background: var(--brand-soft); font-size: 22px; flex: none; }
    .top h3 { font-size: 16px; margin-bottom: 2px; }
    .hint { color: var(--ink-3); font-size: 13px; line-height: 1.5; } .pad { padding: 16px 4px; }
    .filters { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 8px; }
    .filters .grow { flex: 1 1 220px; }
    .dt { display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; color: var(--ink-3); }
    .sm { padding: 6px 12px; font-size: 13px; }
    .day { position: sticky; top: 0; z-index: 1; background: var(--card); font-size: 12px; font-weight: 700; color: var(--ink-3); text-transform: uppercase; letter-spacing: 0.05em; padding: 14px 4px 6px; }
    .ev { display: flex; gap: 12px; align-items: flex-start; padding: 10px 4px; border-top: 1px solid var(--line); animation: rise 0.25s var(--ease) both; }
    .av { width: 34px; height: 34px; border-radius: 50%; display: grid; place-items: center; flex: none; font-size: 12px; font-weight: 700; color: #fff; background: linear-gradient(135deg, var(--brand-deep), var(--brand)); }
    .av.sys { background: #eef0f4; color: var(--ink-3); font-size: 15px; }
    .av.lock { background: #eaf1fd; color: #1f4fb3; font-size: 15px; }
    .bad .av { background: #fdecec; color: #c0262d; }
    .body { flex: 1; min-width: 0; } .body p { font-size: 14px; line-height: 1.45; color: var(--ink-2); } .body p b { color: var(--ink); }
    .lbl { word-break: break-word; }
    .chs { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
    .ch { font-size: 12.5px; background: var(--bg); border: 1px solid var(--line); border-radius: 8px; padding: 3px 8px; color: var(--ink-2); max-width: 100%; overflow-wrap: anywhere; }
    .ch i { font-style: normal; font-weight: 600; color: var(--ink); margin-right: 6px; } .ch s { color: var(--ink-3); } .ch em { font-style: normal; color: var(--ink); }
    .more { border: 0; background: none; color: var(--brand-deep); font: inherit; font-size: 12.5px; font-weight: 600; cursor: pointer; }
    .tm { color: var(--ink-3); font-size: 12px; flex: none; padding-top: 2px; }
    .pager { display: flex; justify-content: space-between; align-items: center; gap: 8px; padding-top: 14px; border-top: 1px solid var(--line); font-size: 13px; color: var(--ink-3); }
    @media (max-width: 860px) { .dt { flex: 1 1 45%; } .dt input { flex: 1; } .pager span { display: none; } }
    @media (prefers-reduced-motion: reduce) { .ev { animation: none; } }
  `,
})
export class ActivityLogView {
  private http = inject(HttpClient);
  protected readonly list = signal<Entry[]>([]);
  protected readonly meta = signal<Page['meta']>({ current_page: 1, last_page: 1, total: 0 });
  protected readonly people = signal<Page['people']>([]);
  protected readonly types = signal<string[]>([]);
  protected readonly keep = signal(365);
  protected readonly loading = signal(true);
  protected readonly open = signal(new Set<number>());
  protected f = { search: '', user_id: '', type: '', from: '', to: '' };
  protected readonly search$ = new Subject<void>();
  private ver = signal(0);
  protected readonly filtered = computed(() => (this.ver(), !!(this.f.search || this.f.user_id || this.f.type || this.f.from || this.f.to)));

  constructor() {
    this.search$.pipe(debounceTime(300)).subscribe(() => this.reload());
    this.load(1);
  }

  reload(): void {
    this.ver.update((v) => v + 1);
    this.load(1);
  }

  clear(): void {
    this.f = { search: '', user_id: '', type: '', from: '', to: '' };
    this.reload();
  }

  go(page: number): void {
    this.load(page);
  }

  private load(page: number): void {
    this.loading.set(true);
    const params: Record<string, string> = { page: String(page) };
    for (const [k, v] of Object.entries(this.f)) if (v) params[k] = v;
    this.http.get<Page>(`${API_BASE}/settings/activity`, { params }).subscribe({
      next: (r) => {
        this.list.set(r.data);
        this.meta.set(r.meta);
        this.people.set(r.people);
        this.types.set(r.types);
        this.keep.set(r.keep_days);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }

  toggle(id: number): void {
    this.open.update((s) => new Set(s).add(id));
  }

  typeName(t: string | null, plural = false): string {
    const n = (t && TYPE[t]) || (t ?? '').replace(/_/g, ' ');
    if (!plural) return n;
    return n === 'sign-in' ? 'Sign-ins' : n.charAt(0).toUpperCase() + n.slice(1) + (n.endsWith('s') ? '' : 's');
  }

  sentence(e: Entry): string {
    const t = this.typeName(e.type);
    switch (e.action) {
      case 'created': return `added ${t}`;
      case 'updated': return e.type === 'settings' ? 'updated' : `changed ${t}`;
      case 'deleted': return `deleted ${t}`;
      case 'login': return 'signed in';
      case 'logout': return 'signed out';
      case 'login_failed': return '— wrong password for';
      case 'permissions': return e.type === 'role' ? 'changed permissions for' : 'changed the access of';
      case 'downloaded': return 'downloaded backup';
      case 'viewed_passwords': return 'viewed the saved passwords of';
      default: return `${e.action.replace(/_/g, ' ')} ${t}`;
    }
  }

  icon(e: Entry): string {
    if (e.action === 'login_failed') return '!';
    if (e.type === 'login') return '🔑';
    if (e.action === 'permissions' || e.action === 'viewed_passwords') return '🔒';
    if (!e.user) return '⚙';
    return e.user.name.split(/\s+/).map((w) => w[0]).slice(0, 2).join('').toUpperCase();
  }

  /** The "Field: old → new" chips (or the permissions added / removed). */
  changeList(e: Entry): { k: string; from?: string | null; to: string | null }[] {
    const c = e.changes as Record<string, unknown>;
    if (Array.isArray(c['added']) || Array.isArray(c['removed'])) {
      return [
        ...((c['added'] as string[] | undefined) ?? []).map((k) => ({ k: '+ ' + k, to: 'allowed' })),
        ...((c['removed'] as string[] | undefined) ?? []).map((k) => ({ k: '− ' + k, to: 'removed' })),
      ];
    }
    return Object.entries(e.changes as Record<string, [string | null, string | null]>).map(([k, v]) => ({ k: this.field(k), from: this.val(v[0]), to: this.val(v[1]) }));
  }

  /** "2026-11-16 11:05:52" → "16 Nov 2026, 11:05"; "2026-11-16" → "16 Nov 2026". */
  val(v: string | null): string | null {
    const m = v?.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
    if (!v || !m) return v;
    const d = new Date(+m[1]!, +m[2]! - 1, +m[3]!, +(m[4] ?? 0), +(m[5] ?? 0));
    const date = d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
    return m[4] ? `${date}, ${d.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' })}` : date;
  }

  field(k: string): string {
    if (FIELD[k]) return FIELD[k]!;
    if (k.includes('.')) return k; // permission keys
    const s = k.replace(/_(id|at)$/, '').replace(/_/g, ' ');
    return s.charAt(0).toUpperCase() + s.slice(1);
  }

  day(i: number): string | null {
    const l = this.list();
    const d = new Date(l[i]!.at).toDateString();
    if (i > 0 && new Date(l[i - 1]!.at).toDateString() === d) return null;
    if (d === new Date().toDateString()) return 'Today';
    if (d === new Date(Date.now() - 86400000).toDateString()) return 'Yesterday';
    return new Date(l[i]!.at).toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'short', year: 'numeric' });
  }

  time(iso: string): string {
    return new Date(iso).toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' });
  }
}
