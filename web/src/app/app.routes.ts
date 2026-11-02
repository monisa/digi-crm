import { Routes } from '@angular/router';
import { authGuard, guestGuard, moduleGuard, onboardingGuard } from './core/auth/auth.guards';
import { AuthLayout } from './features/auth/auth-layout';
import { Shell } from './layout/shell';

const T = (page: string) => `${page} · DigiFox Pro CRM`;

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
    path: 'form/:token',
    title: T('Client onboarding form'),
    data: { mode: 'public' },
    loadComponent: () => import('./features/onboarding/onboarding').then((m) => m.Onboarding),
  },
  {
    path: 'clients/:id/onboarding',
    title: T('Onboarding form'),
    canActivate: [authGuard, moduleGuard],
    data: { mode: 'staff', module: 'clients' },
    loadComponent: () => import('./features/onboarding/onboarding').then((m) => m.Onboarding),
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
      {
        path: 'leads',
        title: T('Leads'),
        canActivate: [moduleGuard],
        data: { module: 'leads' },
        loadComponent: () => import('./features/leads/leads').then((m) => m.Leads),
      },
      {
        path: 'clients',
        title: T('Clients'),
        canActivate: [moduleGuard],
        data: { module: 'clients' },
        loadComponent: () => import('./features/clients/clients').then((m) => m.Clients),
      },
      {
        path: 'clients/:id',
        title: T('Client'),
        canActivate: [moduleGuard],
        data: { module: 'clients' },
        loadComponent: () => import('./features/clients/client-profile').then((m) => m.ClientProfile),
      },
      {
        path: 'employees',
        title: T('Employees'),
        canActivate: [moduleGuard],
        data: { module: 'employees' },
        loadComponent: () => import('./features/employees/employees').then((m) => m.Employees),
      },
      {
        path: 'employees/payroll',
        title: T('Payroll'),
        canActivate: [moduleGuard],
        data: { module: 'employees' },
        loadComponent: () => import('./features/employees/payroll').then((m) => m.Payroll),
      },
      {
        path: 'employees/:id',
        title: T('Employee'),
        canActivate: [moduleGuard],
        data: { module: 'employees' },
        loadComponent: () => import('./features/employees/employee-profile').then((m) => m.EmployeeProfile),
      },
      {
        path: 'leave',
        title: T('Leave'),
        canActivate: [moduleGuard],
        data: { module: 'leave' },
        loadComponent: () => import('./features/leave/leave-page').then((m) => m.LeavePage),
      },
      {
        path: 'expenses',
        title: T('Expenses'),
        canActivate: [moduleGuard],
        data: { module: 'expenses' },
        loadComponent: () => import('./features/expenses/expenses').then((m) => m.Expenses),
      },
      {
        path: 'campaigns',
        title: T('Campaigns'),
        canActivate: [moduleGuard],
        data: { module: 'campaigns' },
        loadComponent: () => import('./features/campaigns/campaigns').then((m) => m.Campaigns),
      },
      {
        path: 'invoices',
        title: T('Invoices'),
        canActivate: [moduleGuard],
        data: { module: 'invoices' },
        loadComponent: () => import('./features/invoices/invoices').then((m) => m.Invoices),
      },
      {
        path: 'invoices/new',
        title: T('New invoice'),
        canActivate: [moduleGuard],
        data: { module: 'invoices' },
        loadComponent: () => import('./features/invoices/invoice-editor').then((m) => m.InvoiceEditor),
      },
      {
        path: 'invoices/:id/edit',
        title: T('Edit invoice'),
        canActivate: [moduleGuard],
        data: { module: 'invoices' },
        loadComponent: () => import('./features/invoices/invoice-editor').then((m) => m.InvoiceEditor),
      },
      {
        path: 'invoices/:id',
        title: T('Invoice'),
        canActivate: [moduleGuard],
        data: { module: 'invoices' },
        loadComponent: () => import('./features/invoices/invoice-view').then((m) => m.InvoiceView),
      },
      {
        path: 'tickets',
        title: T('Tickets'),
        canActivate: [moduleGuard],
        data: { module: 'tickets' },
        loadComponent: () => import('./features/tickets/tickets').then((m) => m.Tickets),
      },
      {
        path: 'tickets/:id',
        title: T('Ticket'),
        canActivate: [moduleGuard],
        data: { module: 'tickets' },
        loadComponent: () => import('./features/tickets/ticket-thread').then((m) => m.TicketThread),
      },
      {
        // Everyone (including clients) has their own notifications, so no module check.
        path: 'notifications',
        title: T('Notifications'),
        loadComponent: () => import('./features/notifications/notifications').then((m) => m.Notifications),
      },
    ],
  },
  { path: '**', redirectTo: 'dashboard' },
];
