import { Component, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { FieldErrors, readErrors } from '../../core/http-errors';
import { AuthService } from '../../core/auth/auth.service';
import { PasswordField } from './password-field';

@Component({
  selector: 'app-login',
  imports: [ReactiveFormsModule, RouterLink, PasswordField],
  template: `
    <h2 class="auth-h">Get Started Now!</h2>
    @if (expired) { <div class="alert info">Your session has ended. Please log in again.</div> }
    @if (message()) { <div class="alert error" role="alert">{{ message() }}</div> }

    <form [formGroup]="form" (ngSubmit)="submit()" novalidate>
      <div class="field" [class.invalid]="errors().email">
        <label for="email">Email <span class="req">*</span></label>
        <input id="email" type="email" formControlName="email" autocomplete="email" />
        @if (errors().email) { <div class="err">{{ errors().email }}</div> }
      </div>

      <app-password-field [control]="form.controls.password" [error]="errors().password" />

      <label class="check"><input type="checkbox" formControlName="remember" /> Keep me signed in for 30 days</label>

      <div class="auth-actions">
        <button class="btn-dark" type="submit" [disabled]="busy()">
          @if (busy()) { <span class="spinner"></span> } Login
        </button>
      </div>
    </form>

    <div class="auth-links">
      <a class="link-u" routerLink="/register">New client? Create an account</a>
      <a class="link-u" routerLink="/forgot-password">Forgot password?</a>
    </div>
  `,
})
export class Login {
  private auth = inject(AuthService);
  private router = inject(Router);
  protected readonly expired = !!inject(ActivatedRoute).snapshot.queryParamMap.get('expired');

  protected readonly form = inject(NonNullableFormBuilder).group({
    email: ['', [Validators.required, Validators.email]],
    password: ['', Validators.required],
    remember: [false],
  });
  protected readonly busy = signal(false);
  protected readonly message = signal('');
  protected readonly errors = signal<FieldErrors>({});

  submit(): void {
    const { email, password, remember } = this.form.getRawValue();
    const local: Record<string, string> = {};
    if (this.form.controls.email.invalid) local['email'] = 'Enter a valid email address.';
    if (!password) local['password'] = 'Enter your password.';
    this.errors.set(local);
    this.message.set('');
    if (Object.keys(local).length) return;

    this.busy.set(true);
    this.auth.login(email, password, remember).subscribe({
      next: () => this.router.navigateByUrl(this.auth.homeUrl()),
      error: (err) => {
        const e = readErrors(err);
        this.errors.set(e.fields);
        this.message.set(e.message);
        this.busy.set(false);
      },
    });
  }
}
