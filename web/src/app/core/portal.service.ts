import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, tap } from 'rxjs';
import { API_BASE } from './api.config';

export type CampaignStatus = 'planned' | 'active' | 'paused' | 'completed';

export interface Campaign {
  id: number;
  client: { id: number; company_name: string } | null;
  name: string;
  platform: string;
  objective: string | null;
  budget: number | null;
  start_date: string | null;
  end_date: string | null;
  status: CampaignStatus;
  results: {
    spend: number | null; reach: number | null; impressions: number | null; clicks: number | null;
    leads: number | null; conversions: number | null; cost_per_lead: number | null; ctr: number | null; updated_at: string | null;
  };
  summary: string | null;
  owner: { id: number; name: string } | null;
  files?: { id: number; title: string; name: string; size: number; uploaded_at: string }[];
}

export type TicketStatus = 'open' | 'in_progress' | 'waiting' | 'resolved' | 'closed';

export interface Ticket {
  id: number;
  number: string;
  subject: string;
  category: string | null;
  priority: 'low' | 'medium' | 'high';
  status: TicketStatus;
  client: { id: number; company_name: string } | null;
  raised_by: string | null;
  assigned_to: { id: number; name: string } | null;
  awaiting_staff: boolean;
  last_reply_at: string;
  created_at: string;
  messages?: { id: number; body: string; internal: boolean; by: string | null; from_client: boolean; mine: boolean; attachment: { id: number; name: string } | null; at: string }[];
}

export interface ClientHome {
  company: string;
  account_manager: { name: string; email: string; phone: string | null } | null;
  billing: { to_pay: number; overdue: number; next: { id: number; invoice_no: string; balance: number; due_date: string } | null };
  campaigns: Campaign[];
  totals: { active_campaigns: number; leads: number; spend: number };
  tickets: { open: number; replied: number; latest: Ticket[] };
}

export const TICKET_STATUS: Record<TicketStatus, string> = {
  open: 'Open', in_progress: 'In progress', waiting: 'Waiting for you', resolved: 'Resolved', closed: 'Closed',
};

function save(blob: Blob, name: string): void {
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = name;
  a.click();
  setTimeout(() => URL.revokeObjectURL(a.href), 5000);
}

@Injectable({ providedIn: 'root' })
export class PortalService {
  private http = inject(HttpClient);

  home(): Observable<ClientHome> {
    return this.http.get<ClientHome>(`${API_BASE}/dashboard/client`);
  }

  campaigns(params: Record<string, string | number | undefined> = {}): Observable<{ data: Campaign[]; platforms: Record<string, string> }> {
    const p: Record<string, string> = {};
    for (const [k, v] of Object.entries(params)) if (v !== undefined && v !== '') p[k] = String(v);
    return this.http.get<{ data: Campaign[]; platforms: Record<string, string> }>(`${API_BASE}/campaigns`, { params: p });
  }
  campaign(id: number): Observable<Campaign> {
    return this.http.get<Campaign>(`${API_BASE}/campaigns/${id}`);
  }
  saveCampaign(data: Record<string, unknown>, id?: number): Observable<Campaign> {
    return id ? this.http.put<Campaign>(`${API_BASE}/campaigns/${id}`, data) : this.http.post<Campaign>(`${API_BASE}/campaigns`, data);
  }
  deleteCampaign(id: number): Observable<unknown> {
    return this.http.delete(`${API_BASE}/campaigns/${id}`);
  }
  uploadReport(id: number, file: File, title: string): Observable<Campaign> {
    const fd = new FormData();
    fd.append('file', file);
    if (title) fd.append('title', title);
    return this.http.post<Campaign>(`${API_BASE}/campaigns/${id}/files`, fd);
  }
  deleteReport(fileId: number): Observable<unknown> {
    return this.http.delete(`${API_BASE}/campaign-files/${fileId}`);
  }
  downloadReport(f: { id: number; name: string }): Observable<Blob> {
    return this.http.get(`${API_BASE}/campaign-files/${f.id}`, { responseType: 'blob' }).pipe(tap((b) => save(b, f.name)));
  }

  tickets(params: Record<string, string | number | undefined> = {}): Observable<{ data: Ticket[]; counts: { active: number; needs_reply: number }; categories: string[] }> {
    const p: Record<string, string> = {};
    for (const [k, v] of Object.entries(params)) if (v !== undefined && v !== '') p[k] = String(v);
    return this.http.get<{ data: Ticket[]; counts: { active: number; needs_reply: number }; categories: string[] }>(`${API_BASE}/tickets`, { params: p });
  }
  ticket(id: number): Observable<Ticket> {
    return this.http.get<Ticket>(`${API_BASE}/tickets/${id}`);
  }
  createTicket(data: FormData): Observable<Ticket> {
    return this.http.post<Ticket>(`${API_BASE}/tickets`, data);
  }
  reply(id: number, data: FormData): Observable<Ticket> {
    return this.http.post<Ticket>(`${API_BASE}/tickets/${id}/messages`, data);
  }
  updateTicket(id: number, data: Record<string, unknown>): Observable<Ticket> {
    return this.http.patch<Ticket>(`${API_BASE}/tickets/${id}`, data);
  }
  downloadAttachment(a: { id: number; name: string }): Observable<Blob> {
    return this.http.get(`${API_BASE}/ticket-files/${a.id}`, { responseType: 'blob' }).pipe(tap((b) => save(b, a.name)));
  }
}
