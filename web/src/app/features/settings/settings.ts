import { Component, signal } from '@angular/core';
import { Integrations } from './integrations';
import { RolePermissions } from './role-permissions';
import { UsersAccess } from './users-access';

@Component({
  selector: 'app-settings',
  imports: [RolePermissions, UsersAccess, Integrations],
  template: `
    <div class="head">
      <h1>Settings</h1>
      <p>Control who can see and do what in the CRM.</p>
    </div>
    <div class="tabs" role="tablist">
      <button role="tab" [class.on]="tab() === 'roles'" (click)="tab.set('roles')">Role permissions</button>
      <button role="tab" [class.on]="tab() === 'users'" (click)="tab.set('users')">Users &amp; access</button>
      <button role="tab" [class.on]="tab() === 'integrations'" (click)="tab.set('integrations')">Integrations</button>
    </div>
    @switch (tab()) {
      @case ('roles') { <app-role-permissions /> }
      @case ('users') { <app-users-access /> }
      @case ('integrations') { <app-integrations /> }
    }
  `,
  styleUrl: './settings.scss',
})
export class Settings {
  protected readonly tab = signal<'roles' | 'users' | 'integrations'>(
    (new URLSearchParams(location.search).get('tab') as 'roles' | 'users' | 'integrations') || 'roles',
  );
}
