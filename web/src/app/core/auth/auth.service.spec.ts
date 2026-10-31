import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { AuthService } from './auth.service';

describe('AuthService', () => {
  let auth: AuthService;
  let http: HttpTestingController;

  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([])],
    });
    auth = TestBed.inject(AuthService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('stores the token and permissions after login', () => {
    auth.login('priya@digifox.test', 'secret', false).subscribe();
    const req = http.expectOne('/api/v1/auth/login');
    expect(req.request.body).toEqual({ email: 'priya@digifox.test', password: 'secret', remember: false });
    req.flush({
      token: 'abc',
      expires_at: '2026-11-02T00:00:00Z',
      user: { id: 1, name: 'Priya Raj', email: 'priya@digifox.test', role: 'employee', status: 'active', needs_onboarding: false },
      permissions: ['dashboard.view', 'leave.create', 'leave.view'],
      modules: ['dashboard', 'leave'],
    });

    expect(auth.isLoggedIn()).toBe(true);
    expect(localStorage.getItem('dfp_crm_token')).toBe('abc');
    expect(auth.initials()).toBe('PR');
    expect(auth.can('leave.create')).toBe(true);
    expect(auth.canView('leads')).toBe(false);
    expect(auth.homeUrl()).toBe('/dashboard');
  });

  it('sends new clients to onboarding', () => {
    auth.register({}).subscribe();
    http.expectOne('/api/v1/auth/register').flush({
      token: 't',
      expires_at: '',
      user: { id: 2, name: 'Anu', email: 'a@b.c', role: 'client', status: 'pending_onboarding', needs_onboarding: true },
      permissions: [],
      modules: [],
    });
    expect(auth.homeUrl()).toBe('/onboarding');
  });

  it('clears the session when the profile can no longer be loaded', async () => {
    localStorage.setItem('dfp_crm_token', 'expired');
    const fresh = TestBed.runInInjectionContext(() => new AuthService());
    const pending = fresh.ensureProfile();
    http.expectOne('/api/v1/auth/me').flush({ message: 'Unauthenticated.' }, { status: 401, statusText: 'Unauthorized' });

    expect(await pending).toBeNull();
    expect(fresh.isLoggedIn()).toBe(false);
    expect(localStorage.getItem('dfp_crm_token')).toBeNull();
  });
});
