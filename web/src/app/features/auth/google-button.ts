import { Component, DestroyRef, ElementRef, NgZone, inject, input, output, signal, viewChild } from '@angular/core';
import { Router } from '@angular/router';
import { AuthService } from '../../core/auth/auth.service';
import { readErrors } from '../../core/http-errors';

declare global {
  interface Window {
    google?: {
      accounts: {
        id: {
          initialize(o: { client_id: string; callback: (r: { credential: string }) => void; ux_mode?: string }): void;
          renderButton(el: HTMLElement, o: Record<string, unknown>): void;
        };
      };
    };
  }
}

let scriptLoad: Promise<void> | null = null;
function loadGoogleScript(): Promise<void> {
  scriptLoad ??= new Promise((resolve, reject) => {
    const s = document.createElement('script');
    s.src = 'https://accounts.google.com/gsi/client';
    s.async = true;
    s.onload = () => resolve();
    s.onerror = () => {
      scriptLoad = null;
      reject(new Error('Google sign-in could not load'));
    };
    document.head.appendChild(s);
  });
  return scriptLoad;
}

/**
 * Official "Sign in with Google" button (Google Identity Services).
 * Hidden entirely when the server has no Google client ID configured.
 */
@Component({
  selector: 'app-google-button',
  template: `
    @if (enabled()) {
      <div class="or"><span>or continue with</span></div>
      <div class="gwrap" [class.busy]="busy()"><div #slot></div></div>
      @if (error()) { <div class="alert error" role="alert">{{ error() }}</div> }
    }
  `,
  styles: `
    .or { display: flex; align-items: center; gap: 12px; margin: 28px 0 16px; color: var(--ink-3); font-size: 13px; }
    .or::before, .or::after { content: ''; flex: 1; height: 1px; background: var(--line); }
    .gwrap { display: flex; justify-content: center; min-height: 44px; transition: opacity 0.2s; }
    .gwrap.busy { opacity: 0.5; pointer-events: none; }
  `,
})
export class GoogleButton {
  /** Button wording: "signin_with" | "signup_with" | "continue_with". */
  text = input<'signin_with' | 'signup_with' | 'continue_with'>('continue_with');
  signedIn = output<void>();

  private auth = inject(AuthService);
  private router = inject(Router);
  private zone = inject(NgZone);
  private host = inject(ElementRef<HTMLElement>);
  private slot = viewChild<ElementRef<HTMLElement>>('slot');

  protected readonly enabled = signal(false);
  protected readonly busy = signal(false);
  protected readonly error = signal('');

  constructor() {
    const sub = this.auth.googleClientId$.subscribe((id) => id && this.setup(id));
    inject(DestroyRef).onDestroy(() => sub.unsubscribe());
  }

  private async setup(clientId: string): Promise<void> {
    this.enabled.set(true);
    try {
      await loadGoogleScript();
    } catch {
      this.error.set('Google sign-in is unavailable right now. Please use your email and password.');
      return;
    }
    const el = this.slot()?.nativeElement;
    if (!el || !window.google) return;

    window.google.accounts.id.initialize({
      client_id: clientId,
      callback: (r) => this.zone.run(() => this.onCredential(r.credential)),
    });
    const width = Math.min(380, Math.max(220, this.host.nativeElement.clientWidth || 320));
    window.google.accounts.id.renderButton(el, {
      theme: 'outline', size: 'large', shape: 'rectangular', text: this.text(), width, logo_alignment: 'center',
    });
  }

  private onCredential(credential: string): void {
    this.busy.set(true);
    this.error.set('');
    this.auth.loginWithGoogle(credential).subscribe({
      next: () => {
        this.signedIn.emit();
        this.router.navigateByUrl(this.auth.homeUrl());
      },
      error: (e) => {
        const r = readErrors(e);
        this.error.set(r.message || r.fields['credential'] || 'Google sign-in failed.');
        this.busy.set(false);
      },
    });
  }
}
