import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE } from './api.config';

export interface Health {
  status: string;
  app: string;
  time: string;
}

@Injectable({ providedIn: 'root' })
export class HealthService {
  private http = inject(HttpClient);

  check(): Observable<Health> {
    return this.http.get<Health>(`${API_BASE}/health`);
  }
}
