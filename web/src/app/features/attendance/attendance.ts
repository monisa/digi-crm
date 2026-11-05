import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { AuthService } from '../../core/auth/auth.service';
import { readErrors } from '../../core/http-errors';
import { Attendance, AttendanceMode, AttendanceSettings, MODE_LABEL, Register, RegisterRow, Timesheet, TimesheetDay, WorkService } from '../../core/work.service';
import { Sheet } from '../../shared/sheet';
import { Toast } from '../../shared/toast.service';
import { CheckInCard } from './check-in-card';

type Tab = 'mine' | 'register' | 'people' | 'settings';

const STATUS: Record<string, [string, string]> = {
  present: ['Present', 'b-good'], late: ['Late', 'b-warn'], half: ['Half day', 'b-warn'], leave: ['On leave', 'b-info'],
  absent: ['Absent', 'b-crit'], weekly_off: ['Sunday', ''], upcoming: ['', ''],
};

@Component({
  selector: 'app-attendance',
  imports: [FormsModule, CheckInCard, Sheet],
  template: `
    <div class="page-head">
      <div><h1>Attendance</h1><p>Check in when you start work and check out when you finish, from your phone or laptop.</p></div>
      @if (canManage() && auth.can('attendance.export')) { <div class="actions"><button class="btn-ghost" (click)="exportCsv()">⬇ Export month</button></div> }
    </div>

    <div class="top">
      <app-check-in-card (changed)="refresh()" />
      @if (sheet(); as s) {
        <div class="card kpis">
          <h3>{{ monthLabel() }} so far</h3>
          <div class="k"><b>{{ s.totals.present }}</b><span>days present</span></div>
          <div class="k"><b>{{ hrs(s.totals.minutes) }}</b><span>hours worked</span></div>
          <div class="k"><b [class.warn]="s.totals.late">{{ s.totals.late }}</b><span>late</span></div>
          <div class="k"><b>{{ s.totals.leave }}</b><span>leave</span></div>
          <div class="k"><b>{{ s.totals.tasks }}</b><span>tasks done</span></div>
        </div>
      }
    </div>

    <div class="tabs2" role="tablist">
      <button role="tab" [class.on]="tab() === 'mine'" (click)="open('mine')">My timesheet</button>
      @if (canManage()) {
        <button role="tab" [class.on]="tab() === 'register'" (click)="open('register')">Team today</button>
        <button role="tab" [class.on]="tab() === 'people'" (click)="open('people')">Team timesheets</button>
      }
      @if (auth.can('settings.edit')) { <button role="tab" [class.on]="tab() === 'settings'" (click)="open('settings')">Settings</button> }
    </div>

    @switch (tab()) {
      @case ('register') {
        <div class="card">
          <div class="toolbar">
            <input class="input-sm" type="date" [(ngModel)]="regDate" (ngModelChange)="loadRegister()" [max]="todayIso" aria-label="Day" />
            @if (reg(); as r) {
              <div class="sumline"><span class="g">{{ r.summary.present }} present</span><span class="w">{{ r.summary.late }} late</span>
                <span class="i">{{ r.summary.leave }} on leave</span><span class="c">{{ r.summary.absent }} absent</span></div>
            }
          </div>
          @for (row of reg()?.rows ?? []; track row.user.id; let i = $index) {
            <div class="rrow" [style.animation-delay.s]="i * 0.02">
              <span class="ini">{{ initials(row.user.name) }}</span>
              <div class="grow"><b>{{ row.user.name }}</b>
                <small>@if (row.attendance; as a) { {{ time(a.check_in_at) }} – {{ a.check_out_at ? time(a.check_out_at) : (a.auto_closed ? 'no check-out' : 'working') }} · {{ modeLabel[a.mode] }}@if (a.distance !== null && a.mode !== 'office') { · {{ km(a.distance) }} away }@if (a.note) { · {{ a.note }} } } @else if (row.leave) { {{ row.leave }} leave } @else { Not checked in }</small></div>
              @if (row.attendance?.map) { <a class="btn-ghost sm" [href]="row.attendance!.map" target="_blank" rel="noopener" title="See on map">📍</a> }
              <span class="badge" [class]="'badge ' + st(row.status)[1]">{{ st(row.status)[0] || row.status }}</span>
              <button class="btn-ghost sm" (click)="edit(row.user, regDate, row.attendance)">Edit</button>
            </div>
          } @empty { <div class="empty-box">Loading…</div> }
        </div>
      }
      @case ('settings') {
        @if (cfg(); as c) {
          <form class="card set" (ngSubmit)="saveSettings()">
            <h3>Office location</h3>
            <p class="hint">Check-ins within this distance of the office count as <b>Office</b>; anywhere else they count as <b>Work from home</b> (people can pick <b>Field visit</b> instead). Leave it empty and every check-in is Work from home, without asking for location.</p>
            <div class="row-3">
              <div class="field compact"><label for="a-lat">Latitude</label><input id="a-lat" name="lat" type="number" step="any" [(ngModel)]="c.office_lat" /></div>
              <div class="field compact"><label for="a-lng">Longitude</label><input id="a-lng" name="lng" type="number" step="any" [(ngModel)]="c.office_lng" /></div>
              <div class="field compact"><label for="a-r">Radius (metres)</label><input id="a-r" name="r" type="number" min="30" [(ngModel)]="c.radius_m" /></div>
            </div>
            <button type="button" class="btn-ghost" (click)="useHere()" [disabled]="busy()">📍 Use my current location (stand in the office)</button>
            @if (c.office_lat !== null) { <button type="button" class="btn-ghost" (click)="clearOffice()">Remove office (work from home)</button> }
            @if (c.office_lat !== null && c.office_lng !== null) { <a class="maplink" [href]="'https://maps.google.com/?q=' + c.office_lat + ',' + c.office_lng" target="_blank" rel="noopener">Check it on the map ↗</a> }
            <h3 style="margin-top: 22px">Rules</h3>
            <div class="row-3">
              <div class="field compact"><label for="a-late">Late after</label><input id="a-late" name="late" type="time" [(ngModel)]="c.late_after" /></div>
              <div class="field compact"><label for="a-half">Half day if under (hours)</label><input id="a-half" name="half" type="number" min="1" max="12" step="0.5" [(ngModel)]="c.half_day_hours" /></div>
              <label class="check tick"><input type="checkbox" name="req" [(ngModel)]="c.require_location" /> Location needed to check in at the office or on a field visit</label>
            </div>
            <div class="form-actions"><button class="btn" type="submit" [disabled]="busy()">Save</button></div>
          </form>
        }
      }
      @default {
        @if (tab() === 'people') {
          <div class="toolbar pick">
            <select class="select-sm" [(ngModel)]="personId" (ngModelChange)="loadSheet()" aria-label="Person">
              @for (p of people(); track p.id) { <option [ngValue]="p.id">{{ p.name }}</option> }
            </select>
            <select class="select-sm" [(ngModel)]="month" (ngModelChange)="loadSheet()" aria-label="Month">@for (m of months; track m.key) { <option [value]="m.key">{{ m.label }}</option> }</select>
          </div>
        } @else {
          <div class="toolbar pick"><select class="select-sm" [(ngModel)]="month" (ngModelChange)="loadSheet()" aria-label="Month">@for (m of months; track m.key) { <option [value]="m.key">{{ m.label }}</option> }</select></div>
        }
        @if (viewSheet(); as s) {
          @if (tab() === 'people') {
            <div class="mini"><b>{{ s.user.name }}</b> · {{ s.totals.present }} present · {{ s.totals.late }} late · {{ s.totals.half }} half · {{ s.totals.leave }} leave · {{ s.totals.absent }} absent · {{ hrs(s.totals.minutes) }} h · {{ s.totals.tasks }} tasks</div>
          }
          <div class="card ts">
            <div class="th"><span>Day</span><span>In</span><span>Out</span><span>Hours</span><span>Where</span><span>Tasks</span><span></span></div>
            @for (d of s.days; track d.date) {
              @if (d.status !== 'upcoming' && d.date <= todayIso) {
                <div class="tr" [class.off]="d.off" [class.today]="d.date === todayIso">
                  <span class="day"><b>{{ dayNum(d.date) }}</b><small>{{ dow(d.date) }}</small></span>
                  @if (d.attendance; as a) {
                    <span>{{ time(a.check_in_at) }}@if (a.late) { <i class="late">late</i> }</span>
                    <span>{{ a.check_out_at ? time(a.check_out_at) : (a.auto_closed ? '—' : '…') }}</span>
                    <span><b>{{ a.minutes !== null ? hrs(a.minutes) : '—' }}</b></span>
                    <span class="where">{{ modeLabel[a.mode] }}@if (a.edited) { <i>edited</i> }</span>
                  } @else {
                    <span class="span4">@if (d.leave) { <span class="badge b-info">{{ d.leave }} leave</span> } @else if (d.off) { <span class="offtxt">{{ offLabel(d.off) }}</span> } @else if (d.status === 'not_started') { <span class="offtxt">—</span> } @else { <span class="badge b-crit">Absent</span> }</span>
                  }
                  <span>{{ d.tasks_done || '' }}</span>
                  <span>@if (canManage() && !d.off) { <button class="btn-ghost sm" (click)="edit(s.user, d.date, d.attendance)">Edit</button> }</span>
                </div>
              }
            }
          </div>
        } @else { <div class="card empty-box">Loading…</div> }
      }
    }

    @if (editing(); as e) {
      <app-sheet [title]="'Attendance · ' + e.user.name" [subtitle]="longDate(e.date)" (closed)="editing.set(null)">
        <form (ngSubmit)="saveDay()">
          @if (editErr()) { <div class="alert error">{{ editErr() }}</div> }
          <div class="row-2">
            <div class="field compact"><label for="e-in">Check in</label><input id="e-in" name="in" type="time" [(ngModel)]="e.check_in" required /></div>
            <div class="field compact"><label for="e-out">Check out</label><input id="e-out" name="out" type="time" [(ngModel)]="e.check_out" /></div>
          </div>
          <div class="field compact"><label for="e-m">Where</label>
            <select id="e-m" name="m" [(ngModel)]="e.mode">@for (m of modes; track m[0]) { <option [value]="m[0]">{{ m[1] }}</option> }</select></div>
          <div class="field compact"><label for="e-n">Note</label><input id="e-n" name="n" [(ngModel)]="e.note" placeholder="e.g. forgot to check in" /></div>
          <div class="form-actions"><button type="button" class="btn-ghost" (click)="editing.set(null)">Cancel</button><button class="btn" type="submit" [disabled]="busy()">Save</button></div>
        </form>
      </app-sheet>
    }
  `,
  styles: `
    .top { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.2fr); gap: 16px; margin-bottom: 20px; }
    .kpis { display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; align-content: start; }
    .kpis h3 { grid-column: 1 / -1; font-size: 15px; }
    .k { background: var(--bg); border-radius: 14px; padding: 12px; } .k b { display: block; font-size: 22px; } .k span { font-size: 12px; color: var(--ink-3); }
    .k b.warn { color: #b54708; }
    .toolbar { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 12px; }
    .sumline { display: flex; gap: 14px; flex-wrap: wrap; font-size: 13.5px; font-weight: 600; }
    .sumline .g { color: #11803b; } .sumline .w { color: #b54708; } .sumline .i { color: #2f6fde; } .sumline .c { color: #c0262d; }
    .rrow { display: flex; align-items: center; gap: 12px; padding: 12px 4px; border-top: 1px solid var(--line); animation: rise 0.35s var(--ease) both; }
    .ini { width: 38px; height: 38px; border-radius: 12px; display: grid; place-items: center; font-weight: 700; background: #16181d; color: var(--brand); flex: none; font-size: 13px; }
    .grow { flex: 1; min-width: 0; } .grow b { display: block; } .grow small { color: var(--ink-3); font-size: 12.5px; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .sm { padding: 6px 10px; font-size: 12.5px; text-decoration: none; }
    .mini { font-size: 13.5px; color: var(--ink-2); margin-bottom: 10px; }
    .ts { padding: 6px 0; }
    .th, .tr { display: grid; grid-template-columns: 70px 1fr 1fr 1fr 1.4fr 60px 70px; gap: 8px; align-items: center; padding: 10px 18px; }
    .th { font-size: 11.5px; letter-spacing: 0.06em; text-transform: uppercase; color: var(--ink-3); border-bottom: 1px solid var(--line); }
    .tr { border-bottom: 1px solid var(--line); font-size: 14px; } .tr:last-child { border-bottom: 0; }
    .tr.off { background: var(--bg); color: var(--ink-3); } .tr.today { box-shadow: inset 3px 0 0 var(--brand); }
    .day b { font-size: 16px; } .day small { display: block; font-size: 11.5px; color: var(--ink-3); }
    .span4 { grid-column: span 4; } .offtxt { font-size: 13px; }
    .late, .where i { font-style: normal; font-size: 11px; font-weight: 700; color: #b54708; background: #fff4e5; border-radius: 99px; padding: 1px 7px; margin-left: 6px; }
    .where i { color: var(--ink-3); background: var(--bg); }
    .set h3 { font-size: 15px; margin-bottom: 4px; } .set .hint { margin-bottom: 12px; }
    .row-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; align-items: end; }
    .maplink { margin-left: 12px; font-size: 13px; color: var(--brand-deep); }
    .tick { display: flex; gap: 8px; align-items: center; font-size: 13.5px; padding-bottom: 12px; }
    @media (max-width: 860px) {
      .top { grid-template-columns: 1fr; }
      .kpis { grid-template-columns: repeat(3, 1fr); }
      .th { display: none; }
      .tr { grid-template-columns: 52px 1fr 1fr 1fr 44px; padding: 10px 12px; }
      .tr > span:nth-child(5), .tr > span:nth-child(6) { display: none; }
      .span4 { grid-column: span 3; }
      .row-3 { grid-template-columns: 1fr; }
    }
  `,
})
export class AttendancePage {
  protected readonly auth = inject(AuthService);
  private api = inject(WorkService);
  private toast = inject(Toast);
  protected readonly modeLabel = MODE_LABEL;
  protected readonly modes = Object.entries(MODE_LABEL) as [AttendanceMode, string][];
  protected readonly canManage = computed(() => this.auth.can('attendance.edit'));
  protected readonly tab = signal<Tab>('mine');
  protected readonly sheet = signal<Timesheet | null>(null); // mine
  protected readonly other = signal<Timesheet | null>(null); // team member
  protected readonly viewSheet = computed(() => (this.tab() === 'people' ? this.other() : this.sheet()));
  protected readonly reg = signal<Register | null>(null);
  protected readonly cfg = signal<AttendanceSettings | null>(null);
  protected readonly people = signal<{ id: number; name: string }[]>([]);
  protected readonly busy = signal(false);
  protected readonly editing = signal<{ user: { id: number; name: string }; date: string; check_in: string; check_out: string; mode: AttendanceMode; note: string } | null>(null);
  protected readonly editErr = signal('');
  protected readonly todayIso = this.iso(new Date());
  protected regDate = this.todayIso;
  protected personId: number | null = null;

