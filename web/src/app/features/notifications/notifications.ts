import { Component, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { Alert, CATEGORY_ICON, NotificationsService, Pref, ago } from '../../core/notifications.service';
import { Toast } from '../../shared/toast.service';

/** All notifications, with filters and email settings. */
@Component({
  selector: 'app-notifications',
  template: `
    <div class="page-head">
      <div><h1>Notifications</h1><p>Everything that needs your attention. Bell alerts always arrive; choose which ones also come by email.</p></div>
      @if (svc.unread()) { <div class="actions"><button class="btn-ghost" (click)="readAll()">Mark all read ({{ svc.unread() }})</button></div> }
    </div>
    <div class="layout">
      <section class="card">
        <div class="seg2 cats">
          @for (c of cats; track c[0]) { <button [class.on]="cat() === c[0]" (click)="pick(c[0])">{{ c[1] }}</button> }
        </div>
        @for (n of items(); track n.id; let i = $index) {
          <button class="item" [class.unread]="!n.read" (click)="go(n)" [style.animation-delay.s]="i * 0.02">
            <span class="ic" [attr.data-c]="n.category">{{ icon[n.category] ?? '🔔' }}</span>
            <span class="txt"><b>{{ n.title }}</b><small>{{ n.body }}</small></span>
            <em>{{ ago(n.at) }}</em>
          </button>
        } @empty { <div class="empty-box">@if (loading()) { Loading… } @else { <b>Nothing here</b>You’re all caught up. 🎉 } </div> }
      </section>
      <aside class="card prefs">
        <h3>Email me about</h3>
        @for (p of prefs(); track p.key) {
          <label class="tog"><span>{{ p.label }}</span>
            <input type="checkbox" role="switch" [checked]="p.email" (change)="toggle(p, $event)" />
            <i aria-hidden="true"></i>
          </label>
        }
        <p class="hint">Emails come from info&#64;digifoxprosolutions.com.</p>
      </aside>
    </div>
  `,
  styles: `
    .layout { display: grid; grid-template-columns: 1fr 320px; gap: 20px; align-items: start; }
    .cats { margin-bottom: 10px; flex-wrap: wrap; }
    .item { width: 100%; display: flex; gap: 12px; align-items: flex-start; text-align: left; padding: 14px 10px; border: 0; border-bottom: 1px solid var(--line); background: none; font: inherit; color: inherit; cursor: pointer; border-radius: 10px; animation: rise 0.3s var(--ease) both; transition: background 0.15s; }
    .item:hover { background: var(--bg); } .item.unread { background: #fffaf3; } .item.unread b::before { content: '● '; color: var(--brand); }
    .ic { width: 38px; height: 38px; border-radius: 12px; display: grid; place-items: center; background: var(--bg); flex: none; font-weight: 700; }
    .ic[data-c='leads'] { background: #fdecec; } .ic[data-c='tickets'] { background: #eaf1fd; } .ic[data-c='leave'] { background: #e8f7ee; } .ic[data-c='billing'] { background: #fdf1e3; color: var(--brand-deep); }
    .txt { flex: 1; min-width: 0; } .txt b { display: block; font-size: 14px; } .txt small { color: var(--ink-2); font-size: 13px; }
    em { font-style: normal; color: var(--ink-3); font-size: 12px; white-space: nowrap; }
    h3 { font-size: 15px; margin-bottom: 6px; }
    .tog { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 10px 0; border-bottom: 1px solid var(--line); font-size: 13.5px; cursor: pointer; position: relative; }
    .tog input { position: absolute; opacity: 0; width: 0; height: 0; }
    .tog i { width: 40px; height: 24px; border-radius: 99px; background: #d5d8e0; position: relative; flex: none; transition: background 0.25s; }
    .tog i::after { content: ''; position: absolute; top: 3px; left: 3px; width: 18px; height: 18px; border-radius: 50%; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.25); transition: transform 0.25s var(--ease); }
    .tog input:checked + i { background: var(--brand); } .tog input:checked + i::after { transform: translateX(16px); }
    .tog input:focus-visible + i { outline: 2px solid var(--brand); outline-offset: 2px; }
    .hint { color: var(--ink-3); font-size: 12.5px; margin-top: 10px; }
    @media (max-width: 960px) { .layout { grid-template-columns: 1fr; } }
  `,
})
export class Notifications {
  protected readonly svc = inject(NotificationsService);
  private router = inject(Router);
  private toast = inject(Toast);
  protected readonly icon = CATEGORY_ICON;
  protected readonly ago = ago;
  protected readonly cats = [['', 'All'], ['leads', 'Leads'], ['tickets', 'Requests'], ['leave', 'Leave'], ['billing', 'Billing'], ['clients', 'Clients']];
  protected readonly cat = signal('');
  protected readonly items = signal<Alert[]>([]);
  protected readonly loading = signal(true);
  protected readonly prefs = signal<Pref[]>([]);

  constructor() {
    this.load();
    this.svc.prefs().subscribe((r) => this.prefs.set(r.types));
  }

  pick(c: string): void {
    this.cat.set(c);
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.svc.list({ category: this.cat(), limit: 100 }).subscribe((r) => {
      this.items.set(r.data);
      this.loading.set(false);
    });
  }

  go(n: Alert): void {
    if (!n.read) this.svc.markRead([n.id]).subscribe();
    this.router.navigateByUrl(n.link);
  }

  readAll(): void {
    this.svc.markRead('all').subscribe(() => this.items.update((l) => l.map((x) => ({ ...x, read: true }))));
  }

  toggle(p: Pref, e: Event): void {
    const on = (e.target as HTMLInputElement).checked;
    const email = Object.fromEntries(this.prefs().map((x) => [x.key, x.key === p.key ? on : x.email]));
    this.svc.savePrefs(email).subscribe({
      next: (r) => {
        this.prefs.set(r.types);
        this.toast.show(on ? `Emails on: ${p.label.toLowerCase()}` : `Emails off: ${p.label.toLowerCase()}`);
      },
      error: () => this.toast.show('Could not save.'),
    });
  }
}
