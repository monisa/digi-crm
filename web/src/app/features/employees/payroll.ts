import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { HrService, Payslip, SkippedSlip } from '../../core/hr.service';
import { readErrors } from '../../core/http-errors';
import { money } from '../../shared/format';
import { Toast } from '../../shared/toast.service';
import { Dialog } from '../../shared/dialog';

/** Monthly payroll: generate drafts from salary + leave, check them, publish to employees. */
@Component({
  selector: 'app-payroll',
  imports: [FormsModule, RouterLink],
  templateUrl: './payroll.html',
  styleUrl: './payroll.scss',
})
export class Payroll {
  protected readonly dialog = inject(Dialog);
  private hr = inject(HrService);
  private toast = inject(Toast);
  protected readonly money = money;

  /** Last 12 months, newest first; default is last month (payroll is run after month end). */
  protected readonly months = Array.from({ length: 12 }, (_, i) => {
    const d = new Date();
    d.setDate(1);
    d.setMonth(d.getMonth() - i);
    return { key: `${d.getFullYear()}-${d.getMonth() + 1}`, year: d.getFullYear(), month: d.getMonth() + 1, label: d.toLocaleDateString('en-IN', { month: 'long', year: 'numeric' }) };
  });
  protected selected = this.months[1]!.key;

  protected readonly slips = signal<Payslip[]>([]);
  protected readonly totals = signal({ gross: 0, net: 0, count: 0 });
  protected readonly skipped = signal<SkippedSlip[]>([]);
  protected readonly loading = signal(true);
  protected readonly busy = signal(false);
  protected readonly drafts = computed(() => this.slips().filter((s) => s.status === 'draft').length);
  protected readonly open = signal<number | null>(null);

  constructor() {
    this.load();
  }

  private get period() {
    return this.months.find((m) => m.key === this.selected)!;
  }

  /** Month changed: forget the last run's "not prepared" list. */
  changeMonth(): void {
    this.skipped.set([]);
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.hr.payroll(this.period.year, this.period.month).subscribe((r) => {
      this.slips.set(r.data);
      this.totals.set(r.totals);
      this.loading.set(false);
    });
  }

  generate(): void {
    this.busy.set(true);
    this.hr.generate(this.period.year, this.period.month).subscribe({
      next: (r) => {
        this.busy.set(false);
        this.skipped.set(r.skipped.filter((s) => s.reason !== 'Already published'));
        if (!r.employees) this.toast.show('No active employees yet. Add them in Employees first.');
        else if (!r.generated) this.toast.show('No payslips prepared. See the reasons below.');
        else this.toast.show(`${r.generated} payslip(s) prepared as drafts. Check them, then publish.`);
        this.load();
      },
      error: (e) => {
        this.busy.set(false);
        this.toast.show(readErrors(e).message);
      },
    });
  }

  async publish(): Promise<void> {
    if (!(await this.dialog.confirm({ title: `Publish ${this.drafts()} payslip(s)?`, message: `For ${this.period.label}. Employees will be able to download them, and they can no longer be regenerated.`, confirmText: 'Publish' }))) return;
    this.busy.set(true);
    this.hr.publish(this.period.year, this.period.month).subscribe({
      next: (r) => {
        this.busy.set(false);
        this.toast.show(`${r.published} payslip(s) published.`);
        this.load();
      },
      error: () => this.busy.set(false),
    });
  }

  /** "Salary starts 1 Dec": move the start back to this month and try again. */
  startFromThisMonth(s: SkippedSlip): void {
    const p = this.period;
    this.busy.set(true);
    this.hr.moveSalary(s.employee_id!, s.salary_id!, `${p.year}-${String(p.month).padStart(2, '0')}-01`).subscribe({
      next: () => this.generate(),
      error: (e) => {
        this.busy.set(false);
        this.toast.show(readErrors(e).message || 'Could not change the start date.');
      },
    });
  }

  label(): string {
    return this.period.label;
  }

  download(p: Payslip): void {
    this.hr.downloadPayslip(p).subscribe({ error: () => this.toast.show('Could not download the payslip.') });
  }

  entries(o: Record<string, number>): [string, number][] {
    return Object.entries(o);
  }
}