  protected readonly months = Array.from({ length: 12 }, (_, i) => {
    const d = new Date();
    d.setDate(1);
    d.setMonth(d.getMonth() - i);
    return { key: `${d.getFullYear()}-${d.getMonth() + 1}`, year: d.getFullYear(), month: d.getMonth() + 1, label: d.toLocaleDateString('en-IN', { month: 'long', year: 'numeric' }) };
  });
  protected month = this.months[0]!.key;
  protected readonly monthLabel = () => this.months.find((m) => m.key === this.month)?.label ?? '';

  constructor() {
    this.loadSheet();
  }

  open(t: Tab): void {
    this.tab.set(t);
    if (t === 'register') this.loadRegister();
    if (t === 'people') {
      if (!this.people().length) {
        this.api.register(this.todayIso).subscribe((r) => {
          this.people.set(r.rows.map((x) => x.user));
          this.personId ??= r.rows[0]?.user.id ?? null;
          this.loadSheet();
        });
      } else this.loadSheet();
    }
    if (t === 'settings' && !this.cfg()) this.api.settings().subscribe((c) => this.cfg.set(c));
    if (t === 'mine') this.loadSheet();
  }

  refresh(): void {
    this.loadSheet();
    if (this.tab() === 'register') this.loadRegister();
  }

