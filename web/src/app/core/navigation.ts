export type IconName =
  | 'dashboard' | 'leads' | 'clients' | 'employees' | 'expenses' | 'bell' | 'settings' | 'menu'
  | 'campaigns' | 'invoices' | 'tickets' | 'leave' | 'logout';

export interface NavItem {
  label: string;
  short?: string; // label in the mobile bottom bar
  path: string;
  icon: IconName;
  module: string; // permission module; shown only if the user can view it
  section: 'Overview' | 'Client portal' | 'System';
}

/** Full menu. Each user sees only the items whose module they can view (set by the admin). */
export const NAV_ITEMS: NavItem[] = [
  { label: 'Dashboard', short: 'Home', path: '/dashboard', icon: 'dashboard', module: 'dashboard', section: 'Overview' },
  { label: 'Leads', path: '/leads', icon: 'leads', module: 'leads', section: 'Overview' },
  { label: 'Clients', path: '/clients', icon: 'clients', module: 'clients', section: 'Overview' },
  { label: 'Employees', short: 'Team', path: '/employees', icon: 'employees', module: 'employees', section: 'Overview' },
  { label: 'Leave', path: '/leave', icon: 'leave', module: 'leave', section: 'Overview' },
  { label: 'Expenses', path: '/expenses', icon: 'expenses', module: 'expenses', section: 'Overview' },
  { label: 'Campaigns', path: '/campaigns', icon: 'campaigns', module: 'campaigns', section: 'Client portal' },
  { label: 'Invoices & Payments', short: 'Invoices', path: '/invoices', icon: 'invoices', module: 'invoices', section: 'Client portal' },
  { label: 'Tickets', path: '/tickets', icon: 'tickets', module: 'tickets', section: 'Client portal' },
  { label: 'Notifications', short: 'Alerts', path: '/notifications', icon: 'bell', module: 'notifications', section: 'System' },
  { label: 'Settings', path: '/settings', icon: 'settings', module: 'settings', section: 'System' },
];
