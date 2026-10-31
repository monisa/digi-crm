import { Component } from '@angular/core';
import { RouterOutlet } from '@angular/router';

/**
 * Login / register / password screens (Figma: dark flowing-lines background,
 * logo top-left, tagline panel on the left, white form card on the right).
 */
@Component({
  selector: 'app-auth-layout',
  imports: [RouterOutlet],
  templateUrl: './auth-layout.html',
  styleUrl: './auth-layout.scss',
})
export class AuthLayout {
  /** Flowing contour lines for the background, generated once. */
  protected readonly lines = Array.from({ length: 22 }, (_, i) => {
    const x0 = -200 + i * 95;
    const amp = 60 + ((i * 37) % 90);
    const pts: string[] = [];
    for (let y = -100; y <= 1100; y += 50) {
      const x = x0 + Math.sin(y / 160 + i * 0.7) * amp + Math.cos(y / 90 + i) * 25;
      pts.push(`${x.toFixed(1)},${y}`);
    }
    return { d: 'M' + pts.join(' L'), o: 0.08 + ((i * 13) % 10) / 70, w: i % 4 === 0 ? 2 : 1 };
  });
}
