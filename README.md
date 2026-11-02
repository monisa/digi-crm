# DigiFox Pro CRM

Internal CRM for **DigiFox Pro Solutions LLP**: leads (Meta Lead Ads), clients and client portal, employees and leave, notifications, expenses and an admin dashboard.

**Live URL (planned):** https://crm.digifoxprosolutions.com

| Folder | What it is |
|---|---|
| `web/` | Angular frontend (the CRM screens) |
| `api/` | Laravel API (PHP 8.3), served at `/api` |
| `deploy/` | Hostinger deployment script |
| `docs/` | Build plan and UI prototype |

The full feature plan, database design and phase roadmap are in [`docs/PLAN.md`](docs/PLAN.md).

## Run it locally

Needs PHP 8.3, Composer and Node 22.

```bash
# 1. API (http://localhost:8000)
cd api
composer install
cp .env.example .env            # then set DB_CONNECTION=sqlite for local use, APP_ENV=local, APP_DEBUG=true
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan crm:create-admin    # your first admin login
php artisan serve
```

```bash
# 2. Frontend (http://localhost:4200), in a second terminal
cd web
npm install
npx ng serve
```

`ng serve` forwards `/api/...` to the local Laravel server (`web/proxy.conf.json`), so the app talks to the API the same way it does in production.

Tests: `php artisan test` in `api/`, `npx ng test --watch=false` in `web/`.

## How it runs on Hostinger

```
crm.digifoxprosolutions.com/        -> Angular build (web/dist/web/browser)
crm.digifoxprosolutions.com/api/... -> Laravel (symlink to ~/digi-crm/api/public)
```

- API routes are versioned: `https://crm.digifoxprosolutions.com/api/v1/...`
- The Laravel code and `.env` live outside the website folder (`~/digi-crm`), so they can't be opened from a browser.
- `web/public/.htaccess` sends every non-file URL to `index.html` for Angular routing and forces HTTPS.

### First-time server setup (once)

1. hPanel → **Domains → Subdomains**: create `crm`. hPanel → **Security → SSL**: install SSL for it.
2. hPanel → **Advanced → PHP Configuration**: PHP 8.3.
3. hPanel → **Databases → MySQL**: create the database and user.
4. hPanel → **Advanced → SSH Access**: enable, then connect and run:
   ```bash
   git clone https://github.com/monisa/digi-crm.git ~/digi-crm
   cd ~/digi-crm/api && cp .env.example .env && nano .env     # DB and mail details
   composer install --no-dev && php artisan key:generate
   php artisan migrate --force && php artisan db:seed --force
   php artisan crm:create-admin        # asks for your name, email and password
   ```
5. hPanel → **Advanced → Cron Jobs**: every minute,
   `cd ~/digi-crm/api && php artisan schedule:run >> /dev/null 2>&1`

### Deploying an update

- **Automatic (recommended):** add the SSH secrets listed in `.github/workflows/deploy.yml` to the GitHub repo, then run **Actions → Deploy to Hostinger**.
- **Manual:** run `bash ~/digi-crm/deploy/server-deploy.sh` on the server, then upload the contents of `web/dist/web/browser/` (from `npx ng build`) into the `crm` subdomain folder, keeping the `api` link.

Check it worked: https://crm.digifoxprosolutions.com/api/v1/health should return `{"status":"ok"}`.

## Accounts and access

- **Admin**: full access; manages everything under **Settings**.
- **Employees**: accounts are created by the admin; what they see is set in **Settings → Role permissions** (for all employees) and **Settings → Users & access → Access** (for one person).
- **Clients**: register themselves at `/register`, must complete the onboarding form, then only see Dashboard, Campaigns, Invoices & Payments and Tickets (never internal sections).
- 5 wrong passwords lock the login for 15 minutes. Password reset links are emailed from `info@digifoxprosolutions.com` and expire after 60 minutes.

## Employees, payroll and leave

- **Employees**: add people under Employees → Add employee. Tick "Create a CRM login" to email them a link to set their password.
- **Salary**: on a person's profile → Salary & payslips → Set salary. A revision applies from its start date; history is kept.
- **Payroll** (each month, after month end): Employees → Payroll & payslips → choose the month → Generate payslips → check the drafts → Publish. Employees then download their PDF payslip. Loss-of-pay leave and days before joining are deducted automatically. Published payslips are never changed.
- **Leave**: Casual 4 and Sick 6 days a year (change under Leave → Leave policy), plus unpaid Loss of pay. Leave days skip Sundays and public holidays. The admin approves or rejects (with a reason) under Leave → Approvals.
- **Public holidays**: Leave → Public holidays. "Add national holidays" adds Republic Day, May Day, Independence Day, Gandhi Jayanti and Christmas; add festival holidays (Onam, Vishu, Eid, Diwali…) each year since their dates change.
- Payslips print `COMPANY_NAME`, `COMPANY_ADDRESS` and `COMPANY_EMAIL` from `api/.env`.

## Leads and Meta Lead Ads

