import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { AuthService } from '../../core/auth/auth.service';
import { BillingService, Company } from '../../core/billing.service';
import { readErrors } from '../../core/http-errors';
import { Toast } from '../../shared/toast.service';

/** Settings → Company: printed on invoices. Stored on the server, not in the code. */
@Component({
  selector: 'app-company',
  imports: [FormsModule],
  template: `
    @if (co()) {
      <form class="card" (ngSubmit)="save()">
        <h3>Company details</h3>
        <p class="hint">Printed on every invoice. Kept on the server only.</p>
        <div class="row-2">
          @for (f of main; track f.k) {
            <div class="field compact" [class.invalid]="err(f.k)" [class.wide]="f.wide">
              <label [for]="'co-' + f.k">{{ f.label }}</label>
              @if (f.k === 'state') {
                <select [id]="'co-' + f.k" [name]="f.k" [(ngModel)]="c[f.k]" [disabled]="!canEdit">@for (s of states(); track s) { <option [value]="s">{{ s }}</option> }</select>
              } @else if (f.k === 'address') {
                <textarea [id]="'co-' + f.k" [name]="f.k" rows="2" [(ngModel)]="c[f.k]" [disabled]="!canEdit"></textarea>
              } @else {
                <input [id]="'co-' + f.k" [name]="f.k" [(ngModel)]="c[f.k]" [placeholder]="f.ph ?? ''" [disabled]="!canEdit" [class.upper]="f.upper" />
              }
              @if (err(f.k)) { <div class="err">{{ err(f.k) }}</div> }
            </div>
          }
        </div>

        <h3 class="mt">Getting paid</h3>
        <p class="hint">The UPI ID makes the “scan to pay” QR on invoices (works with GPay, PhonePe, Paytm). Use the UPI ID linked to the company current account.</p>
        <div class="row-2">
          @for (f of pay; track f.k) {
            <div class="field compact" [class.invalid]="err(f.k)">
              <label [for]="'co-' + f.k">{{ f.label }}</label>
              <input [id]="'co-' + f.k" [name]="f.k" [(ngModel)]="c[f.k]" [placeholder]="f.ph ?? ''" [disabled]="!canEdit" [class.upper]="f.upper"
                     [type]="f.k === 'bank_account_number' && !showAcct() ? 'password' : 'text'" autocomplete="off" />
              @if (f.k === 'bank_account_number') { <button type="button" class="eye" (click)="showAcct.update((v) => !v)">{{ showAcct() ? 'Hide' : 'Show' }}</button> }
              @if (err(f.k)) { <div class="err">{{ err(f.k) }}</div> }
            </div>
          }
        </div>

        <h3 class="mt">Invoices</h3>
        <div class="row-2">
          <div class="field compact" [class.invalid]="err('invoice_prefix')"><label for="co-pre">Invoice number prefix</label>
            <input id="co-pre" name="pre" class="upper" [(ngModel)]="c['invoice_prefix']" [disabled]="!canEdit" />
            <small class="ex">Numbers look like {{ c['invoice_prefix'] || 'DFP' }}/2026-27/001</small>
            @if (err('invoice_prefix')) { <div class="err">{{ err('invoice_prefix') }}</div> }</div>
          <div class="field compact"><label for="co-sac">Default SAC code</label><input id="co-sac" name="sac" [(ngModel)]="c['default_sac']" [disabled]="!canEdit" />
            <small class="ex">998361 advertising · 998314 IT design &amp; development · ask your accountant</small></div>
          <div class="field compact"><label for="co-gst">Default GST rate</label>
            <select id="co-gst" name="gst" [(ngModel)]="c['default_gst_rate']" [disabled]="!canEdit">@for (r of [0, 5, 12, 18, 28]; track r) { <option [ngValue]="r">{{ r }}%</option> }</select></div>
          <div class="field compact"><label for="co-days">Payment due after (days)</label><input id="co-days" name="days" type="number" min="0" max="120" [(ngModel)]="c['payment_terms_days']" [disabled]="!canEdit" /></div>
          <div class="field compact"><label for="co-lut">LUT number (export invoices)</label><input id="co-lut" name="lut" [(ngModel)]="c['lut_number']" [disabled]="!canEdit" /></div>
        </div>
        <div class="field"><label for="co-terms">Terms printed on invoices</label><textarea id="co-terms" name="terms" rows="3" [(ngModel)]="c['terms']" [disabled]="!canEdit"></textarea></div>

        @if (canEdit) { <div class="form-actions plain"><button class="btn" type="submit" [disabled]="busy()">Save company details</button></div> }
      </form>
    } @else { <p class="hint">Loading…</p> }
  `,
  styles: `
    h3 { font-size: 16px; }
    .mt { margin-top: 26px; }
    .hint { color: var(--ink-3); font-size: 13px; margin-top: 2px; }
    .wide { grid-column: 1 / -1; }
    .upper { text-transform: uppercase; }
    .ex { display: block; color: var(--ink-3); font-size: 11.5px; margin-top: 4px; }
    .eye { position: absolute; right: 8px; top: 12px; border: 0; background: none; color: var(--brand-deep); font-weight: 600; font-size: 12px; cursor: pointer; }
    .form-actions.plain { position: static; padding: 0; }
  `,
})
export class CompanySettings {
  private api = inject(BillingService);
  private toast = inject(Toast);
  protected readonly canEdit = inject(AuthService).can('settings.edit');
  /** Signal so the form appears when the data arrives (the app runs without zone.js). */
  protected readonly co = signal<Company | null>(null);
  protected get c(): Company {
    return this.co()!;
  }
  protected readonly states = signal<string[]>([]);
  protected readonly errors = signal<Record<string, string>>({});
  protected readonly busy = signal(false);
  protected readonly showAcct = signal(false);

