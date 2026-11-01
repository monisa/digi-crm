import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { Subject, debounceTime } from 'rxjs';
import { AuthService } from '../../core/auth/auth.service';
import { Employee, HrService } from '../../core/hr.service';
import { initials } from '../../shared/format';
import { Toast } from '../../shared/toast.service';
import { EmployeeForm } from './employee-form';

/** Employee directory for HR managers; everyone else is taken to their own profile. */
@Component({
  selector: 'app-employees',
  imports: [FormsModule, RouterLink, EmployeeForm],
  templateUrl: './employees.html',
  styleUrl: './employees.scss',
})
export class Employees {
  private hr = inject(HrService);
  private router = inject(Router);
  private toast = inject(Toast);
  protected readonly auth = inject(AuthService);
  protected readonly initials = initials;

  protected search = '';
  protected status = 'active';
  protected readonly list = signal<Employee[]>([]);
  protected readonly loading = signal(true);
  protected readonly adding = signal(false);
  private search$ = new Subject<void>();

  constructor() {
    this.search$.pipe(debounceTime(300)).subscribe(() => this.load());
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.hr.employees({ search: this.search, status: this.status }).subscribe({
      next: (r) => {
        if (r.scope === 'own') {
          this.router.navigate(['/employees', r.data[0]?.id ?? 'me'], { replaceUrl: true });
          return;
        }
        this.list.set(r.data);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }

  onSearch(): void {
    this.search$.next();
  }

  added(e: Employee): void {
    this.adding.set(false);
    this.toast.show(`${e.name} added${e.has_login ? ' and invited by email' : ''}.`);
    this.router.navigate(['/employees', e.id]);
  }
}
