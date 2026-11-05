import { Component, ElementRef, HostListener, Injectable, computed, effect, inject, signal, viewChild } from '@angular/core';
import { FormsModule } from '@angular/forms';

export interface ConfirmOptions {
  title: string;
  message?: string;
  /** Button text, e.g. "Delete". */
  confirmText?: string;
  cancelText?: string;
  /** Red button and warning icon, for deleting. */
  danger?: boolean;
  /** Ask the person to type this word (e.g. DELETE) before the button works. */
  typeToConfirm?: string;
}

export interface PromptOptions extends Omit<ConfirmOptions, 'typeToConfirm'> {
  label?: string;
  value?: string;
  /** Quick choices shown as chips above the text box. */
  suggestions?: string[];
  required?: boolean;
}

interface Open {
  kind: 'confirm' | 'prompt';
  opts: ConfirmOptions & PromptOptions;
  resolve: (v: unknown) => void;
}

/**
 * In-app popups instead of the browser's confirm()/prompt():
 *   if (!(await this.dialog.confirm({ title: 'Delete lead?', danger: true, confirmText: 'Delete' }))) return;
 *   const reason = await this.dialog.prompt({ title: 'Why not interested?', suggestions: [...] });
 */
@Injectable({ providedIn: 'root' })
export class Dialog {
  readonly current = signal<Open | null>(null);

  confirm(opts: ConfirmOptions): Promise<boolean> {
    return this.open('confirm', opts) as Promise<boolean>;
  }

  prompt(opts: PromptOptions): Promise<string | null> {
    return this.open('prompt', opts) as Promise<string | null>;
  }

  private open(kind: Open['kind'], opts: ConfirmOptions & PromptOptions): Promise<unknown> {
    this.current()?.resolve(kind === 'confirm' ? false : null); // only one at a time
    return new Promise((resolve) => this.current.set({ kind, opts, resolve }));
  }

  close(value: unknown): void {
    const c = this.current();
    this.current.set(null);
    c?.resolve(value);
  }
}

