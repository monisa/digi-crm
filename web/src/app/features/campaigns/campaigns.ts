import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { AuthService } from '../../core/auth/auth.service';
import { Campaign, PortalService } from '../../core/portal.service';
import { count, money } from '../../shared/format';
import { CampaignSheet } from './campaign-sheet';

@Component({
  selector: 'app-campaigns',
  imports: [FormsModule, CampaignSheet],
  template: `
    <div class="page-head">
      <div><h1>Campaigns</h1><p>{{ isClient() ? 'What DigiFox is running for you, with the latest results and reports.' : 'Campaigns for all your clients, with the results clients see.' }}</p></div>
      @if (!isClient() && auth.can('campaigns.create')) { <div class="actions"><button class="btn" (click)="open(0)">+ New campaign</button></div> }
    </div>
    <div class="filters">
      <div class="seg2">
        @for (s of [['', 'All'], ['active', 'Active'], ['planned', 'Planned'], ['completed', 'Completed']]; track s[0]) {
          <button [class.on]="status === s[0]" (click)="status = s[0]; load()">{{ s[1] }}</button>
        }
      </div>
      <select class="select-sm" [(ngModel)]="platform" (ngModelChange)="load()" aria-label="Platform">
        <option value="">All platforms</option>@for (p of platformList(); track p[0]) { <option [value]="p[0]">{{ p[1] }}</option> }
      </select>
    </div>
    <div class="grid">
      @for (c of list(); track c.id; let i = $index) {
        <button class="card camp" (click)="open(c.id)" [style.animation-delay.s]="i * 0.04">
          <div class="top"><span class="plat" [attr.data-p]="c.platform">{{ platforms()[c.platform] }}</span><span class="badge st" [attr.data-s]="c.status">{{ c.status }}</span></div>
          <b class="name">{{ c.name }}</b>
          @if (!isClient()) { <small class="cl">{{ c.client?.company_name }}</small> }
          <div class="nums">
            <div><span>Leads</span><b>{{ c.results.leads ?? '—' }}</b></div>
            <div><span>Spend</span><b>{{ c.results.spend !== null ? money(c.results.spend) : '—' }}</b></div>
            <div><span>Per lead</span><b>{{ c.results.cost_per_lead !== null ? money(c.results.cost_per_lead) : '—' }}</b></div>
          </div>
          @if (c.budget && c.results.spend !== null) {
            <div class="bar"><div class="fill" [style.width.%]="Math.min(100, (c.results.spend / c.budget) * 100)"></div></div>
            <small class="hint">{{ money(c.results.spend) }} of {{ money(c.budget) }} budget</small>
          }
        </button>
      } @empty {
        <div class="card empty-box">@if (loading()) { Loading… } @else { <b>No campaigns yet</b>@if (isClient()) { Your campaigns will appear here once they start. } }</div>
      }
    </div>
    @if (openId() !== null) {
      <app-campaign-sheet [campaignId]="openId() || null" [platforms]="platforms()" (closed)="openId.set(null)" (changed)="load()" />
    }
  `,
  styles: `
    .filters { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 14px; }
    .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 14px; }
    .grid .empty-box { grid-column: 1 / -1; }
    .camp { text-align: left; cursor: pointer; font: inherit; color: inherit; display: flex; flex-direction: column; gap: 6px; transition: transform 0.25s var(--ease), box-shadow 0.25s; }
    .camp:hover { transform: translateY(-4px); box-shadow: 0 14px 34px rgba(16, 24, 40, 0.12); }
    .top { display: flex; justify-content: space-between; align-items: center; }
    .name { font-size: 16px; margin-top: 4px; } .cl { color: var(--ink-3); font-size: 12.5px; }
    .nums { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-top: 8px; }
    .nums span { display: block; font-size: 11.5px; color: var(--ink-3); } .nums b { font-size: 16px; }
    .bar { height: 8px; background: #f0f1f5; border-radius: 99px; overflow: hidden; margin-top: 10px; }
    .fill { height: 100%; background: linear-gradient(90deg, var(--s1), var(--brand)); border-radius: 99px; animation: grow 1s var(--ease) both; transform-origin: left; }
    @keyframes grow { from { transform: scaleX(0); } }
    .hint { color: var(--ink-3); font-size: 12px; }
    .plat { font-size: 12px; font-weight: 700; padding: 4px 10px; border-radius: 8px; background: #eaf1fd; color: #1f4fb3; }
    .plat[data-p='meta'] { background: #e7f0ff; color: #1877f2; } .plat[data-p='google'] { background: #fdecec; color: #c5221f; }
    .plat[data-p='seo'] { background: #e8f7ee; color: #11803b; } .plat[data-p='social'] { background: #fdf1e3; color: #c2620f; }
    .st { text-transform: capitalize; }
    .st[data-s='active'] { background: #e8f7ee; color: #11803b; } .st[data-s='planned'] { background: #eaf1fd; color: #1f4fb3; }
    .st[data-s='paused'] { background: #fef4e6; color: #b54708; } .st[data-s='completed'] { background: var(--bg); color: var(--ink-3); }
  `,
})
export class Campaigns {
  private api = inject(PortalService);
  protected readonly auth = inject(AuthService);
  protected readonly money = money;
  protected readonly count = count;
  protected readonly Math = Math;
  protected readonly isClient = computed(() => this.auth.user()?.role === 'client');
  protected status = '';
  protected platform = '';
  protected readonly list = signal<Campaign[]>([]);
  protected readonly platforms = signal<Record<string, string>>({});
  protected readonly platformList = computed(() => Object.entries(this.platforms()));
  protected readonly loading = signal(true);
  protected readonly openId = signal<number | null>(null);

  constructor() {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.api.campaigns({ status: this.status, platform: this.platform }).subscribe((r) => {
      this.list.set(r.data);
      this.platforms.set(r.platforms);
      this.loading.set(false);
    });
  }

  open(id: number): void {
    this.openId.set(id);
  }
}
