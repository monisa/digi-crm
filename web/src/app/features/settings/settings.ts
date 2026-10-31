import { Component, signal } from '@angular/core';
import { RolePermissions } from './role-permissions';
import { UsersAccess } from './users-access';

@Component({
  selector: 'app-settings',
  imports: [RolePermissions, UsersAccess],
  template: `
    <div class="head">
      <h1>Settings</h1>
      <p>Control who can see and do what in the CRM.</p>
    </div>
    <div class="tabs" role="tablist">
      <button role="tab" [class.on]="tab() === 'roles'" (click)="tab.set('roles')">Role permissions</button>
      <button role="tab" [class.on]="tab() === 'users'" (click)="tab.set('users')">Users &amp; access</button>
    </div>
    @if (tab() === 'roles') { <app-role-permissions /> } @else { <app-users-access /> }
  `,
  styleUrl: './settings.scss',
})
export class Settings {
  protected readonly tab = signal<'roles' | 'users'>('roles');
}
