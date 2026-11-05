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
import { Dialog } from '../../shared/dialog';

type View = '' | 'uncontacted' | 'followups' | 'today';
type DatePreset = '' | 'today' | 'yesterday' | '7d' | '30d' | 'this_month' | 'last_month' | 'custom';

/** Everything the Filter panel sets. */
interface LeadFilter { date: DatePreset; from: string; to: string; ad: string; callStatus: string; status: string; assigned: string }
const NO_FILTER: LeadFilter = { date: '', from: '', to: '', ad: '', callStatus: '', status: '', assigned: '' };

export const DATE_PRESETS: [DatePreset, string][] = [
  ['', 'Any time'], ['today', 'Today'], ['yesterday', 'Yesterday'], ['7d', 'Last 7 days'], ['30d', 'Last 30 days'],
  ['this_month', 'This month'], ['last_month', 'Last month'], ['custom', 'Pick dates…'],
];

@Component({
  selector: 'app-leads',
  imports: [FormsModule, CdkDropListGroup, CdkDropList, CdkDrag, LeadSheet, LeadAdd, LeadImport, LeadOptionsForm],
  templateUrl: './leads.html',
  styleUrl: './leads.scss',
})
export class Leads {
  protected readonly dialog = inject(Dialog);
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
  protected page = 1;
  protected readonly pageSizes = [25, 50, 100, 200];
  /** Column sort; every visit starts with the newest leads first. */
  protected readonly sort = signal<{ key: 'date' | 'service' | 'assigned'; dir: 'asc' | 'desc' }>({ key: 'date', dir: 'desc' });

  sortBy(key: 'date' | 'service' | 'assigned'): void {
    const s = this.sort();
    this.sort.set(s.key === key ? { key, dir: s.dir === 'asc' ? 'desc' : 'asc' } : { key, dir: key === 'date' ? 'desc' : 'asc' });
    this.load(1);
  }

  sortKey(): string {
    return `${this.sort().key}:${this.sort().dir}`;
  }

  setSortKey(v: string): void {
    const [key, dir] = v.split(':') as ['date' | 'service' | 'assigned', 'asc' | 'desc'];
    this.sort.set({ key, dir });
    this.load(1);
  }

  arrow(key: string): string {
    const s = this.sort();
    return s.key !== key ? '↕' : s.dir === 'asc' ? '↑' : '↓';
  }

  ariaSort(key: string): string {
    const s = this.sort();
    return s.key !== key ? 'none' : s.dir === 'asc' ? 'ascending' : 'descending';
  }
  protected readonly pageSize = signal<number>(this.savedSize());

  private savedSize(): number {
    try {
      const n = +(localStorage.getItem('dfp_leads_page_size') ?? 50);
      return [25, 50, 100, 200].includes(n) ? n : 50;
    } catch {
      return 50;
    }
  }

  setPageSize(n: number): void {
    this.pageSize.set(n);
    try {
      localStorage.setItem('dfp_leads_page_size', String(n));
    } catch {}
    this.load(1);
  }

  /** "51–100 of 240" */
  protected rangeLabel(): string {
    const m = this.result()?.meta;
    if (!m) return '';
    const from = (m.page - 1) * this.pageSize() + 1;
    return `${from}–${Math.min(m.total, from + this.pageSize() - 1)} of ${m.total}`;
  }

  /** Applied filters, and the copy being edited in the panel. */
  protected readonly f = signal<LeadFilter>({ ...NO_FILTER });
  protected draft: LeadFilter = { ...NO_FILTER };
  protected readonly filterOpen = signal(false);
  protected readonly presets = DATE_PRESETS;
  protected readonly ads = computed(() => Object.entries(this.result()?.ads ?? {}));
  protected readonly activeCount = computed(() => this.chips().length);

  // Kept for the template and bulk actions.
  protected get status(): string { return this.f().status; }
  protected get callStatus(): string { return this.f().callStatus; }
  protected get assigned(): string { return this.f().assigned; }

