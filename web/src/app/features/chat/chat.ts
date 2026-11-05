import { Component, ElementRef, OnDestroy, computed, inject, signal, viewChild } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { AuthService } from '../../core/auth/auth.service';
import { ChatMessage, ChatPerson, ChatService, Conversation } from '../../core/chat.service';
import { readErrors } from '../../core/http-errors';
import { Dialog } from '../../shared/dialog';
import { Sheet } from '../../shared/sheet';
import { Toast } from '../../shared/toast.service';

/** Team chat: list of chats on the left, the open chat on the right (one at a time on phones). */
@Component({
  selector: 'app-chat',
  imports: [FormsModule, Sheet],
  templateUrl: './chat.html',
  styleUrl: './chat.scss',
})
export class Chat implements OnDestroy {
  private api = inject(ChatService);
  private toast = inject(Toast);
  private dialog = inject(Dialog);
  private router = inject(Router);
  protected readonly auth = inject(AuthService);
  protected readonly me = computed(() => this.auth.user()?.id ?? 0);

  protected readonly convs = signal<Conversation[]>([]);
  protected readonly loadingList = signal(true);
  protected readonly activeId = signal<number | null>(null);
  protected readonly active = computed(() => this.convs().find((c) => c.id === this.activeId()) ?? null);
  protected readonly msgs = signal<ChatMessage[]>([]);
  protected readonly hasMore = signal(false);
  protected readonly readUpto = signal(0);
  protected readonly sending = signal(false);
  protected readonly images = signal<Record<number, string>>({});
  protected readonly search = signal('');
  protected text = '';
  protected readonly file = signal<File | null>(null);

  // New chat / group
  protected readonly picker = signal<'direct' | 'group' | 'edit' | null>(null);
  protected readonly people = signal<ChatPerson[]>([]);
  protected groupName = '';
  protected picked = new Set<number>();
  protected personSearch = '';

  private thread = viewChild<ElementRef<HTMLElement>>('thread');
  private box = viewChild<ElementRef<HTMLTextAreaElement>>('box');
  private timers: ReturnType<typeof setInterval>[] = [];
  private polling = false;

  protected readonly filtered = computed(() => {
    const q = this.search().trim().toLowerCase();
    return q ? this.convs().filter((c) => c.name.toLowerCase().includes(q)) : this.convs();
  });

  constructor() {
    const open = inject(ActivatedRoute).snapshot.queryParamMap.get('c');
    this.loadList(open ? +open : null);
    // Polling: the open chat every 4 s, the list every 12 s, only while the tab is visible.
    this.timers.push(setInterval(() => !document.hidden && this.poll(), 4000));
    this.timers.push(setInterval(() => !document.hidden && this.loadList(), 12000));
  }

  ngOnDestroy(): void {
    this.api.viewing.set(null);
    this.timers.forEach(clearInterval);
    Object.values(this.images()).forEach((u) => URL.revokeObjectURL(u));
  }

  loadList(openId: number | null = null): void {
    this.api.conversations().subscribe((r) => {
      this.convs.set(r.data);
      this.loadingList.set(false);
      if (openId) this.open(openId);
      else if (!this.activeId() && r.data.length && matchMedia('(min-width: 861px)').matches) this.open(r.data[0]!.id);
    });
  }

  open(id: number): void {
    if (this.activeId() === id && this.msgs().length) return;
    this.activeId.set(id);
    this.api.viewing.set(id);
    this.msgs.set([]);
    this.text = ''; // a half-typed message stays with the chat it was meant for only while it is open
    this.router.navigate([], { queryParams: { c: id }, replaceUrl: true });
    this.api.messages(id).subscribe((r) => {
      if (this.activeId() !== id) return;
      this.msgs.set(r.data);
      this.hasMore.set(r.has_more);
      this.readUpto.set(r.read_upto);
      this.afterNew(true);
    });
    setTimeout(() => this.box()?.nativeElement.focus(), 50);
  }

  back(): void {
    this.activeId.set(null);
    this.api.viewing.set(null);
    this.router.navigate([], { queryParams: {}, replaceUrl: true });
  }

  older(): void {
    const id = this.activeId();
    const first = this.msgs()[0];
    if (!id || !first) return;
    const el = this.thread()?.nativeElement;
    const before = el ? el.scrollHeight - el.scrollTop : 0;
    this.api.messages(id, { before_id: first.id }).subscribe((r) => {
      this.msgs.update((m) => [...r.data, ...m]);
      this.hasMore.set(r.has_more);
      this.loadImages(r.data);
      setTimeout(() => el && (el.scrollTop = el.scrollHeight - before));
    });
  }

