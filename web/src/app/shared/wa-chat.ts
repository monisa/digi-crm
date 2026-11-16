import { Component, ElementRef, OnDestroy, OnInit, computed, inject, input, output, signal, viewChild } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { readErrors } from '../core/http-errors';
import { WaMessage, WaTemplate, WaThread, WaWho, WhatsAppService } from '../core/whatsapp.service';
import { Sheet } from './sheet';
import { Toast } from './toast.service';

/**
 * A WhatsApp conversation in a side panel: the messages, delivery ticks, and sending.
 * Free text within 24 hours of their last message; otherwise an approved template.
 */
@Component({
  selector: 'app-wa-chat',
  imports: [FormsModule, Sheet],
  template: `
    <app-sheet [title]="thread()?.contact?.name ?? 'WhatsApp'" [subtitle]="thread() ? '+' + thread()!.contact.phone : ''" width="560px" (closed)="closed.emit()">
      @if (thread(); as t) {
        @if (!t.connected) {
          <div class="note">WhatsApp Business is not connected yet. An admin can connect it in <b>Settings → Integrations</b>.</div>
        }
        <div class="thread" #box>
          @for (m of t.data; track m.id; let i = $index) {
            @if (day(i); as d) { <div class="day"><span>{{ d }}</span></div> }
            <div class="row" [class.out]="m.direction === 'out'">
              <div class="bub" [class.fail]="m.status === 'failed'">
                @if (m.type === 'template') { <span class="tpl">Template · {{ m.template }}</span> }
                <p>{{ m.body }}</p>
                <span class="meta">@if (m.by && m.direction === 'out') { {{ m.by }} · }{{ time(m.at) }}
                  @if (m.direction === 'out') { <span class="tk" [class.read]="m.status === 'read'" [title]="tickTitle(m)">{{ m.status === 'sent' ? '✓' : m.status === 'failed' ? '!' : '✓✓' }}</span> }</span>
                @if (m.error) { <small class="err">{{ m.error }}</small> }
              </div>
            </div>
          } @empty { <p class="empty">No WhatsApp messages with {{ t.contact.name }} yet.</p> }
        </div>

        @if (t.can_send && t.connected) {
          <div class="composer">
            @if (t.window_open && mode() === 'text') {
              <p class="win">💬 They wrote in the last 24 hours, so you can reply freely until {{ time(t.window_ends_at!) }}.
                <button class="linkish" (click)="useTemplate()">Use a template</button></p>
              <div class="crow">
                <textarea rows="2" [(ngModel)]="text" placeholder="Type a reply…" (keydown.enter)="enter($event)" maxlength="4000"></textarea>
                <button class="btn" (click)="sendText()" [disabled]="busy() || !text.trim()">Send</button>
              </div>
            } @else {
              @if (!t.window_open) { <p class="win">📋 To start a conversation (or more than 24 hours after their last message), WhatsApp only allows an <b>approved template</b>.</p> }
              @else { <p class="win"><button class="linkish" (click)="mode.set('text')">← Back to a normal reply</button></p> }
              <div class="tplrow">
                <select class="select-sm" [ngModel]="picked()?.name ?? ''" (ngModelChange)="pick($event)" aria-label="Template">
                  <option value="">{{ templates() === null ? 'Loading templates…' : templates()!.length ? 'Choose a template…' : 'No approved templates yet' }}</option>
                  @for (x of templates() ?? []; track x.name + x.language) { <option [value]="x.name">{{ x.name }} ({{ x.language }})</option> }
                </select>
                <button class="btn-ghost sm" (click)="loadTemplates(true)" title="Refresh the list from Meta">↻</button>
              </div>
              @if (picked(); as p) {
                @for (n of range(p.header_vars); track n) {
                  <div class="field compact"><label [for]="'hv' + n">Heading {{ '{{' + (n + 1) + '}}' }}</label><input [id]="'hv' + n" [(ngModel)]="hv[n]" /></div>
                }
                @for (n of range(p.body_vars); track n) {
                  <div class="field compact"><label [for]="'bv' + n">Value {{ '{{' + (n + 1) + '}}' }}</label><input [id]="'bv' + n" [(ngModel)]="bv[n]" /></div>
                }
                <div class="preview"><span class="tpl">Preview</span>@if (p.header) { <b>{{ fill(p.header, hv) }}</b> }<p>{{ fill(p.body, bv) }}</p>@if (p.footer) { <small>{{ p.footer }}</small> }</div>
                <div class="form-actions plain"><button class="btn" (click)="sendTemplate()" [disabled]="busy() || !ready()">Send template</button></div>
              }
              @if (tplError()) { <p class="err">{{ tplError() }}</p> }
            }
          </div>
        }
      } @else if (error()) { <p class="err">{{ error() }}</p> } @else { <p class="empty">Loading…</p> }
    </app-sheet>
  `,
  styles: `
    .note { background: #fef4e6; color: #8a4b08; border-radius: 12px; padding: 10px 12px; font-size: 13.5px; margin: 10px 0; }
    .thread { margin: 12px -4px 0; padding: 12px 8px; max-height: 46vh; overflow-y: auto; border-radius: 14px;
      background: radial-gradient(circle at 90% 0%, rgba(37, 211, 102, 0.06), transparent 40%), var(--bg); }
    .day { text-align: center; margin: 10px 0 8px; } .day span { font-size: 11.5px; font-weight: 600; color: var(--ink-3); background: var(--card); padding: 3px 10px; border-radius: 99px; border: 1px solid var(--line); }
    .row { display: flex; margin: 4px 0; } .row.out { justify-content: flex-end; }
    .bub { max-width: 82%; background: var(--card); border: 1px solid var(--line); border-radius: 14px 14px 14px 4px; padding: 7px 11px 5px; }
    .out .bub { background: #dcf8c6; border-color: #c4ecaa; border-radius: 14px 14px 4px 14px; }
    .bub.fail { border-color: #f3b4b4; background: #fff1f1; }
    .bub p { white-space: pre-wrap; word-wrap: break-word; font-size: 14px; line-height: 1.45; }
    .tpl { display: block; font-size: 10.5px; font-weight: 700; color: #11803b; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 2px; }
    .meta { display: block; text-align: right; font-size: 10.5px; color: var(--ink-3); margin-top: 2px; }
    .tk { margin-left: 3px; font-weight: 700; letter-spacing: -2px; } .tk.read { color: #1f6fe5; }
    .err { color: #b42318; font-size: 12.5px; display: block; margin-top: 4px; }
    .empty { color: var(--ink-3); text-align: center; padding: 30px 0; font-size: 14px; }
    .composer { margin-top: 12px; }
    .win { font-size: 12.5px; color: var(--ink-2); margin-bottom: 8px; line-height: 1.5; }
    .linkish { background: none; border: 0; color: var(--brand-deep); font: inherit; font-weight: 600; cursor: pointer; padding: 0; }
    .crow { display: flex; gap: 8px; align-items: flex-end; }
    .crow textarea { flex: 1; resize: none; field-sizing: content; max-height: 140px; padding: 10px 12px; border: 1px solid var(--line); border-radius: 12px; font: inherit; font-size: 14px; background: var(--card); color: var(--ink); }
    .crow textarea:focus { outline: none; border-color: var(--brand); box-shadow: 0 0 0 4px rgba(237, 143, 33, 0.12); }
    .tplrow { display: flex; gap: 8px; } .tplrow select { flex: 1; }
    .sm { padding: 6px 12px; }
    .preview { margin-top: 12px; background: #dcf8c6; border: 1px solid #c4ecaa; border-radius: 12px; padding: 10px 12px; font-size: 14px; }
    .preview p { white-space: pre-wrap; } .preview small { display: block; color: var(--ink-3); margin-top: 6px; }
    .form-actions.plain { position: static; padding: 0; margin-top: 12px; }
  `,
})
export class WaChat implements OnInit, OnDestroy {
  readonly who = input.required<WaWho>();
  readonly firstName = input<string>('');
  readonly closed = output<void>();
  private api = inject(WhatsAppService);
  private toast = inject(Toast);
  protected readonly thread = signal<WaThread | null>(null);
  protected readonly error = signal('');
  protected readonly busy = signal(false);
  protected readonly mode = signal<'text' | 'template'>('text');
  protected readonly templates = signal<WaTemplate[] | null>(null);
  protected readonly picked = signal<WaTemplate | null>(null);
  protected readonly tplError = signal('');
  protected text = '';
  protected hv: string[] = [];
  protected bv: string[] = [];
  private box = viewChild<ElementRef<HTMLElement>>('box');
  private timer?: ReturnType<typeof setInterval>;
  private lastCount = 0;

