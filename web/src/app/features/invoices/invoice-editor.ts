import { Component, computed, inject, input, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth/auth.service';
import { BillingService, Client, InvoiceItem } from '../../core/billing.service';
import { readErrors } from '../../core/http-errors';
import { money } from '../../shared/format';
import { Toast } from '../../shared/toast.service';

/** /invoices/new and /invoices/:id/edit (drafts only). */
@Component({
  selector: 'app-invoice-editor',
  imports: [FormsModule, RouterLink],
  templateUrl: './invoice-editor.html',
  styleUrl: './invoice-editor.scss',
})
export class InvoiceEditor {
  id = input<string>(); // edit mode when set
  private api = inject(BillingService);
  private router = inject(Router);
  private toast = inject(Toast);
  protected readonly money = money;

  protected readonly clients = signal<Client[]>([]);
  protected clientId: number | null = null;
  protected issueDate = new Date().toISOString().slice(0, 10);
  protected dueDate = '';
  protected gstRate = 18;
  protected notes = '';
  protected readonly items = signal<InvoiceItem[]>([{ description: '', sac: null, qty: 1, rate: 0 }]);
  protected readonly companyState = signal('Kerala');
  protected readonly companyCode = signal('32');
  protected readonly termsDays = signal(15);
  protected readonly busy = signal(false);
  protected readonly errors = signal<Record<string, string>>({});
  protected readonly message = signal('');
  protected tick = signal(0); // bump to recompute totals after edits

  protected readonly suggestions = [
    'Meta ads management', 'Google ads management', 'SEO – monthly retainer', 'Social media management',
    'Website design & development', 'Creative design (posters)', 'Video production', 'Branding & logo design',
  ];

  protected readonly client = computed(() => { this.tick(); return this.clients().find((c) => c.id === +(this.clientId ?? 0)) ?? null; });

  /** Same rules as the server: GSTIN state, else client state; same as ours → CGST+SGST. */
  protected readonly gstType = computed<'intra' | 'inter' | 'export'>(() => {
    const c = this.client();
    if (!c) return 'intra';
    if (c.state === 'Outside India') return 'export';
    if (c.gstin && /^\d{2}/.test(c.gstin)) return c.gstin.slice(0, 2) === this.companyCode() ? 'intra' : 'inter';
    return !c.state || c.state === this.companyState() ? 'intra' : 'inter';
  });

  protected readonly totals = computed(() => {
    this.tick();
    const sub = this.items().reduce((s, i) => s + (+i.qty || 0) * (+i.rate || 0), 0);
    const type = this.gstType();
    const rate = type === 'export' ? 0 : +this.gstRate;
    const half = Math.round(sub * rate / 2) / 100;
    const cgst = type === 'intra' ? half : 0;
    const igst = type === 'inter' ? Math.round(sub * rate) / 100 : 0;
    return { sub, cgst, sgst: cgst, igst, total: Math.round((sub + cgst * 2 + igst) * 100) / 100, rate };
  });

  constructor() {
    this.api.clients({ status: 'active' }).subscribe((r) => {
      this.clients.set(r.data);
      this.tick.update((n) => n + 1);
    });
    if (inject(AuthService).can('settings.view')) {
      this.api.company().subscribe((r) => {
        this.companyState.set(String(r.company['state'] || 'Kerala'));
        const g = String(r.company['gstin'] || '');
        if (/^\d{2}/.test(g)) this.companyCode.set(g.slice(0, 2));
        this.gstRate = +r.company['default_gst_rate'] || 18;
        this.termsDays.set(+r.company['payment_terms_days'] || 15);
        this.tick.update((n) => n + 1);
      });
    }
    const q = inject(ActivatedRoute).snapshot.queryParamMap.get('client');
    if (q) this.clientId = +q;
  }

  ngOnInit(): void {
    const id = this.id();
    if (!id) return;
    this.api.invoice(+id).subscribe((inv) => {
      if (inv.status !== 'draft') {
        this.router.navigate(['/invoices', inv.id], { replaceUrl: true });
        return;
      }
      this.clientId = inv.client?.id ?? null;
      this.issueDate = inv.issue_date;
      this.dueDate = inv.due_date;
      this.gstRate = inv.gst_rate || 18;
      this.notes = inv.notes ?? '';
      this.items.set(inv.items.map((i) => ({ ...i })));
      this.tick.update((n) => n + 1);
    });
  }

  add(desc = ''): void {
    this.items.update((l) => [...l, { description: desc, sac: null, qty: 1, rate: 0 }]);
  }

  remove(i: number): void {
    this.items.update((l) => (l.length > 1 ? l.filter((_, n) => n !== i) : l));
  }

  changed(): void {
    this.tick.update((n) => n + 1);
  }

  save(issue: boolean): void {
    if (issue && !confirm('Issue this invoice? It gets its number and can no longer be edited (you can still cancel it).')) return;
    const body = {
      client_id: this.clientId,
      issue_date: this.issueDate,
      due_date: this.dueDate || null,
      gst_rate: this.gstRate,
      notes: this.notes || null,
      items: this.items().map((i) => ({ description: i.description, sac: i.sac || null, qty: +i.qty, rate: +i.rate })),
      issue,
    };
    this.busy.set(true);
    this.errors.set({});
    this.message.set('');
    this.api.saveInvoice(body, this.id() ? +this.id()! : undefined).subscribe({
      next: (inv) => {
        this.toast.show(issue ? `Invoice ${inv.invoice_no} issued.` : 'Draft saved.');
        this.router.navigate(['/invoices', inv.id]);
      },
      error: (e) => {
        this.busy.set(false);
        const r = readErrors(e);
        this.errors.set(r.fields);
        this.message.set(r.message || Object.values(r.fields)[0] || '');
      },
    });
  }

  dueHint(): string {
    const d = new Date(this.issueDate);
    d.setDate(d.getDate() + this.termsDays());
    return d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
  }

  err(k: string): string {
    return this.errors()[k] ?? '';
  }
}
