import { Component, input } from '@angular/core';

/** Temporary page for modules that are built in later phases. */
@Component({
  selector: 'app-placeholder',
  template: `
    <div class="card box">
      <h1>{{ title() }}</h1>
      <p>This module is scheduled for <b>{{ phase() }}</b>.</p>
    </div>
  `,
  styles: `
    .box { margin-top: 12px; text-align: center; padding: 48px 20px; }
    h1 { font-size: 24px; margin-bottom: 8px; }
    p { color: var(--ink-2); }
  `,
})
export class Placeholder {
  // Bound from route data (withComponentInputBinding).
  title = input('');
  phase = input('');
}
