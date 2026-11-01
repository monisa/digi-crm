import { Component, inject, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { readErrors } from '../../core/http-errors';
import { LeadOptions, LeadsService } from '../../core/leads.service';
import { Sheet } from '../../shared/sheet';

/** Admin: dropdown choices and automatic assignment of new Meta leads. */
@Component({
  selector: 'app-lead-options',
  imports: [FormsModule, Sheet],
  template: `
    <app-sheet title="Lead options" subtitle="Dropdown choices and automatic assignment" width="560px" (closed)="closed.emit()">
      <h4>Assign new Meta leads automatically</h4>
      <label class="check"><input type="checkbox" [(ngModel)]="auto" /> Share new leads in turn (round-robin) between:</label>
      <div class="pool" [class.off]="!auto">
        @for (u of options().assignees; track u.id) {
          <button type="button" class="chip" [class.on]="pool.has(u.id)" [disabled]="!auto" (click)="togglePool(u.id)">{{ u.name }}</button>
        }
      </div>
      <p class="hint">People only see leads assigned to them unless you give them “All leads” in Settings → Users &amp; access.</p>

      @for (l of lists; track l.key) {
        <div class="field">
          <label [for]="'lo-' + l.key">{{ l.label }} (one per line)</label>
          <textarea [id]="'lo-' + l.key" rows="4" [(ngModel)]="text[l.key]"></textarea>
        </div>
      }
      @if (error()) { <div class="alert error">{{ error() }}</div> }
      <div class="form-actions">
        <button type="button" class="btn-ghost" (click)="closed.emit()">Cancel</button>
        <button class="btn" (click)="save()" [disabled]="busy()">Save</button>
      </div>
    </app-sheet>
  `,
  styles: `
    h4 { font-size: 14px; margin: 10px 0 0; }
    .pool { display: flex; flex-wrap: wrap; gap: 8px; margin: 10px 0; transition: opacity 0.2s; }
    .pool.off { opacity: 0.45; }
    .chip { border: 1px solid var(--line); background: #fff; padding: 7px 12px; border-radius: 99px; font-size: 13px; cursor: pointer; transition: all 0.2s; }
    .chip.on { background: var(--brand); border-color: var(--brand); color: #fff; }
    .hint { color: var(--ink-3); font-size: 12.5px; }
  `,
})
export class LeadOptionsForm {
  options = input.required<LeadOptions>();
  closed = output<void>();
  saved = output<LeadOptions>();
  private api = inject(LeadsService);

  protected readonly lists = [
    { key: 'call_statuses', label: 'Call status choices' },
    { key: 'packages', label: 'Packages' },
    { key: 'followup_statuses', label: 'Follow-up status choices' },
    { key: 'lost_reasons', label: 'Reasons a lead is not interested' },
  ] as const;
  protected text: Record<string, string> = {};
  protected auto = false;
  protected pool = new Set<number>();
  protected readonly busy = signal(false);
  protected readonly error = signal('');

  ngOnInit(): void {
    const o = this.options();
    for (const l of this.lists) this.text[l.key] = (o[l.key] as string[]).join('\n');
    this.auto = o.auto_assign;
    this.pool = new Set(o.assign_pool);
  }

  togglePool(id: number): void {
    this.pool.has(id) ? this.pool.delete(id) : this.pool.add(id);
  }

  save(): void {
    const body: Record<string, unknown> = { auto_assign: this.auto, assign_pool: [...this.pool] };
    for (const l of this.lists) body[l.key] = [...new Set(this.text[l.key]!.split('\n').map((s) => s.trim()).filter(Boolean))];
    this.busy.set(true);
    this.api.saveOptions(body as Partial<LeadOptions>).subscribe({
      next: (o) => this.saved.emit(o as LeadOptions),
      error: (e) => {
        const r = readErrors(e);
        this.error.set(r.message || Object.values(r.fields)[0] || 'Could not save.');
        this.busy.set(false);
      },
    });
  }
}