  private poll(): void {
    const id = this.activeId();
    if (!id || this.polling) return;
    const last = this.msgs().at(-1)?.id ?? 0;
    this.polling = true;
    this.api.messages(id, { after_id: last }).subscribe({
      next: (r) => {
        this.polling = false;
        if (this.activeId() !== id) return;
        this.readUpto.set(r.read_upto);
        if (r.deleted_ids.length) {
          this.msgs.update((m) => m.map((x) => (r.deleted_ids.includes(x.id) ? { ...x, deleted: true, body: null, file: null } : x)));
        }
        const fresh = r.data.filter((m) => !this.msgs().some((x) => x.id === m.id));
        if (fresh.length) {
          const el = this.thread()?.nativeElement;
          const atBottom = !el || el.scrollHeight - el.scrollTop - el.clientHeight < 120;
          this.msgs.update((m) => [...m, ...fresh]);
          this.afterNew(atBottom);
        }
      },
      error: () => (this.polling = false),
    });
  }

  /** Scroll down, show images, and mark read. */
  private afterNew(scroll: boolean): void {
    this.loadImages(this.msgs());
    if (scroll) setTimeout(() => this.scrollDown());
    const id = this.activeId();
    const last = this.msgs().at(-1);
    if (id && last) {
      this.api.read(id, last.id).subscribe();
      this.convs.update((l) => l.map((c) => (c.id === id ? { ...c, unread: 0 } : c)));
      this.api.unread.set(this.convs().reduce((n, c) => n + c.unread, 0));
    }
  }

  private scrollDown(): void {
    const el = this.thread()?.nativeElement;
    if (el) el.scrollTop = el.scrollHeight;
  }

  private loadImages(list: ChatMessage[]): void {
    for (const m of list) {
      if (m.file?.image && !this.images()[m.id]) {
        this.images.update((x) => ({ ...x, [m.id]: '' })); // loading
        this.api.fileBlob(m.id).subscribe({ next: (b) => this.images.update((x) => ({ ...x, [m.id]: URL.createObjectURL(b) })), error: () => {} });
      }
    }
  }

  onKey(e: KeyboardEvent): void {
    if (e.key === 'Enter' && !e.shiftKey && !(e as KeyboardEvent & { isComposing?: boolean }).isComposing) {
      e.preventDefault();
      this.send();
    }
  }

  pickFile(e: Event): void {
    const f = (e.target as HTMLInputElement).files?.[0] ?? null;
    (e.target as HTMLInputElement).value = '';
    if (f && f.size > 20 * 1024 * 1024) return this.toast.show('Files must be under 20 MB.');
    this.file.set(f);
  }

  send(): void {
    const id = this.activeId();
    const body = this.text.trim();
    if (!id || (!body && !this.file()) || this.sending()) return;
    this.sending.set(true);
    this.api.send(id, body, this.file()).subscribe({
      next: (m) => {
        this.sending.set(false);
        this.text = '';
        this.file.set(null);
        this.msgs.update((l) => (l.some((x) => x.id === m.id) ? l : [...l, m]));
        this.convs.update((l) => {
          const c = l.find((x) => x.id === id);
          return c ? [{ ...c, last: { text: m.body || '📎 ' + (m.file?.name ?? ''), by: 'You', at: m.created_at } }, ...l.filter((x) => x.id !== id)] : l;
        });
        this.afterNew(true);
        this.box()?.nativeElement.focus();
      },
      error: (e) => {
        this.sending.set(false);
        const r = readErrors(e);
        this.toast.show(Object.values(r.fields)[0] || r.message || 'Could not send.');
      },
    });
  }

  async removeMsg(m: ChatMessage): Promise<void> {
    if (!(await this.dialog.confirm({ title: 'Delete this message?', message: 'Everyone in the chat will see “message deleted”.', confirmText: 'Delete', danger: true }))) return;
    this.api.remove(m.id).subscribe(() => this.msgs.update((l) => l.map((x) => (x.id === m.id ? { ...x, deleted: true, body: null, file: null } : x))));
  }

  download(m: ChatMessage): void {
    this.api.fileBlob(m.id).subscribe((b) => {
      const a = document.createElement('a');
      a.href = URL.createObjectURL(b);
      a.download = m.file?.name ?? 'file';
      a.click();
      setTimeout(() => URL.revokeObjectURL(a.href), 1000);
    });
  }

