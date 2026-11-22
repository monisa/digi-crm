import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE } from './api.config';

export type DayStatus = 'pending' | 'approved' | 'rejected';
export interface StudentDay {
  id: number;
  date: string;
  status: DayStatus;
  note: string | null;
  student: { id: number; name: string } | null;
  reviewer: { id: number; name: string } | null;
  review_comment: string | null;
  reviewed_at: string | null;
  whatsapp: 'sent' | 'failed' | 'off' | null;
  whatsapp_error: string | null;
  submitted_at: string;
}
export interface Person { id: number; name: string }
export interface MyMonth {
  month: string;
  data: StudentDay[];
  notes: Record<string, number>;
  reviewers: Person[];
  earliest: string;
  recent: StudentDay[];
}
export interface DayNote {
  id: number; date: string; title: string; body: string | null;
  file: { name: string; size: number; mime: string } | null; by: string | null; at: string;
}
export interface StudentRow { id: number; name: string; email: string; phone: string | null; status: string; last_login_at: string | null; approved_days: number; pending: number }
export interface WaState { connected: boolean; template: string | null; ready: boolean }

@Injectable({ providedIn: 'root' })
export class StudentService {
  private http = inject(HttpClient);

  // Student
  month(month: string): Observable<MyMonth> {
    return this.http.get<MyMonth>(`${API_BASE}/student/attendance`, { params: { month } });
  }
  submit(date: string, reviewer_id: number, note: string): Observable<StudentDay> {
    return this.http.post<StudentDay>(`${API_BASE}/student/attendance`, { date, reviewer_id, note: note || null });
  }
  cancel(id: number): Observable<unknown> {
    return this.http.delete(`${API_BASE}/student/attendance/${id}`);
  }
  notes(date: string): Observable<{ data: DayNote[] }> {
    return this.http.get<{ data: DayNote[] }>(`${API_BASE}/student/notes/${date}`);
  }
  file(id: number): Observable<Blob> {
    return this.http.get(`${API_BASE}/class-notes/${id}/file`, { responseType: 'blob' });
  }

  // Managers
  requests(status: string, scope: string): Observable<{ data: StudentDay[]; counts: { pending: number }; sees_all: boolean; can_edit: boolean; whatsapp: WaState }> {
    return this.http.get<{ data: StudentDay[]; counts: { pending: number }; sees_all: boolean; can_edit: boolean; whatsapp: WaState }>(`${API_BASE}/students/requests`, { params: { status, scope } });
  }
  decide(id: number, decision: 'approve' | 'reject', comment = ''): Observable<StudentDay> {
    return this.http.post<StudentDay>(`${API_BASE}/students/requests/${id}/${decision}`, comment ? { comment } : {});
  }
  noteList(month: string): Observable<{ data: DayNote[] }> {
    return this.http.get<{ data: DayNote[] }>(`${API_BASE}/students/notes`, { params: { month } });
  }
  addNote(fd: FormData): Observable<DayNote> {
    return this.http.post<DayNote>(`${API_BASE}/students/notes`, fd);
  }
  deleteNote(id: number): Observable<unknown> {
    return this.http.delete(`${API_BASE}/students/notes/${id}`);
  }
  students(): Observable<{ data: StudentRow[]; reviewers: (Person & { whatsapp: boolean })[]; settings: { wa_template: string | null }; whatsapp: WaState }> {
    return this.http.get<{ data: StudentRow[]; reviewers: (Person & { whatsapp: boolean })[]; settings: { wa_template: string | null }; whatsapp: WaState }>(`${API_BASE}/students`);
  }
  addStudent(d: { name: string; email: string; phone: string }): Observable<Person> {
    return this.http.post<Person>(`${API_BASE}/students`, d);
  }
  updateStudent(id: number, d: Partial<{ name: string; phone: string; status: string }>): Observable<unknown> {
    return this.http.patch(`${API_BASE}/students/${id}`, d);
  }
  saveSettings(wa_template: string | null): Observable<unknown> {
    return this.http.put(`${API_BASE}/students/settings`, { wa_template });
  }
}

/** Save a downloaded file with its name. */
export function saveBlob(b: Blob, name: string): void {
  const a = document.createElement('a');
  a.href = URL.createObjectURL(b);
  a.download = name;
  a.click();
  setTimeout(() => URL.revokeObjectURL(a.href), 2000);
}
