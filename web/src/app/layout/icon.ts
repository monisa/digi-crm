import { Component, input } from '@angular/core';
import { IconName } from '../core/navigation';

@Component({
  selector: 'app-icon',
  template: `
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      @switch (name()) {
        @case ('dashboard') {
          <rect x="3" y="3" width="7" height="9" rx="2" /><rect x="14" y="3" width="7" height="5" rx="2" />
          <rect x="14" y="12" width="7" height="9" rx="2" /><rect x="3" y="16" width="7" height="5" rx="2" />
        }
        @case ('leads') { <path d="M22 3H2l8 9.5V19l4 2v-8.5L22 3z" /> }
        @case ('clients') { <path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6" /> }
        @case ('employees') {
          <circle cx="9" cy="7" r="4" /><path d="M2 21v-1a6 6 0 0 1 12 0v1M17 11a3 3 0 1 0 0-6M22 21v-1a5 5 0 0 0-4-4.9" />
        }
        @case ('expenses') { <rect x="2" y="5" width="20" height="14" rx="2" /><path d="M2 10h20M6 15h4" /> }
        @case ('bell') { <path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9M10 21a2 2 0 0 0 4 0" /> }
        @case ('settings') {
          <circle cx="12" cy="12" r="3" />
          <path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1" />
        }
        @case ('menu') { <path d="M4 6h16M4 12h16M4 18h16" /> }
        @case ('campaigns') { <path d="M3 11v2a1 1 0 0 0 1 1h2l5 4V6L6 10H4a1 1 0 0 0-1 1zM15 9a3 3 0 0 1 0 6M18 6a7 7 0 0 1 0 12" /> }
        @case ('invoices') { <path d="M6 2h12v20l-3-2-3 2-3-2-3 2V2zM9 7h6M9 11h6M9 15h4" /> }
        @case ('tickets') { <path d="M3 8a2 2 0 0 0 0 4v4h18v-4a2 2 0 0 1 0-4V4H3v4zM13 4v16" /> }
        @case ('leave') { <rect x="3" y="4" width="18" height="17" rx="2" /><path d="M8 2v4M16 2v4M3 10h18M9 15l2 2 4-4" /> }
        @case ('logout') { <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9" /> }
      }
    </svg>
  `,
  styles: `:host { display: inline-flex; width: 20px; height: 20px; flex: none; } svg { width: 100%; height: 100%; }`,
})
export class Icon {
  name = input.required<IconName>();
}
