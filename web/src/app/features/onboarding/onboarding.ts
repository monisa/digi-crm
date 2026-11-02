import { HttpClient } from '@angular/common/http';
import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { Observable, of, switchMap } from 'rxjs';
import { API_BASE } from '../../core/api.config';
import { AuthService } from '../../core/auth/auth.service';
import { readErrors } from '../../core/http-errors';

export const INDIAN_STATES = [
  'Andaman and Nicobar Islands', 'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chandigarh', 'Chhattisgarh',
  'Dadra and Nagar Haveli and Daman and Diu', 'Delhi', 'Goa', 'Gujarat', 'Haryana', 'Himachal Pradesh', 'Jammu and Kashmir',
  'Jharkhand', 'Karnataka', 'Kerala', 'Ladakh', 'Lakshadweep', 'Madhya Pradesh', 'Maharashtra', 'Manipur', 'Meghalaya',
  'Mizoram', 'Nagaland', 'Odisha', 'Puducherry', 'Punjab', 'Rajasthan', 'Sikkim', 'Tamil Nadu', 'Telangana', 'Tripura',
  'Uttar Pradesh', 'Uttarakhand', 'West Bengal', 'Outside India',
];

interface Options { platforms: string[]; goals: string[]; industries: string[]; states: string[] }

/** Which step each field is on, so server errors open the right step. */
const STEP_OF: Record<string, number> = {
  full_name: 0, business_name: 0, email: 0, phone: 0, website: 0, industry: 0, address: 0, city: 0, state: 0, gstin: 0,
  social_platforms: 1, facebook_url: 1, facebook_admin_access: 1, instagram_handle: 1, instagram_email: 1, instagram_password: 1,
  linkedin_url: 1, linkedin_admin_access: 1, twitter_handle: 1, twitter_email: 1, twitter_password: 1, youtube_url: 1, youtube_access: 1, other_platforms: 1,
  website_cms: 2, website_admin_url: 2, website_username: 2, website_password: 2, hosting_provider: 2, domain_provider: 2, website_notes: 2,
  google_analytics: 2, search_console: 2, google_ads: 2, google_business_url: 2,
  brand_notes: 3, logo: 3, brand_files: 3,
  goals: 4, goals_other: 4, target_audience: 4, challenges: 4, competitors: 4, notes: 4,
};

/**
 * "Digital Marketing Client Onboarding Form", in three places:
 * - self:   a new client's first login (/onboarding)
 * - public: a shared link, no login needed (/form/:token)
 * - staff:  the team filling it in for a client (/clients/:id/onboarding)
 */
export type OnboardingMode = 'self' | 'public' | 'staff';

@Component({
  selector: 'app-onboarding',
  imports: [FormsModule],
  templateUrl: './onboarding.html',
  styleUrl: './onboarding.scss',
})
export class Onboarding {
  protected readonly auth = inject(AuthService);
  private http = inject(HttpClient);
  private router = inject(Router);
  private route = inject(ActivatedRoute);

  protected readonly mode: OnboardingMode = this.route.snapshot.data['mode'] ?? 'self';
  private readonly token = this.route.snapshot.paramMap.get('token') ?? '';
  protected readonly clientId = this.route.snapshot.paramMap.get('id') ?? '';
  /** The answer was sent (public link): show the thank-you screen. */
  protected readonly done = signal('');
  protected readonly failed = signal('');
  /** Staff: which logins are already stored (never shown here). */
  protected readonly loginsOnFile = signal<string[]>([]);
  /** Hidden from people; bots fill it in. */
  protected faxNumber = '';

  protected readonly steps = ['About you', 'Social media', 'Website & Google', 'Brand assets', 'Goals'];
  protected readonly step = signal(0);
  protected readonly opts = signal<Options | null>(null);
  protected readonly busy = signal(false);
  protected readonly message = signal('');
  protected readonly errors = signal<Record<string, string>>({});
  protected readonly show = signal<Record<string, boolean>>({});

  protected f: Record<string, string> = {
    full_name: '', business_name: '', email: '', phone: '', website: '', industry: '', address: '', city: '', state: 'Kerala', gstin: '',
    facebook_url: '', facebook_admin_access: '', instagram_handle: '', instagram_email: '', instagram_password: '',
    linkedin_url: '', linkedin_admin_access: '', twitter_handle: '', twitter_email: '', twitter_password: '',
    youtube_url: '', youtube_access: '', other_platforms: '',
    website_cms: '', website_admin_url: '', website_username: '', website_password: '', hosting_provider: '', domain_provider: '', website_notes: '',
    google_analytics: '', search_console: '', google_ads: '', google_business_url: '',
    brand_notes: '', goals_other: '', target_audience: '', challenges: '', competitors: '', notes: '',
  };
  protected readonly platforms = signal<string[]>([]);
  protected readonly goals = signal<string[]>([]);
  protected readonly logo = signal<File | null>(null);
  protected readonly logoPreview = signal<string>('');
  protected readonly brandFiles = signal<File[]>([]);
  protected readonly progress = computed(() => ((this.step() + 1) / this.steps.length) * 100);

  constructor() {
    this.http
      .get<{ options: Options; prefill: Record<string, string | string[] | null>; logins_on_file?: string[] }>(this.url())
      .subscribe({
        next: (r) => {
          for (const [k, v] of Object.entries(r.prefill)) {
            if (k === 'social_platforms' && Array.isArray(v)) this.platforms.set(v);
            else if (k === 'goals' && Array.isArray(v)) this.goals.set(v);
            else if (typeof v === 'string' && v && k in this.f) this.f[k] = v;
          }
          this.loginsOnFile.set(r.logins_on_file ?? []);
          this.opts.set(r.options);
        },
        error: (e) => this.failed.set(readErrors(e).message || 'This form could not be opened.'),
      });
  }

