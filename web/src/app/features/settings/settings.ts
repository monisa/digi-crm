import { Component, inject, signal } from '@angular/core';
import { AuthService } from '../../core/auth/auth.service';
import { Backups } from './backups';
import { CompanySettings } from './company';
import { Integrations } from './integrations';
import { RolePermissions } from './role-permissions';
import { UsersAccess } from './users-access';

@Component({
  selector: 'app-settings',
  imports: [RolePermissions, UsersAccess, Integrations, CompanySettings, Backups],
  template: `
    <div class="head">
      <h1>Settings</h1>
      <p>Control who can see and do what in the CRM.</p>
    </div>
    <div class="tabs" role="tablist">
      <button role="tab" [class.on]="tab() === 'roles'" (click)="tab.set('roles')">Role permissions</button>
      <button role="tab" [class.on]="tab() === 'users'" (click)="tab.set('users')">Users &amp; access</button>
      <button role="tab" [class.on]="tab() === 'company'" (click)="tab.set('company')">Company &amp; billing</button>
      <button role="tab" [class.on]="tab() === 'integrations'" (click)="tab.set('integrations')">Integrations</button>
      @if (isAdmin) { <button role="tab" [class.on]="tab() === 'backups'" (click)="tab.set('backups')">Backups</button> }
    </div>
    @switch (tab()) {
      @case ('roles') { <app-role-permissions /> }
      @case ('users') { <app-users-access /> }
      @case ('company') { <app-company /> }
      @case ('integrations') { <app-integrations /> }
      @case ('backups') { <app-backups /> }
    }
  `,
  styleUrl: './settings.scss',
})
export class Settings {
  protected readonly isAdmin = inject(AuthService).user()?.role === 'admin';
  protected readonly tab = signal<'roles' | 'users' | 'company' | 'integrations' | 'backups'>(
    (new URLSearchParams(location.search).get('tab') as 'roles' | 'users' | 'company' | 'integrations' | 'backups') || 'roles',
  );
}
