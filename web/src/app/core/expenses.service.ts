import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, tap } from 'rxjs';
import { API_BASE } from './api.config';

export interface Expense {
  id: number;
  spent_on: string;
  category: string;
  amount: number;
  gst: number;
  payment_mode: string | null;
  vendor: string | null;
  client: { id: number; company_name: string } | null;
  notes: string | null;
  status: 'pending' | 'approved' | 'rejected';
  reimbursable: boolean;
  reimbursed_at: string | null;
  receipt: { name: string } | null;
  created_by: { id: number; name: string } | null;
  approved_by: string | null;
  review_note: string | null;
  source: string | null;
  created_at: string;
}

export interface ExpenseList {
  data: Expense[];
  summary: { total: number; gst: number; previous: number; by_category: { category: string; total: number; count: number }[]; pending: number; to_reimburse: number };
  categories: string[];
  can_approve: boolean;
}

@Injectable({ providedIn: 'root' })
export class ExpensesService {
  private http = inject(HttpClient);
  private base = `${API_BASE}/expenses`;

  list(params: Record<string, string>): Observable<ExpenseList> {
    const p: Record<string, string> = {};
    for (const [k, v] of Object.entries(params)) if (v) p[k] = v;
    return this.http.get<ExpenseList>(this.base, { params: p });
  }
  save(fd: FormData, id?: number): Observable<Expense> {
    return this.http.post<Expense>(id ? `${this.base}/${id}` : this.base, fd);
  }
  remove(id: number): Observable<unknown> {
    return this.http.delete(`${this.base}/${id}`);
  }
  decide(id: number, decision: 'approve' | 'reject', note?: string): Observable<Expense> {
    return this.http.post<Expense>(`${this.base}/${id}/${decision}`, { note });
  }
  reimburse(id: number): Observable<Expense> {
    return this.http.post<Expense>(`${this.base}/${id}/reimburse`, {});
  }
  categories(): Observable<{ data: { id: number; name: string; active: boolean }[] }> {
    return this.http.get<{ data: { id: number; name: string; active: boolean }[] }>(`${API_BASE}/expense-categories`);
  }
  saveCategories(names: string[]): Observable<{ data: { id: number; name: string; active: boolean }[] }> {
    return this.http.put<{ data: { id: number; name: string; active: boolean }[] }>(`${API_BASE}/expense-categories`, { names });
  }
  private download(url: string, name: string, params: Record<string, string> = {}): Observable<Blob> {
    return this.http.get(url, { params, responseType: 'blob' }).pipe(
      tap((b) => {
        const a = document.createElement('a');
        a.href = URL.createObjectURL(b);
        a.download = name;
        a.click();
        setTimeout(() => URL.revokeObjectURL(a.href), 5000);
      }),
    );
  }
  receipt(e: Expense): Observable<Blob> {
    return this.download(`${this.base}/${e.id}/receipt`, e.receipt?.name ?? 'receipt');
  }
  export(from: string, to: string): Observable<Blob> {
    return this.download(`${this.base}/export`, `digifox-expenses-${from}-to-${to}.csv`, { from, to });
  }
}
