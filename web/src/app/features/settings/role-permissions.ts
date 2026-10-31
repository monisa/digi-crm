import { Component, computed, inject, signal } from '@angular/core';
import { AdminService, PermissionCatalog } from '../../core/admin.service';
import { AuthService } from '../../core/auth/auth.service';
import { readErrors } from '../../core/http-errors';

type EditableRole = 'employee' | 'client';

@Component({
  selector: 'app-role-permissions',
  template: `
    <div class="card">
      <div class="toolbar">
        <div class="seg2" role="tablist" aria-label="Role">
          <button [class.on]="role() === 'employee'" (click)="pick('employee')">Employees</button>
          <button [class.on]="role() === 'client'" (click)="pick('client')">Clients</button>
        </div>
        <span class="hint">Admins always have full access. Clients can only be given client-portal sections.</span>
      </div>

      @if (catalog(); as c) {
        <div class="matrix">
          <div class="mrow headrow"><span>Section</span>@for (a of c.actions; track a) { <span style="text-align:center">{{ a }}</span> }</div>
          @for (m of c.modules; track m.key) {
            @let locked = role() === 'client' && !m.client_allowed;
            <div class="mrow" [class.locked]="locked">
              <div class="mname">{{ m.name }} @if (locked) { <small>Internal only</small> }</div>
              @for (a of c.actions; track a) {
                @let key = m.key + '.' + a;
                <button type="button" class="pchip" [class.on]="selected().has(key)" [disabled]="locked || !canEdit"
                        (click)="toggle(key)" [attr.aria-pressed]="selected().has(key)" [attr.aria-label]="m.name + ' ' + a">
                  <span class="sym">{{ selected().has(key) ? '✓' : '–' }}</span><span class="lbl">{{ a }}</span>
                </button>
              }
            </div>
          }
        </div>
        @if (canEdit) {
          <div class="toolbar" style="margin-top:16px">
            <span class="hint">{{ dirty() ? 'Unsaved changes' : 'All changes saved' }}</span>
            <button class="btn" (click)="save()" [disabled]="!dirty() || busy()">Save {{ role() }} permissions</button>
          </div>
        }
      } @else if (error()) {
        <div class="alert error">{{ error() }}</div>
      } @else {
        <p class="hint">Loading…</p>
      }
    </div>
    @if (toast()) { <div class="toast" role="status">{{ toast() }}</div> }
  `,
})
export class RolePermissions {
  private admin = inject(AdminService);
  protected readonly canEdit = inject(AuthService).can('settings.edit');

  protected readonly catalog = signal<PermissionCatalog | null>(null);
  protected readonly role = signal<EditableRole>('employee');
  protected readonly selected = signal(new Set<string>());
  protected readonly busy = signal(false);
  protected readonly error = signal('');
  protected readonly toast = signal('');
  protected readonly dirty = computed(() => {
    const saved = this.catalog()?.roles[this.role()] ?? [];
    const sel = this.selected();
    return saved.length !== sel.size || saved.some((k) => !sel.has(k));
  });

  constructor() {
    this.admin.catalog().subscribe({
      next: (c) => this.load(c),
      error: (e) => this.error.set(readErrors(e).message),
    });
  }

  pick(role: EditableRole): void {
    this.role.set(role);
    this.selected.set(new Set(this.catalog()?.roles[role] ?? []));
  }

  toggle(key: string): void {
    this.selected.update((s) => {
      const next = new Set(s);
      if (next.has(key)) {
        next.delete(key);
        // Without "view" a section is hidden, so drop its other actions too.
        if (key.endsWith('.view')) [...next].filter((k) => k.startsWith(key.slice(0, -4))).forEach((k) => next.delete(k));
      } else {
        next.add(key);
        next.add(key.replace(/\.[a-z]+$/, '.view')); // any action implies view
      }
      return next;
    });
  }

  save(): void {
    this.busy.set(true);
    this.admin.saveRole(this.role(), [...this.selected()]).subscribe({
      next: (c) => {
        this.load(c);
        this.flash('Permissions saved. Users see the change the next time they open the CRM.');
      },
      error: (e) => {
        this.flash(readErrors(e).message || 'Could not save.');
        this.busy.set(false);
      },
    });
  }

  private load(c: PermissionCatalog): void {
    this.catalog.set(c);
    this.selected.set(new Set(c.roles[this.role()]));
    this.busy.set(false);
  }

  private flash(msg: string): void {
    this.toast.set(msg);
    setTimeout(() => this.toast.set(''), 3500);
  }
}
