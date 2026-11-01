import { Component, inject, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Client, BillingService } from '../../core/billing.service';
import { readErrors } from '../../core/http-errors';
import { LeadsService } from '../../core/leads.service';
import { INDIAN_STATES } from '../onboarding/onboarding';
import { Sheet } from '../../shared/sheet';

@Component({
  selector: 'app-client-form',
  imports: [FormsModule, Sheet],
  template: `
    <app-sheet [title]="client() ? 'Edit ' + client()!.company_name : 'Add client'" subtitle="Billing details are printed on invoices" (closed)="closed.emit()">
      @if (message()) { <div class="alert error">{{ message() }}</div> }
      <form (ngSubmit)="save()">
        <div class="row-2">
          @for (f of fields; track f.k) {
            <div class="field compact" [class.invalid]="err(f.k)" [class.wide]="f.wide">
              <label [for]="'cf-' + f.k">{{ f.label }}@if (f.k === 'company_name') { <span class="req">*</span> }</label>
              @if (f.k === 'state') {
                <select [id]="'cf-' + f.k" [name]="f.k" [(ngModel)]="v[f.k]"><option [ngValue]="null">—</option>@for (s of states; track s) { <option [value]="s">{{ s }}</option> }</select>
              } @else if (f.k === 'account_manager_id') {
                <select [id]="'cf-' + f.k" [name]="f.k" [(ngModel)]="v[f.k]"><option [ngValue]="null">—</option>@for (u of staff(); track u.id) { <option [ngValue]="u.id">{{ u.name }}</option> }</select>
              } @else if (f.k === 'address' || f.k === 'notes') {
                <textarea [id]="'cf-' + f.k" [name]="f.k" rows="2" [(ngModel)]="v[f.k]"></textarea>
              } @else {
                <input [id]="'cf-' + f.k" [name]="f.k" [(ngModel)]="v[f.k]" [class.upper]="f.k === 'gstin'" [attr.maxlength]="f.k === 'gstin' ? 15 : null" />
              }
              @if (err(f.k)) { <div class="err">{{ err(f.k) }}</div> }
            </div>
          }
        </div>
        <div class="form-actions">
          <button type="button" class="btn-ghost" (click)="closed.emit()">Cancel</button>
          <button class="btn" type="submit" [disabled]="busy()">{{ client() ? 'Save' : 'Add client' }}</button>
        </div>
      </form>
    </app-sheet>
  `,
  styles: `.wide { grid-column: 1 / -1; } .upper { text-transform: uppercase; }`,
})
export class ClientForm {
  client = input<Client | null>(null);
  closed = output<void>();
  saved = output<Client>();
  private api = inject(BillingService);
  protected readonly states = INDIAN_STATES;
  protected readonly staff = signal<{ id: number; name: string }[]>([]);
  protected readonly fields = [
    { k: 'company_name', label: 'Company / business name', wide: true },
    { k: 'contact_name', label: 'Contact person' }, { k: 'phone', label: 'Phone' },
    { k: 'email', label: 'Billing email' }, { k: 'industry', label: 'Industry' },
    { k: 'gstin', label: 'GSTIN' }, { k: 'website', label: 'Website' },
    { k: 'address', label: 'Address', wide: true },
    { k: 'city', label: 'City' }, { k: 'state', label: 'State' }, { k: 'pincode', label: 'PIN code' },
    { k: 'account_manager_id', label: 'Account manager' },
    { k: 'notes', label: 'Internal notes', wide: true },
  ];
  protected v: Record<string, string | number | null> = {};
  protected readonly busy = signal(false);
  protected readonly message = signal('');
  protected readonly errors = signal<Record<string, string>>({});

  constructor() {
    inject(LeadsService).options().subscribe((o) => this.staff.set(o.assignees));
  }

  ngOnInit(): void {
    const c = this.client();
    for (const f of this.fields) this.v[f.k] = null;
    if (c) {
      for (const f of this.fields) this.v[f.k] = (c as unknown as Record<string, string | null>)[f.k] ?? null;
      this.v['account_manager_id'] = c.account_manager?.id ?? null;
    }
  }

  err(k: string): string {
    return this.errors()[k] ?? '';
  }

  save(): void {
    const data: Record<string, unknown> = {};
    for (const [k, val] of Object.entries(this.v)) data[k] = typeof val === 'string' ? val.trim() || null : val;
    if (typeof data['gstin'] === 'string') data['gstin'] = (data['gstin'] as string).toUpperCase();
    this.busy.set(true);
    this.errors.set({});
    this.message.set('');
    this.api.saveClient(data, this.client()?.id).subscribe({
      next: (c) => this.saved.emit(c),
      error: (e) => {
        const r = readErrors(e);
        this.errors.set(r.fields);
        this.message.set(r.message);
        this.busy.set(false);
      },
    });
  }
}
