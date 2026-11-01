import { Component, computed, inject, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { AuthService } from '../../core/auth/auth.service';
import { readErrors } from '../../core/http-errors';
import { Lead, LeadOptions, LeadStatus, LeadsService, waNumber } from '../../core/leads.service';
import { Sheet } from '../../shared/sheet';
import { Toast } from '../../shared/toast.service';

/** Everything about one lead: contact buttons, stage, sheet fields, logging and history. */
@Component({
  selector: 'app-lead-sheet',
  imports: [FormsModule, Sheet],
  templateUrl: './lead-sheet.html',
  styleUrl: './lead-sheet.scss',
})
export class LeadSheet {
  leadId = input.required<number>();
  options = input.required<LeadOptions>();
  closed = output<void>();
  changed = output<Lead | null>();

  private api = inject(LeadsService);
  private toast = inject(Toast);
  private router = inject(Router);
  protected readonly auth = inject(AuthService);

  protected readonly lead = signal<Lead | null>(null);
  protected f: Record<string, string | number | null> = {};
  protected readonly saving = signal(false);
  protected readonly errors = signal<Record<string, string>>({});

  // activity form
  protected logType: 'call' | 'whatsapp' | 'meeting' | 'note' = 'call';
  protected logOutcome = '';
  protected logNote = '';
  protected logFollowup = '';
  protected readonly logging = signal(false);

  protected readonly stages = computed(() => Object.entries(this.options().statuses) as [LeadStatus, string][]);
  protected readonly canEdit = this.auth.can('leads.edit');
  protected readonly canReassign = computed(() => this.auth.user()?.leads_scope === 'all');
  protected readonly wa = waNumber;

  ngOnInit(): void {
    this.api.get(this.leadId()).subscribe((l) => this.set(l));
  }

  private set(l: Lead): void {
    this.lead.set(l);
    this.f = {
      full_name: l.full_name, phone: l.phone, email: l.email, city: l.city, call_status: l.call_status, package: l.package,
      requirement: l.requirement, followup_status: l.followup_status, notes: l.notes,
      next_followup_at: l.next_followup_at ? this.localInput(l.next_followup_at) : '',
      assigned_to: l.assigned_to?.id ?? 0, lost_reason: l.lost_reason,
    };
  }

  localInput(iso: string): string {
    const d = new Date(iso);
    const p = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`;
  }

  setStage(s: LeadStatus): void {
    const l = this.lead();
    if (!l || l.status === s || !this.canEdit) return;
    let lost_reason: string | null = null;
    if (s === 'lost') {
      lost_reason = prompt(`Why is this lead not interested?\n(${this.options().lost_reasons.join(', ')})`, this.options().lost_reasons[0] ?? '');
      if (lost_reason === null) return;
    }
    this.api.update(l.id, { status: s, ...(s === 'lost' ? { lost_reason } : {}) }).subscribe({
      next: (u) => {
        this.set(u);
        this.changed.emit(u);
      },
      error: (e) => this.toast.show(readErrors(e).message || 'Could not update.'),
    });
  }

  save(): void {
    const l = this.lead()!;
    const data: Record<string, unknown> = { ...this.f };
    for (const k of Object.keys(data)) if (data[k] === '') data[k] = null;
    data['assigned_to'] = +(this.f['assigned_to'] ?? 0) || null;
    if (!this.canReassign()) delete data['assigned_to'];
    this.saving.set(true);
    this.errors.set({});
    this.api.update(l.id, data).subscribe({
      next: (u) => {
        this.saving.set(false);
        this.set(u);
        this.changed.emit(u);
        this.toast.show('Lead saved.');
      },
      error: (e) => {
        this.saving.set(false);
        const r = readErrors(e);
        this.errors.set(r.fields);
        if (r.message) this.toast.show(r.message);
      },
    });
  }

  quick(type: 'call' | 'whatsapp'): void {
    this.logType = type;
    this.logOutcome = type === 'call' ? '' : 'Message sent';
  }

  log(): void {
    const l = this.lead()!;
    this.logging.set(true);
    this.api
      .log(l.id, {
        type: this.logType,
        outcome: this.logOutcome || null,
        note: this.logNote || null,
        ...(this.logFollowup ? { next_followup_at: this.logFollowup, followup_status: 'Follow-up scheduled' } : {}),
      })
      .subscribe({
        next: (u) => {
          this.logging.set(false);
          this.logNote = '';
          this.logOutcome = '';
          this.logFollowup = '';
          this.set(u);
          this.changed.emit(u);
          this.toast.show('Logged.');
        },
        error: (e) => {
          this.logging.set(false);
          this.toast.show(readErrors(e).fields['note'] || readErrors(e).message || 'Could not log.');
        },
      });
  }

  convert(): void {
    const l = this.lead()!;
    const name = prompt('Company / business name for the new client:', l.full_name);
    if (!name) return;
    this.api.convert(l.id, name).subscribe({
      next: (r) => {
        this.set(r.lead);
        this.changed.emit(r.lead);
        this.toast.show(`${name} is now a client. 🎉`);
      },
      error: (e) => this.toast.show(readErrors(e).message || 'Could not convert.'),
    });
  }

  remove(): void {
    const l = this.lead()!;
    if (!confirm(`Delete the lead "${l.full_name}"? This cannot be undone.`)) return;
    this.api.remove(l.id).subscribe(() => {
      this.toast.show('Lead deleted.');
      this.changed.emit(null);
      this.closed.emit();
    });
  }

  when(iso: string | null): string {
    if (!iso) return '';
    return new Date(iso).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' });
  }

  icon(type: string): string {
    return ({ call: '📞', whatsapp: '💬', email: '✉️', meeting: '🤝', note: '📝', status: '🔁', assignment: '👤', system: '⚡' } as Record<string, string>)[type] ?? '•';
  }

  customEntries(): [string, string][] {
    return Object.entries(this.lead()?.custom_fields ?? {});
  }

  openClient(): void {
    this.router.navigateByUrl('/clients');
  }
}
