import { Component, computed, inject, input, signal } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth/auth.service';
import { Employee, HrService, LeaveSummary, Payslip, SalaryInfo } from '../../core/hr.service';
import { readErrors } from '../../core/http-errors';
import { initials, money } from '../../shared/format';
import { Toast } from '../../shared/toast.service';
import { LeaveBalances } from '../leave/leave-balances';
import { EmployeeForm } from './employee-form';
import { SalaryForm } from './salary-form';

type Tab = 'details' | 'salary' | 'leave';

/** /employees/:id (or /employees/me): details, salary & payslips, leave. */
@Component({
  selector: 'app-employee-profile',
  imports: [RouterLink, LeaveBalances, EmployeeForm, SalaryForm],
  templateUrl: './employee-profile.html',
  styleUrl: './employee-profile.scss',
})
export class EmployeeProfile {
  id = input.required<string>(); // route param

  private hr = inject(HrService);
  private router = inject(Router);
  private toast = inject(Toast);
  protected readonly auth = inject(AuthService);
  protected readonly money = money;
  protected readonly initials = initials;

  protected readonly employee = signal<Employee | null>(null);
  protected readonly error = signal('');
  protected readonly tab = signal<Tab>('details');
  protected readonly salary = signal<SalaryInfo | null>(null);
  protected readonly payslips = signal<Payslip[] | null>(null);
  protected readonly leave = signal<LeaveSummary | null>(null);
  protected readonly editing = signal(false);
  protected readonly editingSalary = signal(false);
  protected readonly downloading = signal<number | null>(null);

  protected readonly canEdit = computed(() => this.auth.can('employees.edit'));

  ngOnInit(): void {
    const req = this.id() === 'me' ? this.hr.me() : this.hr.employee(+this.id());
    req.subscribe({
      next: (e) => this.employee.set(e),
      error: (e) => this.error.set(readErrors(e).message),
    });
  }

  open(t: Tab): void {
    this.tab.set(t);
    const e = this.employee();
    if (!e) return;
    if (t === 'salary' && !this.salary()) this.loadSalary();
    if (t === 'leave' && !this.leave()) this.hr.leaveFor(e.id).subscribe((l) => this.leave.set(l));
  }

  loadSalary(): void {
    const id = this.employee()!.id;
    this.hr.salary(id).subscribe((s) => this.salary.set(s));
    this.hr.payslipsFor(id).subscribe((p) => this.payslips.set(p.data));
  }

  download(p: Payslip): void {
    this.downloading.set(p.id);
    this.hr.downloadPayslip(p).subscribe({
      next: () => this.downloading.set(null),
      error: () => {
        this.downloading.set(null);
        this.toast.show('Could not download the payslip.');
      },
    });
  }

  saved(e: Employee): void {
    this.employee.set(e);
    this.editing.set(false);
    this.toast.show('Details saved.');
  }

  salarySaved(): void {
    this.editingSalary.set(false);
    this.toast.show('Salary saved.');
    this.loadSalary();
  }

  entries<T>(o: Record<string, T> | undefined): [string, T][] {
    return Object.entries(o ?? {});
  }

  date(d: string | null): string {
    return d ? new Date(d).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';
  }

  tenure(doj: string | null): string {
    if (!doj) return '';
    const months = Math.floor((Date.now() - new Date(doj).getTime()) / (30.44 * 864e5));
    if (months < 1) return 'Joined this month';
    const y = Math.floor(months / 12);
    const m = months % 12;
    return [y ? `${y} yr` : '', m ? `${m} mo` : ''].filter(Boolean).join(' ') + ' with DigiFox';
  }

  back(): void {
    this.router.navigateByUrl('/employees');
  }
}
