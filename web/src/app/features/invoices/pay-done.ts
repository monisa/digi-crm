import { Component, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { BillingService } from '../../core/billing.service';
import { readErrors } from '../../core/http-errors';
import { money } from '../../shared/format';

/** Razorpay sends the customer back here after paying (or cancelling). */
@Component({
  selector: 'app-pay-done',
  imports: [RouterLink],
  template: `
    <div class="page">
      <header class="bar"><img src="logo.png" alt="DigiFox Pro Solutions" /></header>
      <main class="card wrap">
        @switch (state()) {
          @case ('checking') { <div class="spin" aria-hidden="true"></div><h1>Confirming your payment…</h1><p class="sub">Please don’t close this page.</p> }
          @case ('paid') {
            <div class="tick" aria-hidden="true">✓</div>
            <h1>Payment received</h1>
            <p class="sub">Thank you! {{ money(amount()) }} was paid{{ invoiceNo() ? ' for invoice ' + invoiceNo() : '' }}.
              @if (balance() > 0) { <br>{{ money(balance()) }} is still due. }</p>
            <a class="btn" [routerLink]="['/invoices', invoiceId()]">View invoice</a>
          }
          @case ('failed') {
            <div class="x" aria-hidden="true">!</div>
            <h1>Payment not completed</h1>
            <p class="sub">{{ message() || 'The payment was cancelled or did not go through. No money was taken, or it will be refunded automatically by your bank.' }}</p>
            @if (invoiceId()) { <a class="btn" [routerLink]="['/invoices', invoiceId()]">Back to invoice</a> }
          }
        }
      </main>
    </div>
  `,
  styles: `
    .page { min-height: 100vh; background: var(--bg); }
    .bar { height: 160px; padding: 16px clamp(16px, 4vw, 48px); background: radial-gradient(circle at 85% 0%, rgba(237, 143, 33, 0.35), transparent 50%), #000; }
    .bar img { height: 40px; }
    .wrap { max-width: 480px; margin: -80px auto 0; padding: 36px 28px; border-radius: 24px; text-align: center; animation: rise 0.4s var(--ease) both; }
    h1 { font-size: 22px; margin-top: 4px; }
    .sub { color: var(--ink-2); margin: 10px 0 22px; line-height: 1.6; }
    .tick, .x { width: 76px; height: 76px; margin: 0 auto 18px; border-radius: 50%; display: grid; place-items: center; font-size: 36px; color: #fff; font-weight: 800; animation: pop 0.5s var(--ease); }
    .tick { background: linear-gradient(135deg, #16a34a, #22c55e); box-shadow: 0 12px 30px rgba(22, 163, 74, 0.3); }
    .x { background: linear-gradient(135deg, #d92d20, #f97066); box-shadow: 0 12px 30px rgba(217, 45, 32, 0.25); }
    .spin { width: 48px; height: 48px; margin: 10px auto 20px; border-radius: 50%; border: 4px solid var(--line); border-top-color: var(--brand); animation: turn 0.9s linear infinite; }
    @keyframes turn { to { transform: rotate(360deg); } }
    @keyframes pop { from { transform: scale(0.4); opacity: 0; } }
    @media (prefers-reduced-motion: reduce) { .wrap, .tick, .x { animation: none; } .spin { animation-duration: 3s; } }
  `,
})
export class PayDone {
  private api = inject(BillingService);
  protected readonly money = money;
  protected readonly state = signal<'checking' | 'paid' | 'failed'>('checking');
  protected readonly message = signal('');
  protected readonly invoiceId = signal<number | null>(null);
  protected readonly invoiceNo = signal<string | null>(null);
  protected readonly amount = signal(0);
  protected readonly balance = signal(0);

  constructor() {
    const q = inject(ActivatedRoute).snapshot.queryParamMap;
    const params: Record<string, string> = {};
    for (const k of ['razorpay_payment_id', 'razorpay_payment_link_id', 'razorpay_payment_link_reference_id', 'razorpay_payment_link_status', 'razorpay_signature']) {
      const v = q.get(k);
      if (v) params[k] = v;
    }
    if (!params['razorpay_payment_link_id']) {
      this.state.set('failed');
      return;
    }
    this.api.razorpayReturn(params).subscribe({
      next: (r) => {
        this.invoiceId.set(r.invoice_id);
        this.invoiceNo.set(r.invoice_no);
        this.amount.set(r.amount);
        this.balance.set(r.balance);
        this.state.set(r.paid ? 'paid' : 'failed');
      },
      error: (e) => {
        this.message.set(readErrors(e).message);
        this.state.set('failed');
      },
    });
  }
}
