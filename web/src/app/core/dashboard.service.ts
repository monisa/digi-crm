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

export interface StaffHome {
  name: string;
  leads?: {
    open: number; uncontacted: number; overdue: number; won_this_month: number;
    uncontacted_list: { id: number; name: string; phone: string | null; service: string | null; at: string; late: boolean; assigned: string | null }[];
    followups: { id: number; name: string; phone: string | null; at: string; overdue: boolean }[];
  };
  tickets?: { open: number; needs_reply: number; items: { id: number; number: string; subject: string; status: string; priority: string; awaiting_staff: boolean; client?: { company_name: string } | null }[] };
  leave?: {
    mine: { id: number; type: string | null; from: string; to: string; days: number; status: string }[];
    to_approve: { id: number; name: string | null; type: string | null; from: string; to: string; days: number; reason: string | null }[] | null;
  };
  me?: { employee_id: number; designation: string | null; leave: { type: { code: string; name: string }; allotted: number | null; remaining: number | null; used: number; pending: number }[] };
  holidays: { id: number; name: string; date: string }[];
  birthdays: { name: string; date: string; today: boolean }[];
}

@Injectable({ providedIn: 'root' })
export class DashboardService {
  private http = inject(HttpClient);

  overview(period: Period): Observable<Overview> {
    return this.http.get<Overview>(`${API_BASE}/dashboard/overview`, { params: { period } });
  }
  staffHome(): Observable<StaffHome> {
    return this.http.get<StaffHome>(`${API_BASE}/dashboard/me`);
  }
}