- **Leads** follow the DigiFox lead sheet: Date, AD Name, Name, Phone Number, Email ID, Location (City), Call Status, Status, Package, Requirement, Follow-up Status, Notes / Remarks.
- **Bring in the existing sheet**: Google Sheets → File → Download → CSV, then Leads → Import sheet. Rows whose phone/email is already in the CRM are skipped, so it is safe to import again. Leads → Export gives the same columns back.
- **Who sees which leads**: give staff the Leads permissions (Settings → Role permissions or a person's Access); by default they see only leads assigned to them. Settings → Users & access → "Leads: all" lets someone see and reassign every lead.
- **Automatic assignment**: Leads → Options → share new Meta leads in turn between chosen people.
- **Meta connection** (Settings → Integrations shows the same steps):
  1. Set `META_APP_SECRET` and `META_VERIFY_TOKEN` (any long random text) in `api/.env`, then `php artisan config:cache`.
  2. In the Meta app, add Webhooks → Page → `leadgen` with callback `https://crm.digifoxprosolutions.com/api/v1/webhooks/meta` and the same verify token.
  3. Create a Business Manager system user token with `leads_retrieval`, `pages_show_list`, `pages_read_engagement`, `pages_manage_metadata`, `pages_manage_ads`, and paste the Page ID and token in Settings → Integrations.
  4. The cron must run every minute (see server setup): it processes incoming leads and re-checks Meta every 15 minutes.

## Clients, invoices and UPI payments

1. **Settings → Company & billing** (once): registered name and address, GSTIN, PAN, the company **UPI ID**, bank details, invoice prefix (numbers look like `DFP/2026-27/001`), default SAC and terms. These are stored on the server only, never in this repository.
2. **Clients**: add clients (or convert a won lead). Invite the client's login from their profile; they fill the onboarding form on first login.
3. **Invoices → New invoice**: pick the client, add lines (amounts before GST). GST is worked out automatically: same state as DigiFox → CGST + SGST, other state → IGST, outside India → zero-rated export. Save as draft or Issue. Issued invoices are locked (cancel and re-issue to change).
4. **Getting paid**: the invoice page and PDF show a **UPI "scan to pay" QR** with the amount and invoice number filled in (GPay, PhonePe, Paytm…). After paying, the client taps **I've paid** and enters the UPI transaction ID. You check the bank and press **Received ✓** under Invoices → Payments to confirm. Bank transfers, cash and cheques can be recorded by hand with **Record payment**.
5. **Email to client** sends the PDF from `info@digifoxprosolutions.com`.

## Campaigns, tickets and the client portal

- **Campaigns**: create one per thing you run for a client (Meta ads, Google ads, SEO, social…). Update the results (spend, leads, reach, clicks) and write a short update; upload monthly reports. Clients see their own campaigns read-only, with cost per lead and click rate worked out.
- **Tickets**: clients raise requests from the portal (with a file if needed). Staff reply, change status/priority, assign, and can tick **Internal note** for comments the client never sees. A client reply reopens a resolved ticket.
- **Client home**: amount to pay with a Pay now link, active campaigns and leads, open requests with new replies, and their account manager's contact.

## Notifications

- The bell (top right) shows alerts for everyone: new and assigned leads, leads not contacted within `CRM_LEAD_SLA_MINUTES` (default 30), client requests and replies, leave requests and decisions, "I've paid" notes and confirmations, new invoices, and clients finishing onboarding.
- The app checks for new alerts every minute while it is open; the browser tab shows the unread count.
- Important alerts are also emailed (from `info@digifoxprosolutions.com`); each person can switch emails off per type under **Notifications**. Bell alerts are saved instantly; emails are sent by the cron worker within a minute.
- Overdue invoices: a reminder email goes to the client each week, at most 4 times.
- All of this needs the every-minute cron from the server setup.

## Turning on "Sign in with Google"

1. Go to https://console.cloud.google.com → create a project (e.g. "DigiFox CRM").
2. **APIs & Services → OAuth consent screen**: External, app name "DigiFox Pro CRM", support email `info@digifoxprosolutions.com`, add the logo, then publish.
3. **APIs & Services → Credentials → Create credentials → OAuth client ID** → *Web application*.
   - Authorised JavaScript origins: `https://crm.digifoxprosolutions.com` (and `http://localhost:4200` for local testing)
   - No redirect URI is needed.
4. Copy the **Client ID** (ends in `.apps.googleusercontent.com`) into `api/.env` on the server:
   `GOOGLE_CLIENT_ID=...` then run `php artisan config:cache`.

The Google button appears automatically once the ID is set (leave it empty to hide it). Existing users sign in by matching email; anyone new becomes a client and goes through onboarding. Employees must be created by the admin first.

## Dashboard preview data

To see the dashboard with realistic sample figures on your own computer (never in production):
`php artisan db:seed --class=DemoDataSeeder`

## Security rules

- Never commit `.env`, passwords, bank details or API keys. This repository is public.
- Company bank details, GSTIN and Meta tokens are entered in the CRM's Settings screen and stored in the database.
