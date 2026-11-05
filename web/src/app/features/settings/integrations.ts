import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { AuthService } from '../../core/auth/auth.service';
import { readErrors } from '../../core/http-errors';
import { LeadsService, MetaPage, RazorpayStatus } from '../../core/leads.service';
import { Toast } from '../../shared/toast.service';

/** Settings → Integrations: connect Facebook Pages for Meta Lead Ads. */
@Component({
  selector: 'app-integrations',
  imports: [FormsModule],
  template: `
    <div class="card">
      <div class="head">
        <span class="logo">f</span>
        <div><h3>Meta Lead Ads (Facebook &amp; Instagram)</h3><p class="hint">New leads from your ad forms arrive in Leads within a minute.</p></div>
        @if (pages().length) { <button class="btn-ghost" (click)="sync()" [disabled]="busy()">{{ busy() ? 'Syncing…' : '↻ Sync now' }}</button> }
      </div>

      @for (p of pages(); track p.id) {
        <div class="page">
          <div class="grow">
            <b>{{ p.name || p.page_id }}</b>
            <small>Page ID {{ p.page_id }} · {{ p.last_polled_at ? 'last checked ' + when(p.last_polled_at) : 'not checked yet' }}</small>
            @if (p.last_error) { <small class="bad">{{ p.last_error }}</small> }
          </div>
          <span class="badge" [class.b-good]="p.subscribed && !p.last_error" [class.b-warn]="!p.subscribed || p.last_error">
            @if (p.subscribed && !p.last_error) { <span class="live"></span> Receiving leads } @else { Needs attention }
          </span>
          @if (canEdit) { <button class="btn-ghost sm" (click)="remove(p)">Disconnect</button> }
        </div>
      }

      @if (canEdit) {
        <form class="connect" (ngSubmit)="connect()">
          <h4>{{ pages().length ? 'Connect another Page' : 'Connect your Facebook Page' }}</h4>
          <div class="row-2">
            <div class="field compact" [class.invalid]="errors()['page_id']">
              <label for="m-page">Facebook Page ID</label>
              <input id="m-page" name="page" inputmode="numeric" [(ngModel)]="pageId" placeholder="e.g. 102938475610" />
              @if (errors()['page_id']) { <div class="err">{{ errors()['page_id'] }}</div> }
            </div>
            <div class="field compact" [class.invalid]="errors()['access_token']">
              <label for="m-token">Page access token</label>
              <input id="m-token" name="token" type="password" autocomplete="off" [(ngModel)]="token" />
              @if (errors()['access_token']) { <div class="err">{{ errors()['access_token'] }}</div> }
            </div>
          </div>
          <div class="form-actions plain"><button class="btn" type="submit" [disabled]="busy() || !pageId || !token">Check &amp; connect</button></div>
        </form>
      }

      <details class="help">
        <summary>How to set this up (one time, about 20 minutes)</summary>
        <ol>
          <li>At <b>developers.facebook.com</b> → My Apps → Create app (use case <i>Other</i>, type <i>Business</i>), owned by the DigiFox business portfolio. In App settings → Basic, add the privacy policy URL and copy the <b>App secret</b>.</li>
          <li>In GitHub → repository Settings → Secrets → Actions add <code>META_APP_SECRET</code> (that secret) and <code>META_VERIFY_TOKEN</code> (any long phrase you make up), then run <b>Deploy to Hostinger</b>.
            @if (!webhookReady()) { <div class="bad">Not done yet: the server has no META_APP_SECRET / META_VERIFY_TOKEN.</div> } @else { <div class="good">Done: the server has both keys.</div> }</li>
          <li>In the app add the <b>Webhooks</b> product, choose <b>Page</b>, and enter:
            <div class="copy">Callback URL: <code>{{ webhookUrl() }}</code></div>
            <div class="copy">Verify token: the same phrase as <code>META_VERIFY_TOKEN</code></div>
            Then subscribe to the <b>leadgen</b> field. Switch the app to <b>Live</b>.</li>
          <li>In <b>Business settings → Users → System users</b> add an admin system user, assign it the Page (full control) and the app, then <b>Generate token</b> for the app with <code>leads_retrieval</code>, <code>pages_show_list</code>, <code>pages_read_engagement</code>, <code>pages_manage_metadata</code> and <code>pages_manage_ads</code>, set to never expire.</li>
          <li>In <b>Business settings → Integrations → Leads access</b>, select the Page and give the app (CRM) access.</li>
          <li>Paste the Page ID (Page → About → Page transparency) and the token above. The CRM checks them with Meta and subscribes the Page.</li>
          <li>Test with Meta’s <b>Lead Ads Testing Tool</b>; the test lead should appear in Leads within a minute.</li>
        </ol>
        <p class="hint">Instagram lead ads use the same Facebook Page, so they come in too. The CRM also re-checks every 15 minutes in case a notification was missed. If test leads arrive but real ones don’t, Meta wants Business Verification or Advanced Access for <code>leads_retrieval</code> (App Review).</p>
      </details>
    </div>

    @if (rzp(); as r) {
      <div class="card rz">
        <div class="head">
          <span class="logo rzl">₹</span>
          <div><h3>Razorpay online payments</h3><p class="hint">Clients pay invoices by UPI, card, net banking or wallet; the invoice is marked paid automatically.</p></div>
        </div>
        <div class="checks">
          <div [class.ok]="r.keys">{{ r.keys ? '✓' : '○' }} API keys {{ r.keys ? (r.live ? '(live)' : '(test mode)') : 'not added yet' }}</div>
          <div [class.ok]="r.webhook">{{ r.webhook ? '✓' : '○' }} Webhook secret {{ r.webhook ? 'added' : 'not added yet' }}</div>
        </div>
        <details class="help">
          <summary>How to set this up (one time, about 10 minutes)</summary>
          <ol>
            <li>In the Razorpay dashboard open <b>Settings → API Keys → Generate key</b> (use <i>Test mode</i> first if you want to try it).</li>
            <li>In GitHub → repository Settings → Secrets → Actions add <code>RAZORPAY_KEY_ID</code>, <code>RAZORPAY_KEY_SECRET</code> and <code>RAZORPAY_WEBHOOK_SECRET</code> (a long phrase you make up). Then run <b>Deploy to Hostinger</b>.</li>
            <li>In Razorpay open <b>Settings → Webhooks → Add new webhook</b>:
              <div class="copy">Webhook URL: <code>{{ r.webhook_url }}</code></div>
              <div class="copy">Secret: the same phrase as <code>RAZORPAY_WEBHOOK_SECRET</code></div>
              Active event: <b>payment_link.paid</b>.</li>
            <li>Open any unpaid invoice and press <b>Pay online</b> to test.</li>
          </ol>
          <p class="hint">Never type the key secret into chat or email. Razorpay’s fee for each payment is saved with the payment.</p>
        </details>
      </div>
    }
  `,
  styles: `
    .head { display: flex; align-items: center; gap: 14px; margin-bottom: 8px; flex-wrap: wrap; }
    .head > div { flex: 1; min-width: 200px; }
    .logo { width: 44px; height: 44px; border-radius: 12px; background: #1877f2; color: #fff; display: grid; place-items: center; font-weight: 800; font-size: 24px; font-family: Arial, sans-serif; }
    h3 { font-size: 16px; }
    h4 { font-size: 14px; margin-top: 18px; }
    .hint { color: var(--ink-3); font-size: 13px; }
    .rz { margin-top: 16px; }
    .logo.rzl { background: #0c2451 !important; color: #3395ff !important; }
    .checks { display: flex; gap: 18px; flex-wrap: wrap; margin: 6px 0 4px; font-size: 14px; color: var(--ink-3); }
    .checks .ok { color: #11803b; font-weight: 600; }
    .good { color: #11803b; font-weight: 600; margin-top: 4px; }
    .page { display: flex; align-items: center; gap: 12px; padding: 12px; border: 1px solid var(--line); border-radius: 12px; margin-top: 10px; flex-wrap: wrap; }
    .page .grow { flex: 1; min-width: 200px; }
    .page b { display: block; }
    .page small { display: block; color: var(--ink-3); font-size: 12.5px; }
    .bad { color: #b42318 !important; }
    .connect { margin-top: 8px; border-top: 1px solid var(--line); padding-top: 4px; }
    .form-actions.plain { position: static; padding: 0; margin-top: 14px; }
    .btn-ghost.sm { height: 30px; font-size: 12px; }
    .help { margin-top: 18px; background: var(--bg); border-radius: 12px; padding: 12px 14px; font-size: 13.5px; line-height: 1.6; }
    .help summary { cursor: pointer; font-weight: 600; color: var(--brand-deep); }
    .help ol { margin: 10px 0 8px 18px; }
    .copy { margin: 4px 0; }
    code { background: #fff; border: 1px solid var(--line); border-radius: 6px; padding: 1px 6px; font-size: 12.5px; word-break: break-all; }
  `,
})
export class Integrations {
  private api = inject(LeadsService);
  private toast = inject(Toast);
  protected readonly canEdit = inject(AuthService).can('settings.edit');
  protected readonly pages = signal<MetaPage[]>([]);
  protected readonly webhookUrl = signal('');
  protected readonly webhookReady = signal(false);
  protected readonly rzp = signal<RazorpayStatus | null>(null);
  protected readonly busy = signal(false);
  protected readonly errors = signal<Record<string, string>>({});
  protected pageId = '';
  protected token = '';

