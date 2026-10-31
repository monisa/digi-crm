const inr = new Intl.NumberFormat('en-IN', { maximumFractionDigits: 0 });

/** ₹18.45 L / ₹1.20 Cr / ₹45,000: the way Indian businesses read amounts. */
export function money(n: number): string {
  const abs = Math.abs(n);
  const sign = n < 0 ? '−' : '';
  if (abs >= 1e7) return `${sign}₹${(abs / 1e7).toFixed(2)} Cr`;
  if (abs >= 1e5) return `${sign}₹${(abs / 1e5).toFixed(2)} L`;
  return `${sign}₹${inr.format(abs)}`;
}

export function count(n: number): string {
  return inr.format(n);
}

/** Percentage change, or null when there is nothing to compare with. */
export function change(current: number, previous: number): number | null {
  if (!previous) return null;
  return Math.round(((current - previous) / previous) * 100);
}

export function waited(minutes: number): string {
  if (minutes < 60) return `${minutes}m`;
  const h = Math.floor(minutes / 60);
  if (h < 24) return `${h}h ${minutes % 60}m`;
  return `${Math.floor(h / 24)}d ${h % 24}h`;
}

export function initials(name: string | null | undefined): string {
  return (name ?? '?').split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0]!.toUpperCase()).join('');
}
