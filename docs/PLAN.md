# DigiFoxPro CRM: Build Plan

**URL:** https://crm.digifoxprosolutions.com
**Stack:** Angular 18 (frontend) · Laravel 11 / PHP 8.3 (API under `/api`) · MySQL (managed with phpMyAdmin) · Hostinger Business hosting
**Roles:** Admin · Employee · Client

---

## 1. Architecture

```
Browser ──► crm.digifoxprosolutions.com        Angular SPA (static build in public_html/crm)
        └─► crm.digifoxprosolutions.com/api    Laravel REST API (JSON)
                                               │
                     ┌─────────────────────────┼──────────────────────────┐
                  MySQL DB              Storage (payslips,           Cron (every 1 min →
                                        receipts, invoices)          Laravel scheduler)
                                               │
                         Meta Lead Ads webhook ─► /api/webhooks/meta
```

- **Auth:** Laravel Sanctum bearer tokens. Angular stores the token and an HTTP interceptor attaches it to every request.
- **Authorization:** checked on the server on every request (Laravel Policies plus permission middleware). Angular only hides menus; it never decides access.
- **System email:** `info@digifoxprosolutions.com` via Hostinger SMTP (notifications, password resets, invoices).
- **Notifications:** stored in the database. Angular polls `/api/notifications/unread-count` every 60 seconds (shared hosting has no WebSockets). Email goes out through Hostinger SMTP.
- **Background jobs:** a single Hostinger cron runs `php artisan schedule:run` every minute. The queue uses the `database` driver.
- **Server layout:** Laravel code lives outside `public_html` (e.g. `~/crm-api`). Only `public/` is linked to `public_html/crm/api`.

---

## 2. Roles and the access-control model (admin controls everything)

Access is checked at three levels:

| Level | What it controls | Who sets it |
|---|---|---|
| **1. Role** | Default menu set for Admin / Employee / Client | System default |
| **2. Module permission** | Per role and per user: `view`, `create`, `edit`, `delete`, `export`, `approve` on each module | Admin → Settings → Roles & Permissions |
| **3. Record access** | Which leads and clients an employee can see: *All*, *Assigned to me*, or *Specific clients* | Admin → user profile → Record Access |

A permission set on a user overrides the role default for that user.

**Modules in the permission matrix:** Dashboard, Employees, Leads, Clients, Campaigns, Invoices & Payments, Tickets, Expenses, Notifications, Settings, Leave Management.

**Client role is locked down.** Clients only ever see their own record and four menus: Dashboard, Campaigns, Invoices & Payments, Tickets. Admin can switch individual client menus on or off but cannot give clients internal modules.

---

## 3. Authentication flows (based on the Figma design)

### 3.1 Register
- **Clients:** self-register (name, company, email, mobile, password) → email verification → account status `pending_onboarding`.
- **Employees:** recommended that the admin creates the account (Employees → Add), which emails an invite link to set a password. Self-registration for employees is possible, but then the account stays `pending_approval` until the admin approves it.

### 3.2 Login
Email + password → token → redirect by role and status:
- Admin → Admin Dashboard
- Employee → Employee Dashboard (My Profile, plus any modules the admin granted)
- Client with `pending_onboarding` → **Onboarding Form** (every other route is blocked)
- Client with `active` → Client Dashboard

Lock the login for 15 minutes after 5 failed attempts. Offer an optional "Remember me".

### 3.3 Forgot password
Enter email → reset link (valid 60 minutes) → set new password → all old tokens are revoked.

### 3.4 Onboarding gate (clients, and optionally new employees)
After the first login the user must complete the onboarding form, which is rebuilt inside the CRM from your Google Form. Until it's submitted the API returns `403 ONBOARDING_REQUIRED` and Angular redirects to the form. When it's submitted, status becomes `active`, the admin is notified, and the menus unlock.

