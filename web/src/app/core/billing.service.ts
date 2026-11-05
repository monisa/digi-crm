import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, tap } from 'rxjs';
import { API_BASE } from './api.config';

export interface Company { [key: string]: string | number }

export interface Client {
  id: number;
  company_name: string;
  contact_name: string | null;
  email: string | null;
  phone: string | null;
  industry: string | null;
  website: string | null;
  gstin: string | null;
  address: string | null;
  city: string | null;
  state: string | null;
  pincode: string | null;
  status: 'active' | 'inactive' | 'pending';
  source?: string | null;
  onboarded_at?: string | null;
  account_manager: { id: number; name: string } | null;
  notes: string | null;
  created_at: string;
  outstanding?: number;
}

export interface ClientFile { id: number; kind: 'logo' | 'brand_asset'; name: string; mime: string | null; size: number; uploaded_at: string }

export interface ClientDetail extends Client {
  onboarding: Record<string, unknown> | null;
  has_credentials: boolean;
  files: ClientFile[];
  logins: { id: number; name: string; email: string; status: string; last_login_at: string | null }[];
  stats: { billed: number; paid: number; outstanding: number; overdue: number };
}

export type InvoiceStatus = 'draft' | 'unpaid' | 'partial' | 'paid' | 'cancelled';

export interface InvoiceRow {
  id: number;
  invoice_no: string | null;
  client: { id: number; company_name: string } | null;
  issue_date: string;
  due_date: string;
  subtotal: number;
  tax: number;
  total: number;
  amount_paid: number;
  balance: number;
  status: InvoiceStatus;
  overdue: boolean;
}

export interface Claim {
  id: number;
  invoice: { id: number; invoice_no?: string | null; balance?: number };
  client: string | null;
  amount: number;
  paid_on: string;
  mode: string;
  reference: string;
  note: string | null;
  status: 'pending' | 'confirmed' | 'rejected';
  submitted_by: string | null;
  review_note: string | null;
  created_at: string;
}

export interface InvoiceItem { id?: number; description: string; sac: string | null; qty: number; rate: number; amount?: number }

export interface Invoice extends InvoiceRow {
  place_of_supply: string | null;
  gst_type: 'intra' | 'inter' | 'export';
  gst_rate: number;
  cgst: number;
  sgst: number;
  igst: number;
  notes: string | null;
  issued_at: string | null;
  emailed_at: string | null;
  items: InvoiceItem[];
  payments: { id: number; amount: number; paid_on: string; mode: string; reference: string | null }[];
  claims: Claim[];
  client_detail: Client | null;
  pay?: { online?: boolean; upi_id: string | null; upi_link: string | null; qr: string | null; bank: Record<string, string> | null };
}

export interface InvoiceSummary { outstanding: number; overdue: number; overdue_count: number; paid_this_month: number; pending_claims: number }

@Injectable({ providedIn: 'root' })
export class BillingService {
  private http = inject(HttpClient);

  company(): Observable<{ company: Company; states: string[] }> {
    return this.http.get<{ company: Company; states: string[] }>(`${API_BASE}/settings/company`);
  }
  saveCompany(c: Company): Observable<{ company: Company; states: string[] }> {
    return this.http.put<{ company: Company; states: string[] }>(`${API_BASE}/settings/company`, c);
  }

  clients(params: { search?: string; status?: string } = {}): Observable<{ data: Client[]; pending: number }> {
    const p: Record<string, string> = {};
    for (const [k, v] of Object.entries(params)) if (v) p[k] = v;
    return this.http.get<{ data: Client[]; pending: number }>(`${API_BASE}/clients`, { params: p });
  }
  /** Razorpay payment link for the balance due. */
  payOnline(id: number): Observable<{ url: string; amount: number }> {
    return this.http.post<{ url: string; amount: number }>(`${API_BASE}/invoices/${id}/pay-online`, {});
  }
  importRazorpay(id: number, paymentId: string): Observable<{ message: string; status: string }> {
    return this.http.post<{ message: string; status: string }>(`${API_BASE}/invoices/${id}/pay-online/import`, { payment_id: paymentId });
  }
  checkOnline(id: number): Observable<{ found: number; status: string; balance: number; message: string }> {
    return this.http.post<{ found: number; status: string; balance: number; message: string }>(`${API_BASE}/invoices/${id}/pay-online/check`, {});
  }
  /** Back from Razorpay: verify and record. */
  razorpayReturn(params: Record<string, string>): Observable<{ paid: boolean; invoice_id: number; invoice_no: string | null; amount: number; balance: number }> {
    return this.http.post<{ paid: boolean; invoice_id: number; invoice_no: string | null; amount: number; balance: number }>(`${API_BASE}/payments/razorpay/callback`, params);
  }
  /** The general "new client" onboarding form link. */
  onboardingLink(): Observable<{ token: string }> {
    return this.http.get<{ token: string }>(`${API_BASE}/clients/onboarding-link`);
  }
  resetOnboardingLink(): Observable<{ token: string }> {
    return this.http.post<{ token: string }>(`${API_BASE}/clients/onboarding-link/reset`, {});
  }
  /** A client's own form link (works once). */
  clientOnboardingLink(id: number): Observable<{ token: string }> {
    return this.http.post<{ token: string }>(`${API_BASE}/clients/${id}/onboarding-link`, {});
  }
  client(id: number): Observable<ClientDetail> {
    return this.http.get<ClientDetail>(`${API_BASE}/clients/${id}`);
  }
  saveClient(data: Partial<Client> & { account_manager_id?: number | null }, id?: number): Observable<Client> {
    return id ? this.http.put<Client>(`${API_BASE}/clients/${id}`, data) : this.http.post<Client>(`${API_BASE}/clients`, data);
  }
  deleteClient(id: number): Observable<unknown> {
    return this.http.delete(`${API_BASE}/clients/${id}`);
  }
  invite(id: number, name: string, email: string): Observable<unknown> {
    return this.http.post(`${API_BASE}/clients/${id}/invite`, { name, email });
  }

