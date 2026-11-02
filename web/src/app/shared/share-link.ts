import { Component, computed, input, output, signal } from '@angular/core';

/** A link with Copy and WhatsApp buttons (WhatsApp opens a chat with `phone` if given). */
@Component({
  selector: 'app-share-link',
  template: `
    <div class="box">
      <input class="url" [value]="url()" readonly (focus)="$any($event.target).select()" aria-label="Form link" />
      <div class="btns">
        <button type="button" class="btn" (click)="copy()">{{ copied() ? 'Copied ✓' : 'Copy link' }}</button>
        <a class="btn wa" [href]="waUrl()" target="_blank" rel="noopener">WhatsApp</a>
        @if (canReset()) { <button type="button" class="btn-ghost" (click)="reset.emit()">New link</button> }
      </div>
    </div>
  `,
  styles: `
    .box { display: grid; gap: 10px; }
    .url { width: 100%; padding: 12px 14px; border: 1px solid var(--line); border-radius: 12px; background: var(--bg); font: 13px ui-monospace, monospace; color: var(--ink); }
    .btns { display: flex; gap: 8px; flex-wrap: wrap; }
    .wa { background: #1fa855; text-decoration: none; }
    .wa:hover { background: #178a45; }
  `,
})
export class ShareLink {
  url = input.required<string>();
  message = input('');
  phone = input<string | null>(null);
  canReset = input(false);
  reset = output<void>();
  protected readonly copied = signal(false);

  protected readonly waUrl = computed(() => {
    const text = encodeURIComponent(`${this.message()}${this.message() ? '\n' : ''}${this.url()}`);
    let digits = (this.phone() ?? '').replace(/\D/g, '');
    if (digits.length === 10) digits = '91' + digits; // Indian mobile without country code
    return `https://wa.me/${digits}?text=${text}`;
  });

  async copy(): Promise<void> {
    try {
      await navigator.clipboard.writeText(this.url());
    } catch {
      const el = document.createElement('textarea');
      el.value = this.url();
      document.body.appendChild(el);
      el.select();
      document.execCommand('copy');
      el.remove();
    }
    this.copied.set(true);
    setTimeout(() => this.copied.set(false), 2000);
  }
}

/** Full address of a public onboarding form link. */
export function formUrl(token: string): string {
  return `${location.origin}/form/${token}`;
}
