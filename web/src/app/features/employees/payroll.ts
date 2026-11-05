import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { HrService, Payslip } from '../../core/hr.service';
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
  protected readonly skipped = signal<{ employee: string; reason: string }[]>([]);
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

  load(): void {
    this.loading.set(true);
    this.skipped.set([]);
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
        this.toast.show(`${r.generated} payslip(s) prepared as drafts. Check them, then publish.`);
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

  download(p: Payslip): void {
    this.hr.downloadPayslip(p).subscribe({ error: () => this.toast.show('Could not download the payslip.') });
  }

  entries(o: Record<string, number>): [string, number][] {
    return Object.entries(o);
  }
}
