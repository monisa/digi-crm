import { HttpClient } from '@angular/common/http';
import { Component, inject, signal } from '@angular/core';
import { API_BASE } from '../../core/api.config';
import { readErrors } from '../../core/http-errors';
import { Toast } from '../../shared/toast.service';

interface BackupFile { name: string; size: number; at: string; kind: 'database' | 'files' }

/** Settings → Backups (admins). */
@Component({
  selector: 'app-backups',
  template: `
    <div class="card">
      <div class="head">
        <span class="ic">🛟</span>
        <div><h3>Backups</h3>
          <p class="hint">The database is backed up <b>every night</b> (last {{ keep().database }} kept) and uploaded files <b>every Sunday</b> (last {{ keep().files }} kept). They are stored privately on the server.</p></div>
      </div>
      <div class="acts">
        <button class="btn" (click)="run('database')" [disabled]="busy()">@if (busy() === 'database') { <span class="spinner"></span> Backing up… } @else { Back up database now }</button>
        <button class="btn-ghost" (click)="run('files')" [disabled]="busy()">@if (busy() === 'files') { <span class="spinner"></span> Zipping… } @else { Back up files now }</button>
      </div>

      @for (b of list(); track b.name) {
        <div class="row">
          <span class="k" [class.f]="b.kind === 'files'">{{ b.kind === 'database' ? 'DB' : 'ZIP' }}</span>
          <div class="grow"><b>{{ when(b.at) }}</b><small>{{ b.kind === 'database' ? 'Database' : 'Uploaded files' }} · {{ size(b.size) }}</small></div>
          <button class="btn-ghost sm" (click)="download(b)">⬇ Download</button>
        </div>
      } @empty { <p class="hint pad">{{ loading() ? 'Loading…' : 'No backups yet. The first one runs tonight, or press “Back up database now”.' }}</p> }

      <details class="help">
        <summary>Keep a copy off the server, and how to restore</summary>
        <ul>
          <li><b>Off the server:</b> once a week, download the newest database backup and save it to Google Drive. If the hosting account were ever lost, this copy is what brings the CRM back. Hostinger’s own backups (hPanel → Files → Backups) are a second safety net.</li>
          <li><b>To restore:</b> in hPanel open <b>phpMyAdmin</b> → choose database <code>u311568037_crm</code> → <b>Import</b> → pick the <code>.sql.gz</code> file → Go. Everyone will need to log in again afterwards.</li>
          <li>Backups contain everything (salaries, client details), so keep downloaded copies private.</li>
        </ul>
      </details>
    </div>
  `,
  styles: `
    .head { display: flex; gap: 14px; align-items: flex-start; margin-bottom: 14px; }
    .ic { width: 44px; height: 44px; border-radius: 12px; display: grid; place-items: center; background: var(--brand-soft); font-size: 22px; flex: none; }
    .head h3 { font-size: 16px; margin-bottom: 2px; }
    .hint { color: var(--ink-3); font-size: 13px; line-height: 1.5; }
    .acts { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
    .row { display: flex; align-items: center; gap: 12px; padding: 10px 4px; border-top: 1px solid var(--line); animation: rise 0.3s var(--ease) both; }
    .k { width: 40px; height: 40px; border-radius: 10px; display: grid; place-items: center; font-size: 11px; font-weight: 800; background: #eaf1fd; color: #1f4fb3; flex: none; }
    .k.f { background: #e8f7ee; color: #11803b; }
    .grow { flex: 1; min-width: 0; } .grow b { display: block; font-size: 14px; } .grow small { color: var(--ink-3); font-size: 12.5px; }
    .sm { padding: 6px 12px; font-size: 13px; }
    .pad { padding: 12px 4px; }
    .help { margin-top: 14px; font-size: 13.5px; color: var(--ink-2); } .help summary { cursor: pointer; font-weight: 600; color: var(--ink); }
    .help ul { margin: 10px 0 0 18px; display: grid; gap: 8px; line-height: 1.5; }
  `,
})
export class Backups {
  private http = inject(HttpClient);
  private toast = inject(Toast);
  protected readonly list = signal<BackupFile[]>([]);
  protected readonly keep = signal({ database: 14, files: 4 });
  protected readonly loading = signal(true);
  protected readonly busy = signal<'' | 'database' | 'files'>('');

  constructor() {
    this.http.get<{ data: BackupFile[]; keep: { database: number; files: number } }>(`${API_BASE}/settings/backups`).subscribe({
      next: (r) => {
        this.list.set(r.data);
        this.keep.set(r.keep);
        this.loading.set(false);
      },
      error: (e) => {
        this.loading.set(false);
        this.toast.show(readErrors(e).message);
      },
    });
  }

  run(kind: 'database' | 'files'): void {
    this.busy.set(kind);
    this.http.post<{ name: string; data: BackupFile[] }>(`${API_BASE}/settings/backups`, { kind }).subscribe({
      next: (r) => {
        this.busy.set('');
        this.list.set(r.data);
        this.toast.show('Backup ready. Download it to keep a copy off the server.');
      },
      error: (e) => {
        this.busy.set('');
        this.toast.show(readErrors(e).message || 'Backup failed.');
      },
    });
  }

  download(b: BackupFile): void {
    this.http.get(`${API_BASE}/settings/backups/${encodeURIComponent(b.name)}`, { responseType: 'blob' }).subscribe((blob) => {
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = b.name;
      a.click();
      setTimeout(() => URL.revokeObjectURL(a.href), 1000);
    });
  }

  when(iso: string): string {
    return new Date(iso).toLocaleString('en-IN', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
  }

  size(n: number): string {
    return n > 1048576 ? `${(n / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(n / 1024))} KB`;
  }
}
