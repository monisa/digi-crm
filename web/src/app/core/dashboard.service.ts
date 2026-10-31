import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE } from './api.config';

export type Period = 'month' | 'quarter' | 'year';

export interface Overview {
  period: { key: Period; label: string; from: string; to: string; compare_from: string; compare_to: string };
  kpis: {
    clients: { total: number; new: number; prev_new: number };
    employees: { total: number; on_leave_today: number };
    revenue: { amount: number; prev: number };
    expenses: { amount: number; prev: number };
    net: number;
    outstanding: { amount: number; overdue: number };
  };
  trend: { month: string; label: string; revenue: number; expenses: number }[];
  funnel: { stages: { key: string; label: string; count: number }[]; lost: number };
  uncontacted: {
    count: number;
    sla_minutes: number;
    items: { id: number; name: string; campaign: string | null; platform: string | null; assignee: string | null; waiting_minutes: number }[];
  };
  tickets: { open: number; high: number; items: { id: number; subject: string; client: string | null; priority: string; status: string }[] };
  leave: { pending: number; items: { id: number; employee: string | null; type: string | null; from: string; to: string; days: number }[] };
}

@Injectable({ providedIn: 'root' })
export class DashboardService {
  private http = inject(HttpClient);

  overview(period: Period): Observable<Overview> {
    return this.http.get<Overview>(`${API_BASE}/dashboard/overview`, { params: { period } });
  }
}
