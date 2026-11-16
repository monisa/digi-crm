import { Injectable, signal } from '@angular/core';

export type Theme = 'light' | 'dark' | 'system';
interface InstallPrompt extends Event { prompt(): Promise<void>; userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }> }
const w = window as unknown as { __dfpInstall?: InstallPrompt };

/** Colour theme (light / dark / follow the device) and "Install app". */
@Injectable({ providedIn: 'root' })
export class AppShellService {
  readonly theme = signal<Theme>((document.documentElement.getAttribute('data-theme') as Theme) || 'light');
  /** The browser offered to install (Chrome, Edge, Android). */
  readonly canInstall = signal(!!w.__dfpInstall);
  readonly installed = signal(matchMedia('(display-mode: standalone)').matches || (navigator as { standalone?: boolean }).standalone === true);
  /** iPhone / iPad Safari: no install prompt, show the "Add to Home Screen" steps instead. */
  readonly isIos = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

  constructor() {
    window.addEventListener('dfp-installable', () => this.canInstall.set(true));
    window.addEventListener('appinstalled', () => {
      this.installed.set(true);
      this.canInstall.set(false);
    });
    this.applyBarColour();
    matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => this.applyBarColour());
  }

  setTheme(t: Theme): void {
    this.theme.set(t);
    document.documentElement.setAttribute('data-theme', t);
    try {
      localStorage.setItem('dfp_theme', t);
    } catch {}
    this.applyBarColour();
  }

  /** True when the screen is dark right now (dark, or system on a dark device). */
  isDark(): boolean {
    const t = this.theme();
    return t === 'dark' || (t === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
  }

  async install(): Promise<boolean> {
    const p = w.__dfpInstall;
    if (!p) return false;
    await p.prompt();
    const r = await p.userChoice;
    w.__dfpInstall = undefined;
    this.canInstall.set(false);
    return r.outcome === 'accepted';
  }

  /** Phone status bar / window title bar follow the theme. */
  private applyBarColour(): void {
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', this.isDark() ? '#0f1115' : '#121418');
  }

  /** Called once at start: the service worker makes the CRM installable and shows a page when offline. */
  static registerWorker(): void {
    if ('serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost')) {
      window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
    }
  }
}
