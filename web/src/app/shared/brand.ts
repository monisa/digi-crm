import { Component, input } from '@angular/core';

/** The DigiFox Pro logo: the fox plus the name as real (sans-serif) text, so it matches the app's font. */
@Component({
  selector: 'app-brand',
  template: `
    <img src="fox.png" alt="" [style.height.px]="size()" />
    @if (!iconOnly()) {
      <span class="name" [style.font-size.px]="size() * 0.45"><b>DIGIFOX PRO</b><small>SOLUTIONS</small></span>
    }
  `,
  styles: `
    :host { display: inline-flex; align-items: center; gap: 10px; color: #fff; line-height: 1; }
    img { width: auto; display: block; flex: none; }
    .name { display: flex; flex-direction: column; font-family: inherit; }
    b { font-weight: 800; letter-spacing: 0.06em; white-space: nowrap; }
    small { font-size: 0.42em; font-weight: 600; letter-spacing: 0.62em; margin-top: 0.45em; opacity: 0.85; white-space: nowrap; }
  `,
  host: { role: 'img', 'aria-label': 'DigiFox Pro Solutions' },
})
export class Brand {
  readonly size = input(40);
  readonly iconOnly = input(false);
}
