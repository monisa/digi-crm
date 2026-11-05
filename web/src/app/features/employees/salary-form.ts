import { Component, inject, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Employee, HrService, Salary } from '../../core/hr.service';
import { readErrors } from '../../core/http-errors';
import { money } from '../../shared/format';
import { Sheet } from '../../shared/sheet';

/** Set a new monthly salary structure from a date (keeps history). */
@Component({
  selector: 'app-salary-form',
  imports: [FormsModule, Sheet],
  template: `
    <app-sheet [title]="'Salary for ' + employee().name" subtitle="Monthly amounts in ₹. A new structure applies from its start date." (closed)="closed.emit()">
      @if (message()) { <div class="alert error">{{ message() }}</div> }
      <div class="field compact" [class.invalid]="errors()['effective_from']">
        <label for="s-from">Applies from <span class="req">*</span></label>
        <input id="s-from" type="date" [(ngModel)]="v['effective_from']" />
        @if (errors()['effective_from']) { <div class="err">{{ errors()['effective_from'] }}</div> }
      </div>
      <h4>Earnings</h4>
      <div class="row-2">
        @for (f of earnings; track f.key) {
          <div class="field compact" [class.invalid]="errors()[f.key]">
            <label [for]="'s-' + f.key">{{ f.label }}@if (f.key === 'basic') { <span class="req">*</span> }</label>
            <input [id]="'s-' + f.key" type="number" min="0" step="100" [(ngModel)]="v[f.key]" />
            @if (errors()[f.key]) { <div class="err">{{ errors()[f.key] }}</div> }
          </div>
        }
      </div>
      <h4>Deductions</h4>
      <div class="row-2">
        @for (f of deductions; track f.key) {
          <div class="field compact"><label [for]="'s-' + f.key">{{ f.label }}</label><input [id]="'s-' + f.key" type="number" min="0" step="50" [(ngModel)]="v[f.key]" /></div>
        }
        <div class="field compact" [class.invalid]="errors()['bank_account_last4']">
          <label for="s-bank">Bank account (last 4 digits)</label>
          <input id="s-bank" inputmode="numeric" maxlength="4" [(ngModel)]="v['bank_account_last4']" />
          @if (errors()['bank_account_last4']) { <div class="err">{{ errors()['bank_account_last4'] }}</div> }
        </div>
      </div>
      <button type="button" class="btn-ghost pf" (click)="suggestPf()">Fill PF at 12% of basic</button>

      <div class="sum card">
        <div><span>Gross / month</span><b>{{ money(gross()) }}</b></div>
        <div><span>Deductions</span><b>{{ money(ded()) }}</b></div>
        <div><span>Net / month</span><b class="net">{{ money(gross() - ded()) }}</b></div>
        <div><span>Yearly (gross × 12)</span><b>{{ money(gross() * 12) }}</b></div>
      </div>

      <div class="form-actions">
        <button type="button" class="btn-ghost" (click)="closed.emit()">Cancel</button>
        <button class="btn" (click)="save()" [disabled]="busy()">Save salary</button>
      </div>
    </app-sheet>
  `,
  styles: `
    h4 { margin: 22px 0 0; font-size: 13px; text-transform: uppercase; letter-spacing: 0.06em; color: var(--ink-3); }
    .pf { margin-top: 12px; }
    .sum { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 18px; background: var(--bg); box-shadow: none; }
    .sum span { display: block; font-size: 12px; color: var(--ink-3); }
    .sum b { font-size: 17px; font-variant-numeric: tabular-nums; }
    .sum .net { color: var(--brand-deep); }
  `,
})
export class SalaryForm {
  employee = input.required<Employee>();
  current = input<Salary | null>(null);
  closed = output<void>();
  saved = output<void>();

  private hr = inject(HrService);
  protected readonly money = money;
  protected readonly earnings = [
    { key: 'basic', label: 'Basic' }, { key: 'hra', label: 'House rent allowance' },
    { key: 'special_allowance', label: 'Special allowance' }, { key: 'other_allowance', label: 'Other allowance' },
  ];
  protected readonly deductions = [
    { key: 'pf', label: 'Provident fund' }, { key: 'esi', label: 'ESI' }, { key: 'professional_tax', label: 'Professional tax' },
    { key: 'tds', label: 'Income tax (TDS)' }, { key: 'other_deduction', label: 'Other deductions' },
  ];
  protected v: Record<string, string | number | null> = {};
  protected readonly busy = signal(false);
  protected readonly message = signal('');
  protected readonly errors = signal<Record<string, string>>({});

  ngOnInit(): void {
    const c = this.current();
    // A first salary starts from joining (or last month, so last month's payroll can run);
    // a revision starts next month.
    const d = new Date();
    if (c) d.setMonth(d.getMonth() + 1, 1);
    else d.setMonth(d.getMonth() - 1, 1);
    const ymd = (x: Date) => `${x.getFullYear()}-${String(x.getMonth() + 1).padStart(2, '0')}-${String(x.getDate()).padStart(2, '0')}`;
    const from = !c && this.employee().doj ? this.employee().doj!.slice(0, 10) : ymd(d);
    this.v = { effective_from: from, bank_account_last4: c?.bank_account_last4 ?? '' };
    for (const f of [...this.earnings, ...this.deductions]) this.v[f.key] = c ? (c as unknown as Record<string, number>)[f.key] || null : null;
  }

  private sum(keys: { key: string }[]): number {
    return keys.reduce((s, f) => s + (+(this.v[f.key] ?? 0) || 0), 0);
  }

  gross(): number {
    return this.sum(this.earnings);
  }

  ded(): number {
    return this.sum(this.deductions);
  }

  suggestPf(): void {
    this.v['pf'] = Math.round((+(this.v['basic'] ?? 0) || 0) * 0.12);
  }

  save(): void {
    this.busy.set(true);
    this.errors.set({});
    this.message.set('');
    const payload: Record<string, unknown> = { ...this.v };
    if (!payload['bank_account_last4']) payload['bank_account_last4'] = null;
    this.hr.saveSalary(this.employee().id, payload).subscribe({
      next: () => this.saved.emit(),
      error: (err) => {
        const r = readErrors(err);
        this.errors.set(r.fields);
        this.message.set(r.message);
        this.busy.set(false);
      },
    });
  }
}