  // ---------- notifications ----------
  protected readonly chatApi = this.api;

  async enableDesktop(): Promise<void> {
    const ok = await this.api.enableDesktop();
    this.toast.show(ok ? 'Desktop notifications are on.' : 'Notifications are blocked in this browser. Allow them in the site settings (🔒 next to the address).');
  }

  // ---------- new chat / group ----------

  startPicker(kind: 'direct' | 'group' | 'edit'): void {
    this.personSearch = '';
    this.picked = new Set(kind === 'edit' ? (this.active()?.members ?? []).map((m) => m.id).filter((id) => id !== this.me()) : []);
    this.groupName = kind === 'edit' ? this.active()?.name ?? '' : '';
    this.picker.set(kind);
    if (!this.people().length) this.api.people().subscribe((r) => this.people.set(r.data));
  }

  shownPeople(): ChatPerson[] {
    const q = this.personSearch.trim().toLowerCase();
    return q ? this.people().filter((p) => p.name.toLowerCase().includes(q)) : this.people();
  }

  togglePick(id: number): void {
    this.picked.has(id) ? this.picked.delete(id) : this.picked.add(id);
    this.picked = new Set(this.picked);
  }

  chatWith(p: ChatPerson): void {
    // Close the current chat at once, so nothing typed now goes to the wrong person.
    this.activeId.set(null);
    this.picker.set(null);
    this.api.direct(p.id).subscribe((c) => {
      this.picker.set(null);
      if (!this.convs().some((x) => x.id === c.id)) this.convs.update((l) => [c, ...l]);
      this.open(c.id);
    });
  }

  saveGroup(): void {
    const name = this.groupName.trim();
    if (!name || !this.picked.size) return this.toast.show('Give the group a name and pick at least one person.');
    const ids = [...this.picked];
    const req = this.picker() === 'edit' ? this.api.updateGroup(this.activeId()!, { name, member_ids: ids }) : this.api.createGroup(name, ids);
    req.subscribe({
      next: (c) => {
        this.picker.set(null);
        this.convs.update((l) => [c, ...l.filter((x) => x.id !== c.id)]);
        this.msgs.set([]);
        this.activeId.set(null);
        this.open(c.id);
      },
      error: (e) => this.toast.show(readErrors(e).message || 'Could not save the group.'),
    });
  }

  async leaveGroup(): Promise<void> {
    const c = this.active()!;
    if (!(await this.dialog.confirm({ title: `Leave ${c.name}?`, message: 'You will no longer see this group.', confirmText: 'Leave group', danger: true }))) return;
    this.api.leave(c.id).subscribe(() => {
      this.convs.update((l) => l.filter((x) => x.id !== c.id));
      this.back();
    });
  }

  // ---------- display helpers ----------

  /** Show the date line before the first message of each day. */
  newDay(i: number): string | null {
    const m = this.msgs();
    const d = new Date(m[i]!.created_at).toDateString();
    if (i > 0 && new Date(m[i - 1]!.created_at).toDateString() === d) return null;
    const today = new Date().toDateString();
    const y = new Date(Date.now() - 86400000).toDateString();
    return d === today ? 'Today' : d === y ? 'Yesterday' : new Date(m[i]!.created_at).toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'short' });
  }

  /** Name above a bubble in groups, only when the sender changes. */
  showName(i: number): boolean {
    const m = this.msgs();
    const c = this.active();
    return !!c && c.type === 'group' && !!m[i]!.user && m[i]!.user!.id !== this.me() && (i === 0 || m[i - 1]!.user?.id !== m[i]!.user!.id || !!this.newDay(i));
  }

  time(iso: string): string {
    return new Date(iso).toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' });
  }

  when(iso: string | undefined): string {
    if (!iso) return '';
    const d = new Date(iso);
    return d.toDateString() === new Date().toDateString() ? this.time(iso) : d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' });
  }

  initials(n: string): string {
    return n.split(/[\s·]+/).filter(Boolean).map((w) => w[0]).slice(0, 2).join('').toUpperCase();
  }

  size(n: number): string {
    return n > 1048576 ? `${(n / 1048576).toFixed(1)} MB` : `${Math.ceil(n / 1024)} KB`;
  }

  /** Turn links into clickable ones (text only, no HTML). */
  parts(body: string): { t: string; url?: string }[] {
    return body.split(/(https?:\/\/[^\s]+)/g).filter(Boolean).map((t) => (/^https?:\/\//.test(t) ? { t, url: t } : { t }));
  }
}