  loadSheet(): void {
    const m = this.months.find((x) => x.key === this.month)!;
    if (this.tab() === 'people') {
      if (!this.personId) return;
      this.other.set(null);
      this.api.timesheet(m.year, m.month, this.personId).subscribe((s) => this.other.set(s));
    } else {
      this.api.timesheet(m.year, m.month).subscribe((s) => this.sheet.set(s));
    }
  }

  loadRegister(): void {
    this.reg.set(null);
    this.api.register(this.regDate).subscribe((r) => this.reg.set(r));
  }

  edit(user: { id: number; name: string }, date: string, a: Attendance | null): void {
    this.editErr.set('');
    this.editing.set({
      user, date,
      check_in: a?.check_in_at ? this.hm(a.check_in_at) : '09:30',
      check_out: a?.check_out_at ? this.hm(a.check_out_at) : '',
      mode: a?.mode ?? 'office', note: a?.note ?? '',
    });
  }

  saveDay(): void {
    const e = this.editing()!;
    this.busy.set(true);
    this.api.saveDay({ user_id: e.user.id, date: e.date, check_in: e.check_in, check_out: e.check_out || null, mode: e.mode, note: e.note || undefined }).subscribe({
      next: () => {
        this.busy.set(false);
        this.editing.set(null);
        this.toast.show('Attendance saved.');
        this.refresh();
      },
      error: (err) => {
        this.busy.set(false);
        const r = readErrors(err);
        this.editErr.set(Object.values(r.fields)[0] || r.message);
      },
    });
  }

