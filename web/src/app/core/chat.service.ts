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
export interface ChatPing { id: number; conversation_id: number; chat: string | null; from: string; text: string }
export interface MessagePage { data: ChatMessage[]; deleted_ids: number[]; has_more: boolean; read_upto: number }

const PREFS_KEY = 'dfp_chat_prefs';

@Injectable({ providedIn: 'root' })
export class ChatService {
  private http = inject(HttpClient);
  private base = `${API_BASE}/chat`;
  /** Total unread, shown on the chat icon. */
  readonly unread = signal(0);
  /** New-message pop-ups (bottom right), newest last. */
  readonly pings = signal<ChatPing[]>([]);
  /** The chat open on screen right now (no pop-up for it). */
  readonly viewing = signal<number | null>(null);
  readonly prefs = signal<{ sound: boolean; desktop: boolean }>(this.loadPrefs());
  private lastId: number | null = null;
  private audio?: AudioContext;
  /** Set by the shell: open a chat when a pop-up / desktop notification is clicked. */
  openChat: (conversationId: number) => void = () => {};

  refreshUnread(): void {
    const params: Record<string, string> = this.lastId === null ? {} : { since_id: String(this.lastId) };
    this.http.get<{ unread: number; max_id: number; latest: ChatPing[] }>(`${this.base}/unread`, { params }).subscribe({
      next: (r) => {
        this.unread.set(r.unread);
        const first = this.lastId === null;
        this.lastId = Math.max(this.lastId ?? 0, r.max_id);
        if (!first) r.latest.forEach((m) => this.notify(m));
      },
      error: () => {},
    });
  }

  /** Like Teams / Google Chat: pop-up, soft sound, and a desktop notification when the tab is in the background. */
  private notify(m: ChatPing): void {
    const looking = this.viewing() === m.conversation_id && !document.hidden;
    if (looking) return;
    const title = m.chat ? `${m.from} · ${m.chat}` : m.from;
    if (!document.hidden) {
      this.pings.update((l) => [...l.filter((x) => x.conversation_id !== m.conversation_id), m].slice(-3));
      setTimeout(() => this.dismiss(m.id), 7000);
    }
    if (this.prefs().sound) this.chime();
    if (this.prefs().desktop && document.hidden && 'Notification' in window && Notification.permission === 'granted') {
      try {
        const n = new Notification(title, { body: m.text, icon: 'favicon.ico', tag: 'chat-' + m.conversation_id });
        n.onclick = () => {
          window.focus();
          this.openChat(m.conversation_id);
          n.close();
        };
      } catch {}
    }
  }

  dismiss(id: number): void {
    this.pings.update((l) => l.filter((x) => x.id !== id));
  }

  /** Two soft notes (no sound file needed). */
  private chime(): void {
    try {
      this.audio ??= new AudioContext();
      const ctx = this.audio;
      [660, 880].forEach((f, i) => {
        const o = ctx.createOscillator();
        const g = ctx.createGain();
        o.type = 'sine';
        o.frequency.value = f;
        const t = ctx.currentTime + i * 0.12;
        g.gain.setValueAtTime(0.0001, t);
        g.gain.exponentialRampToValueAtTime(0.12, t + 0.02);
        g.gain.exponentialRampToValueAtTime(0.0001, t + 0.25);
        o.connect(g).connect(ctx.destination);
        o.start(t);
        o.stop(t + 0.3);
      });
    } catch {}
  }

  setPrefs(p: Partial<{ sound: boolean; desktop: boolean }>): void {
    this.prefs.update((x) => ({ ...x, ...p }));
    try {
      localStorage.setItem(PREFS_KEY, JSON.stringify(this.prefs()));
    } catch {}
  }

  /** Ask the browser for desktop notifications (must come from a click). */
  async enableDesktop(): Promise<boolean> {
    if (!('Notification' in window)) return false;
    const p = Notification.permission === 'default' ? await Notification.requestPermission() : Notification.permission;
    this.setPrefs({ desktop: p === 'granted' });
    return p === 'granted';
  }

  desktopState(): 'on' | 'off' | 'blocked' | 'unsupported' {
    if (!('Notification' in window)) return 'unsupported';
    if (Notification.permission === 'denied') return 'blocked';
    return Notification.permission === 'granted' && this.prefs().desktop ? 'on' : 'off';
  }

  private loadPrefs(): { sound: boolean; desktop: boolean } {
    try {
      return { sound: true, desktop: true, ...JSON.parse(localStorage.getItem(PREFS_KEY) ?? '{}') };
    } catch {
      return { sound: true, desktop: true };
    }
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
