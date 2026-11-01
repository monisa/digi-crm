import { Component, inject } from '@angular/core';
import { AuthService } from '../../core/auth/auth.service';
import { ClientHome } from './client-home';
import { Overview } from './overview/overview';

/**
 * Users with the "Business Overview" permission (admins, or anyone the admin
 * grants it to) see company figures; everyone else gets a personal welcome
 * until their own dashboards arrive in later phases.
 */
@Component({
  selector: 'app-dashboard',
  imports: [Overview, ClientHome],
  template: `
    @if (auth.canView('overview')) {
      <app-overview />
    } @else if (auth.user()?.role === 'client') {
      <app-client-home />
    } @else {
      <div class="head">
        <h1>Welcome, {{ auth.user()?.name }}</h1>
        <p>Here’s your DigiFox Pro workspace.</p>
      </div>
      <div class="card soon">
        <h3>Your workspace is being set up</h3>
        <p>Your sections are in the menu. Their content will appear here as each part of the CRM goes live.</p>
      </div>
    }
  `,
  styles: `
    .head { margin: 12px 0 22px; }
    .head h1 { font-size: 26px; letter-spacing: -0.02em; }
    .head p { color: var(--ink-2); margin-top: 4px; }
    h3 { font-size: 16px; margin-bottom: 8px; }
    .soon p { color: var(--ink-2); line-height: 1.6; }
  `,
})
export class Dashboard {
  protected readonly auth = inject(AuthService);
}