> The Google Form, Google Sheet and Figma links couldn't be opened from my session. Please export the form questions and the sheet headers (or paste them) so the fields can be matched exactly. For now the plan uses placeholder fields.

---

## 4. Module workflows

### 4.0 Admin Dashboard (build first)
**KPI tiles:** Total Clients (active/new this month) · Total Employees (active, on leave today) · Total Revenue (payments received: this month, year to date) · Total Expenses (this month, year to date) · Net (revenue − expenses).
**Widgets:** Revenue vs expenses (12-month chart) · Lead funnel (New → Contacted → Qualified → Won/Lost) · Uncontacted leads (count + list) · Open tickets by priority · Pending leave requests · Overdue invoices · Recent activity feed.
**Filters:** date range, employee.
**Endpoint:** `GET /api/dashboard/admin?from=&to=`. One aggregated response, cached for 5 minutes.

### 4.1 Employees module
Page layout: an Employees list for the admin, and a "My Profile" view for employees.

**Tab 1: Details.** Name, employee code, office email, mobile, designation, department, DOB, DOJ, address, reporting manager, status, photo.
- Admin: full create/edit. Employee: view only, can request profile changes.

**Tab 2: Salary & Payslips.**
- Salary structure (admin only to edit): CTC, basic, HRA, allowances, deductions (PF, PT, TDS), effective-from date.
- Payslips: month-wise list with **Download PDF**. Two ways to create them: (a) admin uploads a PDF, or (b) the system generates a PDF from the salary structure plus that month's leave-without-pay days (Phase 2).
- An employee sees only their own payslips.

**Tab 3: Leave.**
- **Balance cards:** Casual / Sick for this year: allotted, used, pending, remaining.
- **Request Leave:** type, from–to date, half-day option, reason → system checks the balance and overlaps → status `pending`.
- **Lists:** Pending · Approved · Rejected · History.
- **Approval:** the reporting manager or admin approves or rejects with a comment → balance updates → the employee is notified.
- **Admin leave settings** (Settings → Leave Policy): yearly quota per leave type (e.g. CL 12, SL 6), monthly accrual or all upfront, whether unused leave carries forward, holiday calendar, weekly offs.

### 4.2 Leads module (Meta integration)

**Lead capture → Meta**
1. Create a Meta App (Business type) in developers.facebook.com. Add **Webhooks** and request the permissions `leads_retrieval`, `pages_show_list`, `pages_read_engagement`, `pages_manage_metadata`, `ads_management`. App Review and Business Verification are needed to use it in production.
2. In CRM Settings → Integrations → Meta: click "Connect Facebook" (OAuth) → choose Page(s) and lead forms → the CRM stores a long-lived Page token, encrypted.
3. The CRM subscribes the Page to the `leadgen` webhook pointing at `https://crm.digifoxprosolutions.com/api/webhooks/meta`.
4. When a lead arrives, Meta sends a `leadgen_id`. The CRM calls the Graph API `/{leadgen_id}`, maps the fields to a Lead, stores the raw JSON, and removes duplicates by phone or email.
5. **Safety net:** a cron job every 15 minutes pulls `/{form_id}/leads?since=` to catch any missed webhooks.
6. **Manual sources:** CSV/Excel import that matches your Google Sheet column format (with a column-mapping screen) and a manual "Add Lead" form.

**Lead fields (to be aligned with your sheet):** full name, phone, email, city, campaign name, ad set, ad name, form name, platform (FB/IG), created time, custom form answers (JSON), source, status, assigned to, next follow-up, notes.

**Lead workflow**
```
New (uncontacted) → Contacted → Interested/Qualified → Proposal Sent → Won → (convert to Client)
                               ↘ Not Interested / Lost (with reason)
```
- **Auto-assignment rule** (Settings): round-robin, by campaign, or manual.
- **Every interaction is logged as an Activity** (call, WhatsApp, email, meeting, note) with an outcome and the next follow-up date.
- **SLA:** leads still uncontacted after X minutes or hours (set by admin) trigger notifications and appear on dashboards.
- **Convert to Client:** creates a Client record plus a client login invitation, and links the lead history to the client.
- **Views:** table with filters (status, campaign, date, assignee) and a Kanban board. Export depends on the user's permission.

