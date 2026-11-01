import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { Subject, debounceTime } from 'rxjs';
import { AuthService } from '../../core/auth/auth.service';
import { BillingService, Client } from '../../core/billing.service';
import { initials, money } from '../../shared/format';
import { Toast } from '../../shared/toast.service';
import { ClientForm } from './client-form';

@Component({
  selector: 'app-clients',
  imports: [FormsModule, RouterLink, ClientForm],
  template: `
    <div class="page-head">
      <div><h1>Clients</h1><p>{{ list().length }} {{ status === 'active' ? 'active' : status === 'inactive' ? 'past' : '' }} clients</p></div>
      <div class="actions">
        @if (auth.can('invoices.view')) { <a class="btn-ghost" routerLink="/invoices">Invoices</a> }
        @if (auth.can('clients.create')) { <button class="btn" (click)="adding.set(true)">+ Add client</button> }
      </div>
    </div>
    <div class="card">
      <div class="toolbar">
        <div class="filters">
          <input class="input-sm grow" type="search" placeholder="Search company, contact, phone or city" [(ngModel)]="search" (ngModelChange)="search$.next()" />
          <select class="select-sm" [(ngModel)]="status" (ngModelChange)="load()"><option value="active">Active</option><option value="inactive">Past clients</option><option value="all">All</option></select>
        </div>
      </div>
      <div class="grid">
        @for (c of list(); track c.id; let i = $index) {
          <a class="cl" [routerLink]="['/clients', c.id]" [style.animation-delay.s]="i * 0.025">
            <span class="ini">{{ initials(c.company_name) }}</span>
            <div class="who"><b>{{ c.company_name }}</b><small>{{ c.contact_name || '—' }}@if (c.city) { · {{ c.city }} }</small></div>
            <div class="due">
              @if (c.outstanding) { <b class="money">{{ money(c.outstanding) }}</b><small>due</small> } @else { <small class="ok">All paid</small> }
            </div>
          </a>
        } @empty {
          <div class="empty-box">@if (loading()) { Loading… } @else { <b>No clients found</b>Won leads become clients, or add one here. }</div>
        }
      </div>
    </div>
    @if (adding()) { <app-client-form (closed)="adding.set(false)" (saved)="created($event)" /> }
  `,
  styles: `
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
  protected readonly search$ = new Subject<void>();

  constructor() {
    this.search$.pipe(debounceTime(300)).subscribe(() => this.load());
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.api.clients({ search: this.search, status: this.status }).subscribe((r) => {
      this.list.set(r.data);
      this.loading.set(false);
    });
  }

  created(c: Client): void {
    this.adding.set(false);
    this.toast.show(`${c.company_name} added.`);
    this.router.navigate(['/clients', c.id]);
  }
}
