import { Component, computed, inject, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { AuthService } from '../../core/auth/auth.service';
import { BillingService, Client } from '../../core/billing.service';
import { Expense, ExpensesService } from '../../core/expenses.service';
import { readErrors } from '../../core/http-errors';
import { money } from '../../shared/format';
import { Sheet } from '../../shared/sheet';
import { Dialog } from '../../shared/dialog';

@Component({
  selector: 'app-expense-form',
  imports: [FormsModule, Sheet],
  template: `
    <app-sheet [title]="expense() ? 'Edit expense' : 'Add expense'" [subtitle]="canApprove() ? 'Counts in your totals straight away' : 'Goes to the admin for approval'" width="520px" (closed)="closed.emit()">
      @if (expense()?.review_note && expense()?.status === 'rejected') { <div class="alert error">Rejected: {{ expense()!.review_note }}. Fix it and save to send it again.</div> }
      <form (ngSubmit)="save()">
        <div class="amt">
          <div class="field" [class.invalid]="err('amount')"><label for="ex-a">Total paid (₹, incl. GST) <span class="req">*</span></label>
            <input id="ex-a" name="a" type="number" min="1" step="0.01" inputmode="decimal" [(ngModel)]="f.amount" />
            @if (err('amount')) { <div class="err">{{ err('amount') }}</div> }</div>
          <div class="field" [class.invalid]="err('gst')"><label for="ex-g">GST in it (₹)</label>
            <input id="ex-g" name="g" type="number" min="0" step="0.01" inputmode="decimal" [(ngModel)]="f.gst" />
            @if (err('gst')) { <div class="err">{{ err('gst') }}</div> }</div>
        </div>
        <div class="quick">GST included: @for (r of [5, 12, 18]; track r) { <button type="button" (click)="gstFrom(r)">{{ r }}%</button> } <button type="button" (click)="f.gst = 0">None</button>
          @if (+f.amount > 0 && +f.gst > 0) { <span>· before GST {{ money(+f.amount - +f.gst) }}</span> }</div>
        <div class="row-2">
          <div class="field compact" [class.invalid]="err('spent_on')"><label for="ex-d">Date <span class="req">*</span></label><input id="ex-d" name="d" type="date" [max]="today" [(ngModel)]="f.spent_on" />
            @if (err('spent_on')) { <div class="err">{{ err('spent_on') }}</div> }</div>
          <div class="field compact" [class.invalid]="err('category')"><label for="ex-c">Category <span class="req">*</span></label>
            <select id="ex-c" name="c" [(ngModel)]="f.category"><option value="" disabled>Choose…</option>@for (c of categories(); track c) { @if (c !== 'Salaries') { <option [value]="c">{{ c }}</option> } }</select>
            @if (err('category')) { <div class="err">{{ err('category') }}</div> }</div>
          <div class="field compact"><label for="ex-v">Paid to (vendor)</label><input id="ex-v" name="v" [(ngModel)]="f.vendor" placeholder="e.g. Meta, Canva, KSEB" /></div>
          <div class="field compact"><label for="ex-m">Paid by</label>
            <select id="ex-m" name="m" [(ngModel)]="f.payment_mode"><option value="">—</option><option value="upi">UPI</option><option value="bank_transfer">Bank transfer</option><option value="card">Card</option><option value="cash">Cash</option><option value="cheque">Cheque</option></select></div>
          @if (clients().length) {
            <div class="field compact wide"><label for="ex-cl">For a client? (optional)</label>
              <select id="ex-cl" name="cl" [(ngModel)]="f.client_id"><option [ngValue]="null">—</option>@for (c of clients(); track c.id) { <option [ngValue]="c.id">{{ c.company_name }}</option> }</select></div>
          }
        </div>
        <div class="field"><label for="ex-n">Notes</label><input id="ex-n" name="n" [(ngModel)]="f.notes" /></div>
        <label class="check"><input type="checkbox" name="r" [(ngModel)]="f.reimbursable" /> I paid this from my own money. Please reimburse me.</label>

        <label class="drop" [class.has]="file() || expense()?.receipt">
          <input type="file" accept="image/*,.pdf" capture="environment" (change)="pick($event)" />
          <span class="big">🧾</span>
          <b>{{ file()?.name ?? expense()?.receipt?.name ?? 'Add receipt photo or PDF' }}</b>
          <small>On a phone this opens the camera</small>
        </label>
        @if (err('receipt')) { <div class="err">{{ err('receipt') }}</div> }

        <div class="form-actions">
          @if (expense()) { <button type="button" class="btn-ghost danger" (click)="remove()">Delete</button> }
          <span class="sp"></span>
          <button type="button" class="btn-ghost" (click)="closed.emit()">Cancel</button>
          <button class="btn" type="submit" [disabled]="busy()">{{ expense() ? 'Save' : canApprove() ? 'Add expense' : 'Submit for approval' }}</button>
        </div>
      </form>
    </app-sheet>
  `,
  styles: `
    .amt { display: grid; grid-template-columns: 1.4fr 1fr; gap: 0 12px; }
    .amt #ex-a { font-size: 22px; font-weight: 700; }
    .quick { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; margin-top: 8px; font-size: 12.5px; color: var(--ink-3); }
    .quick button { border: 1px solid var(--line); background: #fff; border-radius: 99px; padding: 3px 10px; font-size: 12px; cursor: pointer; }
    .quick button:hover { border-color: var(--brand); color: var(--brand-deep); }
    .wide { grid-column: 1 / -1; }
    .drop { position: relative; display: flex; flex-direction: column; align-items: center; gap: 2px; margin-top: 16px; padding: 18px; border: 2px dashed var(--line); border-radius: 16px; text-align: center; cursor: pointer; transition: all 0.2s; }
    .drop:hover, .drop.has { border-color: var(--brand); background: var(--brand-soft); }
    .drop input { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
    .drop .big { font-size: 26px; } .drop small { color: var(--ink-3); font-size: 12px; }
    .err { color: var(--crit); font-size: 12.5px; margin-top: 6px; }
    .sp { flex: 1; } .btn-ghost.danger { color: #b42318; border-color: #f5b5b0; }
  `,
})
export class ExpenseForm {
  protected readonly dialog = inject(Dialog);
  expense = input<Expense | null>(null);
  categories = input.required<string[]>();
  canApprove = input(false);
  closed = output<void>();
  saved = output<void>();

  private api = inject(ExpensesService);
  protected readonly money = money;
  protected readonly today = new Date().toISOString().slice(0, 10);
  protected readonly clients = signal<Client[]>([]);
  protected f: { amount: number | string; gst: number | string; spent_on: string; category: string; vendor: string; payment_mode: string; client_id: number | null; notes: string; reimbursable: boolean } = {
    amount: '', gst: '', spent_on: this.today, category: '', vendor: '', payment_mode: '', client_id: null, notes: '', reimbursable: false,
  };
  protected readonly file = signal<File | null>(null);
  protected readonly busy = signal(false);
  protected readonly errors = signal<Record<string, string>>({});

  constructor() {
    if (inject(AuthService).can('clients.view')) inject(BillingService).clients({ status: 'active' }).subscribe((r) => this.clients.set(r.data));
  }

  ngOnInit(): void {
    const e = this.expense();
    if (e) {
      this.f = {
        amount: e.amount, gst: e.gst || '', spent_on: e.spent_on, category: e.category, vendor: e.vendor ?? '', payment_mode: e.payment_mode ?? '',
        client_id: e.client?.id ?? null, notes: e.notes ?? '', reimbursable: e.reimbursable,
      };
    }
  }

  gstFrom(rate: number): void {
    const a = +this.f.amount || 0;
    this.f.gst = Math.round((a * rate) / (100 + rate) * 100) / 100;
  }

  pick(e: Event): void {
    this.file.set((e.target as HTMLInputElement).files?.[0] ?? null);
  }

  err(k: string): string {
    return this.errors()[k] ?? '';
  }

  save(): void {
    const fd = new FormData();
    for (const [k, v] of Object.entries(this.f)) {
      if (k === 'reimbursable') fd.append(k, v ? '1' : '0');
      else if (v !== '' && v !== null) fd.append(k, String(v));
    }
    if (this.file()) fd.append('receipt', this.file()!);
    this.busy.set(true);
    this.errors.set({});
    this.api.save(fd, this.expense()?.id).subscribe({
      next: () => this.saved.emit(),
      error: (e) => {
        this.busy.set(false);
        this.errors.set(readErrors(e).fields);
      },
    });
  }

  async remove(): Promise<void> {
    if (!(await this.dialog.confirm({ title: 'Delete this expense?', message: 'This cannot be undone.', confirmText: 'Delete', danger: true }))) return;
    this.api.remove(this.expense()!.id).subscribe(() => this.saved.emit());
  }
}
