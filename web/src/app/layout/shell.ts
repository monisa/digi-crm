import { Component, HostListener, computed, inject, signal } from '@angular/core';
import { NavigationEnd, Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { filter } from 'rxjs';
import { AuthService } from '../core/auth/auth.service';
import { NAV_ITEMS, NavItem } from '../core/navigation';
import { Toast } from '../shared/toast.service';
import { Bell } from './bell';
import { Icon } from './icon';
import { AccountSheet, AccountTab } from './account-sheet';

@Component({
  selector: 'app-shell',
  imports: [RouterOutlet, RouterLink, RouterLinkActive, Icon, Bell, AccountSheet],
  templateUrl: './shell.html',
  styleUrl: './shell.scss',
})
export class Shell {
  protected readonly auth = inject(AuthService);
  protected readonly toast = inject(Toast);

  /** Menu items this user may see, grouped by section. */
  protected readonly sections = computed(() => {
    this.auth.profile(); // re-evaluate when permissions change
    const visible = NAV_ITEMS.filter((i) => this.auth.canView(i.module));
    const groups = new Map<string, NavItem[]>();
    for (const item of visible) groups.set(item.section, [...(groups.get(item.section) ?? []), item]);
    return [...groups.entries()].map(([name, items]) => ({ name, items }));
  });

  /** Up to four shortcuts for the mobile bottom bar (+ "More"). */
  protected readonly mobileNav = computed(() => this.sections().flatMap((s) => s.items).slice(0, 4));

  protected readonly collapsed = signal(false);
  protected readonly drawerOpen = signal(false);
  protected readonly menuOpen = signal(false);
  protected readonly scrolled = signal(false);
  protected readonly account = signal<AccountTab | null>(null);

  openAccount(tab: AccountTab): void {
    this.menuOpen.set(false);
    this.account.set(tab);
  }

  constructor() {
    inject(Router)
      .events.pipe(filter((e) => e instanceof NavigationEnd))
      .subscribe(() => {
        this.drawerOpen.set(false);
        this.menuOpen.set(false);
      });
  }

  @HostListener('window:scroll')
  onScroll() {
    this.scrolled.set(window.scrollY > 4);
  }

  @HostListener('document:keydown.escape')
  onEscape() {
    this.menuOpen.set(false);
    this.drawerOpen.set(false);
  }
}
