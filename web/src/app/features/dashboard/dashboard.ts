import { Component, inject, signal } from '@angular/core';
import { AuthService } from '../../core/auth/auth.service';
import { Health, HealthService } from '../../core/health.service';

interface Phase {
  name: string;
  scope: string;
  done: boolean;
}

/**
 * Phase 0 landing page: confirms the Angular app can reach the Laravel API
 * and shows the build roadmap. Replaced by the real Admin Dashboard in Phase 2.
 */
@Component({
  selector: 'app-dashboard',
  templateUrl: './dashboard.html',
  styleUrl: './dashboard.scss',
})
export class Dashboard {
  protected readonly auth = inject(AuthService);
  protected readonly health = signal<Health | null>(null);
  protected readonly apiError = signal(false);

  protected readonly phases: Phase[] = [
    { name: 'Phase 0 · Setup', scope: 'Angular + Laravel projects, Hostinger deployment', done: true },
    { name: 'Phase 1 · Foundation', scope: 'Login, register, forgot password, roles & permissions', done: true },
    { name: 'Phase 2 · Admin Dashboard', scope: 'Clients, employees, revenue, expenses, charts', done: false },
    { name: 'Phase 3 · Employees + Leave', scope: 'Details, salary & payslips, leave requests', done: false },
    { name: 'Phase 4 · Leads + Meta', scope: 'Meta Lead Ads sync, pipeline, follow-ups', done: false },
    { name: 'Phase 5 · Clients + Portal', scope: 'Onboarding, campaigns, invoices, tickets', done: false },
    { name: 'Phase 6 · Notifications', scope: 'Uncontacted leads, tickets, leave alerts', done: false },
    { name: 'Phase 7 · Expenses', scope: 'Expenses, categories, receipts', done: false },
  ];

  constructor() {
    inject(HealthService)
      .check()
      .subscribe({
        next: (h) => this.health.set(h),
        error: () => this.apiError.set(true),
      });
  }
}
