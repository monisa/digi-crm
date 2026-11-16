import { Component, ElementRef, OnDestroy, computed, inject, signal, viewChild } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { AuthService } from '../../core/auth/auth.service';
import { ChatFile, ChatMessage, ChatPerson, ChatService, Conversation, Receipt } from '../../core/chat.service';
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
  /** How far each other member has received / read this chat (for the ticks). */
  protected readonly receipts = signal<Receipt[]>([]);
  /** The message whose "Read by" list is open. */
  protected readonly infoFor = signal<number | null>(null);
  protected readonly sending = signal(false);
  protected readonly images = signal<Record<string, string>>({});
  protected readonly search = signal('');
  protected text = '';
  /** Files waiting to be sent with the next message (up to 10). */
  protected readonly files = signal<File[]>([]);
  protected readonly previews = signal<Record<string, string>>({});
  protected readonly dragging = signal(false);
  private static readonly MAX_FILES = 10;
  private static readonly MAX_SIZE = 20 * 1024 * 1024;
  private static readonly BLOCKED = /\.(exe|msi|bat|cmd|com|scr|pif|cpl|dll|sys|vbs|vbe|js|jse|wsf|wsh|ps1|psm1|sh|bash|php|phtml|phar|pl|py|rb|jar|apk|app|dmg|html?|xhtml|svgz|hta|lnk|reg|msp|iso)$/i;

  // @mentions: the word being typed after "@", the highlighted choice, and the people picked so far.
  protected readonly mentionQ = signal<string | null>(null);
  protected readonly mentionIdx = signal(0);
  private mentionAt = 0;
  private picks = new Map<number, string>();
  protected readonly mentionList = computed((): ChatPerson[] => {
    const q = this.mentionQ();
    const c = this.active();
    if (q === null || !c) return [];
    const s = q.toLowerCase();
    const hit = (name: string) => !s || name.toLowerCase().startsWith(s) || name.toLowerCase().split(/\s+/).some((w) => w.startsWith(s));
    const people = c.members.filter((p) => p.id !== this.me() && hit(p.name));
    const all = c.type === 'group' && 'everyone'.startsWith(s) ? [{ id: 0, name: 'everyone' }] : [];
    return [...people.slice(0, 7), ...all];
  });

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
    this.receipts.set([]);
    this.infoFor.set(null);
    this.picks.clear();
    this.mentionQ.set(null);
    this.text = ''; // a half-typed message stays with the chat it was meant for only while it is open
    this.router.navigate([], { queryParams: { c: id }, replaceUrl: true });
    this.api.messages(id).subscribe((r) => {
      if (this.activeId() !== id) return;
      this.msgs.set(r.data);
      this.hasMore.set(r.has_more);
      this.readUpto.set(r.read_upto);
      this.receipts.set(r.receipts ?? []);
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
        if (r.receipts) this.receipts.set(r.receipts);
        if (r.deleted_ids.length) {
          this.msgs.update((m) => m.map((x) => (r.deleted_ids.includes(x.id) ? { ...x, deleted: true, body: null, file: null, files: [] } : x)));
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
      for (const f of m.files ?? []) {
        const key = 'f' + f.id;
        if (f.image && this.images()[key] === undefined) {
          this.images.update((x) => ({ ...x, [key]: '' }));
          this.api.attachmentBlob(f.id).subscribe({ next: (b) => this.images.update((x) => ({ ...x, [key]: URL.createObjectURL(b) })), error: () => {} });
        }
      }
      if (m.file?.image && !this.images()[m.id]) {
        this.images.update((x) => ({ ...x, [m.id]: '' })); // loading
        this.api.fileBlob(m.id).subscribe({ next: (b) => this.images.update((x) => ({ ...x, [m.id]: URL.createObjectURL(b) })), error: () => {} });
      }
    }
  }

  onKey(e: KeyboardEvent): void {
    const list = this.mentionList();
    if (list.length) {
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        this.mentionIdx.update((i) => (i + (e.key === 'ArrowDown' ? 1 : list.length - 1)) % list.length);
        return;
      }
      if ((e.key === 'Enter' || e.key === 'Tab') && !e.shiftKey) {
        e.preventDefault();
        this.pickMention(list[Math.min(this.mentionIdx(), list.length - 1)]!);
        return;
      }
      if (e.key === 'Escape') {
        e.preventDefault();
        e.stopPropagation();
        this.mentionQ.set(null);
        return;
      }
    }
    if (e.key === 'Enter' && !e.shiftKey && !(e as KeyboardEvent & { isComposing?: boolean }).isComposing) {
      e.preventDefault();
      this.send();
    }
  }

  /** Open the people list when the word at the cursor starts with "@" (up to two words, e.g. "@Priya R"). */
  checkMention(): void {
    const el = this.box()?.nativeElement;
    if (!el) return;
    const before = el.value.slice(0, el.selectionStart ?? el.value.length);
    const m = before.match(/(?:^|\s)@([^\s@]{0,30}(?: [^\s@]{0,30})?)$/u);
    const q = m ? m[1]! : null;
    if (q !== this.mentionQ()) this.mentionIdx.set(0);
    this.mentionAt = m ? before.length - q!.length - 1 : 0;
    this.mentionQ.set(q);
    // "@Priya hello" with no match after the space: stop suggesting.
    if (q?.includes(' ') && !this.mentionList().length) this.mentionQ.set(null);
  }

  pickMention(p: ChatPerson): void {
    const el = this.box()?.nativeElement;
    const end = el?.selectionStart ?? this.text.length;
    const name = p.id === 0 ? 'everyone' : p.name;
    if (p.id) this.picks.set(p.id, p.name);
    this.text = this.text.slice(0, this.mentionAt) + '@' + name + ' ' + this.text.slice(end);
    this.mentionQ.set(null);
    // Put the cursor right after the name straight away (so typing carries on there).
    const caret = this.mentionAt + name.length + 2;
    if (el) {
      el.value = this.text;
      el.focus();
      el.setSelectionRange(caret, caret);
    }
  }

  pickFile(e: Event): void {
    const list = Array.from((e.target as HTMLInputElement).files ?? []);
    (e.target as HTMLInputElement).value = '';
    this.addFiles(list);
  }

  /** Add files from the picker, drag & drop or paste (max 10, 20 MB each). */
  addFiles(list: File[]): void {
    // Programs and scripts are refused by the server too; say so straight away.
    const blocked = list.filter((f) => Chat.BLOCKED.test(f.name));
    if (blocked.length) this.toast.show(`${blocked.map((f) => f.name).join(', ')}: programs and scripts can't be sent. Put them in a ZIP if needed.`);
    list = list.filter((f) => !Chat.BLOCKED.test(f.name));
    const tooBig = list.filter((f) => f.size > Chat.MAX_SIZE);
    if (tooBig.length) this.toast.show(`${tooBig.map((f) => f.name).join(', ')}: files must be under 20 MB.`);
    const ok = list.filter((f) => f.size <= Chat.MAX_SIZE);
    const room = Chat.MAX_FILES - this.files().length;
    if (ok.length > room) this.toast.show(`You can send up to ${Chat.MAX_FILES} files at a time.`);
    const added = ok.slice(0, Math.max(0, room));
    this.files.update((l) => [...l, ...added]);
    for (const f of added) {
      if (f.type.startsWith('image/') && f.type !== 'image/svg+xml') this.previews.update((p) => ({ ...p, [this.fkey(f)]: URL.createObjectURL(f) }));
    }
    this.box()?.nativeElement.focus();
  }

  removeFile(i: number): void {
    const f = this.files()[i];
    if (f) {
      const u = this.previews()[this.fkey(f)];
      if (u) URL.revokeObjectURL(u);
    }
    this.files.update((l) => l.filter((_, n) => n !== i));
  }

  fkey(f: File): string {
    return `${f.name}|${f.size}|${f.lastModified}`;
  }

  onDrop(e: DragEvent): void {
    e.preventDefault();
    this.dragging.set(false);
    if (this.activeId()) this.addFiles(Array.from(e.dataTransfer?.files ?? []));
  }

  onDragOver(e: DragEvent): void {
    if (e.dataTransfer?.types.includes('Files')) {
      e.preventDefault();
      this.dragging.set(true);
    }
  }

  /** Paste screenshots straight into the message box. */
  onPaste(e: ClipboardEvent): void {
    const pasted = Array.from(e.clipboardData?.files ?? []);
    if (pasted.length) {
      e.preventDefault();
      this.addFiles(pasted.map((f, i) => (f.name === 'image.png' ? new File([f], `screenshot-${Date.now()}-${i + 1}.png`, { type: f.type }) : f)));
    }
  }

  downloadFile(f: ChatFile): void {
    this.api.attachmentBlob(f.id).subscribe((b) => {
      const a = document.createElement('a');
      a.href = URL.createObjectURL(b);
      a.download = f.name;
      a.click();
      setTimeout(() => URL.revokeObjectURL(a.href), 1000);
    });
  }

  send(): void {
    const id = this.activeId();
    const body = this.text.trim();
    if (!id || (!body && !this.files().length) || this.sending()) return;
    this.sending.set(true);
    const mentionIds = [...this.picks].filter(([, name]) => body.includes('@' + name)).map(([pid]) => pid);
    const everyone = this.active()?.type === 'group' && /(^|\s)@everyone\b/i.test(body);
    this.mentionQ.set(null);
    this.api.send(id, body, this.files(), mentionIds, everyone).subscribe({
      next: (m) => {
        this.sending.set(false);
        this.text = '';
        this.picks.clear();
        Object.values(this.previews()).forEach((u) => URL.revokeObjectURL(u));
        this.previews.set({});
        this.files.set([]);
        this.msgs.update((l) => (l.some((x) => x.id === m.id) ? l : [...l, m]));
        this.convs.update((l) => {
          const c = l.find((x) => x.id === id);
          return c ? [{ ...c, last: { text: m.body || (m.files.length > 1 ? `📎 ${m.files.length} files` : '📎 ' + (m.files[0]?.name ?? '')), by: 'You', at: m.created_at } }, ...l.filter((x) => x.id !== id)] : l;
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
    this.api.remove(m.id).subscribe(() => this.msgs.update((l) => l.map((x) => (x.id === m.id ? { ...x, deleted: true, body: null, file: null, files: [] } : x))));
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

  imagesOf(m: ChatMessage): ChatFile[] {
    return (m.files ?? []).filter((f) => f.image);
  }

  docsOf(m: ChatMessage): ChatFile[] {
    return (m.files ?? []).filter((f) => !f.image);
  }

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

  /** sent ✓ · delivered ✓✓ (grey) · read ✓✓ (blue) — in groups, once everyone has. */
  tickState(m: ChatMessage): 'sent' | 'delivered' | 'read' {
    const r = this.receipts();
    if (!r.length) return 'sent';
    if (r.every((x) => x.read_id >= m.id)) return 'read';
    if (r.every((x) => x.delivered_id >= m.id)) return 'delivered';
    return 'sent';
  }

  tickTitle(m: ChatMessage): string {
    const s = this.tickState(m);
    const grp = this.active()?.type === 'group';
    if (s === 'read') return grp ? 'Read by everyone' : 'Read';
    if (s === 'delivered') return grp ? 'Delivered to everyone' : 'Delivered';
    const n = this.receipts().filter((x) => x.read_id >= m.id).length;
    return grp && n ? `Sent · read by ${n} of ${this.receipts().length}` : 'Sent';
  }

  /** Who has read / received / not yet received one of my messages. */
  readInfo(m: ChatMessage): { read: Receipt[]; delivered: Receipt[]; waiting: Receipt[] } {
    const r = this.receipts();
    return {
      read: r.filter((x) => x.read_id >= m.id),
      delivered: r.filter((x) => x.read_id < m.id && x.delivered_id >= m.id),
      waiting: r.filter((x) => x.delivered_id < m.id),
    };
  }

  toggleInfo(m: ChatMessage, e: Event): void {
    e.stopPropagation();
    this.infoFor.update((id) => (id === m.id ? null : m.id));
  }

  /** "10:32 am" today, "15 Nov, 10:32 am" before. Only shown when it is after the message was sent. */
  seenAt(iso: string | null, m: ChatMessage): string {
    if (!iso || new Date(iso) < new Date(m.created_at)) return '';
    return new Date(iso).toDateString() === new Date().toDateString() ? this.time(iso) : `${this.when(iso)}, ${this.time(iso)}`;
  }

  mentionsMe(m: ChatMessage): boolean {
    return !!m.mentions?.includes(this.me());
  }

  /** Links become clickable and @mentions are highlighted (text only, no HTML). */
  parts(m: ChatMessage): { t: string; url?: string; at?: 'me' | 'other' }[] {
    const body = m.body ?? '';
    const ids = m.mentions ?? [];
    const members = this.active()?.members ?? [];
    const names = members.filter((p) => ids.includes(p.id)).map((p) => ({ name: p.name, me: p.id === this.me() }));
    if (ids.length && /@everyone\b/i.test(body)) names.push({ name: 'everyone', me: ids.includes(this.me()) });
    names.sort((a, b) => b.name.length - a.name.length);
    const esc = (s: string) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const at = names.length ? new RegExp(`(@(?:${names.map((n) => esc(n.name)).join('|')}))`, 'gi') : null;
    const out: { t: string; url?: string; at?: 'me' | 'other' }[] = [];
    for (const t of body.split(/(https?:\/\/[^\s]+)/g).filter(Boolean)) {
      if (/^https?:\/\//.test(t)) out.push({ t, url: t });
      else if (!at) out.push({ t });
      else
        for (const x of t.split(at).filter(Boolean)) {
          const hit = x.startsWith('@') ? names.find((n) => '@' + n.name.toLowerCase() === x.toLowerCase()) : undefined;
          out.push(hit ? { t: x, at: hit.me ? 'me' : 'other' } : { t: x });
        }
    }
    return out;
  }
}
