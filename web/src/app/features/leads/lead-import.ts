import { Component, inject, output, signal } from '@angular/core';
import { readErrors } from '../../core/http-errors';
import { LeadsService } from '../../core/leads.service';
import { Sheet } from '../../shared/sheet';

@Component({
  selector: 'app-lead-import',
  imports: [Sheet],
  template: `
    <app-sheet title="Import your lead sheet" subtitle="Bring existing leads from Google Sheets into the CRM" width="520px" (closed)="closed.emit()">
      <ol class="steps">
        <li>Open the lead sheet in Google Sheets.</li>
        <li><b>File → Download → Comma-separated values (.csv)</b></li>
        <li>Choose that file below.</li>
      </ol>
      <p class="hint">Columns are matched by name: Date, AD Name, Name, Phone Number, Email ID, Location (City), Call Status, Status, Package, Requirement, Follow-up Status, Notes / Remarks.
        Leads whose phone or email is already in the CRM are skipped, so importing twice is safe.</p>

      <label class="drop" [class.has]="file()">
        <input type="file" accept=".csv,text/csv" (change)="pick($event)" />
        @if (file(); as f) { <b>{{ f.name }}</b><small>{{ (f.size / 1024).toFixed(0) }} KB · tap to change</small> }
        @else { <b>Choose CSV file</b><small>or drop it here</small> }
      </label>

      @if (result(); as r) {
        <div class="alert success"><b>{{ r.created }}</b> lead(s) imported · {{ r.duplicates }} already in the CRM (skipped)</div>
        @if (r.errors.length) {
          <div class="alert error">@for (e of r.errors; track e) { <div>{{ e }}</div> }</div>
        }
      }
      @if (error()) { <div class="alert error">{{ error() }}</div> }

      <div class="form-actions">
        <button type="button" class="btn-ghost" (click)="result() ? done.emit() : closed.emit()">{{ result() ? 'Done' : 'Cancel' }}</button>
        <button class="btn" (click)="upload()" [disabled]="!file() || busy()">{{ busy() ? 'Importing…' : 'Import' }}</button>
      </div>
    </app-sheet>
  `,
  styles: `
    .steps { margin: 8px 0 8px 18px; line-height: 1.8; font-size: 14px; }
    .hint { color: var(--ink-3); font-size: 12.5px; line-height: 1.55; }
    .drop { margin-top: 16px; display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 28px 16px; border: 2px dashed var(--line); border-radius: 16px; cursor: pointer; text-align: center; transition: border-color 0.2s, background 0.2s; position: relative; }
    .drop:hover, .drop.has { border-color: var(--brand); background: var(--brand-soft); }
    .drop input { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
    .drop small { color: var(--ink-3); }
  `,
})
export class LeadImport {
  closed = output<void>();
  done = output<void>();
  private api = inject(LeadsService);
  protected readonly file = signal<File | null>(null);
  protected readonly busy = signal(false);
  protected readonly result = signal<{ created: number; duplicates: number; errors: string[] } | null>(null);
  protected readonly error = signal('');

  pick(e: Event): void {
    this.file.set((e.target as HTMLInputElement).files?.[0] ?? null);
    this.result.set(null);
    this.error.set('');
  }

  upload(): void {
    this.busy.set(true);
    this.error.set('');
    this.api.import(this.file()!).subscribe({
      next: (r) => {
        this.result.set(r);
        this.busy.set(false);
      },
      error: (e) => {
        const r = readErrors(e);
        this.error.set(r.fields['file'] || r.message);
        this.busy.set(false);
      },
    });
  }
}