/** Rendered once at the app root. */
@Component({
  selector: 'app-dialog-host',
  imports: [FormsModule],
  template: `
    @if (dialog.current(); as d) {
      <div class="scrim" (click)="cancel()"></div>
      <div class="modal" role="alertdialog" aria-modal="true" [attr.aria-labelledby]="'dlg-t'" [attr.aria-describedby]="'dlg-m'" [class.danger]="d.opts.danger">
        <div class="icon" aria-hidden="true">{{ d.opts.danger ? '!' : '?' }}</div>
        <h2 id="dlg-t">{{ d.opts.title }}</h2>
        @if (d.opts.message) { <p id="dlg-m" class="msg">{{ d.opts.message }}</p> }

        <form (ngSubmit)="ok()">
          @if (d.kind === 'prompt') {
            @if (d.opts.suggestions?.length) {
              <div class="chips">
                @for (s of d.opts.suggestions; track s) {
                  <button type="button" class="chip" [class.on]="text() === s" (click)="text.set(s)">{{ s }}</button>
                }
              </div>
            }
            <div class="field">
              @if (d.opts.label) { <label for="dlg-in">{{ d.opts.label }}</label> }
              <input #box id="dlg-in" name="text" [ngModel]="text()" (ngModelChange)="text.set($event)" autocomplete="off" />
            </div>
          }
          @if (d.opts.typeToConfirm; as word) {
            <div class="field">
              <label for="dlg-in">Type <b>{{ word }}</b> to confirm</label>
              <input #box id="dlg-in" name="typed" [ngModel]="text()" (ngModelChange)="text.set($event)" autocomplete="off" autocapitalize="characters" [placeholder]="word" />
            </div>
          }
          <div class="btns">
            <button type="button" class="btn-ghost" (click)="cancel()">{{ d.opts.cancelText || 'Cancel' }}</button>
            <button #okBtn type="submit" class="btn" [class.red]="d.opts.danger" [disabled]="!ready()">{{ d.opts.confirmText || 'OK' }}</button>
          </div>
        </form>
      </div>
    }
  `,
  styles: `
    .scrim { position: fixed; inset: 0; z-index: 90; background: rgba(10, 12, 16, 0.5); backdrop-filter: blur(3px); animation: fade 0.2s; }
    .modal {
      position: fixed; z-index: 91; left: 50%; top: 50%; transform: translate(-50%, -50%); width: min(440px, calc(100% - 32px));
      background: var(--card); border-radius: 20px; padding: 26px 24px 20px; box-shadow: 0 30px 80px rgba(0, 0, 0, 0.35);
      text-align: center; animation: pop 0.28s var(--ease);
    }
    .icon {
      width: 56px; height: 56px; margin: 0 auto 14px; border-radius: 50%; display: grid; place-items: center;
      font-size: 26px; font-weight: 800; color: var(--brand-deep); background: var(--brand-soft);
    }
    .danger .icon { color: #c0262d; background: #fdecec; box-shadow: 0 0 0 8px rgba(192, 38, 45, 0.06); }
    h2 { font-size: 19px; letter-spacing: -0.01em; }
    .msg { color: var(--ink-2); font-size: 14px; line-height: 1.55; margin-top: 8px; white-space: pre-line; }
    form { margin-top: 16px; text-align: left; }
    .field { margin-top: 4px; }
    .field label { display: block; font-size: 13px; color: var(--ink-2); margin-bottom: 6px; }
    .field input {
      width: 100%; padding: 12px 14px; border: 1px solid var(--line); border-radius: 12px; font: inherit; background: var(--card); color: var(--ink);
    }
    .field input:focus { outline: none; border-color: var(--brand); box-shadow: 0 0 0 4px rgba(237, 143, 33, 0.15); }
    .chips { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
    .chip { border: 1px solid var(--line); background: var(--card); padding: 7px 12px; border-radius: 99px; cursor: pointer; font-size: 13px; color: var(--ink-2); }
    .chip.on, .chip:hover { border-color: var(--brand); color: var(--brand-deep); }
    .btns { display: flex; gap: 10px; margin-top: 20px; }
    .btns button { flex: 1; justify-content: center; }
    .btn.red { background: #d92d20; box-shadow: 0 8px 20px rgba(217, 45, 32, 0.25); }
    .btn.red:hover:not(:disabled) { background: #b42318; }
    .btn:disabled { opacity: 0.45; cursor: not-allowed; box-shadow: none; }
    @keyframes fade { from { opacity: 0; } }
    @keyframes pop { from { opacity: 0; transform: translate(-50%, -46%) scale(0.96); } }
    @media (max-width: 560px) {
      .modal { top: auto; bottom: 0; transform: none; left: 0; width: 100%; border-radius: 22px 22px 0 0; padding-bottom: calc(20px + env(safe-area-inset-bottom)); animation: up 0.3s var(--ease); }
      @keyframes up { from { transform: translateY(40px); opacity: 0; } }
    }
    @media (prefers-reduced-motion: reduce) { .scrim, .modal { animation: none; } }
  `,
})
export class DialogHost {
  protected readonly dialog = inject(Dialog);
  protected readonly text = signal('');
  private box = viewChild<ElementRef<HTMLInputElement>>('box');
  private okBtn = viewChild<ElementRef<HTMLButtonElement>>('okBtn');

  protected readonly ready = computed(() => {
    const d = this.dialog.current();
    if (!d) return false;
    if (d.opts.typeToConfirm) return this.text().trim().toUpperCase() === d.opts.typeToConfirm.toUpperCase();
    if (d.kind === 'prompt' && d.opts.required) return !!this.text().trim();
    return true;
  });

  private lastFocus: HTMLElement | null = null;

  constructor() {
    effect(() => {
      const d = this.dialog.current();
      if (d) {
        this.lastFocus = document.activeElement as HTMLElement | null;
        this.text.set(d.opts.value ?? '');
        // Focus the text box, or the safe button for plain confirms.
        setTimeout(() => (this.box()?.nativeElement ?? this.okBtn()?.nativeElement)?.focus());
      } else {
        this.lastFocus?.focus?.();
      }
    });
  }

  @HostListener('document:keydown.escape')
  cancel(): void {
    const d = this.dialog.current();
    if (d) this.dialog.close(d.kind === 'confirm' ? false : null);
  }

  ok(): void {
    const d = this.dialog.current();
    if (!d || !this.ready()) return;
    this.dialog.close(d.kind === 'confirm' ? true : this.text().trim());
  }
}
