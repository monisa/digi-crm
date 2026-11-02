import { Component, HostListener, effect, inject, signal } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { Alert, CATEGORY_ICON, NotificationsService, ago } from '../core/notifications.service';

/** Bell with unread badge and a drop-down of the latest alerts. */
@Component({
  selector: 'app-bell',
  imports: [RouterLink],
  template: `
    <button class="icon-btn bell" [class.ring]="ringing()" (click)="toggle()" [attr.aria-expanded]="open()" aria-haspopup="dialog"
            [attr.aria-label]="'Notifications' + (svc.unread() ? ', ' + svc.unread() + ' unread' : '')">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9M10 21a2 2 0 0 0 4 0" /></svg>
      @if (svc.unread()) { <span class="count">{{ svc.unread() > 99 ? '99+' : svc.unread() }}</span> }
    </button>
    @if (open()) {
      <div class="scrim" (click)="open.set(false)"></div>
      <div class="panel" role="dialog" aria-label="Notifications">
        <header><b>Notifications</b>@if (svc.unread()) { <button class="link" (click)="readAll()">Mark all read</button> }</header>
        <div class="items">
          @for (n of items(); track n.id) {
            <button class="item" [class.unread]="!n.read" (click)="go(n)">
              <span class="ic" [attr.data-c]="n.category">{{ icon[n.category] ?? '🔔' }}</span>
              <span class="txt"><b>{{ n.title }}</b><small>{{ n.body }}</small><em>{{ ago(n.at) }}</em></span>
            </button>
          } @empty {
            <p class="empty">{{ loading() ? 'Loading…' : 'You’re all caught up. 🎉' }}</p>
          }
        </div>
        <a class="all" routerLink="/notifications" (click)="open.set(false)">See all notifications</a>
      </div>
    }
  `,
  styleUrl: './bell.scss',
})
export class Bell {
  protected readonly svc = inject(NotificationsService);
  private router = inject(Router);
  protected readonly icon = CATEGORY_ICON;
  protected readonly ago = ago;
  protected readonly open = signal(false);
  protected readonly items = signal<Alert[]>([]);
  protected readonly loading = signal(false);
  protected readonly ringing = signal(false);

  constructor() {
    let first = true;
    effect(() => {
      this.svc.bumped();
      if (first) {
        first = false;
        return;
      }
      this.ringing.set(true);
      setTimeout(() => this.ringing.set(false), 1000);
    });
  }

  toggle(): void {
    this.open.update((v) => !v);
    if (this.open()) {
      this.loading.set(true);
      this.svc.list({ limit: 8 }).subscribe((r) => {
        this.items.set(r.data);
        this.loading.set(false);
      });
    }
  }

  go(n: Alert): void {
    this.open.set(false);
    if (!n.read) this.svc.markRead([n.id]).subscribe();
    this.router.navigateByUrl(n.link);
  }

  readAll(): void {
    this.svc.markRead('all').subscribe(() => this.items.update((l) => l.map((x) => ({ ...x, read: true }))));
  }

  @HostListener('document:keydown.escape')
  esc(): void {
    this.open.set(false);
  }
}
