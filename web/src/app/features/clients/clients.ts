import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { Subject, debounceTime } from 'rxjs';
import { AuthService } from '../../core/auth/auth.service';
import { BillingService, Client } from '../../core/billing.service';
import { initials, money } from '../../shared/format';
import { Toast } from '../../shared/toast.service';
import { ClientForm } from './client-form';
import { Sheet } from '../../shared/sheet';
import { ShareLink, formUrl } from '../../shared/share-link';
import { Dialog } from '../../shared/dialog';

@Component({
  selector: 'app-clients',
  imports: [FormsModule, RouterLink, ClientForm, Sheet, ShareLink],
  template: `
    <div class="page-head">
      <div><h1>Clients</h1><p>{{ list().length }} {{ status === 'active' ? 'active' : status === 'inactive' ? 'past' : status === 'pending' ? 'waiting for approval:' : '' }} clients</p></div>
      <div class="actions">
        @if (auth.can('clients.create')) { <button class="btn-ghost" (click)="openShare()">🔗 Onboarding form link</button> }
        @if (auth.can('invoices.view')) { <a class="btn-ghost" routerLink="/invoices">Invoices</a> }
        @if (auth.can('clients.create')) { <button class="btn" (click)="adding.set(true)">+ Add client</button> }
      </div>
    </div>
    @if (pending() && status !== 'pending') {
      <button class="card wait" (click)="status = 'pending'; load()">
        <span class="dot"></span><b>{{ pending() }} new onboarding {{ pending() === 1 ? 'form' : 'forms' }} waiting for approval</b><span class="go">Review →</span>
      </button>
    }
    <div class="card">
      <div class="toolbar">
        <div class="filters">
          <input class="input-sm grow" type="search" placeholder="Search company, contact, phone or city" [(ngModel)]="search" (ngModelChange)="search$.next()" />
          <select class="select-sm" [(ngModel)]="status" (ngModelChange)="load()"><option value="active">Active</option><option value="pending">Waiting for approval{{ pending() ? ' (' + pending() + ')' : '' }}</option><option value="inactive">Past clients</option><option value="all">All</option></select>
        </div>
      </div>
      <div class="grid">
        @for (c of list(); track c.id; let i = $index) {
          <a class="cl" [routerLink]="['/clients', c.id]" [style.animation-delay.s]="i * 0.025">
            <span class="ini">{{ initials(c.company_name) }}</span>
            <div class="who"><b>{{ c.company_name }}@if (c.status === 'pending') { <span class="badge-p">New form</span> }</b><small>{{ c.contact_name || '—' }}@if (c.city) { · {{ c.city }} }</small></div>
            <div class="due">
              @if (c.outstanding) { <b class="money">{{ money(c.outstanding) }}</b><small>due</small> } @else { <small class="ok">All paid</small> }
            </div>
          </a>
        } @empty {
          <div class="empty-box">@if (loading()) { Loading… } @else { <b>No clients found</b>Won leads become clients, or add one here. }</div>
        }
      </div>
    </div>
    @if (sharing()) {
      <app-sheet title="Onboarding form link" subtitle="Send this to new clients, like a Google Form link" (closed)="sharing.set(false)">
        @if (link(); as l) {
          <p class="hint" style="margin: 10px 0 14px">Anyone with this link can fill in the onboarding form without logging in. Each form arrives here as a new client
            <b>waiting for approval</b>, and you get a notification.</p>
          <app-share-link [url]="l" message="Hi! Please fill in this DigiFox Pro onboarding form so we can get started:" [canReset]="auth.can('clients.approve')" (reset)="resetLink()" />
          <p class="hint" style="margin-top: 14px">For a client you have already added, open their profile and use <b>Send form link</b> instead. It fills in their record directly.</p>
        } @else { <p class="hint">Loading…</p> }
      </app-sheet>
    }
    @if (adding()) { <app-client-form (closed)="adding.set(false)" (saved)="created($event)" /> }
  `,
  styles: `
    .wait { display: flex; align-items: center; gap: 12px; width: 100%; margin-bottom: 14px; padding: 14px 18px; cursor: pointer; text-align: left; font: inherit;
      border: 1px solid rgba(237, 143, 33, 0.45); background: linear-gradient(90deg, rgba(237, 143, 33, 0.12), transparent); animation: rise 0.4s var(--ease) both; }
    .wait b { flex: 1; } .wait .go { color: var(--brand-deep); font-weight: 600; }
    .dot { width: 10px; height: 10px; border-radius: 50%; background: var(--brand); box-shadow: 0 0 0 4px rgba(237, 143, 33, 0.25); }
    .badge-p { font-size: 11px; font-weight: 700; color: #b54708; background: #fff4e5; padding: 2px 8px; border-radius: 99px; margin-left: 6px; }
    .filters { display: flex; gap: 8px; flex: 1; flex-wrap: wrap; } .grow { flex: 1; min-width: 220px; }
    .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 12px; }
    .grid .empty-box { grid-column: 1 / -1; }
    .cl { display: flex; align-items: center; gap: 12px; padding: 14px; border: 1px solid var(--line); border-radius: 14px; background: #fff; animation: rise 0.4s var(--ease) both; transition: transform 0.25s var(--ease), box-shadow 0.25s, border-color 0.25s; }
    .cl:hover { transform: translateY(-3px); box-shadow: var(--shadow); border-color: var(--brand); }
    .ini { width: 46px; height: 46px; border-radius: 14px; display: grid; place-items: center; font-weight: 700; background: #16181d; color: var(--brand); flex: none; }
    .who { flex: 1; min-width: 0; } .who b { display: block; font-size: 14.5px; }
    .who small { display: block; color: var(--ink-3); font-size: 12.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .due { text-align: right; } .due b { display: block; color: #b54708; } .due small { color: var(--ink-3); font-size: 11.5px; } .ok { color: #11803b !important; font-weight: 600; }
  `,
})
export class Clients {
  protected readonly dialog = inject(Dialog);
  private api = inject(BillingService);
  private router = inject(Router);
  private toast = inject(Toast);
  protected readonly auth = inject(AuthService);
  protected readonly initials = initials;
  protected readonly money = money;
  protected search = '';
  protected status = 'active';
  protected readonly list = signal<Client[]>([]);
  protected readonly loading = signal(true);
  protected readonly adding = signal(false);
  protected readonly pending = signal(0);
  protected readonly sharing = signal(false);
  protected readonly link = signal('');
  protected readonly search$ = new Subject<void>();

  constructor() {
    this.search$.pipe(debounceTime(300)).subscribe(() => this.load());
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.api.clients({ search: this.search, status: this.status }).subscribe((r) => {
      this.list.set(r.data);
      this.pending.set(r.pending ?? 0);
      this.loading.set(false);
    });
  }

  openShare(): void {
    this.sharing.set(true);
    if (!this.link()) this.api.onboardingLink().subscribe((r) => this.link.set(formUrl(r.token)));
  }

  async resetLink(): Promise<void> {
    if (!(await this.dialog.confirm({ title: 'Make a new link?', message: 'The old link will stop working for anyone you already sent it to.', confirmText: 'Make new link', danger: true }))) return;
    this.api.resetOnboardingLink().subscribe((r) => {
      this.link.set(formUrl(r.token));
      this.toast.show('New link ready. The old one no longer works.');
    });
  }

  created(c: Client): void {
    this.adding.set(false);
    this.toast.show(`${c.company_name} added.`);
    this.router.navigate(['/clients', c.id]);
  }
}
