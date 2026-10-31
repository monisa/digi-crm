import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Subject, debounceTime } from 'rxjs';
import { AdminService, ModuleDef, UserAccess } from '../../core/admin.service';
import { AuthUser, Role, Status } from '../../core/auth/auth.models';
import { AuthService } from '../../core/auth/auth.service';
import { readErrors } from '../../core/http-errors';

type Override = 'inherit' | 'allow' | 'deny';

@Component({
  selector: 'app-users-access',
  imports: [FormsModule],
  templateUrl: './users-access.html',
  styleUrl: './users-access.scss',
})
export class UsersAccess {
  private admin = inject(AdminService);
  protected readonly auth = inject(AuthService);
  protected readonly canEdit = this.auth.can('settings.edit');

  protected readonly roles: Role[] = ['admin', 'employee', 'client'];
  protected readonly statuses: Status[] = ['active', 'pending_onboarding', 'pending_approval', 'inactive'];

  protected search = '';
  protected roleFilter = '';
  protected readonly users = signal<AuthUser[]>([]);
  protected readonly loading = signal(true);
  protected readonly toast = signal('');

  // Access panel
  protected readonly modules = signal<ModuleDef[]>([]);
  protected readonly actions = signal<string[]>([]);
  protected readonly access = signal<UserAccess | null>(null);
  protected readonly overrides = signal<Record<string, boolean>>({});
  protected readonly saving = signal(false);

  private search$ = new Subject<void>();

  constructor() {
    this.search$.pipe(debounceTime(300)).subscribe(() => this.load());
    this.load();
    this.admin.catalog().subscribe((c) => {
      this.modules.set(c.modules);
      this.actions.set(c.actions);
    });
  }

  onSearch(): void {
    this.search$.next();
  }

  load(): void {
    this.loading.set(true);
    this.admin.users({ search: this.search, role: this.roleFilter }).subscribe({
      next: (p) => {
        this.users.set(p.data);
        this.loading.set(false);
      },
      error: (e) => {
        this.flash(readErrors(e).message);
        this.loading.set(false);
      },
    });
  }

  change(u: AuthUser, field: 'role' | 'status', value: string): void {
    this.admin.updateUser(u.id, { [field]: value }).subscribe({
      next: (updated) => {
        this.users.update((list) => list.map((x) => (x.id === updated.id ? updated : x)));
        this.flash(`${updated.name} updated.`);
      },
      error: (e) => {
        this.flash(readErrors(e).message || 'Could not update.');
        this.load();
      },
    });
  }

  open(u: AuthUser): void {
    this.admin.userAccess(u.id).subscribe((a) => {
      this.access.set(a);
      this.overrides.set({ ...a.overrides });
    });
  }

  close(): void {
    this.access.set(null);
  }

  state(key: string): Override {
    const o = this.overrides()[key];
    return o === undefined ? 'inherit' : o ? 'allow' : 'deny';
  }

  /** Whether the user's role gives this permission (shown for "role default" cells). */
  inherited(key: string): boolean {
    return this.access()?.role_defaults.includes(key) ?? false;
  }

  cycle(key: string): void {
    const next: Record<Override, Override> = { inherit: 'allow', allow: 'deny', deny: 'inherit' };
    const s = next[this.state(key)];
    this.overrides.update((o) => {
      const copy = { ...o };
      if (s === 'inherit') delete copy[key];
      else copy[key] = s === 'allow';
      return copy;
    });
  }

  saveAccess(): void {
    const a = this.access();
    if (!a) return;
    this.saving.set(true);
    this.admin.saveUserAccess(a.user.id, this.overrides()).subscribe({
      next: (res) => {
        this.access.set(res);
        this.overrides.set({ ...res.overrides });
        this.saving.set(false);
        this.flash(`Access for ${res.user.name} saved.`);
      },
      error: (e) => {
        this.saving.set(false);
        this.flash(readErrors(e).message || 'Could not save.');
      },
    });
  }

  label(s: string): string {
    return s.replace(/_/g, ' ');
  }

  private flash(msg: string): void {
    this.toast.set(msg);
    setTimeout(() => this.toast.set(''), 3000);
  }
}
