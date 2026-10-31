import { Component, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { FieldErrors, readErrors } from '../../core/http-errors';
import { AuthService } from '../../core/auth/auth.service';
import { GoogleButton } from './google-button';
import { PasswordField } from './password-field';

/** Client self-registration. Employee accounts are created by the admin. */
@Component({
  selector: 'app-register',
  imports: [ReactiveFormsModule, RouterLink, PasswordField, GoogleButton],
  template: `
    <h2 class="auth-h">Create your account</h2>
    <p class="auth-sub">For DigiFox Pro clients. Team members get their login from the admin.</p>
    @if (message()) { <div class="alert error" role="alert">{{ message() }}</div> }

    <form [formGroup]="form" (ngSubmit)="submit()" novalidate>
      <div class="row-2">
        <div class="field" [class.invalid]="errors().name">
          <label for="name">Full name <span class="req">*</span></label>
          <input id="name" formControlName="name" autocomplete="name" />
          @if (errors().name) { <div class="err">{{ errors().name }}</div> }
        </div>
        <div class="field" [class.invalid]="errors().company_name">
          <label for="company">Company name <span class="req">*</span></label>
          <input id="company" formControlName="company_name" autocomplete="organization" />
          @if (errors().company_name) { <div class="err">{{ errors().company_name }}</div> }
        </div>
      </div>
      <div class="row-2">
        <div class="field" [class.invalid]="errors().email">
          <label for="email">Email <span class="req">*</span></label>
          <input id="email" type="email" formControlName="email" autocomplete="email" />
          @if (errors().email) { <div class="err">{{ errors().email }}</div> }
        </div>
        <div class="field" [class.invalid]="errors().phone">
          <label for="phone">Mobile number <span class="req">*</span></label>
          <input id="phone" type="tel" formControlName="phone" autocomplete="tel" placeholder="+91" />
          @if (errors().phone) { <div class="err">{{ errors().phone }}</div> }
        </div>
      </div>
      <div class="row-2">
        <app-password-field [control]="form.controls.password" autocomplete="new-password" [error]="errors().password" />
        <app-password-field [control]="form.controls.password_confirmation" inputId="password2" label="Confirm password"
                            autocomplete="new-password" [error]="errors().password_confirmation" />
      </div>

      <div class="auth-actions">
        <button class="btn-dark" type="submit" [disabled]="busy()">
          @if (busy()) { <span class="spinner"></span> } Create account
        </button>
      </div>
    </form>

    <app-google-button text="signup_with" />

    <div class="auth-links center">
      <a class="link-u" routerLink="/login">Already have an account? Log in</a>
    </div>
  `,
})
export class Register {
  private auth = inject(AuthService);
  private router = inject(Router);

  protected readonly form = inject(NonNullableFormBuilder).group({
    name: [''],
    company_name: [''],
    email: [''],
    phone: [''],
    password: [''],
    password_confirmation: [''],
  });
  protected readonly busy = signal(false);
  protected readonly message = signal('');
  protected readonly errors = signal<FieldErrors>({});

  submit(): void {
    const v = this.form.getRawValue();
    const local: Record<string, string> = {};
    for (const [k, label] of Object.entries({ name: 'your name', company_name: 'your company name', email: 'your email', phone: 'your mobile number', password: 'a password' })) {
      if (!v[k as keyof typeof v].trim()) local[k] = `Please enter ${label}.`;
    }
    if (v.password && v.password !== v.password_confirmation) local['password_confirmation'] = 'Passwords do not match.';
    this.errors.set(local);
    this.message.set('');
    if (Object.keys(local).length) return;

    this.busy.set(true);
    this.auth.register(v).subscribe({
      next: () => this.router.navigateByUrl('/onboarding'),
      error: (err) => {
        const e = readErrors(err);
        this.errors.set(e.fields);
        this.message.set(e.message);
        this.busy.set(false);
      },
    });
  }
}
