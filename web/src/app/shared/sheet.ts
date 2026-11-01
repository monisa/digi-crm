import { Component, HostListener, input, output } from '@angular/core';

/** Side panel on laptops, bottom sheet on phones. <app-sheet title="..." (closed)="...">content</app-sheet> */
@Component({
  selector: 'app-sheet',
  template: `
    <div class="scrim" (click)="closed.emit()"></div>
    <aside class="sheet" role="dialog" [attr.aria-label]="title()" [style.--w]="width()">
      <header>
        <div><h3>{{ title() }}</h3>@if (subtitle()) { <p>{{ subtitle() }}</p> }</div>
        <button type="button" class="x" (click)="closed.emit()" aria-label="Close">✕</button>
      </header>
      <div class="body"><ng-content /></div>
    </aside>
  `,
  styles: `
    .scrim { position: fixed; inset: 0; background: rgba(10, 12, 16, 0.45); backdrop-filter: blur(2px); z-index: 50; animation: fade 0.25s; }
    .sheet {
      position: fixed; top: 0; right: 0; bottom: 0; width: min(var(--w, 560px), 100%); background: var(--card); z-index: 55;
      display: flex; flex-direction: column; box-shadow: -20px 0 50px rgba(0, 0, 0, 0.2); animation: slide 0.35s var(--ease);
    }
    header { display: flex; justify-content: space-between; gap: 12px; padding: 20px 24px 12px; border-bottom: 1px solid var(--line); }
    h3 { font-size: 18px; }
    header p { color: var(--ink-3); font-size: 13px; margin-top: 2px; }
    .x { width: 36px; height: 36px; border-radius: 10px; border: 1px solid var(--line); background: #fff; cursor: pointer; flex: none; }
    .x:hover { border-color: var(--brand); color: var(--brand-deep); }
    .body { padding: 8px 24px 24px; overflow-y: auto; flex: 1; }
    @keyframes slide { from { transform: translateX(40px); opacity: 0; } }
    @keyframes fade { from { opacity: 0; } }
    @media (max-width: 860px) {
      .sheet { top: auto; max-height: 92vh; width: 100%; border-radius: 22px 22px 0 0; animation: up 0.35s var(--ease); }
      header, .body { padding-left: 18px; padding-right: 18px; }
      @keyframes up { from { transform: translateY(40px); opacity: 0; } }
    }
  `,
})
export class Sheet {
  title = input.required<string>();
  subtitle = input<string>('');
  width = input<string>('560px');
  closed = output<void>();

  @HostListener('document:keydown.escape')
  onEsc(): void {
    this.closed.emit();
  }
}
