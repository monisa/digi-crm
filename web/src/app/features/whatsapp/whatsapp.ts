import { Component, OnDestroy, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { Subject, debounceTime } from 'rxjs';
import { AuthService } from '../../core/auth/auth.service';
import { WaChat as WaChatRow, WaWho, WhatsAppService } from '../../core/whatsapp.service';
import { WaChat } from '../../shared/wa-chat';

/** WhatsApp inbox: every conversation with leads and clients, newest first. */
@Component({
  selector: 'app-whatsapp',
  imports: [FormsModule, RouterLink, WaChat],
  template: `
    <div class="page-head">
      <div><h1>WhatsApp</h1><p>Messages with leads and clients on the company WhatsApp Business number.</p></div>
    </div>

    @if (connected() === false) {
      <div class="card setup">
        <span class="wl">💬</span>
        <div><b>WhatsApp Business is not connected yet</b>
          <p>@if (auth.can('settings.edit')) { Connect the company number in <a routerLink="/settings" [queryParams]="{ tab: 'integrations' }">Settings → Integrations</a> (about 30 minutes, one time). } @else { Ask the admin to connect it in Settings → Integrations. }</p></div>
      </div>
    }

    <div class="card list">
      <input class="input-sm wide" type="search" placeholder="Search name or number" [(ngModel)]="search" (ngModelChange)="search$.next()" />
      @for (c of chats(); track c.phone; let i = $index) {
        <button class="chat" [class.unread]="c.unread" (click)="open(c)" [style.animation-delay.s]="i * 0.02">
          <span class="av" [class.cl]="c.client_id" [class.unk]="!c.lead_id && !c.client_id">{{ initials(c.name) }}</span>
          <span class="mid">
            <span class="top"><b>{{ c.name }}</b><small>{{ when(c.last.at) }}</small></span>
            <span class="sub">
              <span class="txt">@if (c.last.direction === 'out') { <i class="tk" [class.read]="c.last.status === 'read'" [class.bad]="c.last.status === 'failed'">{{ c.last.status === 'sent' ? '✓' : c.last.status === 'failed' ? '!' : '✓✓' }}</i> }{{ c.last.text }}</span>
              @if (c.unread) { <i class="badge-n">{{ c.unread }}</i> }
            </span>
            <span class="tag">{{ c.lead_id ? 'Lead' : c.client_id ? 'Client' : 'New number' }} · +{{ c.phone }}</span>
          </span>
        </button>
      } @empty {
        <div class="empty-box">@if (loading()) { Loading… } @else { <b>No WhatsApp messages yet</b>Open a lead or a client and press <b>💬 WhatsApp</b> to send the first message. }</div>
      }
    </div>

    @if (who(); as w) { <app-wa-chat [who]="w" (closed)="close()" /> }
  `,
  styles: `
    .setup { display: flex; gap: 14px; align-items: center; margin-bottom: 14px; border: 1px dashed #25a35a; background: var(--x-f1fbf4); }
    .setup .wl { font-size: 28px; } .setup p { color: var(--ink-2); font-size: 13.5px; margin-top: 2px; } .setup a { color: var(--brand-deep); font-weight: 600; }
    .list { padding: 12px; } .wide { width: 100%; margin-bottom: 6px; }
    .chat { display: flex; gap: 12px; width: 100%; align-items: center; border: 0; background: none; padding: 12px 8px; border-radius: 12px; cursor: pointer; text-align: left; font: inherit; color: inherit; border-bottom: 1px solid var(--line); animation: rise 0.3s var(--ease) both; }
    .chat:hover { background: var(--bg); } .chat:last-child { border-bottom: 0; }
    .av { width: 44px; height: 44px; border-radius: 50%; display: grid; place-items: center; flex: none; font-weight: 700; font-size: 14px; color: #fff; background: linear-gradient(135deg, #1f9d55, #25d366); }
    .av.cl { background: linear-gradient(135deg, var(--brand-deep), var(--brand)); } .av.unk { background: #c9cdd6; }
    .mid { flex: 1; min-width: 0; display: grid; gap: 2px; }
    .top { display: flex; justify-content: space-between; gap: 8px; } .top b { font-size: 14.5px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; } .top small { color: var(--ink-3); font-size: 11.5px; flex: none; }
    .sub { display: flex; justify-content: space-between; gap: 8px; align-items: center; font-size: 13px; color: var(--ink-3); }
    .txt { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .unread .txt { color: var(--ink); font-weight: 600; }
    .tk { font-style: normal; font-weight: 700; margin-right: 4px; letter-spacing: -2px; } .tk.read { color: #1f6fe5; } .tk.bad { color: var(--x-b42318); }
    .tag { font-size: 11.5px; color: var(--ink-3); }
    .badge-n { font-style: normal; min-width: 20px; height: 20px; padding: 0 6px; border-radius: 99px; background: #25a35a; color: #fff; font-size: 11px; font-weight: 700; display: grid; place-items: center; flex: none; }
    @media (prefers-reduced-motion: reduce) { .chat { animation: none; } }
  `,
})
export class WhatsAppInbox implements OnDestroy {
  private api = inject(WhatsAppService);
  private router = inject(Router);
  protected readonly auth = inject(AuthService);
  protected readonly chats = signal<WaChatRow[]>([]);
  protected readonly loading = signal(true);
  protected readonly connected = signal<boolean | null>(null);
  protected readonly who = signal<WaWho | null>(null);
  protected search = '';
  protected readonly search$ = new Subject<void>();
  private timer = setInterval(() => !document.hidden && !this.who() && this.load(), 15000);

  constructor() {
    this.search$.pipe(debounceTime(300)).subscribe(() => this.load());
    const phone = inject(ActivatedRoute).snapshot.queryParamMap.get('phone');
    if (phone) this.who.set({ phone });
    this.load();
  }

  ngOnDestroy(): void {
    clearInterval(this.timer);
  }

  load(): void {
    this.api.inbox(this.search.trim()).subscribe((r) => {
      this.chats.set(r.data);
      this.connected.set(r.connected);
      this.loading.set(false);
    });
  }

  open(c: WaChatRow): void {
    this.who.set(c.lead_id ? { lead_id: c.lead_id } : c.client_id ? { client_id: c.client_id } : { phone: c.phone });
  }

  close(): void {
    this.who.set(null);
    this.router.navigate([], { queryParams: {}, replaceUrl: true });
    this.load();
    this.api.status().subscribe();
  }

  initials(n: string): string {
    // Letters only ("Anjali (website)" → "AW"); a bare number shows #.
    const w = n.replace(/[^\p{L}\s]/gu, ' ').split(/\s+/).filter(Boolean);
    return w.length ? w.map((x) => x[0]).slice(0, 2).join('').toUpperCase() : '#';
  }

  when(iso: string): string {
    const d = new Date(iso);
    return d.toDateString() === new Date().toDateString()
      ? d.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' })
      : d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' });
  }
}
