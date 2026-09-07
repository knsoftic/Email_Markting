# KN Softic — Email Marketing & Inbox SaaS
## Master Work Log / Build Plan

**Project root:** `C:\xampp\htdocs\email markting`
**Created:** 2026-09-06
**Status legend:** ⬜ Not started · 🟨 In progress · ✅ Done · ⛔ Blocked

---

## 0. Environment Audit (verified on this machine)

| Item | Detected | Verdict |
|---|---|---|
| PHP | 8.2.12 (XAMPP, ZTS, VC2019 x64) | ✅ OK — Laravel 12 needs ≥ 8.2 |
| Composer | 2.10.2 | ✅ OK |
| Database | MariaDB 10.4.32 (XAMPP MySQL) | ✅ OK |
| Node / npm | 24.18.0 / 11.16.0 | ✅ OK (Vite build) |
| PHP ext present | pdo_mysql, mbstring, openssl, curl, gd, zip, fileinfo, bcmath, dom | ✅ OK |
| PHP ext **missing** | `imap`, `intl`, `sodium`, `pcntl` | ⚠️ see notes |
| Project folder | Empty | Fresh install |

**Notes / decisions forced by the environment**

1. **No `imap` PHP extension** → use `webklex/php-imap` in *native protocol* mode (pure-PHP socket client). No XAMPP recompile needed. **Decision locked.**
2. **No `pcntl`** → Windows queue workers run `queue:work` without graceful signals. Dev = worker in a terminal; production = Supervisor / Windows service, documented in `SETUP.md`.
3. **Scheduler on Windows** → Task Scheduler entry running `php artisan schedule:run` every minute (cron equivalent). Documented in `SETUP.md`.
4. **MariaDB 10.4** → index key-length limits. All indexed `VARCHAR` columns capped at 191 chars, `utf8mb4`. **Decision locked.**

---

## 1. Locked Technical Decisions

| Area | Choice | Reason |
|---|---|---|
| Framework | Laravel 12.x (latest stable) | Required by brief |
| Auth | Laravel Breeze (Blade), customised | Blade-native, no SPA overhead |
| UI | Tailwind CSS 3 + KN Softic design system | Fast, premium SaaS look |
| JS | Alpine.js 3 + vanilla | Required by brief |
| Charts | Chart.js 4 | Lightweight, no build friction |
| Rich text editor | Self-hosted Quill/Trumbowyg | Composer + template HTML |
| Tables | Server-side pagination (no DataTables bloat) | Handles 100k+ rows |
| Queue | `database` driver (Redis-ready via .env) | XAMPP has no Redis |
| Cache / Session | `database` (dev), Redis-ready | Same reason |
| IMAP | `webklex/php-imap` | No PHP ext required |
| CSV | `league/csv` | Streaming, memory-safe at 100k rows |
| Roles/Permissions | Custom lightweight engine (no Spatie) | Full control, fewer deps |
| Multi-tenancy | `account_id` + global scope + policies + middleware | Simple, fast, auditable |
| Secrets | Laravel `encrypted` cast on SMTP/IMAP passwords | Brief requirement |

---

## 2. Work Breakdown Structure

> **Unit** = one focused build step (a controller + its views + routes + policy, or a migration set, or one job). Used for relative sizing only, not clock time.

### PHASE 1 — Foundation ✅ *(≈ 14 units)*

| # | Task | Deliverables | Units | Status |
|---|---|---|---|---|
| 1.1 | Laravel 12.69.1 installed, `.env` + `.env.example` written, DB `knsoftic_mail` created (utf8mb4) | skeleton, `.env`, `.env.example` | 2 | ✅ |
| 1.2 | breeze 2.4.2, webklex/php-imap 6.2.0, league/csv 9.28.0 installed | `composer.json`, `package.json` | 1 | ✅ |
| 1.3 | Tailwind + Alpine + Vite building; KN Softic palette + component layer (`kn-*` classes) | `tailwind.config.js`, `app.css` | 1 | ✅ |
| 1.4 | **Full DB architecture** — 43 custom + 3 Laravel migrations, all applied clean | 46 migration files | 4 | ✅ |
| 1.5 | 38 models with relations, casts, scopes and helpers | 38 models | 3 | ✅ |
| 1.6 | Tenancy core: `TenantManager`, `AccountScope`, `BelongsToAccount`, 5 middleware, 5 passing isolation tests | traits, middleware, tests | 2 | ✅ |
| 1.7 | Branding layer driven by `settings` table: app + guest layouts, sidebar, topbar, flash, toast, logo, brand colours | layouts + components | 1 | ✅ |

**Tables created in 1.4:** users · accounts · account_user · roles · permissions · permission_role · plans · subscriptions · usage_counters · subscribers · custom_fields · subscriber_lists · list_subscriber · tags · subscriber_tag · segments · campaigns · campaign_variants · campaign_recipients · campaign_logs · campaign_links · email_templates · smtp_accounts · smtp_assignments · smtp_usage · mailboxes · mailbox_folders · emails · email_threads · email_attachments · email_opens · email_clicks · unsubscribes · suppressions · bounces · automations · automation_steps · automation_runs · signatures · activity_logs · notifications · settings · jobs · failed_jobs · job_batches · sessions · cache

---

### PHASE 2 — Auth, Super Admin, Plans, Roles ✅ *(≈ 13 units)*

| # | Task | Units | Status |
|---|---|---|---|
| 2.1 | Register / Login / Logout / Forgot / Reset / Verify — branded; login records last_login_at + IP; suspended logins rejected before a session is issued | 2 | ✅ |
| 2.2 | Profile: details, avatar upload/remove, change password, delete-account guard, 2FA status panel (encrypted columns in place) | 2 | ✅ |
| 2.3 | Roles & permissions engine + shared permission matrix UI + account-side Team module (members, custom roles, seat limits) | 2 | ✅ |
| 2.4 | Super Admin → Users: CRUD, suspend/activate, impersonate + reversible banner, delete; Accounts: detail, edit, suspend, delete | 2 | ✅ |
| 2.5 | Super Admin → Plans: CRUD + duplicate, 12 limits and 13 feature toggles, single-default enforcement, in-use plans deactivate instead of delete | 2 | ✅ |
| 2.6 | Subscriptions: list + filters, assign/replace plan, status, expiry, extend-by-days, per-account limit & feature overrides | 1 | ✅ |
| 2.7 | Super Admin dashboard: 14 live stat cards, 2 Chart.js graphs, queue + health panels, recent users/campaigns/activity | 1 | ✅ |
| 2.8 | Branding (logo/favicon upload, live colour preview), System settings, Payment settings, System Health (7 real probes), Queue Status (retry/flush), Activity Log | 1 | ✅ |

---

### PHASE 3 — Contacts ✅ *(≈ 11 units)*

| # | Task | Units | Status |
|---|---|---|---|
| 3.1 | Subscribers CRUD + account-defined custom fields (rename migrates values, delete removes them), 5 statuses, 8 bulk actions behind one allow-listed endpoint | 2 | ✅ |
| 3.2 | Lists CRUD + attach/detach; counters recomputed in one grouped query after bulk changes, never incremented per row | 1 | ✅ |
| 3.3 | Tags CRUD with inline edit + bulk apply/remove via the shared bulk endpoint | 1 | ✅ |
| 3.4 | CSV import: BOM/encoding/delimiter sniffing, column mapping with auto-guess, preview, chunked queued job, live progress polling, cancel, error-row report | 3 | ✅ |
| 3.5 | Streamed CSV export: all / selected / list / tag / segment, column picker, custom fields, mailable-only filter, Excel BOM | 1 | ✅ |
| 3.6 | Segments: descriptor registry (18 fields incl. opened/clicked/received + custom fields), compiler using EXISTS/NOT EXISTS, Alpine rule builder with live preview, cached counts | 2 | ✅ |
| 3.7 | Suppression list: paste-many add, reasons, bulk release, streamed CSV export, automatic hooks from unsubscribe/bounce | 1 | ✅ |

---

### PHASE 4 — SMTP Layer ✅ *(≈ 9 units)*

| # | Task | Units | Status |
|---|---|---|---|
| 4.1 | User SMTP CRUD, encrypted + hidden password, blank-on-edit keeps the stored credential, plan feature + seat limits | 2 | ✅ |
| 4.2 | 8 provider presets with real hosts/ports plus the notes people actually get wrong (Gmail App Password, SendGrid literal `apikey`, SES ≠ AWS keys, Zoho regional hosts) | 1 | ✅ |
| 4.3 | Test Connection does a real connect + EHLO + STARTTLS + AUTH; provider-specific diagnosis; records tested/passed/error/success | 1 | ✅ |
| 4.4 | Admin SMTP: global accounts, assignment scope none/all/plan/account replaced atomically, per-tenant volume view | 2 | ✅ |
| 4.5 | `SmtpSelector`: one atomic UPDATE does rollover + limit check + reservation; rotation by priority then least-used; failure streak → cooldown | 2 | ✅ |
| 4.6 | `MailerFactory` builds the transport by hand (no DSN, so the credential never lands in a URL), `SmtpSender` ties reserve → send → classify → record | 1 | ✅ |

---

### PHASE 5 — Templates & Campaigns ✅ *(≈ 15 units)*

| # | Task | Units | Status |
|---|---|---|---|
| 5.1 | Template CRUD + preview + duplicate | 1 | ✅ |
| 5.2 | **Block template builder** (heading, text, image, button, divider, columns, spacer, social, logo, footer, custom HTML) → JSON schema → responsive HTML compiler | 4 | ✅ |
| 5.3 | 9 ready-made responsive templates seeded (welcome, newsletter, promotion, product offer, course, event, discount, announcement, follow-up) | 2 | ✅ |
| 5.4 | Personalization engine + custom subscriber fields + preview with sample data | 1 | ✅ |
| 5.5 | Campaign builder: all fields, audience picker (list / tag / segment), SMTP picker, schedule + timezone | 3 | ✅ |
| 5.6 | Campaign actions: preview, duplicate, delete, test send, send now, pause, resume + final confirmation screen | 2 | ✅ |
| 5.7 | Recipient generation (dedupe + suppression filter) → `campaign_recipients` | 1 | ✅ |
| 5.8 | Queue pipeline: batching, rate limiting, retries, failed handling, live progress endpoint | 1 | ✅ |

---

### PHASE 6 — Tracking, Unsubscribe, Analytics ✅ *(≈ 9 units)*

| # | Task | Units | Status |
|---|---|---|---|
| 6.1 | Open tracking pixel (signed route) + first / last / count | 1 | ✅ |
| 6.2 | Click tracking: link rewriter + redirect route + first / last / count | 2 | ✅ |
| 6.3 | Unsubscribe page + confirmation + List-Unsubscribe header + preferences page | 2 | ✅ |
| 6.4 | Suppression auto-add + send-time guard | 1 | ✅ |
| 6.5 | Bounce handling (soft / hard) + auto-suppress after repeated hard bounces | 1 | ✅ |
| 6.6 | Campaign analytics: 14 metrics + Chart.js graphs + per-recipient drill-down | 2 | ✅ |

---

### PHASE 7 — Mailboxes & IMAP Sync ✅ *(≈ 11 units)*

| # | Task | Units | Status |
|---|---|---|---|
| 7.1 | Mailbox CRUD + provider presets + encrypted credentials + plan-limit enforcement | 2 | ✅ |
| 7.2 | Test IMAP connection + connection health indicator | 1 | ✅ |
| 7.3 | Folder discovery + mapping to Inbox / Sent / Drafts / Spam / Trash + custom labels | 2 | ✅ |
| 7.4 | `SyncMailboxJob`: incremental UID fetch, Message-ID dedupe, retry, error log, last-sync stamp | 3 | ✅ |
| 7.5 | Attachments: download, storage, size/type validation, storage-quota accounting | 2 | ✅ |
| 7.6 | Scheduler wiring: auto-sync every N minutes per active mailbox | 1 | ✅ |