  async useHere(): Promise<void> {
    this.busy.set(true);
    const fix = await this.api.locate();
    this.busy.set(false);
    if (!fix) return this.toast.show('Could not get your location. Allow location access in the browser.');
    this.cfg.update((c) => (c ? { ...c, office_lat: fix.lat!, office_lng: fix.lng! } : c));
    this.toast.show(`Location set (accurate to about ${fix.accuracy} m). Press Save.`);
  }

  clearOffice(): void {
    this.cfg.update((c) => (c ? { ...c, office_lat: null, office_lng: null } : c));
  }

  saveSettings(): void {
    this.busy.set(true);
    this.api.saveSettings(this.cfg()!).subscribe({
      next: (c) => {
        this.busy.set(false);
        this.cfg.set(c);
        this.toast.show('Attendance settings saved.');
      },
      error: (e) => {
        this.busy.set(false);
        const r = readErrors(e);
        this.toast.show(Object.values(r.fields)[0] || r.message);
      },
    });
  }

  exportCsv(): void {
    const m = this.months.find((x) => x.key === this.month)!;
    this.api.exportMonth(m.year, m.month).subscribe((blob) => {
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = `attendance-${m.year}-${String(m.month).padStart(2, '0')}.csv`;
      a.click();
      URL.revokeObjectURL(a.href);
    });
  }

  st(s: string): [string, string] {
    return STATUS[s] ?? [s.startsWith('holiday') ? s.replace('holiday: ', '') : s, 'b-info'];
  }
  offLabel(off: string): string {
    return off === 'weekly_off' ? 'Sunday' : off.replace('holiday: ', '🎉 ');
  }
  time(iso: string | null): string {
    return iso ? new Date(iso).toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' }) : '—';
  }
  hm(iso: string): string {
    const d = new Date(iso);
    return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
  }
  hrs(min: number): string {
    return `${Math.floor(min / 60)}:${String(min % 60).padStart(2, '0')}`;
  }
  km(m: number): string {
    return m >= 1000 ? `${(m / 1000).toFixed(1)} km` : `${m} m`;
  }
  dayNum(d: string): string {
    return String(+d.slice(8, 10));
  }
  dow(d: string): string {
    return new Date(d + 'T00:00').toLocaleDateString('en-IN', { weekday: 'short' });
  }
  longDate(d: string): string {
    return new Date(d + 'T00:00').toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
  }
  initials(n: string): string {
    return n.split(/\s+/).map((w) => w[0]).slice(0, 2).join('').toUpperCase();
  }
  private iso(d: Date): string {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  }
}
