import { Component, computed, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { AuthService } from '../../../core/auth/auth.service';
import { DashboardService, Overview as Data, Period } from '../../../core/dashboard.service';
import { readErrors } from '../../../core/http-errors';
import { CountUp } from '../../../shared/count-up';
import { change, count, initials, money, waited } from '../../../shared/format';
import { RevenueChart } from './revenue-chart';

/** Company-wide dashboard for users with the "Business Overview" permission. */
@Component({
  selector: 'app-overview',
  imports: [RouterLink, CountUp, RevenueChart],
  templateUrl: './overview.html',
  styleUrl: './overview.scss',
})
export class Overview {
  private api = inject(DashboardService);
  protected readonly auth = inject(AuthService);

  protected readonly money = money;
  protected readonly count = count;
  protected readonly change = change;
  protected readonly waited = waited;
  protected readonly initials = initials;

  protected readonly periods: { key: Period; label: string }[] = [
    { key: 'month', label: 'This month' },
    { key: 'quarter', label: 'Quarter' },
    { key: 'year', label: 'Financial year' },
  ];
  protected readonly period = signal<Period>('month');
  protected readonly data = signal<Data | null>(null);
  protected readonly loading = signal(true);
  protected readonly error = signal('');

  protected readonly greeting = (() => {
    const h = new Date().getHours();
    return h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening';
  })();
  protected readonly firstName = computed(() => this.auth.user()?.name.split(' ')[0] ?? '');

  protected readonly funnelMax = computed(() => Math.max(1, this.data()?.funnel.stages[0]?.count ?? 1));

  constructor() {
    this.load();
  }

  pick(p: Period): void {
    if (p === this.period()) return;
    this.period.set(p);
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.error.set('');
    this.api.overview(this.period()).subscribe({
      next: (d) => {
        this.data.set(d);
        this.loading.set(false);
      },
      error: (e) => {
        this.error.set(readErrors(e).message);
        this.loading.set(false);
      },
    });
  }

  /** Trend chip: up is good for revenue/clients, bad for expenses. */
  trend(current: number, previous: number, upIsGood = true): { text: string; good: boolean } | null {
    const c = change(current, previous);
    if (c === null) return null;
    return { text: `${c >= 0 ? '▲' : '▼'} ${Math.abs(c)}%`, good: c === 0 || (c > 0) === upIsGood };
  }

  dateRange(from: string, to: string): string {
    const f = new Date(from);
    const t = new Date(to);
    const fmt = (d: Date) => d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' });
    return from === to ? fmt(f) : `${fmt(f)} – ${fmt(t)}`;
  }
}