---

### PHASE 8 — Inbox UI & Composer ✅ *(≈ 12 units)*

| # | Task | Units | Status |
|---|---|---|---|
| 8.1 | Inbox shell: folder rail, list pane, reading pane, responsive mobile view | 3 | ✅ |
| 8.2 | Email list: sender, subject, preview, date/time, attachment icon, read/unread, star, bulk select | 2 | ✅ |
| 8.3 | Reading screen: sanitised HTML render (sandboxed iframe), headers, CC, attachments, download | 2 | ✅ |
| 8.4 | Actions: reply, reply-all, forward, star, important, read/unread, move folder, delete, spam | 2 | ✅ |
| 8.5 | Composer: From/To/CC/BCC/Subject/Body, rich editor, attachments, signature insert | 2 | ✅ |
| 8.6 | Send / Save Draft / Schedule / Discard + outgoing copy stored in Sent | 1 | ✅ |

---

### PHASE 9 — Campaign Replies & Threads ✅ *(≈ 6 units)*

| # | Task | Units | Status |
|---|---|---|---|
| 9.1 | Reply-matching engine: `In-Reply-To` / `References` / Reply-To token → campaign + subscriber | 2 | ✅ |
| 9.2 | Campaign Replies screen: campaign, customer, preview, date, status (New / Read / Replied / Closed) | 2 | ✅ |
| 9.3 | Conversation threads: campaign → reply → reply chain in one view | 2 | ✅ |

---

### PHASE 10 — Automation, A/B, Notifications, Reports ✅ *(≈ 12 units)*

| # | Task | Units | Status |
|---|---|---|---|
| 10.1 | Automation builder UI (send email / wait / condition steps) + 4 statuses | 3 | ✅ |
| 10.2 | Automation triggers (7 types) + event listeners | 2 | ✅ |
| 10.3 | Automation runner job + scheduler tick + per-subscriber run state | 2 | ✅ |
| 10.4 | A/B testing: subject / sender / content variants, split send, winner report | 2 | ✅ |
| 10.5 | Internal notifications (9 event types) + bell dropdown + mark read | 2 | ✅ |
| 10.6 | Email logs + received-email logs with search / filter / export | 1 | ✅ |

---

### PHASE 11 — Hardening & Delivery ⬜ *(≈ 8 units)*

| # | Task | Units | Status |
|---|---|---|---|
| 11.1 | Security pass: policy on every model, mass-assignment audit, upload validation, rate limits, CSP for rendered email HTML | 2 | ⬜ |
| 11.2 | Performance pass: index verification, N+1 audit, chunking, cache, pagination everywhere | 2 | ⬜ |
| 11.3 | Queue + scheduler end-to-end test with 10,000 dummy recipients | 1 | ⬜ |
| 11.4 | Global search (sender, address, subject, campaign, subscriber, date range) | 1 | ⬜ |
| 11.5 | Activity logs across 11 event types | 1 | ⬜ |
| 11.6 | Seeders (demo account, plans, templates) + `SETUP.md` + `DEPLOY.md` + README | 1 | ⬜ |

---

## 3. Totals

| Phase | Units | Approx. files |
|---|---|---|
| 1 — Foundation | 14 | ~80 |
| 2 — Auth / Admin / Plans | 13 | ~65 |
| 3 — Contacts | 11 | ~45 |
| 4 — SMTP | 9 | ~25 |
| 5 — Templates & Campaigns | 15 | ~60 |
| 6 — Tracking & Analytics | 9 | ~25 |
| 7 — Mailboxes & IMAP | 11 | ~25 |
| 8 — Inbox & Composer | 12 | ~40 |
| 9 — Campaign Replies | 6 | ~15 |
| 10 — Automation / A/B / Notifications | 12 | ~45 |
| 11 — Hardening & Delivery | 8 | ~15 |
| **TOTAL** | **120 units** | **~440 files** |

**Rough composition:** ~38 migrations · ~30 models · ~45 controllers · ~25 services · ~20 jobs · ~15 policies · ~35 form requests · ~180 Blade views · ~12 events & listeners · ~8 console commands.

---

## 4. Rules Being Followed (from the brief)

- ✅ No dummy buttons — every control wired to a real route and action.
- ✅ No placeholder pages — a screen ships only when its module works.
- ✅ Nothing already working is deleted when a new module lands.
- ✅ Services / Jobs / Events / Policies / Form Requests reused; no copy-paste logic.
- ✅ Account isolation verified per module before that module is marked ✅.
- ✅ Permission-based marketing only: consent status, contact source, unsubscribe, suppression, bounce handling, rate limits. No spam-filter evasion, header faking, or sender-identity hiding will be built.

---

## 5. Session Log

| Date | Phase | What was done | Status |
|---|---|---|---|
| 2026-09-06 | 0 | Environment audited (PHP 8.2.12, Composer 2.10.2, MariaDB 10.4.32, Node 24.18.0). `imap` ext missing → `webklex/php-imap` locked in. Full work plan written. | ✅ |
| 2026-09-06 | 4 | **Phase 4 complete.** SMTP layer: 8 provider presets, atomic limit reservation, rotation, cooldown, real connection testing, failure classification, admin/global accounts with assignment scoping. Verified against a live Brevo server. Test suite: **201 passed**. | ✅ |
| 2026-09-06 | 3 | **Phase 3 complete.** Subscribers + custom fields, lists, tags, suppression, segment engine, CSV import (queued/chunked) and streamed export all built and tested. Switched the test suite from SQLite to MariaDB after a LIKE-escaping test exposed that JSON and LIKE behaviour was never actually being tested. Test suite: **130 passed**. Verified end to end in the browser, including a real 2,000-row queued import. | ✅ |
| 2026-09-06 | 2 | **Phase 2 complete.** Super Admin panel (18 screens), Plans CRUD with all 25 toggles, Users + Accounts + Subscriptions management, impersonation, roles & permissions, branding/system/payment settings, system health + queue status, activity log. Account-side Profile and Team modules. Found and fixed a real tenant-leak bug in `SetTenant`. Test suite: **57 passed**. | ✅ |
| 2026-09-06 | 1 | **Phase 1 complete.** Laravel 12.69.1 + MySQL + Breeze; 46 migrations; 38 models; tenancy core; branding layer; seeders (permissions, roles, 3 plans, settings, super admin); registration provisions account + owner + plan; login/logout activity logging; dashboard on real queries. Verified in browser end-to-end (register → verify → dashboard). Test suite: **32 passed**. | ✅ |

> Append one row per working session, and update each phase table's Status column as tasks complete.

---

## 6. Phase 1 — What Was Built (2026-09-06)

### Verified working end-to-end
Registered a real account in the browser, verified the email link, landed on a
dashboard reading live figures — account row, owner link, Starter subscription
with 14-day trial and the activity-log entry were all confirmed in MySQL.

### Test suite
`php artisan test` → **32 passed / 85 assertions**, including 5 dedicated
tenant-isolation tests (scoped reads, account stamping on create, cross-account
record access blocked, dashboard totals scoped, guests blocked).

### Decisions taken during Phase 1
1. **One account per user.** The planned `account_user` pivot was dropped —
   `users.account_id` + `users.role_id` covers Owner and Staff. A pivot only
   pays off if one person joins several accounts, which the brief does not ask
   for. Reinstating it later is additive, not a rewrite.
2. **Limits: `NULL` = unlimited, `0` = not allowed.** Applies to every
   `max_*` column on plans.
3. **Per-account limit overrides** live in `subscriptions.overrides` (JSON), so
   a super admin can raise one account's ceiling without cloning a plan.
4. **Email verification is admin-switchable** (`system.require_email_verification`)
   via a custom `verified` middleware, instead of Laravel's fixed one.
5. **Users are soft-deleted**, so campaign, log and activity history that
   references them stays intact.
6. **Sidebar renders an item only when its route exists and the user holds the
   permission.** This enforces the "no dummy buttons / no placeholder pages"
   rule mechanically — each phase's menu entries appear as its routes land.
7. **Reply matching will use two paths** — `In-Reply-To`/`References` against
   `campaign_recipients.message_id`, plus a `reply_token` embedded in the
   Reply-To address. Both columns are already in the schema.

### Credentials (local only)
Super admin `admin@knsoftic.com` / `password` — change before any deployment.
Demo tenant created during browser testing: `demo@knsoftic.test` / `Password123!`.

### Not yet present (by design — later phases)
Sidebar currently shows Dashboard and Profile only, because no other module's
routes exist yet. Every other menu entry is already defined and will appear
automatically as Phases 2–10 register their routes.

---

## 7. Phase 2 — What Was Built (2026-09-06)

### Super Admin panel — 18 screens, all verified returning 200
Overview · Plans (list/create/edit) · Users (list/create/show/edit) · Accounts
(list/detail) · Subscriptions · Roles (list/create/edit) · Branding · System
settings · Payment settings · System health · Queue status · Activity log.

### Account side
Profile (details, avatar, password, delete guard, 2FA status) and a full Team
module: members, seat limits read from the plan, and account-private custom
roles built on the same permission matrix the platform roles use.

### Real bug found and fixed during Phase 2
`SetTenant` only ever **set** the tenant, never cleared it. `TenantManager` is a
container singleton, so in any long-lived process — a queue worker, Octane, the
test suite — the previous request's account stayed bound. A platform setting
written straight after a customer request was silently stamped with that
customer's `account_id` and became their private override.

Two fixes, both kept:
1. `SetTenant` now sets the tenant on **every** request, including to `null` for
   guests and super admins, so the value is never inherited.
2. `SettingsService` pins the tenant to the row's own account while writing, so
   a platform setting cannot be captured by an active tenant regardless of what
   the caller left bound.

This matters well beyond settings: every queued job in Phases 5–10 runs in that
same long-lived worker.

### Decisions taken during Phase 2
1. **Super admins have no tenant account.** Account modules redirect them to the
   admin panel via the new `has.account` middleware, and the tenant sidebar
   hides those entries entirely rather than showing links that bounce.
2. **Two absolute roles.** Super Admin and Account Owner short-circuit in
   `hasPermission()`, so their permission matrix is shown read-only and they
   cannot be edited or deleted.
3. **Impersonation is always reversible.** The admin's id is kept in the
   session, an amber banner is pinned to every page while active, and
   `/stop-impersonating` sits outside the admin guard — during impersonation the
   signed-in user is the customer, not the admin.
4. **Plans in use are deactivated, not deleted**, so live customers never lose
   the limits they are running under.
5. **Limit overrides live on the subscription** (`overrides` JSON). Blank falls
   through to the plan; a value wins. Feature overrides are three-state:
   inherit / force on / force off.
6. **Deployment values live in `config/knsoftic.php`**, read from `.env` — send
   rate, sync interval, attachment cap, SMTP cooldown. Runtime-editable values
   live in the settings table. The System Settings screen shows both, and is
   explicit about which is which.
7. **No fake 2FA control.** The encrypted secret, recovery codes and
   confirmation timestamp exist on the user record; the profile shows that
   status and says enrolment ships with the security phase, rather than
   rendering a button that does nothing.

### Test suite after Phase 2
`php artisan test` → **57 passed / 194 assertions**, including 17 admin-panel
tests (access control, plan limit semantics, suspension lockout, impersonation
round-trip, branding propagation, settings switches) and 8 team/permission tests
(seat limits, permission gating, cross-account isolation of members and roles).

---

## 8. Phase 3 — What Was Built (2026-09-06)

