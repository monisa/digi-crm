import { Directive, ElementRef, effect, inject, input } from '@angular/core';

/** Animates a number from its previous value to the new one: <b [countUp]="148" [format]="fn"></b> */
@Directive({ selector: '[countUp]' })
export class CountUp {
  countUp = input.required<number>();
  format = input<(n: number) => string>((n) => Math.round(n).toLocaleString('en-IN'));

  private el = inject(ElementRef<HTMLElement>);
  private current = 0;
  private frame = 0;

  constructor() {
    effect(() => {
      const target = this.countUp();
      const fmt = this.format();
      const reduce = typeof matchMedia !== 'undefined' && matchMedia('(prefers-reduced-motion: reduce)').matches;
      cancelAnimationFrame(this.frame);
      if (reduce) {
        this.current = target;
        this.el.nativeElement.textContent = fmt(target);
        return;
      }
      const start = this.current;
      const t0 = performance.now();
      const step = (t: number) => {
        const p = Math.min((t - t0) / 1200, 1);
        this.current = start + (target - start) * (1 - Math.pow(1 - p, 3));
        this.el.nativeElement.textContent = fmt(this.current);
        if (p < 1) this.frame = requestAnimationFrame(step);
      };
      this.frame = requestAnimationFrame(step);
    });
  }
}
