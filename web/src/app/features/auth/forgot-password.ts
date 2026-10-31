import { Component, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { readErrors } from '../../core/http-errors';
import { AuthService } from '../../core/auth/auth.service';

@Component({
  selector: 'app-forgot-password',
  imports: [ReactiveFormsModule, RouterLink],
  template: `
    <h2 class="auth-h">Forgot Password?</h2>
    <p class="auth-sub">Enter the email address linked to your account and we'll send you a link to reset your password.</p>

    @if (sent()) {
      <div class="alert success" role="status">{{ sent() }} The link is valid for 60 minutes. Check your spam folder if you don't see it.</div>
    } @else {
      @if (message()) { <div class="alert error" role="alert">{{ message() }}</div> }
      <form [formGroup]="form" (ngSubmit)="submit()" novalidate>
        <div class="field" [class.invalid]="error()">
          <label for="email">Email <span class="req">*</span></label>
          <input id="email" type="email" formControlName="email" autocomplete="email" />
          @if (error()) { <div class="err">{{ error() }}</div> }
        </div>
        <div class="auth-actions">
          <button class="btn-dark" type="submit" [disabled]="busy()">
            @if (busy()) { <span class="spinner"></span> } Send Reset Link
          </button>
        </div>
      </form>
    }

    <div class="auth-links center">
      <a class="link-u" routerLink="/login">← Back to Login</a>
    </div>
  `,
})
export class ForgotPassword {
  private auth = inject(AuthService);
  protected readonly form = inject(NonNullableFormBuilder).group({ email: ['', [Validators.required, Validators.email]] });
  protected readonly busy = signal(false);
  protected readonly sent = signal('');
  protected readonly error = signal('');
  protected readonly message = signal('');

  submit(): void {
    this.message.set('');
    if (this.form.invalid) {
      this.error.set('Enter a valid email address.');
      return;
    }
    this.error.set('');
    this.busy.set(true);
    this.auth.forgotPassword(this.form.getRawValue().email).subscribe({
      next: (r) => this.sent.set(r.message),
      error: (err) => {
        const e = readErrors(err);
        this.error.set(e.fields['email'] ?? '');
        this.message.set(e.message);
        this.busy.set(false);
      },
    });
  }
}
