import { HttpErrorResponse } from '@angular/common/http';
import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { AuthService } from '../../core/auth/auth.service';
import { Holiday, HrService, LeaveRequest, LeaveSummary, LeaveType } from '../../core/hr.service';
import { readErrors } from '../../core/http-errors';
import { initials } from '../../shared/format';
import { Toast } from '../../shared/toast.service';
import { LeaveBalances } from './leave-balances';
import { LeaveRequestForm } from './leave-request-form';

type Tab = 'mine' | 'approvals' | 'holidays' | 'policy';

@Component({
  selector: 'app-leave-page',
  imports: [FormsModule, LeaveBalances, LeaveRequestForm],
  templateUrl: './leave-page.html',
  styleUrl: './leave-page.scss',
})
export class LeavePage {
  private hr = inject(HrService);
  private toast = inject(Toast);
  protected readonly auth = inject(AuthService);
  protected readonly initials = initials;

  protected readonly canApprove = this.auth.can('leave.approve');
  protected readonly canManage = this.auth.can('leave.edit');

  protected readonly tab = signal<Tab>('mine');
  protected readonly summary = signal<LeaveSummary | null>(null);
  protected readonly noRecord = signal('');
  protected readonly requesting = signal(false);

  protected approvalStatus = 'pending';
  protected readonly approvals = signal<LeaveRequest[]>([]);
  protected readonly pendingCount = signal(0);
  protected readonly comment: Record<number, string> = {};
  protected readonly rejecting = signal<number | null>(null);

  protected holidayYear = new Date().getFullYear();
  protected readonly years = [this.holidayYear - 1, this.holidayYear, this.holidayYear + 1];
  protected readonly holidays = signal<Holiday[]>([]);
  protected newHoliday = { date: '', name: '', optional: false };
  protected readonly holidayError = signal('');

  protected readonly types = signal<LeaveType[]>([]);
  protected readonly upcoming = computed(() => this.holidays().filter((h) => h.date >= new Date().toISOString().slice(0, 10)));

  constructor() {
    this.loadMine();
    if (this.canApprove) this.loadApprovals();
    this.loadHolidays();
  }

  open(t: Tab): void {
    this.tab.set(t);
    if (t === 'policy' && !this.types().length) this.hr.leaveTypes().subscribe((r) => this.types.set(r.data));
  }

  loadMine(): void {
    this.hr.leaveSummary().subscribe({
      next: (s) => this.summary.set(s),
      error: (e: HttpErrorResponse) => {
        this.noRecord.set(readErrors(e).message);
        if (this.canApprove) this.tab.set('approvals');
      },
    });
  }

  requested(): void {
    this.requesting.set(false);
    this.toast.show('Leave request sent to the admin.');
    this.loadMine();
    if (this.canApprove) this.loadApprovals();
  }

  cancel(r: LeaveRequest): void {
    if (!confirm('Cancel this leave request?')) return;
    this.hr.cancelLeave(r.id).subscribe(() => {
      this.toast.show('Request cancelled.');
      this.loadMine();
    });
  }

  loadApprovals(): void {
    this.hr.approvals(this.approvalStatus).subscribe((r) => {
      this.approvals.set(r.data);
      if (this.approvalStatus === 'pending') this.pendingCount.set(r.data.length);
    });
  }

  decide(r: LeaveRequest, decision: 'approve' | 'reject'): void {
    this.hr.decide(r.id, decision, this.comment[r.id]).subscribe({
      next: () => {
        this.toast.show(`${r.employee.name}'s leave ${decision === 'approve' ? 'approved' : 'rejected'}.`);
        this.rejecting.set(null);
        this.approvals.update((l) => l.filter((x) => x.id !== r.id));
        this.pendingCount.update((n) => Math.max(0, n - 1));
      },
      error: (e) => this.toast.show(readErrors(e).fields['comment'] || readErrors(e).message || 'Could not update.'),
    });
  }

  loadHolidays(): void {
    this.hr.holidays(this.holidayYear).subscribe((r) => this.holidays.set(r.data));
  }

  addHoliday(): void {
    this.holidayError.set('');
    this.hr.saveHoliday(this.newHoliday).subscribe({
      next: () => {
        this.newHoliday = { date: '', name: '', optional: false };
        this.loadHolidays();
        this.toast.show('Holiday added.');
      },
      error: (e) => {
        const r = readErrors(e);
        this.holidayError.set(Object.values(r.fields)[0] || r.message);
      },
    });
  }

  removeHoliday(h: Holiday): void {
    if (!confirm(`Remove ${h.name}?`)) return;
    this.hr.deleteHoliday(h.id).subscribe(() => this.loadHolidays());
  }

  addFixed(): void {
    this.hr.addFixedHolidays(this.holidayYear).subscribe((r) => {
      this.toast.show(r.added ? `${r.added} national holiday(s) added.` : 'They are already in the list.');
      this.loadHolidays();
    });
  }

  saveType(t: LeaveType): void {
    this.hr.updateLeaveType(t.id, { yearly_quota: t.yearly_quota, active: t.active }).subscribe({
      next: () => this.toast.show(`${t.name} updated.`),
      error: (e) => this.toast.show(readErrors(e).message || 'Could not save.'),
    });
  }

  fmt(d: string, opts: Intl.DateTimeFormatOptions = { day: 'numeric', month: 'short' }): string {
    return new Date(d).toLocaleDateString('en-IN', opts);
  }

  range(r: LeaveRequest): string {
    return r.from === r.to ? this.fmt(r.from, { weekday: 'short', day: 'numeric', month: 'short' }) : `${this.fmt(r.from)} – ${this.fmt(r.to)}`;
  }
}