  protected readonly main: { k: string; label: string; ph?: string; upper?: boolean; wide?: boolean }[] = [
    { k: 'legal_name', label: 'Registered name', wide: true },
    { k: 'address', label: 'Registered address', wide: true },
    { k: 'city', label: 'City' }, { k: 'state', label: 'State' }, { k: 'pincode', label: 'PIN code' },
    { k: 'gstin', label: 'GSTIN', upper: true }, { k: 'pan', label: 'PAN', upper: true },
    { k: 'email', label: 'Billing email' }, { k: 'phone', label: 'Phone' }, { k: 'website', label: 'Website' },
  ];
  protected readonly pay: { k: string; label: string; ph?: string; upper?: boolean }[] = [
    { k: 'upi_id', label: 'UPI ID', ph: 'yourname@okbank' }, { k: 'upi_name', label: 'Name shown in UPI apps' },
    { k: 'bank_account_name', label: 'Bank account name' }, { k: 'bank_account_number', label: 'Account number' },
    { k: 'bank_ifsc', label: 'IFSC', upper: true }, { k: 'bank_name', label: 'Bank' },
    { k: 'bank_branch', label: 'Branch' }, { k: 'swift', label: 'SWIFT (overseas clients)', upper: true },
  ];

  constructor() {
    this.api.company().subscribe((r) => {
      this.states.set(r.states);
      this.co.set(r.company);
    });
  }

  err(k: string): string {
    return this.errors()[k] ?? '';
  }

  save(): void {
    const data: Company = {};
    for (const [k, v] of Object.entries(this.c!)) {
      const t = typeof v === 'string' ? v.trim() : v;
      data[k] = ['gstin', 'pan', 'bank_ifsc', 'swift', 'invoice_prefix'].includes(k) && typeof t === 'string' ? t.toUpperCase().replace(/\s/g, '') : t;
    }
    this.busy.set(true);
    this.errors.set({});
    this.api.saveCompany(data).subscribe({
      next: (r) => {
        this.co.set(r.company);
        this.busy.set(false);
        this.toast.show('Company details saved.');
      },
      error: (e) => {
        this.busy.set(false);
        const r = readErrors(e);
        this.errors.set(r.fields);
        this.toast.show(r.message || 'Please fix the highlighted fields.');
      },
    });
  }
}