### The test suite was moved from SQLite to MariaDB
A LIKE-escaping test failed and the reason mattered: MariaDB treats a backslash
as the default `LIKE` escape and SQLite does not. Worse, the custom-field JSON
test had been *passing* on SQLite — so the JSON path behaviour the segment
engine depends on was never actually being tested against the engine that runs
in production. The suite now runs against a real `knsoftic_mail_test` database.
It went from 12s to 79s; that is the right trade.

### Segment engine
A descriptor registry (`SegmentFieldRegistry`) owns 17 filter fields plus every
account-defined custom field. The compiler validates each rule against the
registry and then hands the query to that field's own descriptor — it never
learns about individual fields, and a field key or operator that is not
registered is dropped before any SQL is built.

Behaviour pinned by tests, because each of these is easy to get subtly wrong:

- `is_not` also returns rows where the column is NULL. Otherwise
  "company is not Acme" silently hides every contact with no company.
- "Did not open" includes people the campaign was never sent to.
- Negative conditions compile to `NOT EXISTS`, never `NOT IN` over a
  materialised id list, so they stay usable at 100k recipients.
- An `any` (OR) rule set stays wrapped so it cannot widen a base query — that
  is what stops a suppressed contact leaking into a send.
- A `%` typed by the user is escaped, not treated as a wildcard.
- An unknown field or operator is dropped **and reported to the UI**, because a
  silently wrong count is worse than an error.

### CSV import
`CsvReader` absorbs the mess real exports arrive in: UTF-16/CP1252 with a BOM,
semicolon delimiters, ragged rows. The queued job streams in chunks of 500 and
runs exactly two lookup queries per chunk rather than two per row. Progress is
written after every chunk, which doubles as the resume point if a worker dies.
The plan contact limit is re-checked *during* the run, so a large file cannot
walk an account past its ceiling — it stops and says so.

An address already on the suppression list is imported but never as an active
contact. An import must not undo an opt-out.

### Bugs found and fixed during Phase 3
1. **A method-`static` cache in `SubscriberRequest`.** `static $fields` inside a
   method is shared for the whole PHP process, so under Octane, a queue worker,
   or the test suite the first request's custom-field catalogue would be reused
   by every later request. Same class of leak as the Phase 2 tenant bug. Now a
   per-instance property.
2. **A dead form field.** The suppression form collected a note that
   `suppressMany()` hardcoded to null — the typed note was silently discarded
   while the help text promised an audit trail. Fixed in the service so the
   note is actually stored, rather than deleting the field.
3. **A render-time fatal in the custom-fields screen.** `$errors->get('options.*')`
   returns nested arrays, which the `input-error` component then tried to
   `htmlspecialchars()`. Any failed save with a bad dropdown option blew up the
   whole page.
4. **A bulk-delete permission hole.** The bulk-action menu offered Delete to
   anyone holding `contacts.update`, while the per-row Delete was gated on
   `contacts.delete`.
5. **Route ordering.** `lists/create` was declared after `lists/{list}`, so
   "create" was being parsed as an id.
6. **An optional field read as required.** `$validated['source']` threw when the
   field was simply not submitted.

### Decisions taken during Phase 3
1. **`PlanLimits` is a shared support class**, not per-module logic. Limits read
   through `Subscription::limit()`, so a per-account override always beats the
   plan. NULL = unlimited, 0 = capability off.
2. **One bulk endpoint per resource** with an allow-listed action name. Ids are
   always re-resolved through the tenant-scoped model, so posting another
   account's ids selects nothing rather than erroring.
3. **Releasing a suppression never re-activates the contact.** Re-consent is a
   deliberate act, not a side effect of tidying a list.
4. **Uploaded contact files live on the private disk**, under the account's own
   folder, never in the web root.

---

## 9. Phase 3 — Verified End to End (2026-09-06)

### A real queued import, not a simulated one
A 2,002-row CSV was uploaded through the actual endpoint, mapped (including a
custom field), started, and drained by a real `queue:work` process:

| | |
|---|---|
| Rows read | 2,002 |
| Imported | 1,940, then **stopped at the plan contact limit** |
| Account total afterwards | 2,000 — exactly the Starter ceiling |
| Custom field `tier` | 666 gold (≈ 2000/3), stored in the JSON column |
| Report | "Stopped early: the account reached its plan contact limit." |

The limit was enforced *during* the run, not only at the start. That is the
behaviour the design called for and it now has a real run behind it, not just
a unit test.

### The segment builder, driven in a browser
Selecting `Country is Pakistan` returned **658 matches** with a live sample
table, debounced against `POST /segments/preview`. The field list rendered all
18 registry fields grouped by category, including the account's own `Tier`
custom field, and each field's operator list came from its descriptor.

### Three more defects found after the module was first built
1. **Re-adding a soft-deleted contact crashed with SQLSTATE 23000.**
   `UNIQUE(account_id, email)` does not exclude soft-deleted rows, but the
   validation rule did — so the email passed validation and then collided on
   insert. Now the old record is restored and updated instead.
2. **The bulk endpoint could be used to bypass a permission.** The route is
   gated on `contacts.update`, but the action list includes `delete`. Hiding the
   option in the UI was not enough — a crafted POST still worked. There is now a
   per-action permission map checked in the controller.
3. **A stale CSS build made the rule builder look broken.** Tailwind JIT only
   compiles classes present at build time, and the segment views were written
   after the last `npm run build`, so `lg:col-span-3` and `sm:grid-cols-12`
   simply did not exist in the stylesheet. Measured in the browser rather than
   guessed from a screenshot: the conditions panel was 202px inside a 1,105px
   grid. A rebuild fixed it — the markup had been correct all along.

**Reminder for later phases:** any phase that adds Blade views must re-run
`npm run build`, or new utility classes silently do nothing.

### What the adversarial view verifiers caught
Ten agents wrote and then adversarially re-read the 22 contacts screens. Real
defects they found and fixed, beyond the three above:

- `$errors->get('options.*')` returns nested arrays, which the shared
  `input-error` component then passed to `htmlspecialchars()` — any failed
  custom-field save produced a fatal, not a validation message.
- A "Notes" field on the suppression form that the service silently discarded,
  while its help text promised an audit trail. Fixed in the service so the note
  is stored, rather than removing the field.
- The export screen claimed "excludes unsubscribed, bounced and suppressed",
  but `scopeMailable()` also drops `pending` and `blocked`. The copy now says
  what the code does.
- A `Manage custom fields` link gated on `contacts.update` when the route it
  points at requires `contacts.view` — hiding a link from users allowed to use it.
- The tag detail screen labelled a column "Added" while showing the contact's
  creation date; the pivot has no timestamps, so a tag-application date does not
  exist in the schema at all.

### Two more fixes after the final verifier pass

**Every `warning` and `info` flash was being silently swallowed.** The app
layout included the flash partial only when the session held `status`,
`success` or `error` — but the partial styles `warning` and `info` too, and
three controllers set them (import cancelled, bulk action matched nothing).
The partial is now included unconditionally; it renders nothing when there is
no flash, so the condition could only ever drift.

**A failed import had no resume path.** `start()` zeroed `processed_rows` on
every call, so a 100k-row file that died at row 60k re-read all 60k. The job
already supported resuming from that cursor. A failed import with progress now
offers *Resume from row N* as the primary action and *Start over* as a
deliberate, confirmed second choice — and the on-screen copy says which one
does what.

### Phase 3 final state
- **133 tests passing / 432 assertions**
- **22 contacts screens**, all verified returning 200
- **20 defects** found and fixed across three adversarial verification passes

---

## 10. Phase 4 — SMTP Layer (2026-09-06)

### The concurrency problem, and how it is actually solved

