import { Routes } from '@angular/router';
import { Shell } from './layout/shell';

const later = (title: string, phase: string) => ({
  loadComponent: () => import('./features/placeholder/placeholder').then((m) => m.Placeholder),
  data: { title, phase },
});

export const routes: Routes = [
  {
    path: '',
    component: Shell,
    children: [
      { path: '', pathMatch: 'full', redirectTo: 'dashboard' },
      {
        path: 'dashboard',
        title: 'Dashboard · DigiFox Pro CRM',
        loadComponent: () => import('./features/dashboard/dashboard').then((m) => m.Dashboard),
      },
      { path: 'leads', title: 'Leads · DigiFox Pro CRM', ...later('Leads', 'Phase 4') },
      { path: 'clients', title: 'Clients · DigiFox Pro CRM', ...later('Clients', 'Phase 5') },
      { path: 'employees', title: 'Employees · DigiFox Pro CRM', ...later('Employees', 'Phase 3') },
      { path: 'expenses', title: 'Expenses · DigiFox Pro CRM', ...later('Expenses', 'Phase 7') },
      { path: 'notifications', title: 'Notifications · DigiFox Pro CRM', ...later('Notifications', 'Phase 6') },
      { path: 'settings', title: 'Settings · DigiFox Pro CRM', ...later('Settings', 'Phase 1') },
    ],
  },
  { path: '**', redirectTo: '' },
];