### 4.3 Clients module
**Internal view (admin and permitted employees):** client list → client profile with these tabs: Overview · Onboarding Details · Campaigns · Invoices & Payments · Tickets · Documents · Activity · Assigned Team.

**Client portal (client role, own record only):**
- **Dashboard:** active campaigns, key campaign metrics, amount outstanding, open tickets, latest updates.
- **Campaigns:** list of campaigns run for them (name, platform, budget, dates, status, results/reports PDF).
- **Invoices & Payments:** invoice list (status Unpaid/Partial/Paid/Overdue), download PDF, payment history, optional "Pay Now" (Razorpay, Phase 3).
- **Tickets:** raise ticket (subject, category, priority, description, attachment) → threaded replies → status Open / In Progress / Waiting on Client / Resolved / Closed.

**Data isolation:** every client query is filtered by `client_id = auth()->user()->client_id` through a global scope, so it can't be bypassed by changing the URL.

> The sample "client CRM" PDF didn't come through. Please re-attach it so the portal screens can match it.

### 4.4 Notifications module
| Trigger | Who is notified | Channel |
|---|---|---|
| New lead arrives / assigned | Assignee, admin | In-app (+ email optional) |
| Lead uncontacted beyond SLA | Assignee, admin | In-app + email digest |
| Ticket created / replied / status changed | Assigned staff, client | In-app + email |
| Leave requested | Manager, admin | In-app + email |
| Leave approved / rejected | Employee | In-app + email |
| Invoice due / overdue | Client, admin | Email + in-app |
| Client onboarding submitted | Admin | In-app |

- **Bell icon** shows the unread count and a dropdown list. A full Notifications page has filters (Leads / Tickets / Leaves), mark as read, and clickthrough to the record.
- **"Uncontacted Leads" panel:** count plus details (name, campaign, age, assignee).
- Each user can switch email on or off per notification type in their preferences.

### 4.5 Expenses module
- **Add expense:** date, category (Ads spend, Salary, Rent, Software, Travel, Misc. Admin manages the list), amount, GST, payment mode, vendor, linked client/campaign (optional), receipt upload, notes.
- **Optional workflow:** employee submits → admin approves (for reimbursements).
- **Views:** list with filters, monthly summary by category, export to Excel/PDF.
- These figures feed Total Expenses and Net on the admin dashboard.

### 4.6 Admin management and settings
- **Users & Roles:** create or deactivate users, reset passwords, assign role, reporting manager.
- **Permission matrix:** grid of modules × actions, per role and per user.
- **Record access:** assign clients or leads to employees, or grant "all records".
- **Leave policy:** quotas, holidays, weekly offs.
- **Lead settings:** statuses, lost reasons, assignment rules, SLA minutes.
- **Integrations:** Meta connection, SMTP, Razorpay (later).
- **Company profile:** logo, address, GSTIN, invoice prefix and number series, bank details. Entered through this screen and stored in the database, never in the code repository.
- **GST logic on invoices:** client in Kerala (state code 32) → CGST 9% + SGST 9%; client in another state → IGST 18%; overseas client → export of services (LUT/zero-rated). Client GSTIN and place of supply are captured at onboarding.
- **Expense categories, ticket categories.**
- **Audit log:** who changed what and when (important because the admin can grant broad access).

---

## 5. Database tables (MySQL)

**Core & access**
- `users` (id, name, email, password, role[admin|employee|client], status[pending_onboarding|pending_approval|active|inactive], client_id NULL, employee_id NULL, last_login_at)
- `modules` (id, key, name)
- `permissions` (id, module_id, action)
- `role_permissions` (role, permission_id)
- `user_permissions` (user_id, permission_id, allowed bool), which overrides the role default
- `record_access` (user_id, scope[all|assigned|specific], entity_type, entity_id NULL)
- `password_resets`, `personal_access_tokens`, `audit_logs`

