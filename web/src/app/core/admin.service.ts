import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE } from './api.config';
import { AuthUser, Role, Status } from './auth/auth.models';

export interface ModuleDef {
  id: number;
  key: string;
  name: string;
  client_allowed: boolean;
}

export interface PermissionCatalog {
  actions: string[];
  modules: ModuleDef[];
  roles: Record<'employee' | 'client', string[]>;
}

export interface Page<T> {
  data: T[];
  current_page: number;
  last_page: number;
  total: number;
}

export interface UserAccess {
  user: AuthUser;
  overrides: Record<string, boolean>;
  effective: string[];
  role_defaults: string[];
}

@Injectable({ providedIn: 'root' })
export class AdminService {
  private http = inject(HttpClient);
  private base = `${API_BASE}/admin`;

  catalog(): Observable<PermissionCatalog> {
    return this.http.get<PermissionCatalog>(`${this.base}/permissions`);
  }

  saveRole(role: 'employee' | 'client', permissions: string[]): Observable<PermissionCatalog> {
    return this.http.put<PermissionCatalog>(`${this.base}/roles/${role}/permissions`, { permissions });
  }

  users(filters: { search?: string; role?: string; page?: number }): Observable<Page<AuthUser>> {
    let params = new HttpParams();
    for (const [k, v] of Object.entries(filters)) if (v) params = params.set(k, String(v));
    return this.http.get<Page<AuthUser>>(`${this.base}/users`, { params });
  }

  deleteUser(id: number): Observable<unknown> {
    return this.http.delete(`${this.base}/users/${id}`);
  }
  updateUser(id: number, data: { role?: Role; status?: Status; leads_scope?: string; clients_scope?: string }): Observable<AuthUser> {
    return this.http.patch<AuthUser>(`${this.base}/users/${id}`, data);
  }

  userAccess(id: number): Observable<UserAccess> {
    return this.http.get<UserAccess>(`${this.base}/users/${id}/permissions`);
  }

  saveUserAccess(id: number, overrides: Record<string, boolean>): Observable<UserAccess> {
    return this.http.put<UserAccess>(`${this.base}/users/${id}/permissions`, { overrides });
  }
}
