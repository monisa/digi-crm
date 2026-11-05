import { Component, inject } from '@angular/core';
import { AuthService } from '../../core/auth/auth.service';
import { ClientHome } from './client-home';
import { Overview } from './overview/overview';
import { StaffHome } from './staff-home';

/**
 * Users with the "Business Overview" permission (admins, or anyone the admin
 * grants it to) see company figures; everyone else gets a personal welcome
 * until their own dashboards arrive in later phases.
 */
@Component({
  selector: 'app-dashboard',
  imports: [Overview, ClientHome, StaffHome],
  template: `
    @if (auth.canView('overview')) {
      <app-overview />
    } @else if (auth.user()?.role === 'client') {
      <app-client-home />
    } @else {
      <app-staff-home />
    }
  `,
  styles: `
    .head { margin: 12px 0 22px; }
    .head h1 { font-size: 26px; letter-spacing: -0.02em; }
    .head p { color: var(--ink-2); margin-top: 4px; }
    h3 { font-size: 16px; margin-bottom: 8px; }
    .home { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; align-items: start; }
    .go { display: flex; align-items: center; gap: 14px; text-decoration: none; color: inherit; transition: transform 0.25s var(--ease), box-shadow 0.25s; }
    .go:hover { transform: translateY(-3px); box-shadow: var(--shadow); }
    .go p { color: var(--ink-2); font-size: 13.5px; } .go h3 { margin-bottom: 2px; }
    .ic { width: 46px; height: 46px; border-radius: 14px; display: grid; place-items: center; background: var(--brand-soft); font-size: 20px; flex: none; color: var(--brand-deep); font-weight: 800; }
    .arr { margin-left: auto; color: var(--brand-deep); font-size: 18px; }
  `,
})
export class Dashboard {
  protected readonly auth = inject(AuthService);
}