**Employees & HR**
- `employees` (id, user_id, emp_code, name, office_email, mobile, designation, department, dob, doj, address, reporting_manager_id, photo, status)
- `salary_structures` (id, employee_id, ctc, basic, hra, allowances JSON, deductions JSON, effective_from)
- `payslips` (id, employee_id, month, year, gross, deductions, net, lop_days, file_path, published_at)
- `leave_types` (id, name, code[CL|SL], yearly_quota, accrual[monthly|yearly], carry_forward bool, max_carry)
- `leave_balances` (id, employee_id, leave_type_id, year, allotted, used, pending)
- `leave_requests` (id, employee_id, leave_type_id, from_date, to_date, half_day, days, reason, status, approver_id, approver_comment, actioned_at)
- `holidays` (id, date, name)

**Leads**
- `meta_connections` (id, page_id, page_name, access_token_encrypted, expires_at)
- `meta_forms` (id, connection_id, form_id, form_name, field_mapping JSON, active)
- `leads` (id, meta_leadgen_id UNIQUE NULL, source, full_name, phone, email, city, campaign_name, adset_name, ad_name, form_name, platform, custom_fields JSON, raw_payload JSON, status, lost_reason, assigned_to, first_contacted_at, next_followup_at, converted_client_id, created_at)
- `lead_activities` (id, lead_id, user_id, type, outcome, note, followup_at, created_at)

**Clients & portal**
- `clients` (id, company_name, contact_name, email, phone, gstin, address, industry, onboarding_status, onboarding_data JSON, account_manager_id, source_lead_id)
- `client_user_access` (client_id, user_id), which links employees to their clients
- `campaigns` (id, client_id, name, platform, objective, budget, start_date, end_date, status, metrics JSON, report_file)
- `invoices` (id, client_id, invoice_no, issue_date, due_date, subtotal, tax, total, amount_paid, status, pdf_path)
- `invoice_items` (id, invoice_id, description, qty, rate, amount)
- `payments` (id, invoice_id, client_id, amount, date, mode, reference, gateway_payload JSON)
- `tickets` (id, client_id, raised_by, subject, category, priority, status, assigned_to, created_at, resolved_at)
- `ticket_messages` (id, ticket_id, user_id, message, attachment, internal bool)

**Operations**
- `expenses` (id, date, category_id, amount, gst, payment_mode, vendor, client_id NULL, campaign_id NULL, receipt_path, notes, status, created_by, approved_by)
- `expense_categories` (id, name)
- `notifications` (Laravel standard: id, notifiable_id, type, data JSON, read_at)
- `notification_preferences` (user_id, type, email bool, in_app bool)
- `settings` (key, value JSON)

---

## 6. API outline (REST, `/api/v1`)

```
Auth        POST /auth/register | /auth/login | /auth/logout | /auth/forgot | /auth/reset
            GET  /auth/me            (user + role + permissions + menus + onboarding status)
Onboarding  GET/POST /onboarding
Dashboard   GET  /dashboard/admin | /dashboard/employee | /dashboard/client
Employees   CRUD /employees ; GET /employees/{id}/payslips ; GET /payslips/{id}/download
            CRUD /employees/{id}/salary
Leave       GET /leave/balances ; GET/POST /leave/requests ; POST /leave/requests/{id}/approve|reject
Leads       CRUD /leads ; POST /leads/import ; POST /leads/{id}/activities ; POST /leads/{id}/convert
            POST /leads/bulk-assign ; GET /leads/export
Webhook     GET/POST /webhooks/meta   (verify token + leadgen events, signature checked)
Clients     CRUD /clients ; GET /clients/{id}/overview
Campaigns   CRUD /campaigns
Invoices    CRUD /invoices ; GET /invoices/{id}/pdf ; POST /invoices/{id}/payments
Tickets     CRUD /tickets ; POST /tickets/{id}/messages
Expenses    CRUD /expenses ; GET /expenses/summary
Notifs      GET /notifications ; GET /notifications/unread-count ; POST /notifications/read
Settings    GET/PUT /settings/{group} ; CRUD /roles/permissions ; PUT /users/{id}/permissions
            PUT /users/{id}/record-access ; CRUD /leave-types ; CRUD /holidays
```

