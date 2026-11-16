import { Component, computed, input, signal } from '@angular/core';

export interface DonutSlice { key: string; label: string; value: number; color: string }

/**
 * Ring chart with the total in the middle and a legend with counts.
 * Hover or focus a slice / legend row to see its share in the centre.
 */
@Component({
  selector: 'app-donut',
  template: `
    <div class="wrap" [class.small]="size() < 120">
      <svg [attr.width]="size()" [attr.height]="size()" [attr.viewBox]="'0 0 ' + size() + ' ' + size()" role="img" [attr.aria-label]="summary()">
        <circle class="track" [attr.cx]="c()" [attr.cy]="c()" [attr.r]="r()" [attr.stroke-width]="stroke()" />
        @for (a of arcs(); track a.key) {
          <circle class="arc" [attr.cx]="c()" [attr.cy]="c()" [attr.r]="r()" [attr.stroke]="a.color" [attr.stroke-width]="hover() === a.key ? stroke() + 4 : stroke()"
            [attr.stroke-dasharray]="a.len + ' ' + (circ() - a.len)" [attr.stroke-dashoffset]="-a.start" [class.dim]="hover() && hover() !== a.key"
            [attr.transform]="'rotate(-90 ' + c() + ' ' + c() + ')'" (mouseenter)="hover.set(a.key)" (mouseleave)="hover.set(null)">
            <title>{{ a.label }}: {{ a.value }} ({{ a.pct }}%)</title>
          </circle>
        }
      </svg>
      <div class="mid" [style.width.px]="size()" [style.height.px]="size()" aria-hidden="true">
        @if (active(); as a) { <b>{{ a.value }}</b><span>{{ a.label }}</span><span>{{ a.pct }}%</span> }
        @else { <b>{{ total() }}</b><span>{{ caption() }}</span> }
      </div>
      @if (legend()) {
        <ul class="legend">
          @for (s of slicesPct(); track s.key) {
            <li tabindex="0" (mouseenter)="hover.set(s.key)" (mouseleave)="hover.set(null)" (focus)="hover.set(s.key)" (blur)="hover.set(null)" [class.on]="hover() === s.key">
              <i [style.background]="s.color"></i><span>{{ s.label }}</span><b>{{ s.value }}</b>@if (!compact()) { <small>{{ s.pct }}%</small> }
            </li>
          }
        </ul>
      }
    </div>
  `,
  styles: `
    .wrap { display: flex; align-items: center; gap: 28px; position: relative; flex-wrap: wrap; }
    .wrap.small { gap: 14px; flex-wrap: nowrap; }
    svg { flex: none; overflow: visible; }
    .track { fill: none; stroke: var(--line); }
    .arc { fill: none; transition: stroke-width 0.15s, opacity 0.15s; cursor: default; }
    .arc.dim { opacity: 0.35; }
    .mid { position: absolute; left: 0; top: 0; display: grid; place-content: center; text-align: center; pointer-events: none; padding: 0 18%; }
    .mid b { font-size: 34px; font-weight: 800; letter-spacing: -0.02em; line-height: 1.05; color: var(--ink); }
    .mid span { font-size: 12.5px; color: var(--ink-2); font-weight: 600; white-space: nowrap; }
    .small .mid b { font-size: 19px; } .small .mid span { font-size: 9.5px; line-height: 1.2; }
    .legend { list-style: none; display: grid; gap: 4px; min-width: 0; flex: 1 1 180px; max-width: 340px; }
    .legend li { display: grid; grid-template-columns: 12px 1fr auto auto; align-items: center; gap: 10px; font-size: 14px; color: var(--ink-2); padding: 5px 8px; border-radius: 8px; outline: none; }
    .legend li.on, .legend li:focus-visible { background: var(--bg); }
    .legend i { width: 12px; height: 12px; border-radius: 3px; }
    .legend b { color: var(--ink); font-variant-numeric: tabular-nums; } .legend small { color: var(--ink-3); font-size: 12px; min-width: 34px; text-align: right; font-variant-numeric: tabular-nums; }
    .small .legend li { font-size: 12.5px; padding: 2px 4px; gap: 7px; grid-template-columns: 10px 1fr auto; } .small .legend i { width: 10px; height: 10px; }
    @media (prefers-reduced-motion: reduce) { .arc { transition: none; } }
  `,
})
export class Donut {
  readonly slices = input.required<DonutSlice[]>();
  readonly size = input(200);
  readonly caption = input('Total');
  readonly legend = input(true);
  readonly compact = input(false);
  protected readonly hover = signal<string | null>(null);

  protected readonly stroke = computed(() => Math.max(10, Math.round(this.size() * 0.12)));
  protected readonly c = computed(() => this.size() / 2);
  protected readonly r = computed(() => (this.size() - this.stroke() - 6) / 2);
  protected readonly circ = computed(() => 2 * Math.PI * this.r());
  protected readonly total = computed(() => this.slices().reduce((n, s) => n + s.value, 0));
  protected readonly slicesPct = computed(() => this.slices().map((s) => ({ ...s, pct: this.total() ? Math.round((s.value / this.total()) * 100) : 0 })));

  /** Each slice as a dash on the ring, with a 2px gap between neighbours. */
  protected readonly arcs = computed(() => {
    const t = this.total();
    const shown = this.slicesPct().filter((s) => s.value > 0);
    const gap = shown.length > 1 ? 2 : 0;
    let at = 0;
    return shown.map((s) => {
      const full = (s.value / t) * this.circ();
      const a = { ...s, start: at + gap / 2, len: Math.max(0.5, full - gap) };
      at += full;
      return a;
    });
  });

  protected readonly active = computed(() => this.slicesPct().find((s) => s.key === this.hover()) ?? null);
  protected readonly summary = computed(() => `${this.caption()}: ${this.total()}. ` + this.slicesPct().map((s) => `${s.label} ${s.value}`).join(', '));
}
