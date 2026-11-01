import { Component, computed, inject, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { AuthService } from '../../core/auth/auth.service';
import { BillingService, Client } from '../../core/billing.service';
import { readErrors } from '../../core/http-errors';
import { Campaign, PortalService } from '../../core/portal.service';
import { count, money } from '../../shared/format';
import { Sheet } from '../../shared/sheet';
import { Toast } from '../../shared/toast.service';

/** Add / edit a campaign (staff) or view it with its reports (clients). */
@Component({
  selector: 'app-campaign-sheet',
  imports: [FormsModule, Sheet],
  templateUrl: './campaign-sheet.html',
  styleUrl: './campaign-sheet.scss',
})
export class CampaignSheet {
  campaignId = input<number | null>(null);
  clientId = input<number | null>(null);
  platforms = input.required<Record<string, string>>();
  closed = output<void>();
  changed = output<void>();

  private api = inject(PortalService);
  private toast = inject(Toast);
  protected readonly auth = inject(AuthService);
  protected readonly money = money;
  protected readonly count = count;
  protected readonly isClient = computed(() => this.auth.user()?.role === 'client');
  protected readonly canEdit = computed(() => !this.isClient() && this.auth.can(this.campaignId() ? 'campaigns.edit' : 'campaigns.create'));

  protected readonly c = signal<Campaign | null>(null);
  protected readonly clients = signal<Client[]>([]);
  protected f: Record<string, string | number | null> = { status: 'active', platform: 'meta' };
  protected readonly busy = signal(false);
  protected readonly errors = signal<Record<string, string>>({});
  protected readonly upFile = signal<File | null>(null);
  protected upTitle = '';
  protected readonly platformKeys = computed(() => Object.entries(this.platforms()));

  constructor() {
    if (!inject(AuthService).user()?.client_id) inject(BillingService).clients({ status: 'active' }).subscribe((r) => this.clients.set(r.data));
  }

  ngOnInit(): void {
    if (this.campaignId()) this.api.campaign(this.campaignId()!).subscribe((c) => this.set(c));
    else this.f['client_id'] = this.clientId();
  }

  private set(c: Campaign): void {
    this.c.set(c);
    this.f = {
      client_id: c.client?.id ?? null, name: c.name, platform: c.platform, objective: c.objective, budget: c.budget,
      start_date: c.start_date, end_date: c.end_date, status: c.status, summary: c.summary,
      spend: c.results.spend, reach: c.results.reach, impressions: c.results.impressions, clicks: c.results.clicks,
      leads: c.results.leads, conversions: c.results.conversions,
    };
  }

  save(): void {
    const data: Record<string, unknown> = {};
    for (const [k, v] of Object.entries(this.f)) data[k] = v === '' ? null : v;
    this.busy.set(true);
    this.errors.set({});
    this.api.saveCampaign(data, this.c()?.id).subscribe({
      next: (c) => {
        this.busy.set(false);
        this.set(c);
        this.changed.emit();
        this.toast.show('Campaign saved.');
      },
      error: (e) => {
        this.busy.set(false);
        const r = readErrors(e);
        this.errors.set(r.fields);
        if (r.message) this.toast.show(r.message);
      },
    });
  }

  pick(e: Event): void {
    const file = (e.target as HTMLInputElement).files?.[0] ?? null;
    this.upFile.set(file);
    if (file && !this.upTitle) this.upTitle = file.name.replace(/\.[^.]+$/, '');
  }

  upload(): void {
    this.busy.set(true);
    this.api.uploadReport(this.c()!.id, this.upFile()!, this.upTitle).subscribe({
      next: (c) => {
        this.busy.set(false);
        this.set(c);
        this.upFile.set(null);
        this.upTitle = '';
        this.toast.show('Report shared with the client.');
      },
      error: (e) => {
        this.busy.set(false);
        this.toast.show(readErrors(e).fields['file'] || readErrors(e).message || 'Upload failed.');
      },
    });
  }

  download(f: { id: number; name: string }): void {
    this.api.downloadReport(f).subscribe({ error: () => this.toast.show('Could not download the file.') });
  }

  removeFile(id: number): void {
    if (!confirm('Remove this file? The client will no longer see it.')) return;
    this.api.deleteReport(id).subscribe(() => this.api.campaign(this.c()!.id).subscribe((c) => this.set(c)));
  }

  remove(): void {
    if (!confirm('Delete this campaign and its files?')) return;
    this.api.deleteCampaign(this.c()!.id).subscribe(() => {
      this.toast.show('Campaign deleted.');
      this.changed.emit();
      this.closed.emit();
    });
  }

  err(k: string): string {
    return this.errors()[k] ?? '';
  }

  date(d: string | null): string {
    return d ? new Date(d).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : '';
  }
}