---

## 7. Angular structure

```
src/app/
├── core/        auth.service, token interceptor, error interceptor, guards (auth, role, permission, onboarding)
├── shared/      UI components (table, modal, file upload, status badge, charts), pipes, directives (*hasPermission)
├── layouts/     auth-layout (login/register/forgot per Figma), app-layout (sidebar + topbar + bell)
└── features/    dashboard/ employees/ leads/ clients/ portal/ notifications/ expenses/ settings/
```
- The sidebar is built from `GET /auth/me → menus`, so whatever the admin changes takes effect at the next login or refresh.
- UI library: Angular Material or PrimeNG (PrimeNG has strong ready-made tables and charts for a CRM). Styling follows the Figma design.
- Every feature is a lazy-loaded route.

---

## 7a. UI / UX standards (responsive, charts, animation)

**Prototype:** `prototype/admin-dashboard.html`, the reference for look and feel.

- **Brand:** primary orange `#ED8F21`, deep orange `#D77328`, dark sidebar `#121418`, light content area `#F4F5F8`. Logo (white text) sits on the dark sidebar and login panel. A dark-text logo is needed for invoices, payslips and emails.
- **Responsive breakpoints:**
  - **≥1280px laptop/desktop:** full sidebar (collapsible to icons), 4 KPI tiles in a row, charts side by side.
  - **860–1280px small laptop/tablet:** 2-column KPIs, charts stack.
  - **<860px mobile:** sidebar becomes a slide-in drawer (hamburger), a floating bottom navigation bar (Home, Leads, Clients, Alerts, More) appears, 2×2 KPI tiles, charts show 6 months instead of 12, and tables turn into cards.
  - Touch targets ≥ 40px, no horizontal scrolling on any screen.
- **Charts:** Chart.js (`ng2-charts`) or ApexCharts in Angular. Brand-validated colours: revenue `#D9761C`, expenses `#2F6FDE`, third series `#16A394` (checked for colour-blind readability). Every chart has a legend, a hover/tap tooltip and a table/export view.
- **Animation and transitions:** cards fade up in sequence on load; KPI numbers count up; bars grow and funnel bars fill when scrolled into view; cards lift on hover; menu items slide on hover; pulsing badges for uncontacted leads and new notifications; a shine sweep on primary buttons; smooth page-route transitions (Angular animations); skeleton loaders instead of spinners. All motion is switched off for users who set "reduce motion" on their device.
- **Components:** PrimeNG (tables, dialogs, date pickers, file upload) themed to the brand, plus custom CSS for KPI cards and badges.
- **Mobile-specific screens:** lead list as swipeable cards with tap-to-call and WhatsApp buttons; leave request as a bottom sheet; client portal fully usable on phones.
- **Future:** installable PWA (Add to Home Screen) and dark mode.

---

## 8. Phased build plan (dashboard first, as requested)