  /** Active filters as removable chips. */
  protected readonly chips = computed(() => {
    const f = this.f();
    const out: { key: keyof LeadFilter; label: string }[] = [];
    if (f.date) out.push({ key: 'date', label: f.date === 'custom' ? `${this.short(f.from) || '…'} – ${this.short(f.to) || '…'}` : DATE_PRESETS.find((p) => p[0] === f.date)![1] });
    if (f.ad) out.push({ key: 'ad', label: 'Service: ' + (f.ad === '__none' ? 'Not set' : f.ad) });
    if (f.callStatus) out.push({ key: 'callStatus', label: 'Call: ' + f.callStatus });
    if (f.status) out.push({ key: 'status', label: 'Stage: ' + this.label(f.status as LeadStatus) });
    if (f.assigned) {
      const who = f.assigned === 'me' ? 'Me' : f.assigned === 'none' ? 'Unassigned' : this.options()?.assignees.find((u) => String(u.id) === f.assigned)?.name ?? '…';
      out.push({ key: 'assigned', label: 'Assigned: ' + who });
    }
    return out;
  });

  openFilter(): void {
    this.draft = { ...this.f() };
    this.filterOpen.set(!this.filterOpen());
  }

  applyFilter(): void {
    if (this.draft.date !== 'custom') {
      this.draft.from = '';
      this.draft.to = '';
    }
    this.f.set({ ...this.draft });
    this.filterOpen.set(false);
    this.load(1);
  }

  clearFilter(): void {
    this.draft = { ...NO_FILTER };
    this.f.set({ ...NO_FILTER });
    this.filterOpen.set(false);
    this.load(1);
  }

  removeChip(key: keyof LeadFilter): void {
    const next = { ...this.f(), [key]: '' };
    if (key === 'date') {
      next.from = '';
      next.to = '';
    }
    this.f.set(next);
    this.load(1);
  }

  /** from/to (YYYY-MM-DD) for the chosen date preset. */
  private range(f: LeadFilter): { from?: string; to?: string } {
    const d = new Date();
    const iso = (x: Date) => `${x.getFullYear()}-${String(x.getMonth() + 1).padStart(2, '0')}-${String(x.getDate()).padStart(2, '0')}`;
    const ago = (n: number) => iso(new Date(d.getFullYear(), d.getMonth(), d.getDate() - n));
    switch (f.date) {
      case 'today': return { from: iso(d), to: iso(d) };
      case 'yesterday': return { from: ago(1), to: ago(1) };
      case '7d': return { from: ago(6), to: iso(d) };
      case '30d': return { from: ago(29), to: iso(d) };
      case 'this_month': return { from: iso(new Date(d.getFullYear(), d.getMonth(), 1)), to: iso(d) };
      case 'last_month': return { from: iso(new Date(d.getFullYear(), d.getMonth() - 1, 1)), to: iso(new Date(d.getFullYear(), d.getMonth(), 0)) };
      case 'custom': return { from: f.from || undefined, to: f.to || undefined };
      default: return {};
    }
  }

  private short(v: string): string {
    return v ? new Date(v + 'T00:00').toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }) : '';
  }

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
      .list({ ...this.filters(), page, per_page: this.layout() === 'board' ? 300 : this.pageSize() })
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

  async drop(e: CdkDragDrop<Lead[]>, to: LeadStatus): Promise<void> {
    const lead = e.item.data as Lead;
    if (lead.status === to) return;
    let lost_reason: string | null = null;
    if (to === 'lost') {
      lost_reason = await this.dialog.prompt({ title: 'Why is this lead not interested?', suggestions: this.options()?.lost_reasons ?? [], label: 'Reason', value: this.options()?.lost_reasons[0] ?? '', confirmText: 'Mark not interested' });
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
    return !!(this.view || this.search || this.chips().length);
  }

  private filters(): Record<string, string | undefined> {
    const f = this.f();
    return {
      view: this.view, search: this.search, ad: f.ad, call_status: f.callStatus, assigned: f.assigned,
      sort: this.sort().key, dir: this.sort().dir,
      // On the board, stages are the columns.
      status: this.layout() === 'board' ? '' : f.status,
      ...this.range(f),
    };
  }

  async bulkDelete(): Promise<void> {
    const all = this.allMatching();
    const n = all ? (this.result()?.meta?.total ?? 0) : this.selected().size;
    const what = `${n} lead${n === 1 ? '' : 's'}${all && this.filtered() ? ' matching your filters' : ''}`;
    const ok = await this.dialog.confirm({
      title: `Delete ${what}?`,
      message: 'Their call notes and history are deleted too. This cannot be undone.',
      confirmText: `Delete ${n === 1 ? 'lead' : n + ' leads'}`,
      danger: true,
      typeToConfirm: n > 20 || all ? 'DELETE' : undefined,
    });
    if (!ok) return;

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
