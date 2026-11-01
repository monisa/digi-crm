import { Component, ElementRef, computed, inject, input, signal, viewChild } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth/auth.service';
import { readErrors } from '../../core/http-errors';
import { LeadsService } from '../../core/leads.service';
import { PortalService, TICKET_STATUS, Ticket, TicketStatus } from '../../core/portal.service';
import { initials } from '../../shared/format';
import { Toast } from '../../shared/toast.service';

/** /tickets/:id: the conversation, like a chat. */
@Component({
  selector: 'app-ticket-thread',
  imports: [FormsModule, RouterLink],
  templateUrl: './ticket-thread.html',
  styleUrl: './ticket-thread.scss',
})
export class TicketThread {
  id = input.required<string>();
  private api = inject(PortalService);
  private toast = inject(Toast);
  protected readonly auth = inject(AuthService);
  protected readonly label = TICKET_STATUS;
  protected readonly statuses = Object.entries(TICKET_STATUS);
  protected readonly initials = initials;
  protected readonly isClient = computed(() => this.auth.user()?.role === 'client');
  protected readonly canManage = computed(() => !this.isClient() && this.auth.can('tickets.edit'));

  protected readonly t = signal<Ticket | null>(null);
  protected readonly error = signal('');
  protected readonly staff = signal<{ id: number; name: string }[]>([]);
  protected body = '';
  protected internal = false;
  protected readonly file = signal<File | null>(null);
  protected readonly busy = signal(false);
  private end = viewChild<ElementRef<HTMLElement>>('end');

  constructor() {
    if (!this.isClient()) inject(LeadsService).options().subscribe((o) => this.staff.set(o.assignees));
  }

  ngOnInit(): void {
    this.api.ticket(+this.id()).subscribe({
      next: (t) => this.set(t),
      error: (e) => this.error.set(readErrors(e).message || 'Ticket not found.'),
    });
  }

  private set(t: Ticket): void {
    this.t.set(t);
    setTimeout(() => this.end()?.nativeElement.scrollIntoView({ behavior: 'smooth', block: 'end' }), 50);
  }

  pick(e: Event): void {
    this.file.set((e.target as HTMLInputElement).files?.[0] ?? null);
  }

  send(): void {
    if (!this.body.trim()) return;
    const fd = new FormData();
    fd.append('body', this.body.trim());
    if (this.internal) fd.append('internal', '1');
    if (this.file()) fd.append('attachment', this.file()!);
    this.busy.set(true);
    this.api.reply(this.t()!.id, fd).subscribe({
      next: (t) => {
        this.busy.set(false);
        this.body = '';
        this.internal = false;
        this.file.set(null);
        this.set(t);
      },
      error: (e) => {
        this.busy.set(false);
        this.toast.show(Object.values(readErrors(e).fields)[0] || readErrors(e).message || 'Could not send.');
      },
    });
  }

  update(data: Record<string, unknown>): void {
    this.api.updateTicket(this.t()!.id, data).subscribe({
      next: (t) => {
        this.set(t);
        this.toast.show('Ticket updated.');
      },
      error: (e) => this.toast.show(readErrors(e).message || 'Could not update.'),
    });
  }

  close(): void {
    if (confirm('Close this request? You can reopen it by replying.')) this.update({ status: 'closed' as TicketStatus });
  }

  download(a: { id: number; name: string }): void {
    this.api.downloadAttachment(a).subscribe({ error: () => this.toast.show('Could not download the file.') });
  }

  when(iso: string): string {
    return new Date(iso).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' });
  }
}
