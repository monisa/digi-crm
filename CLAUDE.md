# DigiFox Pro CRM: notes for Claude

- Monorepo: `web/` = Angular (standalone components, signals, new control flow `@if/@for`), `api/` = Laravel 13 API only (no Blade views, no Vite).
- API routes live in `api/routes/api.php` with prefix `v1` (see `api/bootstrap/app.php`). Public URL is `/api/v1/...`; locally `php artisan serve` serves `/v1/...` and the Angular dev proxy rewrites `/api`.
- Frontend calls the API through `API_BASE` in `web/src/app/core/api.config.ts`, never a hard-coded host.
- Design tokens (brand orange `#ED8F21`, chart series colours, motion easing) are CSS variables in `web/src/styles.scss`. Reuse them; respect `prefers-reduced-motion`.
- Every screen must work on mobile (<860px: drawer menu + bottom nav) and laptop.
- Authorization is enforced in Laravel (policies + permission middleware). Clients may only ever see their own `client_id` data.
- Hosting is Hostinger Business (shared): no WebSockets, no long-running workers. Use DB queue + `schedule:run` cron, and polling for notifications.
- The repo is public: never commit secrets, `.env`, bank details or tokens.
- Before finishing a change, run `php artisan test` in `api/` and `npx ng test --watch=false && npx ng build` in `web/`.
- Full plan: `docs/PLAN.md`.
