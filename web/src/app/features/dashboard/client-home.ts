import { Component, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth/auth.service';
import { ClientHome as Home, PortalService, TICKET_STATUS } from '../../core/portal.service';
import { CountUp } from '../../shared/count-up';
import { money } from '../../shared/format';

/** The client's home page. */
@Component({
  selector: 'app-client-home',
  imports: [RouterLink, CountUp],
  template: `
    <div class="head">
      <h1>{{ greeting }}, {{ first }} 👋</h1>
      <p>Here’s what DigiFox is doing for <b>{{ h()?.company ?? '…' }}</b>.</p>
    </div>
    @if (h(); as h) {
      <div class="tiles">
        <a class="card tile pay" routerLink="/invoices" [queryParams]="{ status: 'open' }" [class.due]="h.billing.to_pay > 0">
          <span>To pay</span><b [countUp]="h.billing.to_pay" [format]="money"></b>
          @if (h.billing.overdue) { <small class="od">{{ h.billing.overdue }} overdue</small> }
          @else if (h.billing.next) { <small>Next due {{ date(h.billing.next.due_date) }}</small> }
          @else { <small class="ok">All paid, thank you!</small> }
          @if (h.billing.next) { <span class="cta">Pay now →</span> }
        </a>
        <a class="card tile" routerLink="/campaigns"><span>Active campaigns</span><b [countUp]="h.totals.active_campaigns"></b><small>{{ h.totals.leads }} leads so far</small></a>
        <a class="card tile" routerLink="/tickets"><span>Open requests</span><b [countUp]="h.tickets.open"></b>
          @if (h.tickets.replied) { <small class="new"><span class="live"></span> {{ h.tickets.replied }} with a new reply</small> } @else { <small>We’re on it</small> }</a>
      </div>

      <div class="two">
        <section class="card">
          <div class="ch"><h3>Your campaigns</h3><a routerLink="/campaigns">See all →</a></div>
          @for (c of h.campaigns; track c.id; let i = $index) {
            <a class="camp" routerLink="/campaigns" [style.animation-delay.s]="i * 0.05">
              <div class="grow"><b>{{ c.name }}</b><small>{{ c.status === 'active' ? 'Running' : 'Starting soon' }}@if (c.results.updated_at) { · updated {{ date(c.results.updated_at) }} }</small></div>
              <div class="n"><b>{{ c.results.leads ?? '—' }}</b><small>leads</small></div>
              <div class="n"><b>{{ c.results.cost_per_lead !== null ? money(c.results.cost_per_lead) : '—' }}</b><small>per lead</small></div>
            </a>
          } @empty { <p class="hint">Your campaigns will show here once they start.</p> }
        </section>
        <section class="card">
          <h3>Need anything?</h3>
          @if (h.account_manager; as m) {
            <div class="mgr"><span class="av">{{ m.name.slice(0, 1) }}</span><div><b>{{ m.name }}</b><small>Your account manager</small></div></div>
            <div class="contact">
              @if (m.phone) { <a class="btn-ghost" [href]="'tel:' + m.phone">📞 Call</a> }
              <a class="btn-ghost" [href]="'mailto:' + m.email">✉️ Email</a>
            </div>
          }
          <a class="btn wide" routerLink="/tickets">+ Ask for something</a>
          @for (t of h.tickets.latest; track t.id) {
            <a class="tk" [routerLink]="['/tickets', t.id]"><span>{{ t.subject }}</span><small>{{ label[t.status] }}</small></a>
          }
        </section>
      </div>
    } @else { <div class="tiles">@for (i of [1, 2, 3]; track i) { <div class="card skel"></div> }</div> }
  `,
  styles: `
    .head { margin: 12px 0 22px; } .head h1 { font-size: 26px; letter-spacing: -0.02em; } .head p { color: var(--ink-2); margin-top: 4px; }
    .tiles { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
    .tile { display: flex; flex-direction: column; gap: 2px; color: inherit; transition: transform 0.25s var(--ease), box-shadow 0.25s; position: relative; overflow: hidden; }
    .tile:nth-child(2) { animation-delay: 0.05s; } .tile:nth-child(3) { animation-delay: 0.1s; }
    .tile:hover { transform: translateY(-4px); box-shadow: 0 14px 34px rgba(16, 24, 40, 0.12); }
    .tile span { color: var(--ink-3); font-size: 13px; } .tile b { font-size: 30px; letter-spacing: -0.02em; } .tile small { color: var(--ink-3); font-size: 12.5px; }
    .tile.pay.due { background: linear-gradient(135deg, #16181d, #2a2d35); color: #fff; border-color: #16181d; }
    .tile.pay.due span, .tile.pay.due small { color: #c9cdd6; } .tile.pay.due b { color: var(--brand); }
    .od { color: #ff8a80 !important; font-weight: 600; } .ok { color: #11803b !important; font-weight: 600; } .new { color: var(--brand-deep) !important; font-weight: 600; display: flex; align-items: center; gap: 6px; }
    .cta { position: absolute; right: 16px; bottom: 16px; color: var(--brand) !important; font-weight: 700; }
    .two { display: grid; grid-template-columns: 1.6fr 1fr; gap: 20px; margin-top: 20px; align-items: start; }
    h3 { font-size: 16px; } .ch { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; } .ch a { color: var(--brand-deep); font-size: 13px; font-weight: 600; }
    .camp { display: flex; gap: 14px; align-items: center; padding: 12px 8px; border-bottom: 1px solid var(--line); border-radius: 10px; color: inherit; animation: rise 0.4s var(--ease) both; transition: background 0.2s; }
    .camp:hover { background: var(--bg); } .grow { flex: 1; min-width: 0; } .grow b { display: block; } .grow small { color: var(--ink-3); font-size: 12px; }
    .n { text-align: right; min-width: 70px; } .n b { display: block; font-size: 16px; } .n small { color: var(--ink-3); font-size: 11px; }
    .mgr { display: flex; gap: 12px; align-items: center; margin-top: 12px; } .mgr b { display: block; } .mgr small { color: var(--ink-3); font-size: 12.5px; }
    .av { width: 44px; height: 44px; border-radius: 14px; display: grid; place-items: center; background: linear-gradient(135deg, var(--brand), var(--brand-deep)); color: #fff; font-weight: 800; }
    .contact { display: flex; gap: 8px; margin-top: 12px; }
    .wide { width: 100%; justify-content: center; margin: 14px 0 6px; height: 44px; }
    .tk { display: flex; justify-content: space-between; gap: 10px; padding: 9px 4px; border-bottom: 1px solid var(--line); font-size: 13.5px; color: inherit; }
    .tk small { color: var(--ink-3); white-space: nowrap; }
    .hint { color: var(--ink-3); font-size: 13px; }
    .skel { min-height: 130px; background: linear-gradient(90deg, #f3f4f7 25%, #e9ebf0 50%, #f3f4f7 75%); background-size: 200% 100%; animation: shimmer 1.3s infinite; }
    @keyframes shimmer { to { background-position: -200% 0; } }
    @media (max-width: 960px) { .two { grid-template-columns: 1fr; } }
    @media (max-width: 700px) { .tiles { grid-template-columns: 1fr; } .tile b { font-size: 26px; } }
  `,
})
export class ClientHome {
  private api = inject(PortalService);
  protected readonly money = money;
  protected readonly label = TICKET_STATUS;
  protected readonly h = signal<Home | null>(null);
  protected readonly first = (inject(AuthService).user()?.name ?? '').split(' ')[0];
  protected readonly greeting = (() => {
    const hr = new Date().getHours();
    return hr < 12 ? 'Good morning' : hr < 17 ? 'Good afternoon' : 'Good evening';
  })();

  constructor() {
    this.api.home().subscribe((h) => this.h.set(h));
  }

  date(d: string): string {
    return new Date(d).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' });
  }
}
