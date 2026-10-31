import { Component, HostListener, inject, signal } from '@angular/core';
import { NavigationEnd, Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { filter } from 'rxjs';
import { NAV_ITEMS } from '../core/navigation';
import { Icon } from './icon';

@Component({
  selector: 'app-shell',
  imports: [RouterOutlet, RouterLink, RouterLinkActive, Icon],
  templateUrl: './shell.html',
  styleUrl: './shell.scss',
})
export class Shell {
  protected readonly overview = NAV_ITEMS.filter((i) => i.section === 'Overview');
  protected readonly system = NAV_ITEMS.filter((i) => i.section === 'System');
  protected readonly mobileNav = NAV_ITEMS.filter((i) =>
    ['/dashboard', '/leads', '/clients', '/notifications'].includes(i.path),
  );

  protected readonly collapsed = signal(false);
  protected readonly drawerOpen = signal(false);
  protected readonly scrolled = signal(false);

  constructor() {
    // Close the mobile drawer whenever the user navigates.
    inject(Router)
      .events.pipe(filter((e) => e instanceof NavigationEnd))
      .subscribe(() => this.drawerOpen.set(false));
  }

  @HostListener('window:scroll')
  onScroll() {
    this.scrolled.set(window.scrollY > 4);
  }
}
