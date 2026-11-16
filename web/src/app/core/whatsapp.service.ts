import { HttpClient } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { Observable, tap } from 'rxjs';
import { API_BASE } from './api.config';

export interface WaMessage {
  id: number;
  direction: 'in' | 'out';
  type: string;
  body: string | null;
  template: string | null;
  status: 'sent' | 'delivered' | 'read' | 'failed' | 'received';
  error: string | null;
  by: string | null;
  at: string;
}
export interface WaContact { phone: string; name: string; lead_id: number | null; client_id: number | null }
export interface WaThread { contact: WaContact; data: WaMessage[]; window_open: boolean; window_ends_at: string | null; connected: boolean; can_send: boolean }
export interface WaTemplate { name: string; language: string; category: string | null; header: string | null; body: string; footer: string | null; header_vars: number; body_vars: number }
export interface WaChat { phone: string; name: string; lead_id: number | null; client_id: number | null; last: { text: string; direction: 'in' | 'out'; status: string; at: string }; unread: number }
export interface WaSettings {
  connected: boolean; display_phone: string | null; name: string | null; phone_number_id: string | null; waba_id: string | null;
  webhook_url: string; webhook_ready: boolean; last_incoming_at: string | null;
}
/** Who a conversation is with: a lead, a client or just a phone number. */
export type WaWho = { lead_id: number } | { client_id: number } | { phone: string };

@Injectable({ providedIn: 'root' })
export class WhatsAppService {
  private http = inject(HttpClient);
  private base = `${API_BASE}/whatsapp`;
  /** Known after the first status call; the WhatsApp buttons use it. */
  readonly connected = signal<boolean | null>(null);
  readonly unread = signal(0);

  status(): Observable<{ connected: boolean; can_send: boolean; unread: number }> {
    return this.http.get<{ connected: boolean; can_send: boolean; unread: number }>(`${this.base}/status`).pipe(
      tap((r) => {
        this.connected.set(r.connected);
        this.unread.set(r.unread);
      }),
    );
  }
  inbox(search = ''): Observable<{ data: WaChat[]; connected: boolean; can_send: boolean }> {
    return this.http.get<{ data: WaChat[]; connected: boolean; can_send: boolean }>(`${this.base}/inbox`, { params: search ? { search } : {} });
  }
  thread(who: WaWho): Observable<WaThread> {
    return this.http.get<WaThread>(`${this.base}/thread`, { params: who as Record<string, string | number> });
  }
  templates(fresh = false): Observable<{ data: WaTemplate[] }> {
    return this.http.get<{ data: WaTemplate[] }>(`${this.base}/templates`, { params: fresh ? { fresh: 1 } : {} });
  }
  send(who: WaWho, body: { text?: string; template?: string; header_values?: string[]; body_values?: string[] }): Observable<WaMessage> {
    return this.http.post<WaMessage>(`${this.base}/send`, { ...who, ...body });
  }
  settings(): Observable<WaSettings> {
    return this.http.get<WaSettings>(`${API_BASE}/integrations/whatsapp`);
  }
  connect(d: { phone_number_id: string; waba_id: string; access_token: string }): Observable<WaSettings> {
    return this.http.post<WaSettings>(`${API_BASE}/integrations/whatsapp`, d);
  }
  disconnect(): Observable<WaSettings> {
    return this.http.delete<WaSettings>(`${API_BASE}/integrations/whatsapp`);
  }
}
