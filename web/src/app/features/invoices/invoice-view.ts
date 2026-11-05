import { Component, computed, inject, input, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth/auth.service';
import { BillingService, Invoice, STATUS_LABEL } from '../../core/billing.service';
import { readErrors } from '../../core/http-errors';
import { money } from '../../shared/format';
import { Toast } from '../../shared/toast.service';
import { Sheet } from '../../shared/sheet';
import { ShareLink } from '../../shared/share-link';
import { Dialog } from '../../shared/dialog';

/** One invoice: looks like the PDF, with pay (clients) or manage (staff) actions. */
@Component({
  selector: 'app-invoice-view',
  imports: [FormsModule, RouterLink, Sheet, ShareLink],
  templateUrl: './invoice-view.html',
  styleUrl: './invoice-view.scss',
})
export class InvoiceView {
  protected readonly dialog = inject(Dialog);
  id = input.required<string>();
  private api = inject(BillingService);
  private router = inject(Router);
  private toast = inject(Toast);
  protected readonly auth = inject(AuthService);
  protected readonly money = money;
  protected readonly label = STATUS_LABEL;
  protected readonly isClient = computed(() => this.auth.user()?.role === 'client');

  protected readonly inv = signal<Invoice | null>(null);
  protected readonly error = signal('');
  protected readonly busy = signal(false);
  protected readonly paying = signal(false);
  protected readonly recording = signal(false);
  protected readonly copied = signal(false);
  protected pay = { amount: 0, paid_on: new Date().toISOString().slice(0, 10), mode: 'upi', reference: '', note: '' };
  protected readonly payErrors = signal<Record<string, string>>({});
  protected readonly isMobile = typeof matchMedia !== 'undefined' && matchMedia('(max-width: 860px)').matches;

  protected readonly pendingClaim = computed(() => this.inv()?.claims.find((c) => c.status === 'pending') ?? null);
  protected readonly payable = computed(() => {
    const i = this.inv();
    return !!i && (i.status === 'unpaid' || i.status === 'partial');
  });

  /** Staff: the Razorpay link to send the client. */
  protected readonly payLink = signal('');
  protected readonly sharingLink = signal(false);

  ngOnInit(): void {
    this.load();
  }

  /** Client: go to Razorpay's payment page. */
  payOnline(): void {
    this.busy.set(true);
    this.api.payOnline(this.inv()!.id).subscribe({
      next: (r) => (window.location.href = r.url),
      error: (e) => {
        this.busy.set(false);
        this.toast.show(readErrors(e).message || 'Online payment is not available right now. Please use UPI below.');
      },
    });
  }

  /** Staff: get the link to share on WhatsApp / email. */
  shareLink(): void {
    this.busy.set(true);
    this.api.payOnline(this.inv()!.id).subscribe({
      next: (r) => {
        this.busy.set(false);
        this.payLink.set(r.url);
        this.sharingLink.set(true);
      },
      error: (e) => {
        this.busy.set(false);
        this.toast.show(readErrors(e).message || 'Could not make a payment link.');
      },
    });
  }

  /** Ask Razorpay now (if the payer did not come back to the CRM after paying). */
  checkOnline(): void {
    this.busy.set(true);
    this.api.checkOnline(this.inv()!.id).subscribe({
      next: (r) => {
        this.busy.set(false);
        if (r.found) {
          this.toast.show(r.message);
          this.load();
        } else this.importRazorpay(r.message);
      },
      error: (e) => {
        this.busy.set(false);
        this.toast.show(readErrors(e).message || 'Could not reach Razorpay.');
      },
    });
  }

  /** Paid through a link made in the Razorpay dashboard: add it by its payment ID. */
  async importRazorpay(why = ''): Promise<void> {
    const id = await this.dialog.prompt({
      title: 'Add a Razorpay payment',
      message: (why ? why + '\n\n' : '') + 'Paste the payment ID from Razorpay → Transactions → Payments (it starts with pay_). The CRM checks it with Razorpay and records the amount.',
      label: 'Razorpay payment ID', value: '', confirmText: 'Check & record', required: true,
    });
    if (!id) return;
    this.busy.set(true);
    this.api.importRazorpay(this.inv()!.id, id.trim()).subscribe({
      next: (r) => {
        this.busy.set(false);
        this.toast.show(r.message);
        this.load();
      },
      error: (e) => {
        this.busy.set(false);
        const r = readErrors(e);
        this.toast.show(Object.values(r.fields)[0] || r.message || 'Could not add the payment.');
      },
    });
  }

  modeLabel(m: string): string {
    return ({ upi: 'UPI', online: 'Online (Razorpay)', bank_transfer: 'Bank transfer' } as Record<string, string>)[m] ?? m.replace('_', ' ');
  }

  load(): void {
    this.api.invoice(+this.id()).subscribe({
      next: (i) => this.inv.set(i),
      error: (e) => this.error.set(readErrors(e).message || 'Invoice not found.'),
    });
  }

  private act(obs: ReturnType<BillingService['issue']>, msg: string): void {
    this.busy.set(true);
    obs.subscribe({
      next: (i) => {
        this.busy.set(false);
        this.inv.set({ ...this.inv()!, ...i });
        this.toast.show(msg);
        this.load();
      },
      error: (e) => {
        this.busy.set(false);
        this.toast.show(readErrors(e).message || 'Something went wrong.');
      },
    });
  }

  async issue(): Promise<void> {
    if (await this.dialog.confirm({ title: 'Issue this invoice?', message: 'It gets its number and can no longer be edited.', confirmText: 'Issue invoice' })) this.act(this.api.issue(this.inv()!.id), 'Invoice issued.');
  }

  async cancel(): Promise<void> {
    if (await this.dialog.confirm({ title: 'Cancel this invoice?', message: 'It stays on record marked as cancelled.', confirmText: 'Cancel invoice', cancelText: 'Keep it', danger: true })) this.act(this.api.cancel(this.inv()!.id), 'Invoice cancelled.');
  }

  async deleteDraft(): Promise<void> {
    if (!(await this.dialog.confirm({ title: 'Delete this draft?', message: 'This cannot be undone.', confirmText: 'Delete', danger: true }))) return;
    this.api.deleteDraft(this.inv()!.id).subscribe(() => {
      this.toast.show('Draft deleted.');
      this.router.navigateByUrl('/invoices');
    });
  }

  email(): void {
    this.busy.set(true);
    this.api.email(this.inv()!.id).subscribe({
      next: (r) => {
        this.busy.set(false);
        this.toast.show(`Invoice emailed to ${r.sent_to}.`);
        this.load();
      },
      error: (e) => {
        this.busy.set(false);
        this.toast.show(readErrors(e).message || 'Could not send.');
      },
    });
  }

  download(): void {
    this.api.downloadPdf(this.inv()!).subscribe({ error: () => this.toast.show('Could not download the PDF.') });
  }

  copyUpi(): void {
    navigator.clipboard?.writeText(this.inv()!.pay?.upi_id ?? '').then(() => {
      this.copied.set(true);
      setTimeout(() => this.copied.set(false), 2000);
    });
  }

  openForm(kind: 'claim' | 'record'): void {
    this.pay = { amount: this.inv()!.balance, paid_on: new Date().toISOString().slice(0, 10), mode: kind === 'claim' ? 'upi' : 'bank_transfer', reference: '', note: '' };
    this.payErrors.set({});
    this.paying.set(kind === 'claim');
    this.recording.set(kind === 'record');
  }

  submit(kind: 'claim' | 'record'): void {
    const i = this.inv()!;
    this.busy.set(true);
    this.payErrors.set({});
    const req = kind === 'claim' ? this.api.claim(i.id, this.pay) : this.api.recordPayment(i.id, this.pay);
    (req as ReturnType<BillingService['recordPayment']>).subscribe({
      next: () => {
        this.busy.set(false);
        this.paying.set(false);
        this.recording.set(false);
        this.toast.show(kind === 'claim' ? 'Thank you! We will confirm your payment shortly.' : 'Payment recorded.');
        this.load();
      },
      error: (e) => {
        this.busy.set(false);
        const r = readErrors(e);
        this.payErrors.set(r.fields);
        if (r.message) this.toast.show(r.message);
      },
    });
  }

  date(d: string | null): string {
    return d ? new Date(d).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';
  }

  state(): string {
    const i = this.inv()!;
    return i.overdue ? 'overdue' : i.status;
  }
}