  protected readonly ready = computed(() => !!this.picked());

  ngOnInit(): void {
    this.load(true);
    this.timer = setInterval(() => !document.hidden && this.load(false), 8000);
  }

  ngOnDestroy(): void {
    clearInterval(this.timer);
  }

  private load(first: boolean): void {
    this.api.thread(this.who()).subscribe({
      next: (t) => {
        const wasOpen = this.thread()?.window_open;
        this.thread.set(t);
        if (first && !t.window_open && t.can_send && t.connected) this.useTemplate();
        // They just wrote back: switch to a normal reply (unless a template is half filled in).
        if (!first && t.window_open && !wasOpen && !this.picked()) this.mode.set('text');
        if (t.data.length !== this.lastCount) setTimeout(() => this.scroll());
        this.lastCount = t.data.length;
      },
      error: (e) => this.error.set(readErrors(e).message || 'Could not open the conversation.'),
    });
  }

  private scroll(): void {
    const el = this.box()?.nativeElement;
    if (el) el.scrollTop = el.scrollHeight;
  }

  useTemplate(): void {
    this.mode.set('template');
    if (this.templates() === null) this.loadTemplates(false);
  }

  loadTemplates(fresh: boolean): void {
    this.tplError.set('');
    this.api.templates(fresh).subscribe({
      next: (r) => this.templates.set(r.data),
      error: (e) => {
        this.templates.set([]);
        this.tplError.set(readErrors(e).message || 'Could not load the templates from Meta.');
      },
    });
  }

