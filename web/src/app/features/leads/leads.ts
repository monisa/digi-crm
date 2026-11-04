import { CdkDrag, CdkDragDrop, CdkDropList, CdkDropListGroup } from '@angular/cdk/drag-drop';
import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute } from '@angular/router';
import { Subject, debounceTime } from 'rxjs';
import { AuthService } from '../../core/auth/auth.service';
import { readErrors } from '../../core/http-errors';
import { Lead, LeadList, LeadOptions, LeadStatus, LeadsService, waNumber } from '../../core/leads.service';
import { initials } from '../../shared/format';
import { Toast } from '../../shared/toast.service';
import { LeadAdd } from './lead-add';
import { LeadImport } from './lead-import';
import { LeadOptionsForm } from './lead-options';
import { LeadSheet } from './lead-sheet';

type View = '' | 'uncontacted' | 'followups' | 'today';

@Component({
  selector: 'app-leads',
  imports: [FormsModule, CdkDropListGroup, CdkDropList, CdkDrag, LeadSheet, LeadAdd, LeadImport, LeadOptionsForm],
  templateUrl: './leads.html',
  styleUrl: './leads.scss',
})
export class Leads {
  private api = inject(LeadsService);
  private toast = inject(Toast);
  protected readonly auth = inject(AuthService);
  protected readonly initials = initials;
  protected readonly wa = waNumber;

  protected readonly options = signal<LeadOptions | null>(null);
  protected readonly result = signal<LeadList | null>(null);
  protected readonly loading = signal(true);
  protected readonly layout = signal<'list' | 'board'>(localStorage.getItem('dfp_leads_layout') === 'board' ? 'board' : 'list');

  protected view: View = '';
  protected search = '';
  protected status = '';
  protected callStatus = '';
  protected assigned = '';
  protected page = 1;

  protected readonly openId = signal<number | null>(null);
  protected readonly adding = signal(false);
  protected readonly importing = signal(false);
  protected readonly editingOptions = signal(false);
  protected readonly selected = signal(new Set<number>());
  protected bulkTo = '';

  protected readonly seesAll = computed(() => this.auth.user()?.leads_scope === 'all');
  /** Ticking leads is for reassigning (all-leads users) or deleting. */
  protected readonly canSelect = computed(() => this.seesAll() || this.auth.can('leads.delete'));
  /** "Select all N leads": the action covers every lead matching the filters, not just this page. */
  protected readonly allMatching = signal(false);
  protected readonly stages = computed(() => Object.entries(this.options()?.statuses ?? {}) as [LeadStatus, string][]);
  protected readonly board = computed(() => {
    const cols: Record<string, Lead[]> = {};
    for (const [k] of this.stages()) cols[k] = [];
    for (const l of this.result()?.data ?? []) cols[l.status]?.push(l);
    return cols;
  });

  private search$ = new Subject<void>();

  constructor() {
    this.api.options().subscribe((o) => this.options.set(o));
    this.search$.pipe(debounceTime(300)).subscribe(() => this.load(1));
    const q = inject(ActivatedRoute).snapshot.queryParamMap;
    this.view = (q.get('view') as View) ?? '';
    const open = q.get('open');
    if (open) this.openId.set(+open);
    this.load();
  }

  load(page = this.page): void {
    this.page = page;
    this.loading.set(true);
    this.api
      .list({
        view: this.view, search: this.search, status: this.status, call_status: this.callStatus, assigned: this.assigned,
        page, per_page: this.layout() === 'board' ? 300 : 50,
      })
      .subscribe({
        next: (r) => {
          this.result.set(r);
          this.loading.set(false);
          this.selected.set(new Set());
          this.allMatching.set(false);
        },
        error: () => this.loading.set(false),
      });
  }

  setView(v: View): void {
    this.view = this.view === v ? '' : v;
    this.load(1);
  }

  setLayout(l: 'list' | 'board'): void {
    this.layout.set(l);
    try {
      localStorage.setItem('dfp_leads_layout', l);
    } catch {}
    this.load(1);
  }

  onSearch(): void {
    this.search$.next();
  }

  /** Keep the list in step after edits in the side panel. */
  changed(l: Lead | null): void {
    if (!l) return this.load();
    this.result.update((r) => (r ? { ...r, data: r.data.map((x) => (x.id === l.id ? l : x)) } : r));
  }

