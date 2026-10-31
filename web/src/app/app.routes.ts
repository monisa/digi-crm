import { Routes } from '@angular/router';
import { authGuard, guestGuard, moduleGuard, onboardingGuard } from './core/auth/auth.guards';
import { AuthLayout } from './features/auth/auth-layout';
import { Shell } from './layout/shell';

const T = (page: string) => `${page} · DigiFox Pro CRM`;

/** Pages for modules built in later phases. */
const later = (path: string, title: string, module: string, phase: string) => ({
  path,
  title: T(title),
  canActivate: [moduleGuard],
  data: { module, title, phase },
  loadComponent: () => import('./features/placeholder/placeholder').then((m) => m.Placeholder),
});

export const routes: Routes = [
  // Must come first: '/' would otherwise match the empty-path auth layout below.
  { path: '', pathMatch: 'full', redirectTo: 'dashboard' },
  {
    path: '',
    component: AuthLayout,
    canActivate: [guestGuard],
    children: [
      { path: 'login', title: T('Login'), loadComponent: () => import('./features/auth/login').then((m) => m.Login) },
      { path: 'register', title: T('Create account'), loadComponent: () => import('./features/auth/register').then((m) => m.Register) },
      {
        path: 'forgot-password',
        title: T('Forgot password'),
        loadComponent: () => import('./features/auth/forgot-password').then((m) => m.ForgotPassword),
      },
    ],
  },
  // Reset links must open even if someone else is signed in on this browser.
  {
    path: '',
    component: AuthLayout,
    children: [
      {
        path: 'reset-password',
        title: T('Reset password'),
        loadComponent: () => import('./features/auth/reset-password').then((m) => m.ResetPassword),
      },
    ],
  },
  {
    path: 'onboarding',
    title: T('Welcome'),
    canActivate: [onboardingGuard],
    loadComponent: () => import('./features/onboarding/onboarding').then((m) => m.Onboarding),
  },
  {
    path: '',
    component: Shell,
    canActivate: [authGuard],
    children: [
      {
        path: 'dashboard',
        title: T('Dashboard'),
        loadComponent: () => import('./features/dashboard/dashboard').then((m) => m.Dashboard),
      },
      {
        path: 'settings',
        title: T('Settings'),
        canActivate: [moduleGuard],
        data: { module: 'settings' },
        loadComponent: () => import('./features/settings/settings').then((m) => m.Settings),
      },
      later('leads', 'Leads', 'leads', 'Phase 4'),
      later('clients', 'Clients', 'clients', 'Phase 5'),
      later('employees', 'Employees', 'employees', 'Phase 3'),
      later('leave', 'Leave', 'leave', 'Phase 3'),
      later('expenses', 'Expenses', 'expenses', 'Phase 7'),
      later('campaigns', 'Campaigns', 'campaigns', 'Phase 5'),
      later('invoices', 'Invoices & Payments', 'invoices', 'Phase 5'),
      later('tickets', 'Tickets', 'tickets', 'Phase 5'),
      later('notifications', 'Notifications', 'notifications', 'Phase 6'),
    ],
  },
  { path: '**', redirectTo: 'dashboard' },
];
