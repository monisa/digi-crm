import { Component, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth/auth.service';
import { DashboardService, StaffHome as Home } from '../../core/dashboard.service';
import { HrService } from '../../core/hr.service';
import { readErrors } from '../../core/http-errors';
import { waNumber } from '../../core/leads.service';
import { Dialog } from '../../shared/dialog';
import { Toast } from '../../shared/toast.service';
import { CheckInCard } from '../attendance/check-in-card';

/** An employee's home page: leads waiting for a call, tickets, leave, and check-in. */
@Component({
  selector: 'app-staff-home',
  imports: [RouterLink, CheckInCard],
  templateUrl: './staff-home.html',
  styleUrl: './staff-home.scss',
})
export class StaffHome {
  protected readonly auth = inject(AuthService);
  private api = inject(DashboardService);
  private hr = inject(HrService);
  private toast = inject(Toast);
  private dialog = inject(Dialog);
  protected readonly wa = waNumber;
  protected readonly d = signal<Home | null>(null);
  protected readonly busy = signal<number | null>(null);
  protected readonly hello = (() => {
    const h = new Date().getHours();
    return h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening';
  })();

  constructor() {
    this.load();
  }

  load(): void {
    this.api.staffHome().subscribe((d) => this.d.set(d));
  }

  async decide(id: number, decision: 'approve' | 'reject'): Promise<void> {
    let comment: string | undefined;
    if (decision === 'reject') {
      const c = await this.dialog.prompt({ title: 'Reject this leave?', label: 'Reason (sent to them)', confirmText: 'Reject', danger: true, required: true });
      if (!c) return;
      comment = c;
    }
    this.busy.set(id);
    this.hr.decide(id, decision, comment).subscribe({
      next: () => {
        this.busy.set(null);
        this.toast.show(decision === 'approve' ? 'Leave approved.' : 'Leave rejected.');
        this.load();
      },
      error: (e) => {
        this.busy.set(null);
        this.toast.show(readErrors(e).message || 'Could not save.');
      },
    });
  }

  ago(iso: string): string {
    const m = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 60000));
    if (m < 60) return `${m}m ago`;
    if (m < 1440) return `${Math.floor(m / 60)}h ago`;
    return `${Math.floor(m / 1440)}d ago`;
  }

  range(from: string, to: string): string {
    const f = (s: string) => new Date(s + 'T00:00').toLocaleDateString('en-IN', { day: 'numeric', month: 'short' });
    return from === to ? f(from) : `${f(from)} – ${f(to)}`;
  }

  time(iso: string): string {
    return new Date(iso).toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' });
  }

  day(s: string): string {
    return new Date(s + 'T00:00').toLocaleDateString('en-IN', { weekday: 'short', day: 'numeric', month: 'short' });
  }

  statusLabel(s: string): string {
    return ({ pending: 'Waiting', approved: 'Approved', rejected: 'Rejected', cancelled: 'Cancelled' } as Record<string, string>)[s] ?? s;
  }
}
