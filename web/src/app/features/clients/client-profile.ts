import { Component, computed, inject, input, signal } from '@angular/core';
import { KeyValuePipe, TitleCasePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth/auth.service';
import { BillingService, ClientDetail, InvoiceRow, STATUS_LABEL } from '../../core/billing.service';
import { readErrors } from '../../core/http-errors';
import { initials, money } from '../../shared/format';
import { Toast } from '../../shared/toast.service';
import { ClientForm } from './client-form';
import { Sheet } from '../../shared/sheet';
import { ShareLink, formUrl } from '../../shared/share-link';
import { Dialog } from '../../shared/dialog';
import { Campaign, PortalService, TICKET_STATUS, Ticket } from '../../core/portal.service';
import { CampaignSheet } from '../campaigns/campaign-sheet';

type Tab = 'overview' | 'campaigns' | 'tickets' | 'invoices';
const TABS: Tab[] = ['overview', 'campaigns', 'tickets', 'invoices'];

@Component({
  selector: 'app-client-profile',
  imports: [FormsModule, RouterLink, ClientForm, KeyValuePipe, TitleCasePipe, Sheet, ShareLink, CampaignSheet],
  templateUrl: './client-profile.html',
  styleUrl: './client-profile.scss',
})
export class ClientProfile {
  protected readonly dialog = inject(Dialog);
  id = input.required<string>();
  private api = inject(BillingService);
  private toast = inject(Toast);
  protected readonly auth = inject(AuthService);
  protected readonly money = money;
  protected readonly initials = initials;
  protected readonly label = STATUS_LABEL;

  protected readonly c = signal<ClientDetail | null>(null);
  protected readonly error = signal('');
  protected readonly tab = signal<Tab>('overview');
  protected readonly invoices = signal<InvoiceRow[] | null>(null);
  protected readonly editing = signal(false);
  protected readonly inviting = signal(false);
  protected inviteName = '';
  protected inviteEmail = '';
  protected readonly inviteError = signal('');

  private route = inject(ActivatedRoute);
  private portal = inject(PortalService);
  protected readonly Math = Math;
  protected readonly ticketLabel = TICKET_STATUS;

  // Campaigns tab
  protected readonly campaigns = signal<Campaign[] | null>(null);
  protected readonly platforms = signal<Record<string, string>>({});
  protected readonly campaignOpen = signal<number | null>(null);
  protected readonly campStats = computed(() => {
    const l = this.campaigns() ?? [];
    const leads = l.reduce((n, k) => n + (k.results.leads ?? 0), 0);
    const spend = l.reduce((n, k) => n + (k.results.spend ?? 0), 0);
    return { active: l.filter((k) => k.status === 'active').length, leads, spend, cpl: leads ? spend / leads : null };
  });

  // Tickets tab
  protected readonly tickets = signal<Ticket[] | null>(null);
  protected readonly ticketFilter = signal<'open' | 'all'>('open');
  protected readonly openTickets = computed(() => (this.tickets() ?? []).filter((t) => !['resolved', 'closed'].includes(t.status)).length);
  protected readonly shownTickets = computed(() => {
    const l = this.tickets() ?? [];
    return this.ticketFilter() === 'open' ? l.filter((t) => !['resolved', 'closed'].includes(t.status)) : l;
  });
  protected readonly sharing = signal(false);
  protected readonly link = signal('');

  ngOnInit(): void {
    this.load();
    // Counts on the tabs straight away; the lists are small per client.
    if (this.auth.can('campaigns.view')) this.loadCampaigns();
    if (this.auth.can('tickets.view')) this.portal.tickets({ client_id: +this.id(), status: 'all' }).subscribe((r) => this.tickets.set(r.data));
    const t = this.route.snapshot.queryParamMap.get('tab') as Tab | null;
    if (t && TABS.includes(t)) this.open(t);
    if (this.route.snapshot.queryParamMap.get('saved')) this.toast.show('Onboarding answers saved.');
  }

  private router = inject(Router);

  /** Delete a client added by mistake (not possible once invoices are issued). */
  async removeClient(): Promise<void> {
    const c = this.c()!;
    const ok = await this.dialog.confirm({
      title: `Delete ${c.company_name}?`,
      message: 'Their portal logins, draft invoices, campaigns, tickets, files and onboarding answers are deleted permanently. Clients with issued invoices cannot be deleted (GST records); set them to Past client instead.',
      confirmText: 'Delete client', danger: true, typeToConfirm: 'DELETE',
    });
    if (!ok) return;
    this.api.deleteClient(c.id).subscribe({
      next: () => {
        this.toast.show(`${c.company_name} deleted.`);
        this.router.navigateByUrl('/clients');
      },
      error: (e) => this.toast.show(readErrors(e).message || 'Could not delete the client.'),
    });
  }

  /** A form link just for this client; it fills in their record. */
  sendLink(): void {
    this.link.set('');
    this.sharing.set(true);
    this.api.clientOnboardingLink(this.c()!.id).subscribe((r) => this.link.set(formUrl(r.token)));
  }

  setStatus(status: 'active' | 'inactive', then?: () => void): void {
    this.api.saveClient({ status }, this.c()!.id).subscribe(() => {
      this.toast.show(status === 'active' ? 'Client approved.' : 'Marked as not a client.');
      this.load();
      then?.();
    });
  }

  approveAndInvite(): void {
    this.setStatus('active', () => this.startInvite());
  }

  load(): void {
    this.api.client(+this.id()).subscribe({
      next: (c) => this.c.set(c),
      error: (e) => this.error.set(readErrors(e).message),
    });
  }

  loadCampaigns(): void {
    this.portal.campaigns({ client_id: +this.id() }).subscribe((r) => {
      this.campaigns.set(r.data);
      this.platforms.set(r.platforms);
    });
  }

  ago(iso: string): string {
    const m = Math.round((Date.now() - new Date(iso).getTime()) / 60000);
    if (m < 60) return `${Math.max(1, m)}m ago`;
    if (m < 1440) return `${Math.round(m / 60)}h ago`;
    return this.date(iso);
  }

  open(t: Tab): void {
    this.tab.set(t);
    // Remember the tab, so "Back" from a ticket or invoice returns here.
    this.router.navigate([], { queryParams: { tab: t === 'overview' ? null : t }, queryParamsHandling: 'merge', replaceUrl: true });
    if (t === 'invoices' && !this.invoices()) this.api.invoices({ client_id: +this.id() }).subscribe((r) => this.invoices.set(r.data));
  }

  startInvite(): void {
    const c = this.c()!;
    this.inviteName = c.contact_name ?? '';
    this.inviteEmail = c.email ?? '';
    this.inviteError.set('');
    this.inviting.set(true);
  }

  invite(): void {
    this.api.invite(this.c()!.id, this.inviteName, this.inviteEmail).subscribe({
      next: () => {
        this.inviting.set(false);
        this.toast.show(`Invitation emailed to ${this.inviteEmail}.`);
        this.load();
      },
      error: (e) => {
        const r = readErrors(e);
        this.inviteError.set(Object.values(r.fields)[0] || r.message);
      },
    });
  }

  saved(): void {
    this.editing.set(false);
    this.toast.show('Client saved.');
    this.load();
  }

  /** Onboarding answers in the form's order and wording. */
  answers(): { title: string; rows: [string, string][] }[] {
    const o = (this.c()?.onboarding ?? {}) as Record<string, unknown>;
    const yn = (v: unknown) => (v === 'yes' ? 'Yes' : v === 'no' ? 'No' : '');
    const val = (k: string) => {
      const v = o[k];
      if (Array.isArray(v)) return v.join(', ');
      if (k.endsWith('_access') || ['google_analytics', 'search_console', 'google_ads'].includes(k)) return yn(v);
      return v == null ? '' : String(v);
    };
    const groups: [string, [string, string][]][] = [
      ['About', [['full_name', 'Full name'], ['email', 'Email'], ['phone', 'Phone'], ['website', 'Website'], ['industry', 'Industry']]],
      ['Social media', [['social_platforms', 'Platforms'], ['facebook_url', 'Facebook Page'], ['facebook_admin_access', 'Facebook admin access'],
        ['instagram_handle', 'Instagram'], ['linkedin_url', 'LinkedIn page'], ['linkedin_admin_access', 'LinkedIn admin access'],
        ['twitter_handle', 'Twitter / X'], ['youtube_url', 'YouTube channel'], ['youtube_access', 'YouTube access'], ['other_platforms', 'Other platforms']]],
      ['Website & Google', [['website_cms', 'CMS'], ['hosting_provider', 'Hosting'], ['domain_provider', 'Domain'], ['website_notes', 'Website notes'],
        ['google_analytics', 'Google Analytics'], ['search_console', 'Search Console'], ['google_ads', 'Google Ads account'], ['google_business_url', 'Google Business Profile']]],
      ['Brand', [['brand_notes', 'Style guide, colours, fonts']]],
      ['Goals', [['goals', 'Primary goals'], ['goals_other', 'Other goal'], ['target_audience', 'Target audience'], ['challenges', 'Challenges'],
        ['competitors', 'Competitors'], ['notes', 'Notes']]],
    ];
    return groups
      .map(([title, rows]) => ({ title, rows: rows.map(([k, l]) => [l, val(k)] as [string, string]).filter(([, v]) => v) }))
      .filter((g) => g.rows.length);
  }

  protected readonly creds = signal<{ credentials: Record<string, Record<string, string>>; views: { name: string; viewed_at: string }[] } | null>(null);

  async reveal(): Promise<void> {
    if (!(await this.dialog.confirm({ title: 'Show shared passwords?', message: 'Your name and the time are recorded.', confirmText: 'Show passwords' }))) return;
    this.api.revealCredentials(this.c()!.id).subscribe({
      next: (r) => {
        this.creds.set(r);
        setTimeout(() => this.creds.set(null), 120000); // hide again after 2 minutes
      },
      error: (e) => this.toast.show(readErrors(e).message || 'You do not have access to client logins.'),
    });
  }

  credEntries(): [string, Record<string, string>][] {
    return Object.entries(this.creds()?.credentials ?? {});
  }

  download(f: import('../../core/billing.service').ClientFile): void {
    this.api.downloadFile(f).subscribe({ error: () => this.toast.show('Could not download the file.') });
  }

  when(d: string): string {
    return new Date(d).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' });
  }

  place(c: ClientDetail): string {
    return [c.city, c.state, c.pincode].filter((x) => !!x).join(', ');
  }

  date(d: string | null): string {
    return d ? new Date(d).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';
  }
}