  private url(): string {
    if (this.mode === 'public') return `${API_BASE}/public/onboarding/${encodeURIComponent(this.token)}`;
    if (this.mode === 'staff') return `${API_BASE}/clients/${this.clientId}/onboarding`;
    return `${API_BASE}/onboarding`;
  }

  cancel(): void {
    this.router.navigate(['/clients', this.clientId]);
  }

  onFile(platform: string): boolean {
    return this.loginsOnFile().includes(platform);
  }

  has(p: string): boolean {
    return this.platforms().includes(p);
  }

  toggle(list: 'platforms' | 'goals', v: string): void {
    this[list].update((l) => (l.includes(v) ? l.filter((x) => x !== v) : [...l, v]));
  }

  reveal(k: string): void {
    this.show.update((s) => ({ ...s, [k]: !s[k] }));
  }

  pickLogo(e: Event): void {
    const file = (e.target as HTMLInputElement).files?.[0] ?? null;
    this.logo.set(file);
    if (this.logoPreview()) URL.revokeObjectURL(this.logoPreview());
    this.logoPreview.set(file && file.type.startsWith('image/') && file.type !== 'image/svg+xml' ? URL.createObjectURL(file) : '');
  }

  pickFiles(e: Event): void {
    const files = Array.from((e.target as HTMLInputElement).files ?? []);
    this.brandFiles.update((l) => [...l, ...files].slice(0, 10));
    (e.target as HTMLInputElement).value = '';
  }

  removeFile(i: number): void {
    this.brandFiles.update((l) => l.filter((_, n) => n !== i));
  }

  err(k: string): string {
    return this.errors()[k] ?? '';
  }

  /** Required questions on the current step. */
  private check(step: number): boolean {
    const e: Record<string, string> = {};
    const staff = this.mode === 'staff';
    const need = (k: string, msg = 'This question is required.') => {
      if (!(this.f[k] ?? '').trim() && (!staff || k === 'business_name')) e[k] = msg;
    };
    if (step === 0) {
      need('full_name'); need('business_name'); need('email'); need('phone');
      if (this.f['email'] && !/^\S+@\S+\.\S+$/.test(this.f['email'])) e['email'] = 'Enter a valid email address.';
      if (this.f['gstin'] && !/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][0-9A-Z]Z[0-9A-Z]$/.test(this.f['gstin'].toUpperCase())) e['gstin'] = 'Enter a valid 15-character GSTIN, or leave it empty.';
    }
    if (step === 2) { need('google_analytics', 'Please choose Yes or No.'); need('search_console', 'Please choose Yes or No.'); need('google_ads', 'Please choose Yes or No.'); }
    if (step === 4) {
      if (!this.goals().length && !staff) e['goals'] = 'Choose at least one goal.';
      need('target_audience');
    }
    this.errors.set(e);
    return !Object.keys(e).length;
  }

  next(): void {
    if (!this.check(this.step())) return;
    this.step.update((s) => Math.min(s + 1, this.steps.length - 1));
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  back(): void {
    this.errors.set({});
    this.step.update((s) => Math.max(s - 1, 0));
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  submit(): void {
    if (!this.check(4)) return;
    const fd = new FormData();
    for (const [k, v] of Object.entries(this.f)) {
      const val = k === 'gstin' ? v.trim().toUpperCase() : v.trim();
      // Skip platform fields the client did not tick.
      const platform = k.split('_')[0];
      const map: Record<string, string> = { facebook: 'Facebook', instagram: 'Instagram', linkedin: 'LinkedIn', twitter: 'Twitter', youtube: 'YouTube' };
      if (map[platform] && !this.has(map[platform]!)) continue;
      if (val) fd.append(k, val);
    }
    this.platforms().forEach((p) => fd.append('social_platforms[]', p));
    this.goals().forEach((g) => fd.append('goals[]', g));
    if (this.faxNumber) fd.append('fax_number', this.faxNumber);
    if (this.logo()) fd.append('logo', this.logo()!);
    this.brandFiles().forEach((file) => fd.append('brand_files[]', file));

    this.busy.set(true);
    this.message.set('');
    this.http
      .post<{ message: string }>(this.url(), fd)
      .pipe(switchMap((r): Observable<unknown> => (this.mode === 'self' ? this.auth.refresh() : of(r))))
      .subscribe({
        next: (r) => {
          if (this.mode === 'self') this.router.navigateByUrl('/dashboard');
          else if (this.mode === 'staff') this.router.navigate(['/clients', this.clientId], { queryParams: { saved: 1 } });
          else {
            this.done.set((r as { message?: string })?.message || 'Thank you!');
            window.scrollTo({ top: 0, behavior: 'smooth' });
          }
        },
        error: (e) => {
          this.busy.set(false);
          const r = readErrors(e);
          const fields: Record<string, string> = {};
          for (const [k, v] of Object.entries(r.fields)) fields[k.replace(/\.\d+$/, '')] = v;
          this.errors.set(fields);
          const first = Object.keys(fields).map((k) => STEP_OF[k] ?? 4).sort()[0];
          if (first !== undefined) this.step.set(first);
          this.message.set(r.message || 'Please check the highlighted answers.');
        },
      });
  }

  size(n: number): string {
    return n > 1048576 ? `${(n / 1048576).toFixed(1)} MB` : `${Math.ceil(n / 1024)} KB`;
  }
}
