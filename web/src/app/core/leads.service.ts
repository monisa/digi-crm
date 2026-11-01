import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, shareReplay, tap } from 'rxjs';
import { API_BASE } from './api.config';

export type LeadStatus = 'new' | 'contacted' | 'qualified' | 'proposal' | 'won' | 'lost';

export interface Lead {
  id: number;
  date: string;
  ad_name: string | null;
  campaign_name: string | null;
  full_name: string;
  phone: string | null;
  email: string | null;
  city: string | null;
  call_status: string | null;
  status: LeadStatus;
  package: string | null;
  requirement: string | null;
  followup_status: string | null;
  next_followup_at: string | null;
  notes: string | null;
  source: string;
  platform: string | null;
  assigned_to: { id: number; name: string } | null;
  first_contacted_at: string | null;
  lost_reason: string | null;
  converted_client_id: number | null;
  adset_name?: string | null;
  form_name?: string | null;
  custom_fields?: Record<string, string>;
  activities?: Activity[];
}

export interface Activity { id: number; type: string; outcome: string | null; note: string | null; by: string | null; at: string }

export interface LeadOptions {
  statuses: Record<LeadStatus, string>;
  call_statuses: string[];
  packages: string[];
  followup_statuses: string[];
  lost_reasons: string[];
  auto_assign: boolean;
  assign_pool: number[];
  assignees: { id: number; name: string }[];
}

export interface LeadList {
  data: Lead[];
  meta: { total: number; page: number; last_page: number };
  counts: { all: number; uncontacted: number; followups: number; today: number; by_status: Partial<Record<LeadStatus, number>> };
}

export interface MetaPage { id: number; page_id: string; name: string | null; subscribed: boolean; last_polled_at: string | null; last_error: string | null }

@Injectable({ providedIn: 'root' })
export class LeadsService {
  private http = inject(HttpClient);
  private base = `${API_BASE}/leads`;
  private options$?: Observable<LeadOptions>;

  options(refresh = false): Observable<LeadOptions> {
    if (!this.options$ || refresh) this.options$ = this.http.get<LeadOptions>(`${this.base}/options`).pipe(shareReplay(1));
    return this.options$;
  }
  saveOptions(o: Partial<LeadOptions>): Observable<LeadOptions> {
    return this.http.put<LeadOptions>(`${this.base}/options`, o).pipe(tap(() => (this.options$ = undefined)));
  }
  list(filters: Record<string, string | number | undefined>): Observable<LeadList> {
    const params: Record<string, string> = {};
    for (const [k, v] of Object.entries(filters)) if (v !== undefined && v !== '') params[k] = String(v);
    return this.http.get<LeadList>(this.base, { params });
  }
  get(id: number): Observable<Lead> {
    return this.http.get<Lead>(`${this.base}/${id}`);
  }
  create(data: Partial<Lead>): Observable<Lead> {
    return this.http.post<Lead>(this.base, data);
  }
  update(id: number, data: Record<string, unknown>): Observable<Lead> {
    return this.http.patch<Lead>(`${this.base}/${id}`, data);
  }
  remove(id: number): Observable<unknown> {
    return this.http.delete(`${this.base}/${id}`);
  }
  log(id: number, data: Record<string, unknown>): Observable<Lead> {
    return this.http.post<Lead>(`${this.base}/${id}/activities`, data);
  }
  assign(ids: number[], assigned_to: number | null): Observable<{ updated: number }> {
    return this.http.post<{ updated: number }>(`${this.base}/assign`, { ids, assigned_to });
  }
  convert(id: number, company_name: string): Observable<{ client_id: number; lead: Lead }> {
    return this.http.post<{ client_id: number; lead: Lead }>(`${this.base}/${id}/convert`, { company_name });
  }
  import(file: File): Observable<{ created: number; duplicates: number; errors: string[] }> {
    const fd = new FormData();
    fd.append('file', file);
    return this.http.post<{ created: number; duplicates: number; errors: string[] }>(`${this.base}/import`, fd);
  }
  export(filters: Record<string, string | undefined>): Observable<Blob> {
    const params: Record<string, string> = {};
    for (const [k, v] of Object.entries(filters)) if (v) params[k] = v;
    return this.http.get(`${this.base}/export`, { params, responseType: 'blob' }).pipe(
      tap((blob) => {
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `digifox-leads-${new Date().toISOString().slice(0, 10)}.csv`;
        a.click();
        setTimeout(() => URL.revokeObjectURL(a.href), 5000);
      }),
    );
  }

  // Meta integration
  meta(): Observable<{ pages: MetaPage[]; webhook_url: string; webhook_ready: boolean }> {
    return this.http.get<{ pages: MetaPage[]; webhook_url: string; webhook_ready: boolean }>(`${API_BASE}/integrations/meta`);
  }
  connectPage(page_id: string, access_token: string): Observable<MetaPage> {
    return this.http.post<MetaPage>(`${API_BASE}/integrations/meta/pages`, { page_id, access_token });
  }
  removePage(id: number): Observable<unknown> {
    return this.http.delete(`${API_BASE}/integrations/meta/pages/${id}`);
  }
  syncMeta(): Observable<{ created: number; pages: MetaPage[] }> {
    return this.http.post<{ created: number; pages: MetaPage[] }>(`${API_BASE}/integrations/meta/sync`, {});
  }
}

/** "+91 98470 12345" -> "919847012345" for WhatsApp links. */
export function waNumber(phone: string | null): string {
  const d = (phone ?? '').replace(/\D/g, '');
  return d.length === 10 ? '91' + d : d;
}