  pick(name: string): void {
    const t = (this.templates() ?? []).find((x) => x.name === name) ?? null;
    this.picked.set(t);
    this.hv = Array(t?.header_vars ?? 0).fill('');
    this.bv = Array(t?.body_vars ?? 0).fill('');
    // {{1}} is nearly always the person's name.
    if (t?.body_vars) this.bv[0] = this.firstName() || (this.thread()?.contact.name.split(' ')[0] ?? '');
  }

  enter(e: Event): void {
    const k = e as KeyboardEvent;
    if (!k.shiftKey) {
      k.preventDefault();
      this.sendText();
    }
  }

  sendText(): void {
    const text = this.text.trim();
    if (!text) return;
    this.post({ text }, () => (this.text = ''));
  }

  sendTemplate(): void {
    const p = this.picked()!;
    if ([...this.hv, ...this.bv].some((v) => !v?.trim())) {
      this.toast.show('Fill in every value of the template.');
      return;
    }
    this.post({ template: p.name, header_values: this.hv, body_values: this.bv }, () => {
      this.picked.set(null);
      this.mode.set(this.thread()?.window_open ? 'text' : 'template');
    });
  }

  private post(body: Parameters<WhatsAppService['send']>[1], done: () => void): void {
    this.busy.set(true);
    this.api.send(this.who(), body).subscribe({
      next: (m) => {
        this.busy.set(false);
        done();
        this.thread.update((t) => (t ? { ...t, data: [...t.data, m] } : t));
        setTimeout(() => this.scroll());
        this.toast.show('Sent on WhatsApp.');
      },
      error: (e) => {
        this.busy.set(false);
        const r = readErrors(e);
        this.toast.show(Object.values(r.fields)[0] || r.message || 'Could not send.');
      },
    });
  }

  range(n: number): number[] {
    return Array.from({ length: n }, (_, i) => i);
  }

  fill(text: string, vals: string[]): string {
    return text.replace(/\{\{(\d+)\}\}/g, (m, n) => vals[+n - 1]?.trim() || m);
  }

  tickTitle(m: WaMessage): string {
    return { sent: 'Sent', delivered: 'Delivered', read: 'Read', failed: 'Not delivered', received: '' }[m.status];
  }

  day(i: number): string | null {
    const l = this.thread()?.data ?? [];
    const d = new Date(l[i]!.at).toDateString();
    if (i > 0 && new Date(l[i - 1]!.at).toDateString() === d) return null;
    if (d === new Date().toDateString()) return 'Today';
    if (d === new Date(Date.now() - 86400000).toDateString()) return 'Yesterday';
    return new Date(l[i]!.at).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
  }

  time(iso: string): string {
    const d = new Date(iso);
    const t = d.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' });
    return d.toDateString() === new Date().toDateString() ? t : `${d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' })}, ${t}`;
  }
}
