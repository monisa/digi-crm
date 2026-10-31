import { AfterViewInit, Component, DestroyRef, ElementRef, computed, inject, input, signal } from '@angular/core';
import { money } from '../../../shared/format';

interface Point {
  month: string;
  label: string;
  revenue: number;
  expenses: number;
}

const PAD = { l: 44, r: 6, t: 12, b: 26 };

/**
 * Grouped bars, revenue vs expenses per month. Shows 12 months on wide
 * screens and the last 6 on phones. Hover/tap a month for exact figures;
 * "Table" shows the numbers as a table (accessibility).
 */
@Component({
  selector: 'app-revenue-chart',
  templateUrl: './revenue-chart.html',
  styleUrl: './revenue-chart.scss',
})
export class RevenueChart implements AfterViewInit {
  data = input.required<Point[]>();

  protected readonly money = money;
  protected readonly narrow = signal(false);
  protected readonly drawn = signal(false);
  protected readonly asTable = signal(false);
  protected readonly hover = signal<number | null>(null);

  protected readonly points = computed(() => (this.narrow() ? this.data().slice(-6) : this.data()));
  /** Narrower drawing on phones so labels stay readable when scaled down. */
  protected readonly W = computed(() => (this.narrow() ? 380 : 720));
  protected readonly H = computed(() => (this.narrow() ? 250 : 290));

  /** Rounded axis maximum and 4 gridlines. */
  protected readonly scale = computed(() => {
    const top = Math.max(1, ...this.points().flatMap((p) => [p.revenue, p.expenses]));
    const mag = Math.pow(10, Math.floor(Math.log10(top)));
    const max = Math.ceil(top / mag / 2) * 2 * mag;
    return { max, ticks: [0, 0.25, 0.5, 0.75, 1].map((f) => f * max) };
  });

  protected readonly bars = computed(() => {
    const pts = this.points();
    const h = this.H();
    const cw = (this.W() - PAD.l - PAD.r) / pts.length;
    const bw = Math.min(18, cw * 0.3);
    return pts.map((p, i) => {
      const cx = PAD.l + cw * i + cw / 2;
      return {
        ...p,
        cx,
        x0: PAD.l + cw * i,
        cw,
        rev: this.bar(cx - bw - 1, p.revenue, bw, h, i),
        exp: this.bar(cx + 1, p.expenses, bw, h, i),
        top: this.y(Math.max(p.revenue, p.expenses), h),
      };
    });
  });

  private host = inject(ElementRef<HTMLElement>);
  private destroyRef = inject(DestroyRef);

  ngAfterViewInit(): void {
    const ro = new ResizeObserver(([e]) => this.narrow.set(e!.contentRect.width < 560));
    ro.observe(this.host.nativeElement);
    this.destroyRef.onDestroy(() => ro.disconnect());

    const io = new IntersectionObserver(([e]) => {
      if (e!.isIntersecting) {
        this.drawn.set(true);
        io.disconnect();
      }
    });
    io.observe(this.host.nativeElement);
    this.destroyRef.onDestroy(() => io.disconnect());
  }

  y(v: number, h = this.H()): number {
    return PAD.t + (h - PAD.t - PAD.b) * (1 - v / this.scale().max);
  }

  axis(v: number): string {
    if (v >= 1e7) return `${+(v / 1e7).toFixed(1)}Cr`;
    if (v >= 1e5) return `${+(v / 1e5).toFixed(1)}L`;
    if (v >= 1e3) return `${+(v / 1e3).toFixed(0)}k`;
    return `${v}`;
  }

  get baseline(): number {
    return this.y(0);
  }

  get padL(): number {
    return PAD.l;
  }

  get padR(): number {
    return PAD.r;
  }

  /** Bar with 4px rounded top, anchored on the baseline. */
  private bar(x: number, v: number, w: number, h: number, i: number) {
    const top = this.y(v, h);
    const bh = this.y(0, h) - top;
    const r = Math.min(4, bh, w / 2);
    const d = bh <= 0 ? '' : `M${x},${top + bh}V${top + r}q0,-${r} ${r},-${r}h${w - 2 * r}q${r},0 ${r},${r}V${top + bh}z`;
    return { d, delay: `${i * 0.04}s` };
  }
}