Several queue workers send at the same time. A read-then-write ("is there
room? yes → increment") lets two workers both see 499 of 500 and both send.

So there is no read. Reservation is **one** statement, with the limits in the
WHERE clause and the increments in the SET:

```sql
UPDATE smtp_accounts SET
  sent_today = (CASE WHEN day_reset_at < :dayStart THEN 0 ELSE sent_today END) + 1,
  day_reset_at = CASE WHEN day_reset_at < :dayStart THEN :dayStart ELSE day_reset_at END,
  ...
WHERE id = :id AND is_active = 1
  AND (cooldown_until IS NULL OR cooldown_until <= :now)
  AND (daily_limit IS NULL OR (CASE WHEN day_reset_at < :dayStart THEN 0 ELSE sent_today END) < daily_limit)
```

MariaDB holds the row lock for the statement, so exactly one of two concurrent
workers gets the last slot; the other's WHERE no longer matches and it gets 0
affected rows and moves to the next account.

The hour/day/month **rollover happens inside the same statement** via those
CASE expressions. Doing rollover as a separate UPDATE would open a second race
where one worker zeroes a counter while another is incrementing it. It also
means there is no scheduled "reset counters" job to go wrong.

A test proves it end to end: 600 reservation attempts against a 500 ceiling
grant exactly 500, and a separate test asserts the operation is a single
statement containing no SELECT.

### Account fault vs recipient fault

The most consequential judgement in the sending path. Get it wrong one way and
one mistyped address takes a working provider out of service for every
campaign; the other way and a genuine hard bounce is retried forever.

It is decided from two signals Symfony really exposes — the SMTP reply code,
and the command that was in flight, recovered from the exception's debug
dialogue (Symfony prefixes what it sent with `>`):

| Signal | Classified as | Action |
|---|---|---|
| 5xx answering `RCPT TO:` | recipient hard bounce | suppress the address, account untouched |
| 4xx answering `RCPT TO:` | recipient soft bounce | retry later, never suppress |
| 5xx answering `MAIL FROM:` / DATA | message/sender rejected | do not retry, do not blame the account |
| 535/534/530/538 or auth text | account | cool down, not retryable |
| 421 / other 4xx | account transient | cool down, retry |
| Connection refused / DNS | account | retry on another account |
| Anything unrecognised | account transient | retry, and **never** suppress on a guess |

A recipient-level failure explicitly does **not** increment the account's
failure streak, and is not retried on another provider — a mailbox that does
not exist will not exist elsewhere either.

### Credential safety
- Built with `setUsername()`/`setPassword()` rather than a DSN, so the password
  never enters a URL that could be dumped or logged.
- Every stored error is scrubbed against the account's own username, password
  and their base64 forms, plus any `AUTH PLAIN/LOGIN/XOAUTH2` payload — servers
  echo the AUTH line back often enough that this matters.
- A test asserts a failed connection test returns JSON containing no credential
  and stores none.

### Two real bugs found while building this
1. **`BelongsToAccount` stamped platform-owned rows.** `withoutGlobalScopes()`
   removes the query scope but not the `creating` model event, so creating a
   global SMTP account while any tenant was bound silently turned it into that
   tenant's private account — vanishing from the admin list and appearing in
   one customer's. The trait now asks the model via `shouldStampAccountId()`.
2. **`Account::subscription()` returned NULL across tenants.** Subscription is
   itself tenant-scoped, so reading account B's subscription while account A
   was bound returned nothing — and `PlanLimits::allows()` then reported every
   feature as *switched off* rather than failing loudly. Both the relation and
   `PlanLimits` now read it unscoped, since they are given an explicit account.

The second one is the dangerous kind: it fails silently and in the direction
that looks like a legitimate answer.

### A design smell fixed rather than worked around
`MailerFactory::transport()` returned a concrete `EsmtpTransport`, which made
the sender impossible to test without a real SMTP server. Rather than loosen
the test, the factory gained `for(): TransportInterface` — the narrow contract
sending actually needs. The connection tester still takes the concrete type,
because it calls `start()`/`stop()`.

### Verified against a real SMTP server, not a mock

The connection test was run from the browser against the live
`smtp-relay.brevo.com:587` with a deliberately wrong key. The full path ran —
TCP connect, EHLO, STARTTLS, AUTH — and came back in 1,969 ms with:

```
ok      : false
code    : 535
summary : Brevo rejected the credentials. Use an SMTP key from
          SMTP & API → SMTP, not your account password.
detail  : Failed to authenticate on SMTP server with username "[redacted]" …
```

Three things this proves at once: the transport is built correctly (a real
STARTTLS handshake succeeded), the classifier reads the reply code and returns
provider-specific advice rather than a generic error, and the scrubber works on
real server output — the username came back `[redacted]` in both the JSON
response and the stored `last_error`.

### Screens verified in the browser
`/smtp` renders own accounts with masked usernames, per-window counters
(`41 / 100` this hour, `312 / 500` today) with a usage bar, and priority. A
cooling-down account shows an explicit panel: *"Skipped by sending until
09:33 — 5 consecutive failures put this account into a cooldown"* with the
stored provider error and a Clear cooldown button. Shared platform accounts
appear in a separate read-only section with only a View action.

### Phase 4 totals
- **201 tests passing / 661 assertions** (157 → 201)
- 5 SMTP services, 2 controllers, 1 request, 11 Blade views, 21 routes
- 48 new tests across selector, classifier, sender and screens

---

## 11. Phase 5 — Templates & Campaigns (2026-09-06)

### What the phase produced
- **Block builder**: one shared `<x-block-builder>` Blade component plus `resources/js/block-builder.js`
  (609 lines), used by *both* the template editor and the campaign editor. Eleven block types, live
  server-side compile, drag reordering, placeholder insertion, and a document-design panel.
- **9 ready-made templates** seeded by `SystemTemplateSeeder` (welcome, newsletter, promotion,
  product, course, event, discount, announcement, follow-up), compiled at seed time by the same
  `EmailCompiler` a campaign uses — so a system template can never contain HTML the compiler would
  not produce. Every one carries a footer, because the footer is where the unsubscribe link comes from.
- **7 screens**: `templates/{index,edit}`, `campaigns/{index,scheduled,edit,show,confirm}`.
- **191 routes** registered (was 187).
- Rich text: **Quill 2, self-hosted**, dynamically imported so it lands in its own Vite chunk
  (202 KB) instead of the main bundle. The toolbar offers only formatting inboxes actually render.

### The preview is sandboxed, and the server compiles it
The rendered email never enters the app's own DOM. It goes into an `iframe sandbox=""` — no scripts,
no forms, no same-origin access — because a Custom HTML block is user input that gets rendered back
to a logged-in session. The builder previews via `srcdoc`; the template cards embed the standalone
preview route.

The preview HTML is produced by the *server*, by the same `EmailCompiler` that builds the real
message. A browser-side preview would be a second renderer that could drift from the one that sends.

### Real bugs found and fixed during this phase

1. **`abort_if($blockers !== [], 422, $blockers[0])`** — PHP evaluates every argument before the
   call, so `$blockers[0]` was read even when the list was empty. Every clean send threw
   *"Undefined array key 0"* instead of proceeding. Found by the first dispatcher test written.

2. **Deleted SMTP accounts kept sending.** `SmtpAccount::withoutGlobalScopes()` removes *all* global
   scopes — including `SoftDeletingScope`. `SmtpSelector::candidatesFor()` therefore offered
   soft-deleted accounts as send candidates. Narrowed to `withoutGlobalScope(AccountScope::class)` in
   the selector and the three admin screens; pinned by `test_a_deleted_account_is_not_even_offered`.

3. **A config knob that lied.** `config/knsoftic.php` advertised `KNS_CAMPAIGN_CHUNK` (default 500)
   and documented it, but `CampaignRunner` hard-coded `self::CHUNK = 100` and never read it. Now
   read, cast and clamped to 1–1000 — it is interpolated into a `LIMIT` clause, so it is not trusted
   just because it comes from config.

4. **Guards ran after validation.** Posting to a *sending* campaign answered *"The subject field is
   required"* rather than *"This campaign is sending"*, because `FormRequest` validation runs before
   the controller body. The editability check moved into `authorize()`, which runs first; same for
   the read-only system-template check.

5. **`templates.compile` was gated on `templates.view` alone** — a role holding `campaigns.update`
   without `templates.view` would have had a dead preview pane in the campaign editor.

6. **`{{company_name}}` was not a real token.** The seeded templates need the *sender's* company name
   in the footer, which a shared template cannot hard-code. Added to `PersonalizationEngine`,
   resolved from the campaign's account (or the bound tenant when previewing), memoised so a
   10,000-recipient send does not ask the database 10,000 times.

7. **A whole class of 500s from the query string.** `?q[]=x` on any list screen made
   `$request->string()` stringify an array — *"Array to string conversion"*, which Laravel promotes
   to a 500, from a plain URL anyone can type. Fixed once, in `AppServiceProvider`, as
   `$request->filter()` / `$request->filters()`; nine controllers now route through it.

8. **The same class of 500 on form redisplay.** A field posted as `name[]=x` came back from `old()`
   as an array, and `x-text-input`'s attribute bag called `trim()` on it — fatal on the redisplay of
   the very form that rejected the input, the one moment the user's unsaved work exists only in that
   response. Guarded in the shared component, plus `old_text()` / `old_list()` helpers, plus a scalar
   guard in `CampaignRequest::prepareForValidation()` (which runs *before* validation and so sees the
   raw array).

9. **`X-Frame-Options: SAMEORIGIN` contradicts `sandbox=""`.** A sandboxed frame has an opaque
   origin, which is never "same origin". Framing policy is now expressed as `frame-ancestors 'self'`
   in the CSP, which is evaluated against the *embedding* page and therefore works.

### What the view fan-out and its adversaries caught
Verification ran per screen: a reviewer, then an adversary told to refute the reviewer. Findings that
survived, all fixed:

- **`campaigns/scheduled` had a "Send now" that bypassed the confirmation screen** and would dump a
  raw 422 for a campaign whose SMTP account had since been deleted. Replaced with a link to
  `campaigns.confirm`, which is where every other send goes.
- **A card claimed "Scheduled 1 minute ago"** using `updated_at` — the last edit, not the moment of
  scheduling. Relabelled "Last edited".
- **`$errors->get('blocks.*')` returns messages grouped by key, not a flat list** — the campaign
  editor handed an array to `e()` and 500'd on any block-level validation error. (The same shape bit
  us in Phase 3 with `options.*`.)
- **Unticked audience boxes came back ticked** after a rejected save, so a user who cleared every
  list and then tripped an unrelated error would silently re-save the audience they had just removed.
- **A required field on a hidden tab made Save a dead button** — the browser refuses to submit a form
  with an unfocusable invalid control, and reports nothing. The offending tab now opens.
- **`show.blade.php` hid the blockers for a `failed` campaign** while simultaneously offering to send
  it.
- An adversary caught **a reviewer's own fix shipping a 500**: `(string) old('category')` crashes on
  exactly the array input the field it guards is validated against.

### Verified by running it, not by reading it
- A campaign built in the browser from the seeded **Promotion** template, saved, reviewed and sent to
  3 contacts through a real `php artisan queue:work`. The worker ran one chunk in 21s (paced by
  `KNS_SEND_RATE_PER_MINUTE`), attempted all three, and finished `completed` with an honest
  `sent: 0, failed: 3` — the shared relay's credentials are placeholders, and every recipient row
  recorded the Phase 4 classifier's provider-specific advice: *"Amazon SES rejected the credentials.
  SES SMTP credentials are generated in the SES console"*. No credential appeared in the stored error.
- **Loading a template into a campaign replaces rather than appends** — measured 10 blocks → 6, with
  a confirmation naming both the count and the template.
- The Quill round trip: typed text reached `doc.blocks[].settings.html`, the server recompiled, and
  both `previewHtml` and the iframe `srcdoc` carried it.
- The blank template thumbnails turned out to be **this preview browser blocking every `sandbox=""`
  frame that has a `src`** — a sandboxed frame pointed at `/dashboard` is blocked too, so it is not
  the app. A painted fallback (icon + name) now sits *behind* the frame, so a card never reads as
  broken where a browser or extension refuses the frame.

### A gap closed rather than documented away
The campaign editor's template picker recorded a link and nothing else, while the template editor
claimed *"a campaign takes a copy of the content when you pick this template"*. One of the two was
wrong — and with nine ready-made templates seeded there was **no path from a template to a sent
email**. Added `templates.document` (it returns the *normalised* document, so an old or hand-edited
document cannot arrive in the builder carrying a colour that is really a `<style>` breakout) and a
real "Use this template's content" button that replaces the document and says so first.

### Known, deliberate
- **Pint reports style deviations across ~52 files**, most of them from Phases 1–4. It has never been
  part of this project's workflow, and a blanket reformat now would bury the Phase 5 diff in noise.
  Only what this phase introduced was corrected (one unused import, import ordering).
- **`reply_to` posted as an array is dropped rather than rejected.** It is optional, and the value is
  not one the field could have held; the alternative is a validation error on a request no UI can
  produce.

### Phase 5 totals
- **371 tests passing / 1,283 assertions** (280 → 371)
- 7 screens, 1 shared builder component, 2 controllers, 2 form requests, 1 console command,
  1 queue job, 6 services, 191 routes
- 90 new tests across dispatcher, chunk job, template screens, campaign screens, list screens and the
  adversarial edit sweep

---

## 12. Phase 6 — Tracking, Unsubscribe, Analytics (2026-09-06)

### What the phase produced
- **Open tracking**: a signed pixel route, a 43-byte GIF, per-recipient and per-campaign counters.
- **Click tracking**: link extraction, `campaign_links`, a signed redirect route, per-link counters.
- **A real preferences page** — see below; it did not exist before, despite being linked from every footer.
- **Bounce log**: `bounces` rows with soft/hard, and the configured hard-bounce threshold actually read.
- **Analytics**: an account dashboard and a per-campaign report with 14+ metrics, Chart.js series,
  top links, an open-source breakdown and a filterable recipient drill-down with CSV export.
- 4 new services, 2 controllers, 2 route files, 4 screens, 1 migration, **73 new tests**.

### The click redirect is not an open redirect
The obvious design is `/click?to=https://example.com`, and it is an open redirect on a domain the
recipient has been told to trust — anyone could mail a link that looks like ours and lands anywhere.
So the tracked URL carries **only ids**. The destination is read back from `campaign_links`, a row
this account wrote when the campaign was prepared, and re-checked against `http(s)` at redirect time
because a row can outlive the code that wrote it. The link id is also checked against the recipient's
campaign, so a signed URL from one campaign cannot be paired with a link id from another.

### Why the document is rewritten once, not per recipient
The tracked URL differs per recipient; the surrounding HTML does not. Rewriting a 50 KB document
100,000 times is a regex pass per message. Instead `MessageBuilder::blueprint()` rewrites **once per
chunk** into `%%KNL<id>%%` placeholders, and each message replaces them with a single `strtr()` over
a map of a few entries. The placeholder is not a `{{token}}` on purpose: the personalisation engine
drops tokens it does not recognise, so a token there would be deleted before tracking ever saw it.

### Unique vs total, and why the guard is the write
"Opens" and "unique opens" are different numbers and people compare them. The same client can fetch
the pixel twice in a second and two workers can serve both. Reading `first_opened_at` and then
writing it lets both see NULL and both count a unique. So the guard **is** the write:

```sql
UPDATE campaign_recipients SET first_opened_at = ?
 WHERE id = ? AND first_opened_at IS NULL
```

Exactly one gets 1 affected row; only that one increments the campaign's unique counter. A click
applies the same guard and additionally implies an open — most clients block images, so a click is
often the only evidence the message was read, and a report showing more clicks than opens is one
nobody believes.

