import { HttpClient } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { Observable, tap } from 'rxjs';
import { API_BASE } from './api.config';

export interface ChatPerson { id: number; name: string }
export interface Conversation {
  id: number;
  type: 'direct' | 'group';
  everyone: boolean;
  name: string;
  members: ChatPerson[];
  can_edit: boolean;
  last: { text: string; by: string | null; at: string } | null;
  unread: number;
}
export interface ChatMessage {
  id: number;
  conversation_id: number;
  user: ChatPerson | null; // null = system note ("Priya created the group")
  body: string | null;
  file: { name: string; mime: string; size: number; image: boolean } | null;
  deleted: boolean;
  created_at: string;
}
export interface MessagePage { data: ChatMessage[]; deleted_ids: number[]; has_more: boolean; read_upto: number }

@Injectable({ providedIn: 'root' })
export class ChatService {
  private http = inject(HttpClient);
  private base = `${API_BASE}/chat`;
  /** Total unread, shown on the chat icon. */
  readonly unread = signal(0);

  refreshUnread(): void {
    this.http.get<{ unread: number }>(`${this.base}/unread`).subscribe({ next: (r) => this.unread.set(r.unread), error: () => {} });
  }
  conversations(): Observable<{ data: Conversation[] }> {
    return this.http.get<{ data: Conversation[] }>(`${this.base}/conversations`).pipe(
      tap((r) => this.unread.set(r.data.reduce((n, c) => n + c.unread, 0))),
    );
  }
  people(): Observable<{ data: ChatPerson[] }> {
    return this.http.get<{ data: ChatPerson[] }>(`${this.base}/people`);
  }
  direct(userId: number): Observable<Conversation> {
    return this.http.post<Conversation>(`${this.base}/direct`, { user_id: userId });
  }
  createGroup(name: string, memberIds: number[]): Observable<Conversation> {
    return this.http.post<Conversation>(`${this.base}/groups`, { name, member_ids: memberIds });
  }
  updateGroup(id: number, data: { name?: string; member_ids?: number[] }): Observable<Conversation> {
    return this.http.patch<Conversation>(`${this.base}/conversations/${id}`, data);
  }
  leave(id: number): Observable<unknown> {
    return this.http.post(`${this.base}/conversations/${id}/leave`, {});
  }
  messages(id: number, opts: { after_id?: number; before_id?: number } = {}): Observable<MessagePage> {
    const params: Record<string, string> = {};
    if (opts.after_id) params['after_id'] = String(opts.after_id);
    if (opts.before_id) params['before_id'] = String(opts.before_id);
    return this.http.get<MessagePage>(`${this.base}/conversations/${id}/messages`, { params });
  }
  send(id: number, body: string, file?: File | null): Observable<ChatMessage> {
    const fd = new FormData();
    if (body) fd.append('body', body);
    if (file) fd.append('file', file);
    return this.http.post<ChatMessage>(`${this.base}/conversations/${id}/messages`, fd);
  }
  read(id: number, messageId: number): Observable<unknown> {
    return this.http.post(`${this.base}/conversations/${id}/read`, { message_id: messageId });
  }
  remove(messageId: number): Observable<unknown> {
    return this.http.delete(`${this.base}/messages/${messageId}`);
  }
  fileBlob(messageId: number): Observable<Blob> {
    return this.http.get(`${this.base}/messages/${messageId}/file`, { responseType: 'blob' });
  }
}
