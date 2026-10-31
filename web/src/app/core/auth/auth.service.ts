import { HttpClient } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { Observable, catchError, firstValueFrom, map, of, tap } from 'rxjs';
import { API_BASE } from '../api.config';
import { AuthUser, Profile, TokenResponse } from './auth.models';

const TOKEN_KEY = 'dfp_crm_token';

@Injectable({ providedIn: 'root' })
export class AuthService {
  private http = inject(HttpClient);
  private router = inject(Router);

  private readonly _token = signal<string | null>(localStorage.getItem(TOKEN_KEY));
  private readonly _profile = signal<Profile | null>(null);

  readonly token = this._token.asReadonly();
  readonly profile = this._profile.asReadonly();
  readonly user = computed<AuthUser | null>(() => this._profile()?.user ?? null);
  readonly isLoggedIn = computed(() => !!this._token());
  readonly initials = computed(() =>
    (this.user()?.name ?? '')
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((p) => p[0]!.toUpperCase())
      .join(''),
  );

  can(permission: string): boolean {
    const p = this._profile();
    return !!p && p.permissions.includes(permission);
  }

  canView(module: string): boolean {
    const p = this._profile();
    return !!p && p.modules.includes(module);
  }

  login(email: string, password: string, remember: boolean): Observable<Profile> {
    return this.http
      .post<TokenResponse>(`${API_BASE}/auth/login`, { email, password, remember })
      .pipe(tap((r) => this.startSession(r)));
  }

  register(data: Record<string, unknown>): Observable<Profile> {
    return this.http
      .post<TokenResponse>(`${API_BASE}/auth/register`, data)
      .pipe(tap((r) => this.startSession(r)));
  }

  forgotPassword(email: string): Observable<{ message: string }> {
    return this.http.post<{ message: string }>(`${API_BASE}/auth/forgot-password`, { email });
  }

  resetPassword(data: Record<string, unknown>): Observable<{ message: string }> {
    return this.http.post<{ message: string }>(`${API_BASE}/auth/reset-password`, data);
  }

  /** Loads the profile once per page load; resolves to null if the token is no longer valid. */
  ensureProfile(): Promise<Profile | null> {
    if (this._profile()) return Promise.resolve(this._profile());
    if (!this._token()) return Promise.resolve(null);
    return firstValueFrom(this.refresh());
  }

  refresh(): Observable<Profile | null> {
    return this.http.get<Profile>(`${API_BASE}/auth/me`).pipe(
      tap((p) => this._profile.set(p)),
      catchError(() => {
        this.clearSession();
        return of(null);
      }),
    );
  }

  logout(): void {
    const done = () => {
      this.clearSession();
      this.router.navigateByUrl('/login');
    };
    if (!this._token()) return done();
    this.http.post(`${API_BASE}/auth/logout`, {}).pipe(map(() => null), catchError(() => of(null))).subscribe(done);
  }

  /** Called by the HTTP interceptor when the server says the token is invalid. */
  clearSession(): void {
    localStorage.removeItem(TOKEN_KEY);
    this._token.set(null);
    this._profile.set(null);
  }

  /** Where a signed-in user should land. */
  homeUrl(): string {
    return this.user()?.needs_onboarding ? '/onboarding' : '/dashboard';
  }

  private startSession(r: TokenResponse): void {
    localStorage.setItem(TOKEN_KEY, r.token);
    this._token.set(r.token);
    this._profile.set({ user: r.user, permissions: r.permissions, modules: r.modules });
  }
}
