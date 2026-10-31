import { HttpClient } from '@angular/common/http';
import { Component, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { switchMap } from 'rxjs';
import { API_BASE } from '../../core/api.config';
import { AuthService } from '../../core/auth/auth.service';
import { FieldErrors, readErrors } from '../../core/http-errors';

export const INDIAN_STATES = [
  'Andaman and Nicobar Islands', 'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chandigarh', 'Chhattisgarh',
  'Dadra and Nagar Haveli and Daman and Diu', 'Delhi', 'Goa', 'Gujarat', 'Haryana', 'Himachal Pradesh', 'Jammu and Kashmir',
  'Jharkhand', 'Karnataka', 'Kerala', 'Ladakh', 'Lakshadweep', 'Madhya Pradesh', 'Maharashtra', 'Manipur', 'Meghalaya',
  'Mizoram', 'Nagaland', 'Odisha', 'Puducherry', 'Punjab', 'Rajasthan', 'Sikkim', 'Tamil Nadu', 'Telangana', 'Tripura',
  'Uttar Pradesh', 'Uttarakhand', 'West Bengal', 'Outside India',
];

/**
 * First-login form for new clients. Placeholder questions until the fields
 * from the DigiFox Google Form are added in Phase 5.
 */
@Component({
  selector: 'app-onboarding',
  imports: [ReactiveFormsModule],
  templateUrl: './onboarding.html',
  styleUrl: './onboarding.scss',
})
export class Onboarding {
  protected readonly auth = inject(AuthService);
  private http = inject(HttpClient);
  private router = inject(Router);

  protected readonly states = INDIAN_STATES;
  protected readonly industries = ['Retail', 'Real Estate', 'Healthcare', 'Education', 'Restaurant & Food', 'Jewellery', 'Travel', 'Manufacturing', 'IT & Services', 'Other'];
  protected readonly serviceOptions = ['Meta Ads', 'Google Ads', 'SEO', 'Social Media Management', 'Website Development', 'Branding & Design', 'Content & Video'];

  protected readonly form = inject(NonNullableFormBuilder).group({
    business_name: [this.auth.user()?.company_name ?? ''],
    industry: [''],
    website: [''],
    city: [''],
    state: ['Kerala'],
    gstin: [''],
    notes: [''],
  });
  protected readonly services = signal<string[]>([]);
  protected readonly busy = signal(false);
  protected readonly message = signal('');
  protected readonly errors = signal<FieldErrors>({});

  toggle(s: string): void {
    this.services.update((list) => (list.includes(s) ? list.filter((x) => x !== s) : [...list, s]));
  }

  submit(): void {
    const v = this.form.getRawValue();
    const local: Record<string, string> = {};
    if (!v.business_name.trim()) local['business_name'] = 'Please enter your business name.';
    if (!v.industry) local['industry'] = 'Please choose your industry.';
    if (!v.city.trim()) local['city'] = 'Please enter your city.';
    if (!this.services().length) local['services'] = 'Choose at least one service.';
    this.errors.set(local);
    this.message.set('');
    if (Object.keys(local).length) return;

    const payload = {
      ...v,
      gstin: v.gstin.trim().toUpperCase() || null,
      website: v.website.trim() || null,
      services: this.services(),
    };
    this.busy.set(true);
    this.http
      .post(`${API_BASE}/onboarding`, payload)
      .pipe(switchMap(() => this.auth.refresh()))
      .subscribe({
        next: () => this.router.navigateByUrl('/dashboard'),
        error: (err) => {
          const e = readErrors(err);
          this.errors.set(e.fields);
          this.message.set(e.message);
          this.busy.set(false);
        },
      });
  }
}
