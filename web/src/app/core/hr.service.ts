import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, tap } from 'rxjs';
import { API_BASE } from './api.config';

export interface Employee {
  id: number;
  emp_code: string;
  name: string;
  office_email: string | null;
  mobile: string | null;
  designation: string | null;
  department: string | null;
  dob: string | null;
  doj: string | null;
  address: string | null;
  status: 'active' | 'inactive';
  reporting_manager: { id: number; name: string } | null;
  has_login: boolean;
}

export interface Salary {
  id: number;
  effective_from: string;
  /** Saved to start on a later date. */
  upcoming?: boolean;
  basic: number; hra: number; special_allowance: number; other_allowance: number;
  pf: number; esi: number; professional_tax: number; tds: number; other_deduction: number;
  bank_account_last4: string | null;
  gross: number;
  total_deductions: number;
  net: number;
  ctc_yearly: number;
}

export interface SkippedSlip { employee: string; reason: string; employee_id?: number; salary_id?: number; starts?: string }
export interface GenerateResult { generated: number; skipped: SkippedSlip[]; employees: number }

export interface SalaryInfo {
  current: Salary | null;
  history: Salary[];
  components: { earnings: Record<string, string>; deductions: Record<string, string> };
}

export interface Payslip {
  id: number;
  employee: { id: number; name: string; emp_code: string };
  year: number;
  month: number;
  label: string;
  days_in_month: number;
  lop_days: number;
  earnings: Record<string, number>;
  deductions: Record<string, number>;
  gross: number;
  total_deductions: number;
  net: number;
  status: 'draft' | 'published';
  published_at: string | null;
}

export interface LeaveType { id: number; code: string; name: string; yearly_quota: number; paid: boolean; active: boolean }

export interface Balance {
  type: { id: number; code: string; name: string; paid: boolean };
  allotted: number | null;
  used: number;
  pending: number;
  remaining: number | null;
}

export interface LeaveRequest {
  id: number;
  employee: { id: number; name: string; designation: string | null };
  type: { id: number; code: string; name: string };
  from: string;
  to: string;
  half_day: boolean;
  days: number;
  reason: string | null;
  status: 'pending' | 'approved' | 'rejected' | 'cancelled';
  approver: string | null;
  approver_comment: string | null;
  created_at: string;
}

export interface Holiday { id: number; date: string; day: string; name: string; optional: boolean }

export interface LeaveSummary {
  year: number;
  balances: Balance[];
  requests: LeaveRequest[];
  upcoming_holidays?: Holiday[];
}

@Injectable({ providedIn: 'root' })
export class HrService {
  private http = inject(HttpClient);

  // Employees
  employees(params: { search?: string; status?: string } = {}): Observable<{ data: Employee[]; scope: 'all' | 'own' }> {
    const p: Record<string, string> = {};
    for (const [k, v] of Object.entries(params)) if (v) p[k] = v;
    return this.http.get<{ data: Employee[]; scope: 'all' | 'own' }>(`${API_BASE}/employees`, { params: p });
  }
  me(): Observable<Employee> {
    return this.http.get<Employee>(`${API_BASE}/employees/me`);
  }
  employee(id: number): Observable<Employee> {
    return this.http.get<Employee>(`${API_BASE}/employees/${id}`);
  }
  deleteEmployee(id: number): Observable<unknown> {
    return this.http.delete(`${API_BASE}/employees/${id}`);
  }
  saveEmployee(data: Partial<Employee> & { create_login?: boolean; reporting_manager_id?: number | null }, id?: number): Observable<Employee> {
    return id
      ? this.http.put<Employee>(`${API_BASE}/employees/${id}`, data)
      : this.http.post<Employee>(`${API_BASE}/employees`, data);
  }

