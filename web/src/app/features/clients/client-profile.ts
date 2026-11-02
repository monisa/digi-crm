import { Component, inject, input, signal } from '@angular/core';
import { KeyValuePipe, TitleCasePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth/auth.service';
import { BillingService, ClientDetail, InvoiceRow, STATUS_LABEL } from '../../core/billing.service';
import { readErrors } from '../../core/http-errors';
import { initials, money } from '../../shared/format';
import { Toast } from '../../shared/toast.service';
import { ClientForm } from './client-form';
import { Sheet } from '../../shared/sheet';
import { ShareLink, formUrl } from '../../shared/share-link';

type Tab = 'overview' | 'invoices';

@Component({
  selector: 'app-client-profile',
  imports: [FormsModule, RouterLink, ClientForm, KeyValuePipe, TitleCasePipe, Sheet, ShareLink],
  templateUrl: './client-profile.html',
  styleUrl: './client-profile.scss',
})
export class ClientProfile {
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
  protected readonly sharing = signal(false);
  protected readonly link = signal('');

  ngOnInit(): void {
    this.load();
    if (this.route.snapshot.queryParamMap.get('saved')) this.toast.show('Onboarding answers saved.');
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

  open(t: Tab): void {
    this.tab.set(t);
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

  reveal(): void {
    if (!confirm('Show this client\'s shared passwords? Your name and the time are recorded.')) return;
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