  revealCredentials(id: number): Observable<{ credentials: Record<string, Record<string, string>>; views: { name: string; viewed_at: string }[] }> {
    return this.http.post<{ credentials: Record<string, Record<string, string>>; views: { name: string; viewed_at: string }[] }>(`${API_BASE}/clients/${id}/credentials`, {});
  }
  downloadFile(f: ClientFile): Observable<Blob> {
    return this.http.get(`${API_BASE}/client-files/${f.id}`, { responseType: 'blob' }).pipe(
      tap((blob) => {
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = f.name;
        a.click();
        setTimeout(() => URL.revokeObjectURL(a.href), 5000);
      }),
    );
  }

  invoices(params: Record<string, string | number | undefined> = {}): Observable<{ data: InvoiceRow[]; summary: InvoiceSummary }> {
    const p: Record<string, string> = {};
    for (const [k, v] of Object.entries(params)) if (v !== undefined && v !== '') p[k] = String(v);
    return this.http.get<{ data: InvoiceRow[]; summary: InvoiceSummary }>(`${API_BASE}/invoices`, { params: p });
  }
  invoice(id: number): Observable<Invoice> {
    return this.http.get<Invoice>(`${API_BASE}/invoices/${id}`);
  }
  saveInvoice(data: Record<string, unknown>, id?: number): Observable<Invoice> {
    return id ? this.http.put<Invoice>(`${API_BASE}/invoices/${id}`, data) : this.http.post<Invoice>(`${API_BASE}/invoices`, data);
  }
  issue(id: number): Observable<Invoice> {
    return this.http.post<Invoice>(`${API_BASE}/invoices/${id}/issue`, {});
  }
  cancel(id: number): Observable<Invoice> {
    return this.http.post<Invoice>(`${API_BASE}/invoices/${id}/cancel`, {});
  }
  deleteDraft(id: number): Observable<unknown> {
    return this.http.delete(`${API_BASE}/invoices/${id}`);
  }
  email(id: number): Observable<{ sent_to: string }> {
    return this.http.post<{ sent_to: string }>(`${API_BASE}/invoices/${id}/email`, {});
  }
  recordPayment(id: number, data: Record<string, unknown>): Observable<Invoice> {
    return this.http.post<Invoice>(`${API_BASE}/invoices/${id}/payments`, data);
  }
  claim(id: number, data: Record<string, unknown>): Observable<Claim> {
    return this.http.post<Claim>(`${API_BASE}/invoices/${id}/claims`, data);
  }
  claims(status = 'pending'): Observable<{ data: Claim[] }> {
    return this.http.get<{ data: Claim[] }>(`${API_BASE}/payment-claims`, { params: { status } });
  }
  decideClaim(id: number, decision: 'confirm' | 'reject', note?: string): Observable<Claim> {
    return this.http.post<Claim>(`${API_BASE}/payment-claims/${id}/${decision}`, { note });
  }
  downloadPdf(inv: { id: number; invoice_no: string | null }): Observable<Blob> {
    return this.http.get(`${API_BASE}/invoices/${inv.id}/pdf`, { responseType: 'blob' }).pipe(
      tap((blob) => {
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `Invoice-${(inv.invoice_no ?? 'DRAFT-' + inv.id).replace(/\//g, '-')}.pdf`;
        a.click();
        setTimeout(() => URL.revokeObjectURL(a.href), 5000);
      }),
    );
  }
}

export const STATUS_LABEL: Record<InvoiceStatus, string> = {
  draft: 'Draft', unpaid: 'Unpaid', partial: 'Part paid', paid: 'Paid', cancelled: 'Cancelled',
};
