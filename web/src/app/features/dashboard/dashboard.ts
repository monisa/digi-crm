import { Component, inject } from '@angular/core';
import { AuthService } from '../../core/auth/auth.service';
import { ClientHome } from './client-home';
import { Overview } from './overview/overview';
import { RouterLink } from '@angular/router';
import { CheckInCard } from '../attendance/check-in-card';

/**
 * Users with the "Business Overview" permission (admins, or anyone the admin
 * grants it to) see company figures; everyone else gets a personal welcome
 * until their own dashboards arrive in later phases.
 */
@Component({
  selector: 'app-dashboard',
  imports: [Overview, ClientHome, CheckInCard, RouterLink],
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
      <div class="home">
        @if (auth.canView('attendance')) { <app-check-in-card /> }
        @if (auth.canView('tasks')) {
          <a class="card go" routerLink="/tasks"><span class="ic">✓</span><div><h3>My tasks</h3><p>See what’s due today and tick things off.</p></div><span class="arr">→</span></a>
        }
        @if (auth.canView('leave')) {
          <a class="card go" routerLink="/leave"><span class="ic">🌴</span><div><h3>Leave</h3><p>Apply for leave and see your balance.</p></div><span class="arr">→</span></a>
        }
      </div>
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