  // Salary & payslips
  salary(id: number): Observable<SalaryInfo> {
    return this.http.get<SalaryInfo>(`${API_BASE}/employees/${id}/salary`);
  }
  saveSalary(id: number, data: Record<string, unknown>): Observable<Salary> {
    return this.http.post<Salary>(`${API_BASE}/employees/${id}/salary`, data);
  }
  payslipsFor(id: number): Observable<{ data: Payslip[] }> {
    return this.http.get<{ data: Payslip[] }>(`${API_BASE}/employees/${id}/payslips`);
  }
  payroll(year: number, month: number): Observable<{ data: Payslip[]; totals: { gross: number; net: number; count: number } }> {
    return this.http.get<{ data: Payslip[]; totals: { gross: number; net: number; count: number } }>(`${API_BASE}/payslips`, { params: { year, month } });
  }
  generate(year: number, month: number): Observable<GenerateResult> {
    return this.http.post<GenerateResult>(`${API_BASE}/payslips/generate`, { year, month });
  }
  /** Change when a salary starts (e.g. so it also covers last month's payroll). */
  moveSalary(employeeId: number, salaryId: number, effective_from: string): Observable<Salary> {
    return this.http.patch<Salary>(`${API_BASE}/employees/${employeeId}/salary/${salaryId}`, { effective_from });
  }
  publish(year: number, month: number): Observable<{ published: number }> {
    return this.http.post<{ published: number }>(`${API_BASE}/payslips/publish`, { year, month });
  }
  /** Downloads the PDF through the API (keeps the login token) and saves it. */
  downloadPayslip(p: Payslip): Observable<Blob> {
    return this.http.get(`${API_BASE}/payslips/${p.id}/pdf`, { responseType: 'blob' }).pipe(
      tap((blob) => {
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `Payslip-${p.employee.emp_code}-${p.year}-${String(p.month).padStart(2, '0')}.pdf`;
        a.click();
        setTimeout(() => URL.revokeObjectURL(a.href), 5000);
      }),
    );
  }

  // Leave
  leaveSummary(year?: number): Observable<LeaveSummary> {
    return this.http.get<LeaveSummary>(`${API_BASE}/leave/summary`, { params: year ? { year } : {} });
  }
  leaveFor(id: number, year?: number): Observable<LeaveSummary> {
    return this.http.get<LeaveSummary>(`${API_BASE}/employees/${id}/leave`, { params: year ? { year } : {} });
  }
  leaveTypes(): Observable<{ data: LeaveType[] }> {
    return this.http.get<{ data: LeaveType[] }>(`${API_BASE}/leave/types`);
  }
  updateLeaveType(id: number, data: Partial<LeaveType>): Observable<LeaveType> {
    return this.http.put<LeaveType>(`${API_BASE}/leave/types/${id}`, data);
  }
  previewDays(from_date: string, to_date: string, half_day: boolean): Observable<{ days: number }> {
    return this.http.post<{ days: number }>(`${API_BASE}/leave/preview`, { from_date, to_date, half_day });
  }
  requestLeave(data: Record<string, unknown>): Observable<LeaveRequest> {
    return this.http.post<LeaveRequest>(`${API_BASE}/leave/requests`, data);
  }
  cancelLeave(id: number): Observable<LeaveRequest> {
    return this.http.post<LeaveRequest>(`${API_BASE}/leave/requests/${id}/cancel`, {});
  }
  approvals(status = 'pending'): Observable<{ data: LeaveRequest[] }> {
    return this.http.get<{ data: LeaveRequest[] }>(`${API_BASE}/leave/approvals`, { params: { status } });
  }
  decide(id: number, decision: 'approve' | 'reject', comment?: string): Observable<LeaveRequest> {
    return this.http.post<LeaveRequest>(`${API_BASE}/leave/requests/${id}/${decision}`, { comment });
  }

  // Holidays
  holidays(year: number): Observable<{ year: number; data: Holiday[] }> {
    return this.http.get<{ year: number; data: Holiday[] }>(`${API_BASE}/holidays`, { params: { year } });
  }
  saveHoliday(data: Partial<Holiday>, id?: number): Observable<Holiday> {
    return id ? this.http.put<Holiday>(`${API_BASE}/holidays/${id}`, data) : this.http.post<Holiday>(`${API_BASE}/holidays`, data);
  }
  deleteHoliday(id: number): Observable<unknown> {
    return this.http.delete(`${API_BASE}/holidays/${id}`);
  }
  addFixedHolidays(year: number): Observable<{ added: number }> {
    return this.http.post<{ added: number }>(`${API_BASE}/holidays/fixed`, { year });
  }
}
