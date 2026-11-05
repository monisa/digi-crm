import { HttpClient } from '@angular/common/http';
import { Component, inject, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { switchMap } from 'rxjs';
import { API_BASE } from '../core/api.config';
import { AuthService } from '../core/auth/auth.service';
import { readErrors } from '../core/http-errors';
import { Sheet } from '../shared/sheet';
import { Toast } from '../shared/toast.service';

export type AccountTab = 'profile' | 'password';

/** My profile (name, phone) and Change password, from the top-right menu. */
@Component({
  selector: 'app-account-sheet',
  imports: [FormsModule, Sheet],
  template: `
    <app-sheet title="My account" [subtitle]="auth.user()?.email ?? ''" [width]="'460px'" (closed)="closed.emit()">
      <div class="seg" role="tablist">
        <button role="tab" [class.on]="tab() === 'profile'" (click)="tab.set('profile'); err.set({})">Profile</button>
        <button role="tab" [class.on]="tab() === 'password'" (click)="tab.set('password'); err.set({})">Change password</button>
      </div>

      @if (tab() === 'profile') {
        <form (ngSubmit)="saveProfile()">
          <div class="field compact" [class.invalid]="err()['name']"><label for="ac-n">Name</label><input id="ac-n" name="n" [(ngModel)]="name" autocomplete="name" maxlength="120" />
            @if (err()['name']) { <div class="err">{{ err()['name'] }}</div> }</div>
          <div class="field compact" [class.invalid]="err()['phone']"><label for="ac-p">Phone</label><input id="ac-p" name="p" type="tel" [(ngModel)]="phone" placeholder="+91" autocomplete="tel" />
            @if (err()['phone']) { <div class="err">{{ err()['phone'] }}</div> }</div>
          <div class="field compact"><label for="ac-e">Email (login)</label><input id="ac-e" name="e" [value]="auth.user()?.email ?? ''" disabled />
            <small class="hint">Ask an admin to change the login email.</small></div>
          <div class="form-actions"><button class="btn" type="submit" [disabled]="busy()">Save</button></div>
        </form>
      } @else {
        <form (ngSubmit)="savePassword()" autocomplete="on">
          <input type="email" name="username" [value]="auth.user()?.email ?? ''" autocomplete="username" hidden />
          @for (f of fields; track f.key) {
            <div class="field compact" [class.invalid]="err()[f.key]">
              <label [for]="'ac-' + f.key">{{ f.label }}</label>
              <div class="pw">
                <input [id]="'ac-' + f.key" [name]="f.key" [type]="show() ? 'text' : 'password'" [(ngModel)]="pw[f.key]" [attr.autocomplete]="f.ac" />
                @if (f.key === 'current_password') { <button type="button" class="eye" (click)="show.set(!show())">{{ show() ? 'Hide' : 'Show' }}</button> }
              </div>
              @if (err()[f.key]) { <div class="err">{{ err()[f.key] }}</div> }
            </div>
          }
          <p class="hint">At least 8 characters, with letters and numbers. Other phones and laptops signed in to your account will be signed out.</p>
          <div class="form-actions"><button class="btn" type="submit" [disabled]="busy()">Change password</button></div>
          <p class="hint forgot">Signed up with Google, or forgot your password? Log out and use <b>Forgot password</b> on the login page.</p>
        </form>
      }
    </app-sheet>
  `,
  styles: `
    .seg { display: flex; gap: 4px; background: var(--bg); padding: 4px; border-radius: 12px; margin: 12px 0 18px; }
    .seg button { flex: 1; border: 0; background: none; padding: 9px; border-radius: 9px; font: inherit; font-weight: 600; font-size: 13.5px; color: var(--ink-3); cursor: pointer; }
    .seg button.on { background: var(--card); color: var(--ink); box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08); }
    .pw { position: relative; } .pw input { width: 100%; padding-right: 64px; }
    .eye { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); border: 0; background: none; color: var(--brand-deep); font-weight: 600; font-size: 12.5px; cursor: pointer; }
    .hint { display: block; margin-top: 6px; } .forgot { margin-top: 16px; }
  `,
})
export class AccountSheet {
  protected readonly auth = inject(AuthService);
  private http = inject(HttpClient);
  private toast = inject(Toast);
  start = input<AccountTab>('profile');
  closed = output<void>();
  protected readonly tab = signal<AccountTab>('profile');
  protected readonly busy = signal(false);
  protected readonly show = signal(false);
  protected readonly err = signal<Record<string, string>>({});
  protected name = this.auth.user()?.name ?? '';
  protected phone = this.auth.user()?.phone ?? '';
  protected pw: Record<string, string> = { current_password: '', password: '', password_confirmation: '' };
  protected readonly fields = [
    { key: 'current_password', label: 'Current password', ac: 'current-password' },
    { key: 'password', label: 'New password', ac: 'new-password' },
    { key: 'password_confirmation', label: 'New password again', ac: 'new-password' },
  ];

  ngOnInit(): void {
    this.tab.set(this.start());
  }

  saveProfile(): void {
    this.busy.set(true);
    this.err.set({});
    this.http.put(`${API_BASE}/auth/profile`, { name: this.name, phone: this.phone || null }).pipe(switchMap(() => this.auth.refresh())).subscribe({
      next: () => {
        this.busy.set(false);
        this.toast.show('Profile saved.');
        this.closed.emit();
      },
      error: (e) => this.fail(e),
    });
  }

  savePassword(): void {
    if (this.pw['password'] !== this.pw['password_confirmation']) {
      this.err.set({ password_confirmation: 'The two new passwords are not the same.' });
      return;
    }
    this.busy.set(true);
    this.err.set({});
    this.http.post<{ message: string }>(`${API_BASE}/auth/password`, this.pw).subscribe({
      next: (r) => {
        this.busy.set(false);
        this.toast.show(r.message);
        this.closed.emit();
      },
      error: (e) => this.fail(e),
    });
  }

  private fail(e: unknown): void {
    this.busy.set(false);
    const r = readErrors(e);
    this.err.set(r.fields);
    if (!Object.keys(r.fields).length) this.toast.show(r.message || 'Could not save.');
  }
}