  drop(e: CdkDragDrop<Lead[]>, to: LeadStatus): void {
    const lead = e.item.data as Lead;
    if (lead.status === to) return;
    let lost_reason: string | null = null;
    if (to === 'lost') {
      lost_reason = prompt('Why is this lead not interested?', this.options()?.lost_reasons[0] ?? '');
      if (lost_reason === null) return;
    }
    this.changed({ ...lead, status: to }); // move it straight away
    this.api.update(lead.id, { status: to, ...(to === 'lost' ? { lost_reason } : {}) }).subscribe({
      next: (u) => this.changed(u),
      error: (err) => {
        this.changed(lead);
        this.toast.show(readErrors(err).message || 'Could not move the lead.');
      },
    });
  }

  toggle(id: number, ev: Event): void {
    ev.stopPropagation();
    this.selected.update((s) => {
      const n = new Set(s);
      n.has(id) ? n.delete(id) : n.add(id);
      return n;
    });
  }

  clearSelection(): void {
    this.selected.set(new Set());
    this.allMatching.set(false);
  }

  toggleAll(): void {
    const ids = this.result()?.data.map((l) => l.id) ?? [];
    this.allMatching.set(false);
    this.selected.set(this.selected().size === ids.length ? new Set() : new Set(ids));
  }

  pageAllTicked(): boolean {
    const n = this.result()?.data.length ?? 0;
    return n > 0 && this.selected().size === n;
  }

  filtered(): boolean {
    return !!(this.view || this.search || this.status || this.callStatus || this.assigned);
  }

  private filters() {
    return { view: this.view, search: this.search, status: this.status, call_status: this.callStatus, assigned: this.assigned };
  }

  bulkDelete(): void {
    const all = this.allMatching();
    const n = all ? (this.result()?.meta?.total ?? 0) : this.selected().size;
    const what = `${n} lead${n === 1 ? '' : 's'}${all && this.filtered() ? ' matching your filters' : ''}`;
    if (n > 20 || all) {
      const typed = prompt(`Delete ${what}? Their call notes and history go too. This cannot be undone.\n\nType DELETE to confirm.`);
      if (typed?.trim().toUpperCase() !== 'DELETE') return;
    } else if (!confirm(`Delete ${what}? This cannot be undone.`)) return;

    const body = all ? { all: true, ...Object.fromEntries(Object.entries(this.filters()).filter(([, v]) => v)) } : { ids: [...this.selected()] };
    this.api.bulkDelete(body).subscribe({
      next: (r) => {
        this.toast.show(`${r.deleted} lead${r.deleted === 1 ? '' : 's'} deleted.`);
        this.openId.set(null);
        this.load(1);
      },
      error: (e) => this.toast.show(readErrors(e).message || 'Could not delete.'),
    });
  }

  bulkAssign(): void {
    const ids = [...this.selected()];
    this.api.assign(ids, this.bulkTo === 'none' ? null : +this.bulkTo).subscribe({
      next: (r) => {
        this.toast.show(`${r.updated} lead(s) reassigned.`);
        this.bulkTo = '';
        this.load();
      },
      error: (e) => this.toast.show(readErrors(e).message || 'Could not reassign.'),
    });
  }

  export(): void {
    this.api.export(this.filters()).subscribe({
      error: () => this.toast.show('Export failed.'),
    });
  }

  created(l: Lead): void {
    this.adding.set(false);
    this.toast.show('Lead added.');
    this.load(1);
    this.openId.set(l.id);
  }

  imported(): void {
    this.importing.set(false);
    this.load(1);
  }

  optionsSaved(o: LeadOptions): void {
    this.options.set(o);
    this.editingOptions.set(false);
    this.api.options(true).subscribe((x) => this.options.set(x));
    this.toast.show('Lead options saved.');
  }

  label(s: LeadStatus): string {
    return this.options()?.statuses[s] ?? s;
  }

  date(iso: string | null, withTime = false): string {
    if (!iso) return '';
    const d = new Date(iso);
    return withTime
      ? d.toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })
      : d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' });
  }

  due(iso: string | null): 'overdue' | 'today' | '' {
    if (!iso) return '';
    const d = new Date(iso);
    const end = new Date();
    end.setHours(23, 59, 59);
    if (d < new Date()) return 'overdue';
    return d <= end ? 'today' : '';
  }

  age(iso: string): string {
    const m = Math.floor((Date.now() - new Date(iso).getTime()) / 60000);
    if (m < 60) return `${m}m ago`;
    if (m < 1440) return `${Math.floor(m / 60)}h ago`;
    return `${Math.floor(m / 1440)}d ago`;
  }
}
