import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute } from '@angular/router';
import { AuthService } from '../../core/auth/auth.service';
import { Expense, ExpenseList, ExpensesService } from '../../core/expenses.service';
import { readErrors } from '../../core/http-errors';
import { CountUp } from '../../shared/count-up';
import { change, money } from '../../shared/format';
import { Sheet } from '../../shared/sheet';
import { Toast } from '../../shared/toast.service';
import { ExpenseForm } from './expense-form';

type Tab = 'all' | 'approvals' | 'reimburse';

function iso(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

@Component({
  selector: 'app-expenses',
  imports: [FormsModule, CountUp, ExpenseForm, Sheet],
  templateUrl: './expenses.html',
  styleUrl: './expenses.scss',
})
export class Expenses {
  private api = inject(ExpensesService);
  private toast = inject(Toast);
  protected readonly auth = inject(AuthService);
  protected readonly money = money;

  /** Period choices; financial year runs April–March. */
  protected readonly periods = (() => {
    const now = new Date();
    const y = now.getFullYear();
    const m = now.getMonth();
    const fy = m >= 3 ? y : y - 1;
    const q = Math.floor(((m + 9) % 12) / 3);
    const qStart = new Date(fy, 3 + q * 3, 1);
    return [
      { key: 'month', label: 'This month', from: iso(new Date(y, m, 1)), to: iso(new Date(y, m + 1, 0)) },
      { key: 'last', label: 'Last month', from: iso(new Date(y, m - 1, 1)), to: iso(new Date(y, m, 0)) },
      { key: 'quarter', label: 'This quarter', from: iso(qStart), to: iso(new Date(qStart.getFullYear(), qStart.getMonth() + 3, 0)) },
      { key: 'fy', label: `FY ${fy}-${String((fy + 1) % 100).padStart(2, '0')}`, from: iso(new Date(fy, 3, 1)), to: iso(new Date(fy + 1, 2, 31)) },
    ];
  })();
  protected period = 'month';
  protected category = '';
  protected readonly tab = signal<Tab>('all');
  protected readonly res = signal<ExpenseList | null>(null);
  protected readonly loading = signal(true);
  protected readonly editing = signal<Expense | null | undefined>(undefined); // undefined = closed, null = new
  protected readonly rejecting = signal<number | null>(null);
  protected rejectNote = '';
  protected readonly editingCats = signal(false);
  protected catText = '';

  protected readonly vsPrev = computed(() => {
    const s = this.res()?.summary;
    return s ? change(s.total, s.previous) : null;
  });
  protected readonly maxCat = computed(() => Math.max(1, ...(this.res()?.summary.by_category ?? []).map((c) => c.total)));

  constructor() {
    const t = inject(ActivatedRoute).snapshot.queryParamMap.get('tab');
    if (t === 'approvals') this.tab.set('approvals');
    this.load();
  }

  private get range() {
    return this.periods.find((p) => p.key === this.period)!;
  }

  load(): void {
    this.loading.set(true);
    const status = this.tab() === 'approvals' ? 'pending' : this.tab() === 'reimburse' ? 'to_reimburse' : '';
    this.api.list({ from: this.range.from, to: this.range.to, category: this.category, status }).subscribe((r) => {
      this.res.set(r);
      this.loading.set(false);
    });
  }

  setTab(t: Tab): void {
    this.tab.set(t);
    this.load();
  }

  saved(): void {
    const wasNew = this.editing() === null;
    this.editing.set(undefined);
    this.toast.show(wasNew ? (this.res()?.can_approve ? 'Expense added.' : 'Sent to the admin for approval.') : 'Expense saved.');
    this.load();
  }

  decide(e: Expense, d: 'approve' | 'reject'): void {
    this.api.decide(e.id, d, d === 'reject' ? this.rejectNote : undefined).subscribe({
      next: () => {
        this.rejecting.set(null);
        this.rejectNote = '';
        this.toast.show(d === 'approve' ? `${money(e.amount)} approved.` : 'Claim rejected; they have been told why.');
        this.load();
      },
      error: (err) => this.toast.show(readErrors(err).fields['note'] || readErrors(err).message || 'Could not update.'),
    });
  }

  reimburse(e: Expense): void {
    if (!confirm(`Mark ${money(e.amount)} as paid back to ${e.created_by?.name}?`)) return;
    this.api.reimburse(e.id).subscribe(() => {
      this.toast.show('Marked as reimbursed.');
      this.load();
    });
  }

  receipt(e: Expense, ev: Event): void {
    ev.stopPropagation();
    this.api.receipt(e).subscribe({ error: () => this.toast.show('Could not download the receipt.') });
  }

  export(): void {
    this.api.export(this.range.from, this.range.to).subscribe({ error: () => this.toast.show('Export failed.') });
  }

  openCats(): void {
    this.api.categories().subscribe((r) => {
      this.catText = r.data.filter((c) => c.active && c.name !== 'Salaries').map((c) => c.name).join('\n');
      this.editingCats.set(true);
    });
  }

  saveCats(): void {
    const names = [...new Set(this.catText.split('\n').map((s) => s.trim()).filter(Boolean))];
    this.api.saveCategories(names).subscribe({
      next: () => {
        this.editingCats.set(false);
        this.toast.show('Categories saved.');
        this.load();
      },
      error: (e) => this.toast.show(readErrors(e).message || 'Could not save.'),
    });
  }

  open(e: Expense): void {
    if (e.source) {
      this.toast.show('Salaries come from payroll. Change them under Employees → Payroll.');
      return;
    }
    this.editing.set(e);
  }

  periodLabel(): string {
    return this.range.label;
  }

  date(d: string): string {
    return new Date(d).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' });
  }
}