| Phase | Scope | Est. |
|---|---|---|
| **0. Setup** | Laravel + Angular projects, Git repo, Hostinger subdomain, SSL, DB, deploy script, `.htaccess` | 2–3 days |
| **1. Foundation** | Auth (login/register/forgot per Figma), roles, permission matrix, record access, app layout, audit log | 1–1.5 wk |
| **2. Admin Dashboard** | KPI tiles and charts (with seeded demo data until the modules fill it) | 3–4 days |
| **3. Employees + Leave** | Details tab, salary and payslip upload/download, leave policy, request → approve flow | 1.5 wk |
| **4. Leads + Meta** | Lead CRUD, statuses, activities, Kanban, CSV import, Meta webhook + polling, assignment, convert to client | 1.5–2 wk (plus Meta App Review time) |
| **5. Clients + Portal** | Onboarding gate/form, client profile, campaigns, invoices and payments, tickets, data isolation | 2 wk |
| **6. Notifications** | All triggers, bell, uncontacted leads panel, email templates, preferences, cron | 4–5 days |
| **7. Expenses** | CRUD, categories, receipts, summary, dashboard link-up | 3–4 days |
| **8. Hardening & Launch** | Security review, backups, testing on all roles, user training data, go-live | 1 wk |
| **Later** | Auto payslip PDF generation, Razorpay, WhatsApp API for leads, employee attendance, mobile PWA | — |

Total MVP is roughly 10–12 weeks with one developer working with Claude.

---

## 9. How to build it with Claude, one prompt per step

Use these prompts in order. Each one is a self-contained task.

1. *"Create a Laravel 11 API project for a CRM with Sanctum auth, roles (admin, employee, client), the users/modules/permissions/role_permissions/user_permissions/record_access tables, seeders for modules and default role permissions, and permission middleware. Include register, login, logout, forgot/reset password and `/auth/me` returning menus."*
2. *"Create an Angular 18 app with an auth layout (login, register, forgot password) matching [attach Figma screenshots], token interceptor, auth/role/permission/onboarding guards, and a sidebar layout built from `/auth/me` menus."*
3. *"Build the admin settings screen for the permission matrix (modules × actions per role and per user) and record access, with the matching Laravel endpoints."*
4. *"Build the Admin Dashboard API `/dashboard/admin` and Angular page with KPI tiles (clients, employees, revenue, expenses, net) and charts, using seeded demo data."*
5. *"Build the Employees module: migrations, API and Angular page with three tabs: Details, Salary & Payslips (upload/download PDF), Leave (balances, request, pending/approved lists, manager approval). Include admin Leave Policy settings for CL and SL quotas."*
6. *"Build the Leads module with these fields [paste sheet headers]: CRUD, status pipeline, activities, Kanban, CSV import with column mapping, bulk assign, export."*
7. *"Add Meta Lead Ads integration: OAuth connect page, page/form selection, webhook endpoint with verify token and signature check, Graph API lead fetch, field mapping, dedupe, and a 15-minute polling job for missed leads."*
8. *"Build the client onboarding form with these fields [paste Google Form questions] and the onboarding gate, then the Clients module and the client portal (dashboard, campaigns, invoices & payments, tickets) with strict client_id isolation."*
9. *"Build the Notifications system: database notifications for leads/tickets/leaves/invoices, the uncontacted-leads SLA job, bell with unread count polling, notifications page, email templates, preferences."*
10. *"Build the Expenses module with categories, receipts, approval, monthly summary, and connect totals to the admin dashboard."*
11. *"Prepare Hostinger deployment: build script, folder layout, .htaccess for Angular and Laravel, cron for the scheduler, .env checklist, daily DB backup."*

---

## 10. Inputs still needed from you

1. **Google Sheet headers** (Meta lead format): paste the first row.
2. **Google Form questions** (onboarding): paste or export them.
3. **Figma screens:** export login/register/forgot screens as PNG (Figma link requires login).
4. **Client CRM sample PDF:** re-attach.
5. **Leave quotas:** e.g. CL 12/year, SL 6/year? Monthly accrual? Carry forward?
6. **Who approves leave:** reporting manager, admin, or both?
7. **Payslips:** upload ready PDFs, or generate them from salary data?
8. **Employee registration:** admin-created (recommended) or self-register with approval?
9. **Invoices:** created in the CRM (with GST), or uploaded from Tally/Zoho?
10. **Payment gateway** for clients paying online: Razorpay?
