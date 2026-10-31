import { Component, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { FieldErrors, readErrors } from '../../core/http-errors';
import { AuthService } from '../../core/auth/auth.service';
import { PasswordField } from './password-field';

/** Opened from the link in the reset email: /reset-password?token=...&email=... */
@Component({
  selector: 'app-reset-password',
  imports: [ReactiveFormsModule, RouterLink, PasswordField],
  template: `
    <h2 class="auth-h">Set a new password</h2>
    <p class="auth-sub">For <b>{{ email || 'your account' }}</b>. Use at least 8 characters with letters and numbers.</p>

    @if (done()) {
      <div class="alert success" role="status">{{ done() }}</div>
      <div class="auth-actions"><a class="btn-dark" routerLink="/login">Go to Login</a></div>
    } @else {
      @if (!token) { <div class="alert error">This reset link is incomplete. Please request a new one.</div> }
      @if (message()) { <div class="alert error" role="alert">{{ message() }}</div> }
      <form [formGroup]="form" (ngSubmit)="submit()" novalidate>
        <app-password-field [control]="form.controls.password" label="New password" autocomplete="new-password" [error]="errors().password" />
        <app-password-field [control]="form.controls.password_confirmation" inputId="password2" label="Confirm new password"
                            autocomplete="new-password" [error]="errors().password_confirmation" />
        <div class="auth-actions">
          <button class="btn-dark" type="submit" [disabled]="busy() || !token">
            @if (busy()) { <span class="spinner"></span> } Reset Password
          </button>
        </div>
      </form>
      <div class="auth-links center"><a class="link-u" routerLink="/forgot-password">Request a new link</a></div>
    }
  `,
})
export class ResetPassword {
  private auth = inject(AuthService);
  private params = inject(ActivatedRoute).snapshot.queryParamMap;
  protected readonly token = this.params.get('token') ?? '';
  protected readonly email = this.params.get('email') ?? '';

  protected readonly form = inject(NonNullableFormBuilder).group({ password: [''], password_confirmation: [''] });
  protected readonly busy = signal(false);
  protected readonly done = signal('');
  protected readonly message = signal('');
  protected readonly errors = signal<FieldErrors>({});

  submit(): void {
    const v = this.form.getRawValue();
    const local: Record<string, string> = {};
    if (v.password.length < 8) local['password'] = 'Use at least 8 characters.';
    if (v.password !== v.password_confirmation) local['password_confirmation'] = 'Passwords do not match.';
    this.errors.set(local);
    this.message.set('');
    if (Object.keys(local).length) return;

    this.busy.set(true);
    this.auth.resetPassword({ token: this.token, email: this.email, ...v }).subscribe({
      next: (r) => this.done.set(r.message),
      error: (err) => {
        const e = readErrors(err);
        this.message.set(e.message || e.fields['email'] || '');
        this.errors.set(e.fields);
        this.busy.set(false);
      },
    });
  }
}
