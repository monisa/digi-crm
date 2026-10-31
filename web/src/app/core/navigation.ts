export type IconName =
  | 'dashboard' | 'leads' | 'clients' | 'employees' | 'expenses' | 'bell' | 'settings' | 'menu';

export interface NavItem {
  label: string;
  path: string;
  icon: IconName;
  section: 'Overview' | 'System';
}

/**
 * Sidebar menu. From Phase 1 this list comes from GET /auth/me so that it
 * follows each user's role and the permissions set by the admin.
 */
export const NAV_ITEMS: NavItem[] = [
  { label: 'Dashboard', path: '/dashboard', icon: 'dashboard', section: 'Overview' },
  { label: 'Leads', path: '/leads', icon: 'leads', section: 'Overview' },
  { label: 'Clients', path: '/clients', icon: 'clients', section: 'Overview' },
  { label: 'Employees', path: '/employees', icon: 'employees', section: 'Overview' },
  { label: 'Expenses', path: '/expenses', icon: 'expenses', section: 'Overview' },
  { label: 'Notifications', path: '/notifications', icon: 'bell', section: 'System' },
  { label: 'Settings', path: '/settings', icon: 'settings', section: 'System' },
];