  constructor() {
    this.load();
  }

  load(): void {
    this.api.meta().subscribe((m) => {
      this.pages.set(m.pages);
      this.webhookUrl.set(m.webhook_url);
      this.webhookReady.set(m.webhook_ready);
      this.rzp.set(m.razorpay ?? null);
    });
  }

  connect(): void {
    this.busy.set(true);
    this.errors.set({});
    this.api.connectPage(this.pageId.trim(), this.token.trim()).subscribe({
      next: (p) => {
        this.busy.set(false);
        this.pageId = this.token = '';
        this.toast.show(`${p.name} connected.`);
        this.load();
      },
      error: (e) => {
        this.busy.set(false);
        const r = readErrors(e);
        this.errors.set(r.fields);
        if (r.message) this.toast.show(r.message);
      },
    });
  }

  sync(): void {
    this.busy.set(true);
    this.api.syncMeta().subscribe({
      next: (r) => {
        this.busy.set(false);
        this.pages.set(r.pages);
        this.toast.show(r.created ? `${r.created} new lead(s) fetched from Meta.` : 'No new leads. Everything is up to date.');
      },
      error: () => this.busy.set(false),
    });
  }

  remove(p: MetaPage): void {
    if (!confirm(`Disconnect ${p.name}? New leads from this Page will stop arriving.`)) return;
    this.api.removePage(p.id).subscribe(() => this.load());
  }

  when(iso: string): string {
    return new Date(iso).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' });
  }
}
