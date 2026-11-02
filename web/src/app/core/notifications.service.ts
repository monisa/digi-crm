import { HttpClient } from '@angular/common/http';
import { Injectable, effect, inject, signal } from '@angular/core';
import { Observable, tap } from 'rxjs';
import { API_BASE } from './api.config';
import { AuthService } from './auth/auth.service';

export interface Alert {
  id: string;
  type: string;
  category: string;
  title: string;
  body: string;
  link: string;
  read: boolean;
  at: string;
}

export interface Pref { key: string; label: string; category: string; email: boolean }

/**
 * Unread count for the bell. Shared hosting has no push connection, so the app
 * asks every 60 seconds while the tab is visible (and right away when it comes back).
 */
@Injectable({ providedIn: 'root' })
export class NotificationsService {
  private http = inject(HttpClient);
  private auth = inject(AuthService);
  readonly unread = signal(0);
  /** Bumps when the count goes up, so the bell can wiggle. */
  readonly bumped = signal(0);
  private timer?: ReturnType<typeof setInterval>;
  private readonly baseTitle = typeof document !== 'undefined' ? document.title : '';

  constructor() {
    effect(() => {
      if (this.auth.isLoggedIn()) this.start();
      else this.stop();
    });
    if (typeof document !== 'undefined') {
      document.addEventListener('visibilitychange', () => document.visibilityState === 'visible' && this.auth.isLoggedIn() && this.refresh());
    }
    effect(() => {
      const n = this.unread();
      if (typeof document === 'undefined') return;
      document.title = document.title.replace(/^\(\d+\)\s*/, '');
      if (n > 0) document.title = `(${n}) ${document.title}`;
    });
  }

  private start(): void {
    if (this.timer) return;
    this.refresh();
    this.timer = setInterval(() => document.visibilityState === 'visible' && this.refresh(), 60000);
  }

  private stop(): void {
    clearInterval(this.timer);
    this.timer = undefined;
    this.unread.set(0);
  }

  refresh(): void {
    this.http.get<{ unread: number }>(`${API_BASE}/notifications/unread-count`).subscribe({
      next: (r) => this.setCount(r.unread),
      error: () => {},
    });
  }

  private setCount(n: number): void {
    if (n > this.unread()) this.bumped.update((x) => x + 1);
    this.unread.set(n);
  }

  list(params: { category?: string; unread?: boolean; limit?: number } = {}): Observable<{ data: Alert[]; unread: number }> {
    const p: Record<string, string> = {};
    if (params.category) p['category'] = params.category;
    if (params.unread) p['unread'] = '1';
    if (params.limit) p['limit'] = String(params.limit);
    return this.http.get<{ data: Alert[]; unread: number }>(`${API_BASE}/notifications`, { params: p }).pipe(tap((r) => this.unread.set(r.unread)));
  }

  markRead(ids: string[] | 'all'): Observable<{ unread: number }> {
    return this.http
      .post<{ unread: number }>(`${API_BASE}/notifications/read`, ids === 'all' ? { all: true } : { ids })
      .pipe(tap((r) => this.unread.set(r.unread)));
  }

  prefs(): Observable<{ types: Pref[] }> {
    return this.http.get<{ types: Pref[] }>(`${API_BASE}/notifications/preferences`);
  }

  savePrefs(email: Record<string, boolean>): Observable<{ types: Pref[] }> {
    return this.http.put<{ types: Pref[] }>(`${API_BASE}/notifications/preferences`, { email });
  }
}

export const CATEGORY_ICON: Record<string, string> = { leads: '🎯', tickets: '💬', leave: '🌴', billing: '₹', clients: '🤝', other: '🔔' };

export function ago(iso: string): string {
  const m = Math.floor((Date.now() - new Date(iso).getTime()) / 60000);
  if (m < 1) return 'just now';
  if (m < 60) return `${m}m ago`;
  if (m < 1440) return `${Math.floor(m / 60)}h ago`;
  return new Date(iso).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' });
}
