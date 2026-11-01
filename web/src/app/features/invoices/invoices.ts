import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { Subject, debounceTime } from 'rxjs';
import { AuthService } from '../../core/auth/auth.service';
import { BillingService, Claim, InvoiceRow, InvoiceSummary, STATUS_LABEL } from '../../core/billing.service';
import { readErrors } from '../../core/http-errors';
import { money } from '../../shared/format';
import { Toast } from '../../shared/toast.service';

@Component({
  selector: 'app-invoices',
  imports: [FormsModule, RouterLink],
  templateUrl: './invoices.html',
  styleUrl: './invoices.scss',
})
export class Invoices {
  private api = inject(BillingService);
  private toast = inject(Toast);
  protected readonly auth = inject(AuthService);
  protected readonly money = money;
  protected readonly label = STATUS_LABEL;
  protected readonly isClient = computed(() => this.auth.user()?.role === 'client');
  protected readonly canConfirm = this.auth.can('invoices.approve') && this.auth.user()?.role !== 'client';

  protected readonly tab = signal<'invoices' | 'claims'>('invoices');
  protected status = '';
  protected search = '';
  protected readonly rows = signal<InvoiceRow[]>([]);
  protected readonly summary = signal<InvoiceSummary | null>(null);
  protected readonly loading = signal(true);
  protected readonly claims = signal<Claim[]>([]);
  protected readonly notes: Record<number, string> = {};
  protected readonly rejecting = signal<number | null>(null);
  protected readonly search$ = new Subject<void>();

  constructor() {
    const q = inject(ActivatedRoute).snapshot.queryParamMap;
    this.status = q.get('status') ?? '';
    if (q.get('tab') === 'claims') this.openClaims();
    this.search$.pipe(debounceTime(300)).subscribe(() => this.load());
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.api.invoices({ status: this.status, search: this.search }).subscribe((r) => {
      this.rows.set(r.data);
      this.summary.set(r.summary);
      this.loading.set(false);
    });
  }

  filter(s: string): void {
    this.status = this.status === s ? '' : s;
    this.tab.set('invoices');
    this.load();
  }

  openClaims(): void {
    this.tab.set('claims');
    this.api.claims().subscribe((r) => this.claims.set(r.data));
  }

  decide(c: Claim, d: 'confirm' | 'reject'): void {
    this.api.decideClaim(c.id, d, this.notes[c.id]).subscribe({
      next: () => {
        this.toast.show(d === 'confirm' ? `Payment of ${money(c.amount)} confirmed.` : 'Payment note rejected; the client can see why.');
        this.claims.update((l) => l.filter((x) => x.id !== c.id));
        this.rejecting.set(null);
        this.load();
      },
      error: (e) => this.toast.show(readErrors(e).fields['note'] || readErrors(e).message || 'Could not update.'),
    });
  }

  date(d: string): string {
    return new Date(d).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
  }

  stateOf(i: InvoiceRow): string {
    return i.overdue ? 'overdue' : i.status;
  }
}
