import { Component, inject, input, output, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule } from '@angular/forms';
import { Employee, HrService } from '../../core/hr.service';
import { readErrors } from '../../core/http-errors';
import { Sheet } from '../../shared/sheet';

/** Add / edit an employee (admin). */
@Component({
  selector: 'app-employee-form',
  imports: [ReactiveFormsModule, Sheet],
  template: `
    <app-sheet [title]="employee() ? 'Edit ' + employee()!.name : 'Add employee'" subtitle="Personal and job details" (closed)="closed.emit()">
      @if (message()) { <div class="alert error">{{ message() }}</div> }
      <form [formGroup]="form" (ngSubmit)="save()" novalidate>
        <div class="row-2">
          <div class="field compact" [class.invalid]="err('name')">
            <label for="f-name">Full name <span class="req">*</span></label>
            <input id="f-name" formControlName="name" />
            @if (err('name')) { <div class="err">{{ err('name') }}</div> }
          </div>
          <div class="field compact" [class.invalid]="err('emp_code')">
            <label for="f-code">Employee ID</label>
            <input id="f-code" formControlName="emp_code" placeholder="Auto (DFP###)" />
            @if (err('emp_code')) { <div class="err">{{ err('emp_code') }}</div> }
          </div>
        </div>
        <div class="row-2">
          <div class="field compact" [class.invalid]="err('office_email')">
            <label for="f-email">Office email</label>
            <input id="f-email" type="email" formControlName="office_email" />
            @if (err('office_email')) { <div class="err">{{ err('office_email') }}</div> }
          </div>
          <div class="field compact" [class.invalid]="err('mobile')">
            <label for="f-mobile">Mobile number</label>
            <input id="f-mobile" type="tel" formControlName="mobile" placeholder="+91" />
            @if (err('mobile')) { <div class="err">{{ err('mobile') }}</div> }
          </div>
        </div>
        <div class="row-2">
          <div class="field compact">
            <label for="f-des">Designation</label>
            <input id="f-des" formControlName="designation" list="designations" />
            <datalist id="designations">
              @for (d of designations; track d) { <option [value]="d"></option> }
            </datalist>
          </div>
          <div class="field compact">
            <label for="f-dep">Department</label>
            <select id="f-dep" formControlName="department">
              <option value="">—</option>
              @for (d of departments; track d) { <option [value]="d">{{ d }}</option> }
            </select>
          </div>
        </div>
        <div class="row-2">
          <div class="field compact" [class.invalid]="err('dob')">
            <label for="f-dob">Date of birth</label>
            <input id="f-dob" type="date" formControlName="dob" />
            @if (err('dob')) { <div class="err">{{ err('dob') }}</div> }
          </div>
          <div class="field compact" [class.invalid]="err('doj')">
            <label for="f-doj">Date of joining</label>
            <input id="f-doj" type="date" formControlName="doj" />
          </div>
        </div>
        <div class="row-2">
          <div class="field compact">
            <label for="f-mgr">Reporting manager</label>
            <select id="f-mgr" formControlName="reporting_manager_id">
              <option [value]="0">—</option>
              @for (m of managers(); track m.id) {
                @if (m.id !== employee()?.id) { <option [value]="m.id">{{ m.name }}</option> }
              }
            </select>
          </div>
          @if (employee()) {
            <div class="field compact">
              <label for="f-status">Status</label>
              <select id="f-status" formControlName="status">
                <option value="active">Active</option>
                <option value="inactive">Left the company</option>
              </select>
            </div>
          }
        </div>
        <div class="field">
          <label for="f-addr">Address</label>
          <textarea id="f-addr" formControlName="address" rows="2"></textarea>
        </div>
        @if (!employee()) {
          <label class="check"><input type="checkbox" formControlName="create_login" /> Create a CRM login and email them a link to set their password</label>
        }
        <div class="form-actions">
          <button type="button" class="btn-ghost" (click)="closed.emit()">Cancel</button>
          <button class="btn" type="submit" [disabled]="busy()">{{ employee() ? 'Save changes' : 'Add employee' }}</button>
        </div>
      </form>
    </app-sheet>
  `,
})
export class EmployeeForm {
  employee = input<Employee | null>(null);
  managers = input<Employee[]>([]);
  closed = output<void>();
  saved = output<Employee>();

  private hr = inject(HrService);
  protected readonly departments = ['Marketing', 'Design', 'Development', 'Content', 'Accounts', 'Management'];
  protected readonly designations = ['Digital Marketing Executive', 'SEO Analyst', 'Graphic Designer', 'Web Developer', 'Account Manager', 'Content Writer', 'Video Editor'];
  protected readonly busy = signal(false);
  protected readonly message = signal('');
  protected readonly errors = signal<Record<string, string>>({});

  protected readonly form = inject(NonNullableFormBuilder).group({
    name: [''], emp_code: [''], office_email: [''], mobile: [''], designation: [''], department: [''],
    dob: [''], doj: [''], address: [''], reporting_manager_id: [0], status: ['active'], create_login: [true],
  });

  ngOnInit(): void {
    const e = this.employee();
    if (e) {
      this.form.patchValue({
        ...Object.fromEntries(Object.entries(e).map(([k, v]) => [k, v ?? ''])),
        reporting_manager_id: e.reporting_manager?.id ?? 0,
        create_login: false,
      });
    }
  }

  err(k: string): string {
    return this.errors()[k] ?? '';
  }

  save(): void {
    const v = this.form.getRawValue();
    if (!v.name.trim()) {
      this.errors.set({ name: 'Please enter the name.' });
      return;
    }
    const payload: Record<string, unknown> = {};
    for (const [k, val] of Object.entries(v)) payload[k] = val === '' ? null : val;
    payload['reporting_manager_id'] = +v.reporting_manager_id || null;
    if (this.employee()) delete payload['create_login'];

    this.busy.set(true);
    this.errors.set({});
    this.message.set('');
    this.hr.saveEmployee(payload, this.employee()?.id).subscribe({
      next: (e) => this.saved.emit(e),
      error: (err) => {
        const r = readErrors(err);
        this.errors.set(r.fields);
        this.message.set(r.message);
        this.busy.set(false);
      },
    });
  }
}
