import { Component, OnDestroy, computed, inject, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { readErrors } from '../../core/http-errors';
import { Attendance, AttendanceMe, AttendanceMode, MODE_LABEL, WorkService } from '../../core/work.service';
import { Toast } from '../../shared/toast.service';

/** Big check in / check out card with the live clock and hours so far. */
@Component({
  selector: 'app-check-in-card',
  imports: [FormsModule],
  template: `
    <section class="card cin" [class.in]="state() === 'in'" [class.out]="state() === 'out'">
      <div class="top">
        <div>
          <span class="eyebrow">{{ today }}</span>
          <div class="clock">{{ clock() }}</div>
        </div>
        @if (me()?.today; as t) {
          <div class="chips">
            <span class="badge b-info">{{ modeLabel[t.mode] }}</span>
            @if (t.late) { <span class="badge b-warn">Late</span> }
          </div>
        }
      </div>

      @switch (state()) {
        @case ('loading') { <p class="hint">Loading…</p> }
        @case ('none') {
          <p class="sub">You haven’t checked in yet.@if (me()?.late_after) { Check in by <b>{{ me()!.late_after }}</b> to be on time. }</p>
          @if (askMode()) {
            <div class="away">
              <p>{{ awayMsg() }}</p>
              <div class="modes">
                <button class="chip" [class.on]="mode === 'field'" (click)="mode = 'field'">🚗 Field visit</button>
                <button class="chip" [class.on]="mode === 'wfh'" (click)="mode = 'wfh'">🏠 Work from home</button>
              </div>
              <input class="input-sm" [(ngModel)]="note" placeholder="Where / why (e.g. client meeting at Infopark)" maxlength="300" />
            </div>
          }
          <button class="btn big" (click)="checkIn()" [disabled]="busy()">
            @if (busy()) { <span class="spinner"></span> Checking in… } @else if (askMode()) { Check in } @else if (remote()) { 🏠 Check in · Work from home } @else { Check in }
          </button>
          @if (!askMode()) {
            @if (!remote()) { <p class="hint center">At the office it’s marked Office; anywhere else, Work from home.</p> }
            <button class="linkish" (click)="askMode.set(true); mode = 'field'">On a field visit today?</button>
          }
        }
        @case ('in') {
          <p class="sub">Checked in at <b>{{ time(me()!.today!.check_in_at) }}</b> · <b>{{ hours(worked()) }}</b> so far</p>
          <button class="btn big outb" (click)="checkOut()" [disabled]="busy()">@if (busy()) { <span class="spinner"></span> } Check out</button>
        }
        @case ('out') {
          <p class="sub">Done for today: {{ time(me()!.today!.check_in_at) }} – {{ time(me()!.today!.check_out_at) }} · <b>{{ hours(me()!.today!.minutes ?? 0) }}</b></p>
        }
      }
    </section>
  `,
  styles: `
    .cin { position: relative; overflow: hidden; }
    .cin::after { content: ''; position: absolute; right: -60px; top: -60px; width: 180px; height: 180px; border-radius: 50%;
      background: radial-gradient(circle, rgba(237, 143, 33, 0.22), transparent 70%); pointer-events: none; }
    .cin.in::after { background: radial-gradient(circle, rgba(22, 163, 148, 0.22), transparent 70%); }
    .top { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; }
    .eyebrow { font-size: 12px; letter-spacing: 0.08em; text-transform: uppercase; color: var(--ink-3); }
    .clock { font-size: 34px; font-weight: 800; letter-spacing: -0.02em; font-variant-numeric: tabular-nums; margin-top: 2px; }
    .chips { display: flex; gap: 6px; flex-wrap: wrap; }
    .sub { color: var(--ink-2); margin: 8px 0 14px; }
    .big { width: 100%; justify-content: center; height: 50px; font-size: 16px; }
    .outb { background: #16181d; box-shadow: none; }
    .outb:hover:not(:disabled) { background: #000; }
    .center { text-align: center; margin-top: 8px; font-size: 12.5px; }
    .linkish { display: block; margin: 10px auto 0; background: none; border: 0; color: var(--brand-deep); font: inherit; font-size: 13px; font-weight: 600; cursor: pointer; }
    .away { background: var(--brand-soft); border-radius: 14px; padding: 12px; margin-bottom: 12px; display: grid; gap: 10px; }
    .away p { font-size: 13.5px; color: var(--ink-2); }
    .modes { display: flex; gap: 8px; flex-wrap: wrap; }
    .chip { border: 1px solid var(--line); background: var(--card); padding: 9px 14px; border-radius: 99px; cursor: pointer; font-size: 14px; }
    .chip.on { border-color: var(--brand); color: var(--brand-deep); background: #fff; font-weight: 600; }
    .away .input-sm { width: 100%; }
  `,
})
export class CheckInCard implements OnDestroy {
  private api = inject(WorkService);
  private toast = inject(Toast);
  changed = output<Attendance>();
  protected readonly modeLabel = MODE_LABEL;
  protected readonly me = signal<AttendanceMe | null>(null);
  protected readonly busy = signal(false);
  protected readonly askMode = signal(false);
  protected readonly awayMsg = signal('Choose how you are working today:');
  protected readonly now = signal(new Date());
  protected mode: AttendanceMode = 'field';
  protected note = '';
  protected readonly today = new Date().toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'long' });
  private tick = setInterval(() => this.now.set(new Date()), 1000);

  protected readonly clock = computed(() => this.now().toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', second: '2-digit' }));
  protected readonly state = computed(() => {
    const m = this.me();
    if (!m) return 'loading';
    if (!m.today) return 'none';
    return m.today.check_out_at || m.today.auto_closed ? 'out' : 'in';
  });
  /** No office location set: the team works from home by default. */
  protected readonly remote = computed(() => this.me()?.office_set === false);
  protected readonly worked = computed(() => {
    const t = this.me()?.today;
    return t?.check_in_at ? Math.max(0, Math.round((this.now().getTime() - new Date(t.check_in_at).getTime()) / 60000)) : 0;
  });

  constructor() {
    this.load();
  }

  ngOnDestroy(): void {
    clearInterval(this.tick);
  }

  load(): void {
    this.api.me().subscribe((m) => this.me.set(m));
  }

  async checkIn(): Promise<void> {
    this.busy.set(true);
    const asking = this.askMode();
    // Location is only needed at the office or on a field visit.
    const needFix = !this.remote() || (asking && this.mode === 'field');
    const fix = needFix ? await this.api.locate() : null;
    const body = { ...(fix ?? {}), ...(asking ? { mode: this.mode, note: this.note.trim() || undefined } : {}) };
    this.api.checkIn(body).subscribe({
      next: (a) => {
        this.busy.set(false);
        this.me.update((m) => (m ? { ...m, today: a } : m));
        this.toast.show(a.late ? `Checked in (late) · ${MODE_LABEL[a.mode]}` : `Checked in · ${MODE_LABEL[a.mode]}. Have a great day!`);
        this.changed.emit(a);
      },
      error: (e) => {
        this.busy.set(false);
        const r = readErrors(e);
        if (r.fields['mode']) {
          // Away from the office: ask how they are working.
          this.awayMsg.set(r.fields['mode']);
          this.askMode.set(true);
        } else {
          this.toast.show(r.fields['location'] || Object.values(r.fields)[0] || r.message || 'Could not check in.');
          if (r.fields['location']) this.askMode.set(true);
        }
      },
    });
  }

  async checkOut(): Promise<void> {
    this.busy.set(true);
    const t = this.me()?.today;
    const fix = t && t.mode !== 'wfh' ? await this.api.locate() : null;
    this.api.checkOut(fix ?? {}).subscribe({
      next: (a) => {
        this.busy.set(false);
        this.me.update((m) => (m ? { ...m, today: a } : m));
        this.toast.show(`Checked out · ${this.hours(a.minutes ?? 0)} today.`);
        this.changed.emit(a);
      },
      error: (e) => {
        this.busy.set(false);
        this.toast.show(Object.values(readErrors(e).fields)[0] || 'Could not check out.');
      },
    });
  }

  time(iso: string | null): string {
    return iso ? new Date(iso).toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' }) : '—';
  }

  hours(min: number): string {
    return `${Math.floor(min / 60)}h ${String(min % 60).padStart(2, '0')}m`;
  }
}
