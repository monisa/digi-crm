import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE } from './api.config';

export type TaskStatus = 'todo' | 'in_progress' | 'done';
export type TaskPriority = 'low' | 'normal' | 'high' | 'urgent';
export type TaskRepeat = 'none' | 'daily' | 'weekly' | 'monthly';

export interface Task {
  id: number;
  title: string;
  description: string | null;
  assigned_to: { id: number; name: string } | null;
  created_by: { id: number; name: string } | null;
  client: { id: number; company_name: string } | null;
  ticket: { id: number; subject: string } | null;
  due_date: string | null;
  priority: TaskPriority;
  status: TaskStatus;
  overdue: boolean;
  completed_at: string | null;
  minutes_spent: number;
  repeat: TaskRepeat;
  series_id: number | null;
}

export interface TaskList {
  data: Task[];
  counts: { today: number; overdue: number; upcoming: number; done_today: number };
  can_manage: boolean;
}

export type AttendanceMode = 'office' | 'field' | 'wfh';
export interface Attendance {
  id: number;
  date: string;
  user: { id: number; name: string } | null;
  check_in_at: string | null;
  check_out_at: string | null;
  mode: AttendanceMode;
  late: boolean;
  distance: number | null;
  map: string | null;
  note: string | null;
  minutes: number | null;
  auto_closed: boolean;
  edited: boolean;
}
export interface AttendanceMe { today: Attendance | null; late_after: string; office_set: boolean; require_location: boolean; can_manage: boolean }
export interface RegisterRow { user: { id: number; name: string }; status: string; leave: string | null; attendance: Attendance | null }
export interface Register { date: string; rows: RegisterRow[]; summary: { present: number; late: number; leave: number; absent: number; people: number } }
export interface TimesheetDay { date: string; status: string; off: string | null; leave: string | null; attendance: Attendance | null; tasks_done: number; task_minutes: number }
export interface Timesheet {
  user: { id: number; name: string };
  days: TimesheetDay[];
  totals: { present: number; late: number; half: number; leave: number; absent: number; minutes: number; tasks: number; working_days: number };
}
export interface AttendanceSettings { office_lat: number | null; office_lng: number | null; radius_m: number; late_after: string; half_day_hours: number; require_location: boolean }
export interface Fix { lat?: number; lng?: number; accuracy?: number }

export const MODE_LABEL: Record<AttendanceMode, string> = { office: 'Office', field: 'Field visit', wfh: 'Work from home' };
export const PRIORITY_LABEL: Record<TaskPriority, string> = { low: 'Low', normal: 'Normal', high: 'High', urgent: 'Urgent' };
export const REPEAT_LABEL: Record<TaskRepeat, string> = { none: 'Does not repeat', daily: 'Every working day', weekly: 'Every week', monthly: 'Every month' };

@Injectable({ providedIn: 'root' })
export class WorkService {
  private http = inject(HttpClient);

  tasks(params: Record<string, string | number | undefined> = {}): Observable<TaskList> {
    const p: Record<string, string> = {};
    for (const [k, v] of Object.entries(params)) if (v !== undefined && v !== '') p[k] = String(v);
    return this.http.get<TaskList>(`${API_BASE}/tasks`, { params: p });
  }
  createTask(data: Partial<Record<string, unknown>>): Observable<Task> {
    return this.http.post<Task>(`${API_BASE}/tasks`, data);
  }
  updateTask(id: number, data: Record<string, unknown>): Observable<Task> {
    return this.http.patch<Task>(`${API_BASE}/tasks/${id}`, data);
  }
  deleteTask(id: number): Observable<unknown> {
    return this.http.delete(`${API_BASE}/tasks/${id}`);
  }
  taskFromTicket(ticketId: number, data: { assigned_to: number; due_date?: string; priority?: string }): Observable<Task> {
    return this.http.post<Task>(`${API_BASE}/tickets/${ticketId}/task`, data);
  }

  me(): Observable<AttendanceMe> {
    return this.http.get<AttendanceMe>(`${API_BASE}/attendance/me`);
  }
  checkIn(data: Fix & { mode?: AttendanceMode; note?: string }): Observable<Attendance> {
    return this.http.post<Attendance>(`${API_BASE}/attendance/check-in`, data);
  }
  checkOut(data: Fix & { note?: string }): Observable<Attendance> {
    return this.http.post<Attendance>(`${API_BASE}/attendance/check-out`, data);
  }
  register(date: string): Observable<Register> {
    return this.http.get<Register>(`${API_BASE}/attendance/register`, { params: { date } });
  }
  timesheet(year: number, month: number, userId?: number): Observable<Timesheet> {
    const params: Record<string, string> = { year: String(year), month: String(month) };
    if (userId) params['user_id'] = String(userId);
    return this.http.get<Timesheet>(`${API_BASE}/attendance/timesheet`, { params });
  }
  saveDay(data: { user_id: number; date: string; check_in: string; check_out?: string | null; mode: AttendanceMode; note?: string }): Observable<Attendance> {
    return this.http.post<Attendance>(`${API_BASE}/attendance`, data);
  }
  settings(): Observable<AttendanceSettings> {
    return this.http.get<AttendanceSettings>(`${API_BASE}/attendance/settings`);
  }
  saveSettings(s: AttendanceSettings): Observable<AttendanceSettings> {
    return this.http.put<AttendanceSettings>(`${API_BASE}/attendance/settings`, s);
  }
  exportMonth(year: number, month: number): Observable<Blob> {
    return this.http.get(`${API_BASE}/attendance/export`, { params: { year, month }, responseType: 'blob' });
  }

  /** The phone/laptop location, or null if refused / unavailable. */
  locate(): Promise<Fix | null> {
    return new Promise((resolve) => {
      if (!('geolocation' in navigator)) return resolve(null);
      navigator.geolocation.getCurrentPosition(
        (p) => resolve({ lat: +p.coords.latitude.toFixed(7), lng: +p.coords.longitude.toFixed(7), accuracy: Math.round(p.coords.accuracy) }),
        () => resolve(null),
        { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 },
      );
    });
  }
}
