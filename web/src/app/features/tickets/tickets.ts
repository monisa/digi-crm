import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth/auth.service';
import { BillingService, Client } from '../../core/billing.service';
import { readErrors } from '../../core/http-errors';
import { PortalService, TICKET_STATUS, Ticket } from '../../core/portal.service';
import { Sheet } from '../../shared/sheet';
import { Toast } from '../../shared/toast.service';

@Component({
  selector: 'app-tickets',
  imports: [FormsModule, RouterLink, Sheet],
  templateUrl: './tickets.html',
  styleUrl: './tickets.scss',
})
export class Tickets {
  private api = inject(PortalService);
  private router = inject(Router);
  private toast = inject(Toast);
  protected readonly auth = inject(AuthService);
  protected readonly label = TICKET_STATUS;
  protected readonly isClient = computed(() => this.auth.user()?.role === 'client');

  protected status = 'active';
  protected assigned = '';
  protected readonly list = signal<Ticket[]>([]);
  protected readonly counts = signal({ active: 0, needs_reply: 0 });
  protected readonly categories = signal<string[]>([]);
  protected readonly loading = signal(true);

  protected readonly creating = signal(false);
  protected readonly clients = signal<Client[]>([]);
  protected nt: { client_id: number | null; subject: string; category: string; priority: string; body: string } = { client_id: null, subject: '', category: '', priority: 'medium', body: '' };
  protected readonly ntFile = signal<File | null>(null);
  protected readonly errors = signal<Record<string, string>>({});
  protected readonly busy = signal(false);

  constructor() {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.api.tickets({ status: this.status, assigned: this.assigned }).subscribe((r) => {
      this.list.set(r.data);
      this.counts.set(r.counts);
      this.categories.set(r.categories);
      this.loading.set(false);
    });
  }

  startNew(): void {
    this.nt = { client_id: null, subject: '', category: '', priority: 'medium', body: '' };
    this.ntFile.set(null);
    this.errors.set({});
    if (!this.isClient()) this.billing.clients({ status: 'active' }).subscribe((r) => this.clients.set(r.data));
    this.creating.set(true);
  }

  private billing = inject(BillingService);

  pick(e: Event): void {
    this.ntFile.set((e.target as HTMLInputElement).files?.[0] ?? null);
  }

  create(): void {
    const fd = new FormData();
    for (const [k, v] of Object.entries(this.nt)) if (v !== null && v !== '' && !(this.isClient() && k === 'client_id')) fd.append(k, String(v));
    if (this.ntFile()) fd.append('attachment', this.ntFile()!);
    this.busy.set(true);
    this.errors.set({});
    this.api.createTicket(fd).subscribe({
      next: (t) => {
        this.busy.set(false);
        this.creating.set(false);
        this.toast.show(this.isClient() ? 'Request sent. We will reply here soon.' : 'Ticket created.');
        this.router.navigate(['/tickets', t.id]);
      },
      error: (e) => {
        this.busy.set(false);
        const r = readErrors(e);
        this.errors.set(r.fields);
        if (r.message) this.toast.show(r.message);
      },
    });
  }

  ago(iso: string): string {
    const m = Math.floor((Date.now() - new Date(iso).getTime()) / 60000);
    if (m < 60) return `${Math.max(1, m)}m ago`;
    if (m < 1440) return `${Math.floor(m / 60)}h ago`;
    return `${Math.floor(m / 1440)}d ago`;
  }

  err(k: string): string {
    return this.errors()[k] ?? '';
  }
}
