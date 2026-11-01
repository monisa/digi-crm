import { Injectable, signal } from '@angular/core';

/** One short message at the bottom of the screen. */
@Injectable({ providedIn: 'root' })
export class Toast {
  readonly message = signal('');
  private timer?: ReturnType<typeof setTimeout>;

  show(msg: string): void {
    clearTimeout(this.timer);
    this.message.set(msg);
    this.timer = setTimeout(() => this.message.set(''), 3500);
  }
}