### What an open actually means, said out loud
An open is a pixel fetch. It **undercounts** (most clients block remote images) and it
**overcounts** (Apple Mail Privacy Protection and Gmail's proxy fetch images nobody looked at).
Rather than quietly folding that in, proxy and scanner fetches are classified on the way in and the
machine share is reported next to the open rate on every screen that shows one. The open-rate card
and the caveat are deliberately adjacent, not a tooltip.

### Real bugs and gaps found in this phase

1. **The preferences link was a lie.** `preferences.show` routed to the unsubscribe confirmation, so
   the two links in every footer did the same thing and one of them misdescribed itself. There is now
   a real page: leave one list, keep the rest. Only the lists the contact is actually on are shown —
   listing every list the account owns would tell a recipient the names of segments they were never
   part of. Unticking everything is treated and recorded as a full opt-out, and the page says so
   before the button is pressed: on no lists but not suppressed is a trap, because the next campaign
   aimed at all contacts would reach them anyway.

2. **The `bounces` table was never written to.** The model and the migration had existed since Phase 1
   and nothing used them; bounces only ever touched `campaign_recipients`.

3. **`KNS_HARD_BOUNCE_LIMIT` was advertised but never read** — the same class of defect as
   `KNS_CAMPAIGN_CHUNK` in Phase 5. The send path suppressed on the first hard bounce whatever the
   setting said, so an operator who set 3 got 1. The count now comes from the bounce log, which is
   also what makes the setting meaningful: you cannot count to three without keeping the first two.

4. **An SMTP authentication failure was being written to a contact's history as a bounce.** Our
   credentials being wrong is not the recipient's address being dead, and treating it as one would
   eventually suppress an entire healthy list. `BounceRecorder` records only recipient-level
   rejections; anything else is our problem, not theirs.

5. **APP_URL is every link in every email.** A queue worker has no incoming request, so `URL::signedRoute`
   falls back to `APP_URL` — which on this machine is `http://localhost:8000`. Sending with that set
   puts an unsubscribe link nobody can reach in every inbox. Now: a hard blocker in production, and a
   warning outside it, because a local URL is exactly right for a test send. Found by generating a
   real message and reading the URLs in it, not by reasoning about the code.

6. **The campaign page's "Open analytics" link went to the wrong place.** It called
   `route('analytics.index', ['campaign' => $id])`, and `analytics.index` takes no campaign — so it
   produced `/analytics?campaign=5`, a parameter nothing reads, landing on the account dashboard
   instead of that campaign's report.

### A warning that does not block, and a blocker that does
`CampaignDispatcher` now has `warnings()` alongside `blockers()`, and the confirmation screen renders
them in separate panels. The split is deliberate: a warning that stops the send teaches people to
ignore warnings, and a blocker that is only advice teaches them to look for a way around it.

### Verified by running it, not by reading it
- A real Chrome fetched a real signed pixel and followed a real signed click URL from the running
  app: `open rows: 1` (device `reader`, real user agent), `click rows: 1`, the link counters at
  `clicks=1 unique=1`, and the campaign at `unique_opens=1 unique_clicks=1` — consistent, no
  double-count, redirect issued as a 302.
- The analytics report was walked in the browser on a campaign with **0 sent and 3 failed**, which is
  the shape most likely to produce a wall of meaningless zeros. It says instead: *"Not one message has
  been accepted by a receiving server, so there is no open rate, no click rate and no engagement to
  report — every rate on this page divides by sent, and sent is zero."* The drill-down still lists all
  three recipients with the SES credential error against each, which is exactly what someone needs in
  that case.
- `AnalyticsScreensTest` renders the report in **all eight campaign statuses**, with no recipients at
  all, and against malformed query strings (`?days=abc`, `?q[]=x`, `?engagement[]=x`, `?page=999`).

### Known, deliberate
- **Per-link unique clicks are near-exact, not exact.** The live increment uses an EXISTS check before
  the insert; two simultaneous clicks on the same link by the same person could both count as unique
  for a few milliseconds. The alternative — `COUNT(DISTINCT)` inside the increment — rescans every
  click on the link on every click and does not survive a popular link. `refreshLinkCounts()` recomputes
  the exact figure, and the Recalculate button on the report exposes it.
- **The builder's Gmail-clip warning measures the untracked document.** Tracked URLs are longer than
  the placeholders they replace, so a heavily linked email is a few KB larger when sent than when
  measured. It shifts the 102 KB threshold slightly; it does not change what the warning means.
- **Full IPs are stored** against opens and clicks, as the Phase 1 schema intended. They are never
  logged elsewhere and never shown outside the account that owns the campaign.

### Phase 6 totals
- **444 tests passing / 1,521 assertions** (371 → 444)
- 4 services (`TrackingLinkRewriter`, `TrackingUrls`, `TrackingRecorder`, `BounceRecorder`,
  plus `CampaignAnalytics`), 2 controllers, 4 screens, 6 new routes, 1 migration
- 73 new tests across tracking, bounces, preferences, the analytics service and the analytics screens

---

## 13. Phase 7 — Mailboxes & IMAP Sync (2026-09-06)

### What the phase produced
- **Mailbox CRUD** with six IMAP provider presets, encrypted credentials, and plan-limit enforcement.
- **A real connection test** that authenticates *and* lists folders, with provider-specific advice.
- **Folder discovery** mapped to inbox / sent / drafts / spam / trash / archive.
- **`SyncMailboxJob`**: incremental UID fetch, UIDVALIDITY handling, Message-ID dedupe, threading,
  attachments, failure counting and self-disabling.
- **Scheduler wiring**: `mailboxes:sync` every minute, each mailbox on its own interval.
- 7 services, 1 controller, 1 form request, 1 job, 1 command, 5 screens, 11 routes, **54 new tests**.

### UIDVALIDITY is the rule that makes incremental sync safe
IMAP UIDs are only meaningful while a folder's UIDVALIDITY is unchanged. If the server renumbers a
folder — a restore from backup, a migration between servers, some providers on a whim — every UID we
stored points at a different message or at nothing. Continuing from `last_uid` after that silently
skips everything, and the mailbox looks empty forever **with no error anywhere**. It is the classic
IMAP bug and it is silent, which is what makes it worth building the sync around.

So the first thing every folder sync does is compare UIDVALIDITY and, if it moved, reset the cursor
to zero. The re-fetch is then deduped by Message-ID, so a reset costs bandwidth and nothing else.

### The gateway is an interface on purpose
`MailboxSyncer` never sees a webklex object. It talks to `ImapGateway`, which returns plain arrays
and Carbon dates, and `WebklexImapGateway` is the only file that knows the library exists.

Two reasons, both practical. **Testing**: the rules worth getting right — UIDVALIDITY resets, dedupe,
threading, attachment limits — are logic over message data, and coupling them to the library would
mean the only way to exercise them is against a live mail server, which is to say not exercising
them. All 19 sync tests run against a scriptable fake. **Drift**: webklex's Message API changes
between majors; one adapter is a small thing to fix.

### Folder types come from the server, not from names
Every provider names these differently — "Sent", "Sent Items", "[Gmail]/Sent Mail", "Gesendet",
"Enviados". Matching on names is how a German user's drafts end up displayed as their sent mail.
RFC 6154 SPECIAL-USE has the server tell us which folder is `\Sent`, `\Drafts`, `\Junk`, `\Trash` or
`\Archive`, so the order is: the server's own attribute, then a known name in several languages, then
"custom" — which is honest about not knowing rather than guessing.

A folder that disappears from the server stops syncing but keeps its row: deleting it would orphan
its messages behind a foreign key.

### Real bugs and gaps found in this phase

1. **`max_storage_mb` was advertised by every plan and never computed** — `usageFor()` fell through to
   `default => 0`, so the limit could not be hit however much an account stored. The third instance of
   this exact family, after `KNS_CAMPAIGN_CHUNK` in Phase 5 and `KNS_HARD_BOUNCE_LIMIT` in Phase 6.
   Attachments are what consume storage, so it is now computed from `email_attachments.size` and
   checked per file — a single message can carry twenty.

2. **Clicks had no bot classification at all** — found because a Phase 6 adversary caught the
   analytics copy claiming "a click is a person" while `TrackingRecorder::PROXIES` already classified
   link scanners. Opens were classified from the start; clicks were not, on that same assumption.
   Mimecast, Proofpoint, Barracuda and Microsoft Safe Links follow every URL in a message to check it,
   and Slack and WhatsApp fetch them for previews. Worse than the inflated click rate: a click implies
   an open, so **a security gateway was manufacturing engagement for every recipient behind it**.
   `email_clicks.device` added; a machine's click is still recorded as the raw truth, but no longer
   invents attention.

3. **A cancelled campaign's real sends vanished from the dashboard.** `accountSummary()` and the
   recent list both filtered on statuses that excluded `cancelled`, so an account whose only campaign
   was stopped half way through 500 deliveries was told "nothing has been sent yet" while its
   contacts held the proof. The filter is about whether a campaign ever ran, not how it ended.

### What the adversarial pass caught in my own code
The view reviewers found two defects that were not in the views at all:

4. **A failed sync did not stamp `last_sync_at`.** `recordFailure()` wrote `last_error_at` and left
   `last_sync_at` holding the time of the last *successful* pass — so one card showed *"last sync
   failed 9 hours ago"* directly above *"Failed 2 minutes ago"*: two lines about the same event,
   hours apart. A failed pass is still a pass.

5. **`status = 'disconnected'` was unreachable.** It sat in the enum from Phase 1 with nothing ever
   writing it, so the UI carried a branch that could not happen. Switching a mailbox off is exactly
   what it means; switching it back on returns it to `pending`, because nothing has been tried since.

They also caught a `'running'` sync being reported as *"Last sync succeeded"* — the `@else` branch
swallowed it — and a zero-storage plan telling an account it had *"0 MB still free"* while
`PlanLimits::hasRoomFor()` refused every attachment.

### Failure is counted, not just logged
A mailbox whose password changed will fail every five minutes forever. The streak is what lets the UI
say "this has been broken since Tuesday" instead of showing the same error afresh each time — and
after ten consecutive failures automatic syncing switches itself off, because polling then is
spending the provider's rate limit to relearn the same fact. The row keeps its credentials and the
operator gets a Resume control that also clears the streak, so one more failure does not immediately
switch it off again.

Every stored error is scrubbed first: a server happily echoes the whole `LOGIN` command back, and the
classifier removes the username, the password and any echoed auth line before the text is saved.

### Advice, not error text
"Connection failed" tells nobody whether to fix the port, the password or the firewall. Every branch
of `ImapFailureClassifier` ends in an instruction, and where the provider is known it is specific —
"check your password" is useless for a Gmail mailbox, because the password is not the problem, Google
stopped accepting it. TLS failures are checked **before** connection failures, because the socket
layer reports a certificate mismatch as a connection error and would otherwise send the operator to
look at their firewall.

### Verified by running it
- 19 sync tests against a scriptable IMAP server covering: SPECIAL-USE typing beating names, a folder
  vanishing, dedupe across UIDs, a synthetic Message-ID staying stable across syncs, **a UIDVALIDITY
  change resetting the cursor to zero**, the cursor never moving backwards, a three-message reference
  chain staying one thread, an oversized attachment being skipped while the message is kept, the
  filename extension never coming from the claimed MIME type, the storage allowance stopping a
  download, and one unreadable folder not abandoning the others.
- 22 screen tests including: the stored password never appearing in any rendered page, a blank
  password keeping the stored one, changing the host marking the connection untested again while a
  rename does not, and another tenant's SMTP account being refused as a reply route.
- In the browser: choosing Gmail fills the host, port and encryption and shows the app-password note;
  a manual host override sticks; setting port 143 with implicit SSL raises the live warning *"Port 143
  is the plain IMAP port and expects STARTTLS to upgrade the connection."*

### Known, deliberate
- **Trash and Spam are discovered but not synced.** They are the two folders nobody wants pulled into
  an inbox view, and syncing them would spend the storage allowance on mail the user already rejected.
  The folder rows exist, so switching one on later is a flag, not a migration.
- **Attachment content is held in memory while it is written.** The install-wide ceiling
  (`KNS_MAX_ATTACHMENT_KB`, 10 MB) bounds it. Streaming straight from the socket to disk would be
  better for very large files; it is not worth the complexity at this ceiling.
- **The incoming HTML body is stored raw.** Throwing away the original would make the inbox lie about
  what arrived. It is sanitised at render time, in Phase 8, where the display context is known.

### Phase 7 totals
- **502 tests passing / 1,704 assertions** (444 → 502)
- 7 services (`ImapClientFactory`, `ImapFailureClassifier`, `ImapGateway` + `WebklexImapGateway`,
  `FolderMapper`, `MailboxTester`, `MailboxSyncer`), 1 controller, 1 job, 1 command, 5 screens,
  209 routes total
- 54 new tests across sync, the job and command, and the screens

---

## 14. Phase 8 — Inbox UI & Composer (2026-09-07)

### The three layers around a stranger's HTML
An incoming body is the most hostile input this application handles: written by anyone on the
internet, stored verbatim, and rendered back inside a logged-in session. Three separate things
protect that render, and none of them is sufficient alone.

1. **`IncomingHtmlSanitizer`** — a parser-based allow-list (symfony/html-sanitizer, a W3C
   HTML-sanitizer-API implementation). Hand-rolled tag stripping loses to mutation XSS in ways that
   are genuinely hard to foresee, so the tests include the known `<noscript>`, `<svg><style>`,
   `<math><mtext><table><mglyph>` and `<xmp>` payloads.
2. **A sandboxed iframe** — the body is served by its own route (`inbox.body`) into
   `<iframe sandbox="">`. It never shares a document with the application, so even a bypass runs in
   an opaque origin with no script permission.
3. **A CSP on the frame document** — `default-src 'none'`, `frame-ancestors 'self'`, and
   `Referrer-Policy: no-referrer`.

Verified together against a real hostile message in a real browser: script, `onclick`,
`javascript:` href, `<iframe>` and a tracking pixel all present in the source; none of it reached the
page, the frame reported an opaque origin, and the frame document itself was clean.

### Remote images are blocked, and the screen says why
A remote image in an email is a tracking pixel unless proven otherwise — this application ships one
of its own, which is exactly how it knows. Loading them on open would tell every sender the moment
their mail was read and the reader's IP with it. So they are held back, the count is shown, and the
screen states the reason rather than hiding it behind an icon.

The address is kept in a `data-` attribute, which the browser never fetches, so "show images" is a
re-render rather than a second trip to the message. The choice is deliberately not remembered: it is
a decision about one sender's message, not a setting.

### Real bugs found in this phase

1. **`cid:` inline images never worked.** symfony/html-sanitizer has no notion of the `cid` scheme
   and strips the `src`, leaving a bare `<img>` — so every embedded image in received mail would have
   silently failed to display. They are now resolved to a real URL on our own origin *before*
   sanitising, and the allow-list then accepts them on their merits.

2. **"Delete for good" was a soft delete.** `Email` uses `SoftDeletes`, so the row, its attachment
   files and the storage they occupied all survived — behind a label that promised otherwise.

3. **The reply quote carried no `<blockquote>`.** Found in the browser, not in a test: Quill
   normalises whatever it is given into its own formats, and a styled `<div class="kn-quote">` is
   flattened away. Receiving clients use `blockquote` to *collapse* quoted history — without it every
   reply on a long thread arrives with the whole chain expanded.

### What the adversarial pass caught in the controller
Two real UX defects, neither of them in the views the reviewers were sent to look at:

- **Delete from the reading screen returned a 404.** `back()` sent the reader to `/inbox/{id}` for a
  row that no longer existed — a 404 as the reward for a successful delete. It now goes to the Trash.
- **"Mark as unread" was a write the reader never saw.** `back()` returned to `show()`, and the first
  thing `show()` does is mark the message read again. It now leaves the message, which is also what
  the reader meant by it.

### Phase 8 totals
- 3 services (`IncomingHtmlSanitizer`, `MessageComposer`, `InboxService`), 3 controllers,
  4 screens, 25 routes
- 60 new tests across the sanitiser, the composer and the screens

---

## 15. Phase 9 — Campaign Replies & Threads (2026-09-07)

### Three matching paths, ranked by what they actually know
The clean answer is `In-Reply-To`: every campaign message goes out with a Message-ID we minted and
stored against one recipient, so a reply carrying it identifies that recipient exactly. It is
certain and needs nothing configured.

It is also not enough. Clients strip `References` on a "new message to the same person", people reply
from a different address than the one that was mailed, and gateways rewrite headers wholesale. So
there are three paths, and the matcher records which one it used:

| path | evidence | confidence |
|---|---|---|
| `header` | In-Reply-To or References names one of our Message-IDs | certain |
| `token` | a per-recipient token came back in the address | certain |
| `address` | this contact was mailed within 30 days **and** the subject matches | probable |

`reply_token` has been generated for every recipient since Phase 5 and was never read by anything.
It is read now — and it costs nothing where the sending domain does not route those addresses back,
so it works the moment somebody configures it.

### The failure worth guarding is a wrong match, not a missed one
A reply filed under the wrong campaign puts words in a customer's mouth in somebody's reporting, and
nobody goes back to check. So the address fallback is deliberately narrow:

- The address **alone** is never enough. A customer emailing support about a delivery would otherwise
  be filed under whatever campaign they last received.
- A campaign subject containing a placeholder can never match by subject: the recipient saw "Hello
  Ayesha" where the campaign says "Hello {{first_name}}", and comparing those either never matches
  or matches the wrong thing.
- `References` is read **right-to-left**. The last entry is the message being answered; taking the
  first would attribute a forwarded chain to whatever started it.
- Our own Sent copy carries the same `References` and would otherwise match itself.

### A screen that can correct itself
Because the fallback is a judgement, the conversation screen offers "unlink from this campaign", and
unlinking gives the reply count back — but only when no other message still proves that recipient
replied. A screen that cannot correct itself makes every number on it untrustworthy.

The queue screen states, in plain words, that matching is done from the headers with a fallback that
can occasionally attribute a message to the wrong campaign. And it does **not** show a per-message
confidence badge: the confidence is computed but never persisted, so claiming it per row would be
inventing data. The screen says that too.

### A real bug found in the browser
The queue showed a conversation with **"0 messages"** that plainly held one. `ReplyMatcher` creates a
thread when it attributes a reply, but only the syncer was counting messages into threads — so a
reply the matcher filed was never counted. Fixed, with a test for both directions: the matcher counts
when it files, and does not count again when the syncer already did.

### Verified by running it
- Against real data: an inbound reply carrying `In-Reply-To` matched `header / certain`, linked to
  the campaign and recipient, created the conversation, stamped `replied_at`, moved
  `replied_count` to 1 — and a second `apply()` left it at 1.
- 20 matcher tests including the wrong-match cases above and cross-tenant isolation: one account's
  reply can never be attributed to another account's campaign.
- 19 screen tests including a page of 12 conversations issuing fewer than 25 queries — a queue that
  costs a query per row is a queue nobody keeps open.

### Phase 9 totals
- **616 tests passing / 2,163 assertions** (563 → 616)
- 1 service (`ReplyMatcher`), 1 controller, 2 screens, 5 routes, 239 routes in total
- 39 new tests across matching and the screens

---

## 16. Phase 10 — Automation, A/B Testing, Notifications, Logs (2026-09-07)

### The two bugs an automation makes, and what actually prevents each

An automation fails quietly, at scale, to everybody at once. There are only two shapes of
failure worth designing around, and neither is prevented by care:

**Entering twice.** A welcome automation fires again the next time the contact is imported,
re-tagged, or added to a second list. The guard is the unique index on
`(automation_id, subscriber_id)` — not a `SELECT` first. Two events arriving together both
insert; one wins, the other catches the violation and walks away. `allow_reentry` re-arms an
existing **finished** run rather than creating a second one, and never touches a run that is
mid-flight: re-arming somebody halfway through a sequence would give them step one twice and
step four never.

**Sending twice.** The scheduler ticks every minute and a slow tick is still going when the
next starts. Each run is claimed with a conditional `UPDATE ... WHERE status = 'waiting'`; the
loser's statement matches nothing. `WithoutOverlapping` on the job is convenience — the claim
is the guarantee, and it holds even if the job runs in five copies.

There is a test for each, and the concurrency one runs two independent runner instances against
the same due run.

### A step budget is not a nicety

Instant steps chain: tag, then move list, then a condition, then a wait. A run legitimately
executes several in one tick. But a badly built automation can also execute forever, and a
runner with no ceiling turns that into a worker that never returns and a subscriber tagged ten
thousand times. `MAX_STEPS_PER_TICK` bounds the honest case and stops the broken one. A run that
hits it is **rescheduled, not killed** — a long-but-finite sequence still finishes next tick.

### Three ways a send does not happen, and only one is a failure

This distinction is most of the value in the runner:

| outcome | what it means | what happens |
|---|---|---|
| deferred | no SMTP capacity, or the monthly allowance is spent | the run is parked and retried — it is not the subscriber's fault |
| bounced | the address rejected it | recorded in the bounce log; a hard bounce ends the run and suppresses the address |
| failed | our problem — bad credentials, a dropped connection | the run fails with the reason on it |

Killing a three-week sequence because the month's allowance ran out on day four would silently
drop mail that was perfectly fine. `BounceRecorder` gained `recordStandalone()` so an automation
bounce lands in the same log and counts toward the same hard-bounce threshold as a campaign one —
otherwise an operator's threshold of 3 would only ever see half the bounces.

### Suppression is checked twice, deliberately

At enrolment, because enrolling somebody who opted out and then dropping every email is dishonest
bookkeeping — the automation would report entries it never mailed. And again immediately before
every send, because a person enrolled on day one can unsubscribe on day three, and the only
correct answer then is to stop.

### Triggers: the failure is firing when nothing happened

Every trigger fires from the middle of ordinary work — saving a contact, importing a
spreadsheet, recording an open. Two rules shape all of them:

- **The common case must be free.** Almost every account, almost every time, has no automation
  listening. The first thing `AutomationTrigger` does is one indexed query; where nothing
  matches, that is the entire cost.
- **A trigger must never break what triggered it.** An import of ten thousand contacts must not
  fail because one automation is misconfigured. Every path is wrapped, logged, and the import
  finishes. There is a test that saves a contact through an automation whose step cannot send.

"Nothing happened" is the case that needed the most care:

- Re-saving a contact is not adding one — `create()` fires, `update()` does not.
- Re-attaching a list membership somebody already has is not a join. `ListService::attach()`
  uses `insertOrIgnore`, which leaves existing rows untouched, so only rows carrying *this
  call's* timestamp are new joins.
- Bulk-tagging people who already carry the tag is not a tagging. The pivot has no timestamps,
  so the existing pairs are read before the write and subtracted after.
- Re-importing the same spreadsheet enrols nobody: only ids the import actually **created** are
  passed on.

### A machine reading your email is not a person reading your email

`campaign_opened` and `link_clicked` fire only on a genuine **human** first. A corporate gateway
opens every message to scan it; enrolling on that would send a "since you read our email"
follow-up to everybody behind that gateway, none of whom read anything.

The click case had a subtler version of the same bug, found by a test rather than by reading:
the trigger was gated on the first click *on that link*, and a scanner is almost always first —
so it consumed the first click and the reader's real click, minutes later, started nothing. The
gate is now the first click **by a person**, which is a different question and the right one.

### Two triggers are not events

"Did not open" happens to nobody. It is the absence of an event, and an absence can only be
noticed by looking — so `campaign_not_opened` and `specific_date` are swept on a schedule.

A sweep repeats, which changes the safety argument. Where an event fires once and lets the
unique index reject a duplicate, a sweep would present the same person every minute and, on an
automation with `allow_reentry`, the enroller would obligingly re-arm them each time. So every
sweep excludes anybody who already has a run, and re-entry stays what it is meant to be:
something a new event triggers, not something a clock does.

`campaign_not_opened` measures its window from each recipient's **own `sent_at`**, not from the
campaign finishing: a large campaign takes hours to go out, and measuring from the end would
give the first recipient a longer grace period than the last. `specific_date` is one-shot and
closes itself afterwards, so a contact added next week is not enrolled for a day that has passed.

### A/B testing: how the audience is divided

A sample gets the versions; the rest — the holdback — waits and receives the winner. Membership
is a hash of `(campaign, subscriber)`, not row order and not `RAND()`:

- Row order is signup order. Sampling the first 20% would test the oldest contacts and then send
  the winner to the newest. That result would look like a subject-line finding and actually be an
  audience finding.
- `ORDER BY RAND()` sorts 100,000 rows to pick a fifth of them, every time.

A hash gives an even spread in one indexed `UPDATE` and gives the same answer twice — so
re-running assignment cannot reshuffle a test that has already started sending. There is a test
for exactly that.

**The winner is a rate, never a total.** If A got 60% of the sample it wins on raw opens whatever
it said. Every comparison is over that variant's own delivered count, and a version that
delivered nothing cannot win. The test that proves it gives B a fifth of the audience and a
better rate, and asserts B wins.

Three small, surgical changes carried this into the Phase 5 send path rather than bolting a
second pipeline beside it:

1. `claim()` will not claim holdback rows while a test is undecided — otherwise the whole list
   gets the control copy and the test means nothing.
2. The chunk builds one blueprint **per variant** rather than one per campaign, so compiling
   stays at one or two passes per chunk.
3. `runChunk()` reports `awaiting_decision`, so the send job stops instead of re-queueing itself
   every second for the whole measuring window.

A variant overrides only what it sets. A subject test leaves `html` null, and reading that null
as "an empty email" would send half the list a blank message.

`CampaignDispatcher::blockers()` now refuses a split test with fewer than two versions, or with
versions that are identical — a "test" between two copies of the same email splits the audience,
waits four hours, and declares a meaningless winner.

### A real bug the tests caught

`park()` wrote `status = 'waiting'` through `forceFill()->save()` on a model whose in-memory
status was **still** `'waiting'`, because the claim had been raw SQL. Eloquent saw no change and
dropped the column from the `UPDATE`. Every parked run — every wait, every deferral, every paused
automation — would have stayed stuck on `running` until the stale-claim sweep noticed fifteen
minutes later. Silent, and it would have looked like "automations are just slow".

### Other things fixed while here

- **`AutomationStep::waitUntil()` had no ceiling.** `next_run_at` is a MySQL `TIMESTAMP`, which
  cannot hold a date past 2038. "Wait 999 weeks" computed a date the column silently refuses.
  Every unit is now capped at roughly a year.
- **Nothing ever wrote an `automation` email log.** `campaign_logs.type` has always allowed it,
  so the log screen would have shown campaign mail only while an automation sent thousands of
  messages beside it — precisely the gap somebody hits asking "did this person get the email?".
- **"Flag as important" had no inverse.** A message could be marked important and nothing
  anywhere could unmark it. A control that only works in one direction is a half-built control.
- **Two implementations of the list excerpt.** The service's copy still carried the `strip_tags()`
  flaw the view's copy had been fixed for. There is now one, and the view calls it.

### Verified by running it

- End to end on the real development database: creating a contact through the ordinary service
  fired the trigger, enrolled the contact, and one tick applied the tag and parked the run three
  days out — the wait honoured to the minute.
- `schedule:list` shows all four minute-ticks registered; both new commands run clean against
  real data.

### The adversarial audit, and the seven things it found

Phase 10 was reviewed by an independent pass whose brief was to *refute*, not agree. It found
one critical defect and six real ones. All are fixed, each with a test that fails without the fix.

**1. Deleted things were not deleted.** `withoutGlobalScopes()` — plural — lifts *every* global
scope, `SoftDeletingScope` included. Used across the automation code where only tenancy was meant
to be lifted, it handed back rows the operator had deleted: a deleted automation went on enrolling
contacts and sending mail, and a deleted contact went on receiving it. Deleting the automation is
precisely the action taken to make it stop.

This is the second time this exact bug class has appeared in this codebase — Phase 4's
`SmtpSelector` offered deleted SMTP accounts for the same reason. Nineteen call sites across seven
files are now `withoutGlobalScope(AccountScope::class)`, and a run whose automation has been
deleted is **cancelled** rather than parked, so it stops waking every fifteen minutes for something
nobody can see.

**2. A date-triggered automation could never finish.** The sweep marks itself completed when it
selects nobody, but its query selected `status = 'active'` contacts while the enroller additionally
refuses suppressed ones. A contact matching the first test and failing the second was offered every
minute, never enrolled, so the automation never completed — and, still active, went on enrolling
contacts added weeks after its date had passed. The query now selects exactly who can actually enter.

**3. The builder offered a chain the runner never walked.** A "tag them" step wrote the tag
directly, so a `tag_added` automation watching that tag never fired. Steps now feed the trigger
layer, which raised the question the original design was avoiding: two automations that tag each
other. The unique index stops that at the second hop — unless both allow re-entry, in which case
they would re-arm one another one hop per tick, forever, and every hop is an email. So an
automation-caused event may **never re-arm a finished run**. Re-entry stays what it is for:
something a new real-world event triggers, never something the automations do to each other.

**4. Importing to tag existing customers did nothing.** The import fired `tag_added` only for rows
it had created, justified by a comment claiming there was "no cheap way" to tell which existing
contacts genuinely gained the tag. `SubscriberService::bulk()` had been doing exactly that all
along — one indexed query per tag, then a diff. Re-importing a spreadsheet to tag customers the
account already knows is the ordinary way people use this.

**5 and 6. Restoring a contact disagreed with itself.** Re-adding a soft-deleted address through
the New Contact form restores the old row. The activity log said "Added contact" and the plan's
contact allowance was charged, but no trigger fired — the automation was the only part of the app
that disagreed. It fires now; anybody still holding a run cannot re-enter anyway, which is what the
unique index is for. Conversely `tag_added` fired for every tag on the form including ones a
restored contact already carried, which on a re-entrant automation would re-send a whole sequence
for no new event. Both now turn on the same diff.

**7. A trigger could still break the thing that triggered it.** The lookup was wrapped but the
queue dispatch and the subscriber fetch were not, so a locked queue table could fail the import
that fired it. Every path is covered now: a missed enrolment is the cost, never the customer's
upload.

### Two defects the audit found outside Phase 10

Both are Phase 5 code that Phase 10 exercised harder than the campaign editor ever had:

- **A block setting posted as an array crashed the save with a 500.** `blocks.*.settings => array`
  validates the container and says nothing about what is inside it, so `settings.text` could arrive
  as an array; the compiler cast it to string, PHP raised "Array to string conversion", and Laravel
  turned that into a 500 where a validation message belonged. Fixed at `BlockCatalogue::normalise()`
  — the one point every document passes through, for the same reason the colour coercion already
  lives there. A fix in the fourteen block renderers would have to be remembered fourteen more times.
- **The builder's live preview never normalised at all.** `templates.compile` handed raw input
  straight to the compiler, so the same document 500'd on every keystroke — and a preview built from
  raw input would show something the saved document never produces. It normalises now, exactly as
  the save path does.

### Two screens that disagreed with themselves

- **The "In progress" card said 3 and the page it opened showed 1.** The card counts `waiting`
  plus `running`; its link filtered `status=waiting` only. The card was right and its own link
  contradicted it — the fastest way to make every number on a report untrustworthy. The runs screen
  now understands `live`, and carries a chip for it.
- **The automations list got slower the more it was used.** Every trigger naming a tag, list or
  campaign cost its own `EXISTS` query to decide whether that target still existed — twenty rows,
  twenty extra queries. The descriptions already batched the identical lookup; both now share it.
  Twenty rows: **35 queries → 15**, the same as twenty rows watching nothing.

### Verified by running it, not only by testing it

The runner was driven through a real automation on the development database — enrol, tag, wait,
condition, tag, complete. The three things worth watching all held: a second `enrol()` returned
null on the unique index rather than creating a second run; the tick immediately after the wait
step claimed **nothing**, proving the wait is not skipped; and `entered_count` / `completed_count`
finished at 1 and 1 with no drift.

### Three more the A/B audit found

The split-test reviewer's own probes survived it hitting a limit, and each named something real:

- **A deleted campaign was still being decided.** The same `withoutGlobalScopes()` defect as the
  automations, in `decideDue()` and in the `--campaign` override: a campaign the operator had
  binned had its winner chosen, its held-back audience released, and somebody notified about it.
- **The console override ignored the data, not just the clock.** `--campaign` means "ignore the
  waiting window". It was also ignoring whether the sample had finished, so it would pick a winner
  by comparing a version that had gone out against one that had sent **nothing at all** — a state
  the screen refuses with a 422 and the sweep skips. Three copies of that rule existed and the
  command was the one without it; there is now one copy on the service and the other two delegate.
- **The split would break entirely under `ANSI_QUOTES`.** Assignment is a CRC32 over string
  literals in raw SQL, written with double quotes. Under MySQL's `ANSI_QUOTES` sql_mode those parse
  as *identifiers*, and assignment died with `Unknown column ':' in 'where clause'`. Latent rather
  than live on this server, and exactly the kind of thing nobody finds until the day the app meets
  a differently configured one. Now single-quoted, with the one status literal bound instead.

### The same bug class, swept

`withoutGlobalScopes()` — plural — has now caused a real defect three times in this project
(Phase 4's SMTP selector, Phase 10's automations, Phase 10's A/B decision). It reads like "ignore
tenancy" and actually means "ignore everything, including the fact that this row was deleted".

A sweep of `app/` found **108 call sites, 48 of them on a soft-deleting model**. Most are correct —
an admin dashboard counting every account that ever existed means to include deleted ones. One was
not, and it was charging customers for it:

- **Deleted contacts and lists still counted against the plan allowance.** `PlanLimits::usageFor()`
  answers seven questions; five spell out `whereNull('deleted_at')` and these two did not. Delete
  five hundred contacts and the plan still said they were there — and would eventually refuse to
  let any more be added. The file's own adjacent lines showed the intended behaviour.

The remaining 46 need a judgement each rather than a blind sweep, and that is the first item of
Phase 11.

### Phase 10 totals

- **793 tests passing / 2,978 assertions** (616 → 793), 265 routes
- 4 services (`AutomationEnroller`, `AutomationRunner`, `AutomationTrigger`, `AutomationSweeper`),
  plus `AbTestService`; 5 controllers, 2 jobs, 2 commands, 4 scheduler ticks
- 9 screens across automations, notifications and the email log
- 7 trigger types, all with a real code path that fires them; 7 step types, all implemented by
  the runner

---

## 17. Phase 11 — Hardening & Delivery (2026-09-07)

### 11.1 Security pass

**The scope audit, finished.** Phase 10 ended with a list of 48 `withoutGlobalScopes()` call sites
on soft-deleting models and the note that each needed a judgement rather than a sweep. Working
through them found five more places where deleting something did not stop it:

- **A campaign deleted after its chunk was queued kept sending.** The worker picked the job up
  afterwards and mailed the rest of the list. Deleting a campaign is the only action available to
  stop a send already in flight, so it has to work.
- **A deleted mailbox kept being polled** every minute, spending the provider's rate limit to
  download mail into an inbox nobody can open.
- **Removed team members stayed on the Team screen and kept their seat.** `PlanLimits` has always
  counted seats with `whereNull('deleted_at')`, so the team page and the plan page were answering
  the same question differently — and the seat never came back, so a replacement could not be
  invited.
- **Two notifications could never reach their own "this has since been deleted" fallback.** Both
  carry a graceful branch for a deleted target; the plural scope found the deleted row, so the
  branch only ran for hard deletes, which do not happen. The notification linked to a page that
  answers 404.
- **A deleted contact could never be re-imported.** `update` reported them updated while they
  stayed invisible; `skip` counted them as duplicates. The operator re-uploads their list and
  those people simply never come back, with nothing said. It restores them now, exactly as the
  single-contact form always has.

The rest of the 48 are correct as they stand and stay: an admin dashboard counting every account
that ever existed means to include deleted ones, the IMAP de-duplication check must see deleted
messages or a sync would download them again, and the import's existence check must see them
because `UNIQUE(account_id, email)` does not exclude them.

**Policies.** The plan called for a policy on every model. This application does not have one and
should not: authorisation here is already two layers — the `AccountScope` global scope, so another
tenant's row is not found at all (a 404, which does not even confirm it exists), and `permission:`
middleware on 106 routes for what a role may do. A third mechanism restating the same rule is
ceremony, and ceremony that can drift.

What that arrangement does need is proof, because it is enforced by a scope being armed rather than
by a check at each call site: forget it in one query and nothing complains. So there is now a sweep
that takes the list of bound routes **from the router**, not from a hand-written list, creates one
record of every tenant-owned kind under a second account, and asks for each of them by id. It fails
if a route hands one over, and it also fails if a new binding appears that the sweep has never heard
of — so the next model added cannot quietly go untested. It caught two bindings the first time it
ran (email attachments, and an account's custom roles).

**Rate limits.** Login was already throttled inside `LoginRequest`, keyed on email *and* IP —
the only key that stops one attacker without locking out everybody behind a shared address.
Registration, the forgotten-password form, the reset and confirm forms and both password-change
routes were not, and are now. They are the endpoints cheap to call and expensive to be wrong about:
registration creates accounts, and the forgotten-password form sends mail to an address the caller
names, which is a way to bomb somebody else's inbox.

The public unsubscribe and tracking routes are deliberately **not** throttled. They are signed, and
they are reached from inside delivered mail — a whole office behind one address, or a provider's
proxy, shares an IP. Refusing somebody's opt-out because a colleague opted out first is the one
failure worth avoiding above all others here.

**Uploads** were already sound and were re-checked rather than changed: contact imports are capped
at 50 MB, restricted by MIME, and stored on the private disk under the account's own folder with a
generated name; attachments carry a configurable size cap and a plan storage check, and downloads
are always served as `application/octet-stream`.

**CSP** over rendered email HTML was in place on all three surfaces — the campaign preview, the
template preview and the inbox body — each embedded in a `sandbox=""` frame with
`frame-ancestors 'self'` rather than `X-Frame-Options`, because a sandboxed frame's origin is
opaque and `SAMEORIGIN` can never match it. The two previews were missing `Referrer-Policy`, so a
remote image in a preview told its host which page of the application was open. They have it now.

### 11.2 Performance pass

**N+1.** Rather than asserting a fixed query count per screen — a number that legitimately moves
whenever a screen gains a feature, so the test ends up being rewritten to match the code — each of
the **thirteen** list screens is now rendered twice, with three rows and with fifteen, and what is
asserted is the shape: the count must not grow with the row count. A per-row query adds twelve and
is caught immediately; an honest extra query for a new feature is not. All thirteen pass, which
also confirms the automations-index batching done at the end of Phase 10 held.

**Indexes.** Four list screens run `WHERE account_id = ? ORDER BY id DESC LIMIT n`, and every one
of those tables already had an index beginning with `account_id` — which is exactly why it looked
covered. It was not: the next column was `status` or `created_at`, and InnoDB appends the primary
key *after* those, so the stored order is `(account_id, status, id)`. With `status` unconstrained —
as it is on the default view everybody loads — the index cannot supply the `id` ordering, and MySQL
sorts every matching row before taking the first fifty.

`EXPLAIN` said `Using filesort` on the email log for both the plain and the status-filtered
listing. `EmailLogController` carried a comment asserting "id DESC is chronological and
index-backed"; it was half right, and the half that was wrong is the half that matters. That table
gains a row per delivered email, so a busy account was sorting millions of rows to render page one.

`2026_09_07_000193` adds `(account_id, id)` to subscribers, campaigns, automations and
campaign_logs, plus `(account_id, status, id)` for the log's filtered view. Every plan re-checked
with `EXPLAIN` afterwards: no filesort anywhere. The comment now says what is true, and names the
migration that made it true.

**Pagination** was already everywhere it needed to be — the inbox at 25 a page is the one that
matters most. The handful of screens that do not paginate are bounded by something real rather than
by luck: mailboxes and SMTP accounts by plan limits, custom fields by a feature flag, roles by an
explicit `limit(50)`. The contact export deliberately does not paginate, because it streams.

### 11.3 Ten thousand recipients through the real queue

Every correctness property in the send path is about scale — claim-before-send, the chunk loop,
the pacing, the counters — and every one of them has a test at three or four recipients that would
still pass if the design fell apart at ten thousand: a lock held a moment too long, a chunk that
re-claims rows it has already sent, an accumulator that drifts by one per pass. None of that shows
up in a small test.

`tests/Load/TenThousandRecipientsTest.php` sends to ten thousand addresses through the **database**
queue driver rather than `sync`, so the job genuinely re-queues itself, genuinely competes for its
own lock, and genuinely paces. It lives outside the two testsuites `phpunit.xml` declares, so an
ordinary run never touches it:

```
php vendor/bin/phpunit tests/Load/TenThousandRecipientsTest.php
```

The result, on this machine:

| | |
|---|---|
| recipients | 10,000 |
| audience generated in | 2.0 s |
| sent in | 185-225 s across **20** worker passes |
| throughput | 44-54 messages/sec |
| peak memory | 82 MB |

(Two runs, quoted as a range rather than a single figure: this is a laptop
running MariaDB, PHP and the test in one process, and a single number from it
would be precision the measurement does not have.)

Twenty passes is exactly twenty chunks of five hundred, which is the first thing worth checking:
the loop neither stalled nor spun.

What is asserted is exactly-once, and it is asserted from the transport's own record rather than
from the application's counters: ten thousand messages, ten thousand **distinct** addresses, and no
address written to twice. Then the bookkeeping is checked against it — every row `sent` with none
left claimed or locked, `sent_count` agreeing with the rows, the month's allowance charged once per
message, nothing left on the queue and nothing in `failed_jobs`.

Forty-four a second is not a ceiling worth optimising: the install-wide default pace is 120 a
minute, so the send path is already twenty times faster than the rate it is configured to send at,
and real throughput is set by the SMTP provider rather than by this code. Memory is flat because
nothing loads the audience into it — the recipients are claimed and released a chunk at a time.

**The scheduler** gets its own two tests in the same file, because a campaign usually sends because
a cron tick found it, and that path has no user in it at all: nothing about it is exercised by
pressing Send. One schedules a campaign in the past and follows it through the tick, the claim, the
queue and out to twelve hundred recipients. The other deletes a scheduled campaign first and proves
the tick will not pick it up — the guard `DispatchScheduledCampaigns` claims in its own comment, now
with a test behind it.

### 11.4 Global search

One box across contacts, campaigns, inbox messages, the email log, templates, automations, lists,
segments, tags and the do-not-send list, with a date range.

**It reuses each model's own idea of "search".** Four models already carry a `scopeSearch`, written
when their own list screen was built. The global search calls those rather than restating them: a
global search matching different fields from the screen it sends you to would answer a question
nobody asked, and the two definitions would drift the first time one of them gained a column.

**Permissions decide what is searched, not what is hidden afterwards.** Each group carries the
permission its own screen requires, and a group the user may not open is never queried. Filtering
results after the fact would still leak the count of matches behind a locked door. The screen says
plainly that some areas were skipped and why, rather than quietly presenting a partial answer as a
complete one.

**Two things about the cost, both stated on the screen.** A substring match cannot use an index, so
what keeps it honest is `ORDER BY id DESC LIMIT n` over the `(account_id, id)` indexes added in
11.2: MySQL walks the account newest-first and stops as soon as it has enough. That makes a term
which matches *anything* cheap however large the table is. The expensive case is a term that
matches **nothing** — it has to walk the whole account before it can say so — which is why terms
shorter than two characters are refused outright rather than run, and the empty state says so.

The screen also says what it does *not* do: it never reads message bodies or attachments. That
content is not indexed and searching it would mean reading every message on every search. Somebody
whose search misses deserves to know why rather than concluding the product is broken.

**A defect the tests surfaced.** `%` and `_` are LIKE's own operators, so searching for a single `%`
returned every record in every table, and an address containing an underscore returned a list with
nothing to do with it — with nothing on screen able to explain it. `App\Support\Search` now escapes
them in one place, and the four model scopes and the global search all go through it, so a term
means the same thing in the box at the top of the page as it does on the contacts screen.

Verified in a browser as well as in tests: the results render, and a result row is a real link —
clicking a contact lands on that contact's edit screen, not a placeholder.

### 11.5 The account's own activity log

Every module already wrote to `activity_logs` — **86 distinct events across 17 areas**, from
`campaign.cancelled` to `smtp.test_failed` to `auth.login`. The plan asked for eleven event types
and the application had eight times that. What it did not have was a screen an account could read:
the only one that existed was the super admin's.

That is the gap worth naming plainly. An account whose contact list was emptied, whose campaign was
cancelled or whose SMTP password was changed had no way to find out who did it, while the platform
operator could see all of it. An audit trail only the vendor can read is not an audit trail for the
customer.

`/activity` shows it, filtered by area, by person, by date range and by a search over the
description and the event name. It says what it will not do, too: entries are kept for as long as
the account exists and nothing can edit or remove them, which is the only reason a log is worth
reading.

**Areas rather than a list of every event.** The admin screen builds its dropdown from
`SELECT DISTINCT event`, and at 86 options that is a list nobody scans. This filters on the part
before the dot — 17 choices, no query at all to build them, and `event LIKE 'campaign.%'` is a
prefix match an index can serve. (The admin screen's `DISTINCT` was checked rather than assumed:
`EXPLAIN` reports a covering index scan, so it was left alone.)

**A real leak, caught by its own test.** The "Done by" dropdown was `User::query()->get()` — and
`User` is one of the few models that does **not** use the `BelongsToAccount` trait, so there is no
`AccountScope` on it and that returns every user of every account. The screen was listing other
customers' staff by name. It is filtered explicitly now, with the reason written above it, and a
sweep confirmed the only other unscoped `User` query in the application (`AccountNotifier`) already
filters correctly.

**And the same index trap, one phase later.** `EXPLAIN` on the new screen said `Using filesort`:
`activity_logs` had `(account_id, created_at)` and `(event, created_at)`, and neither can serve
`WHERE account_id = ? ORDER BY id DESC` — exactly the shape fixed for four other tables in 11.2,
reintroduced by a new screen a day later. `2026_09_07_000194` adds `(account_id, id)`.

Ordering by `id` rather than by `created_at`, which the existing index would have served, is
deliberate: an audit trail records bursts of events inside the same second, and paginating an
unstable ordering shows some rows twice and skips others. In an audit trail that is worse than
being slow.
