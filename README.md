# KN Softic — Email Marketing & Inbox

A multi-tenant email marketing platform with a real mailbox attached: send
campaigns, receive the replies, and see them as one conversation.

Built on Laravel 12 and MySQL/MariaDB. Every account is a tenant; every query is
scoped to one.

---

## What it does

**Sending.** A block-based email builder, contact lists, tags and segments,
scheduled and immediate sends, and A/B tests that split an audience by a hash
rather than by row order — so the test measures the subject line and not the
age of the contacts who happened to be sampled.

**Delivering.** Multiple SMTP accounts per tenant with rotation, per-hour,
per-day and per-month ceilings, cooldowns after repeated failures, and a send
path that claims each recipient with a conditional `UPDATE` before it sends —
so two workers cannot mail the same person twice.

**Receiving.** IMAP mailboxes synced incrementally (UIDVALIDITY-aware, so a
folder that is rebuilt on the server is re-read rather than half-skipped), a
full inbox with a composer, and reply matching that links an inbound message
back to the campaign that prompted it.

**Automating.** Seven trigger types and seven step types, with per-subscriber
run state, wait steps, conditions, and a guard that a contact enters an
automation once unless it explicitly allows re-entry.

**Measuring.** Open and click tracking, bounce handling, unsubscribe and
suppression, an email log, per-campaign analytics, in-app notifications, and an
audit trail the account itself can read.

---

## The rules this codebase follows

These shaped most of the decisions in it, so they are worth stating up front.

**Nothing is a placeholder.** Every button posts to a route that exists and does
what its label says. There are no stub pages and no half-built modules.

**It will not help anyone send unsolicited mail.** There is no feature here for
bypassing spam filters, hiding sender identity, faking headers or evading a
provider's limits. Every campaign carries a working unsubscribe link and the
RFC 8058 one-click header; the public unsubscribe route is deliberately left
un-throttled, because refusing somebody's opt-out because a colleague opted out
first is the worst failure this application could have.

**A screen may not claim more than it knows.** Where a number is an estimate it
says so; where a match is a judgement rather than a certainty it says that too;
and where something was not searched or not counted, the screen says which and
why rather than presenting a partial answer as a complete one.

**Deleting something stops it.** A deleted campaign stops sending mid-send, a
deleted mailbox stops being polled, a deleted automation stops enrolling. This
sounds obvious and was the single most common defect found during hardening —
`withoutGlobalScopes()` lifts the soft-delete scope along with the tenant one.

---

## Getting started

Full instructions, including the Windows/XAMPP specifics, are in
[SETUP.md](SETUP.md). The short version:

```bash
composer install
npm install && npm run build
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan db:seed --class=DemoDataSeeder
php artisan serve
```

That leaves you with a demo account at `demo@knsoftic.test` / `Password123!`
and a super admin at `admin@knsoftic.com`.

Two background processes matter and the application does nothing time-based
without them — a queue worker and the scheduler. Both are covered in SETUP.md.

For a server, see [DEPLOY.md](DEPLOY.md).

---

## Testing

```bash
php artisan test                                        # the suite
php vendor/bin/phpunit tests/Load/TenThousandRecipientsTest.php   # the load tests
```

**846 tests / 3,220 assertions**, run against a real MySQL database rather than
SQLite — the segment engine depends on JSON path behaviour and `LIKE` escaping
that differ between engines, so testing on SQLite would have passed while
production failed.

The load tests are kept out of the ordinary run because one of them sends to ten
thousand recipients through the real database queue. It is the test that matters
most for the send path: every correctness property there is about scale, and a
three-recipient test would still pass if the design fell apart at ten thousand.

Two of the suites are sweeps rather than fixed lists, and are meant to fail when
the application grows past them:

- `CrossTenantAccessTest` takes the bound routes **from the router**, creates one
  record of every tenant-owned kind under a second account, and asks for each by
  id. It fails if a route hands one over — and also if a new binding appears that
  it has never heard of, so the next model added cannot quietly go untested.
- `ListScreenQueryCountTest` renders every list screen twice, with three rows and
  with fifteen, and asserts the query count does not grow with the row count.

---

## Layout

```
app/
  Http/Controllers/    one per module, thin
  Jobs/                queued work: sending, importing, IMAP, automations
  Models/              Eloquent models; AccountScope is applied by a trait
  Services/            where the decisions live — sending, tracking, IMAP,
                       automation, search
  Support/             TenantManager, PlanLimits, ActivityLogger
database/
  migrations/          51, in dependency order
  seeders/             permissions, roles, plans, settings, templates, demo
resources/views/       Blade + Tailwind + Alpine
tests/
  Feature/             the suite
  Load/                deliberate, not part of a normal run
```

[PROJECT-LOG.md](PROJECT-LOG.md) is the build log: what was decided in each
phase and, more usefully, what was found wrong afterwards and why.

---

## Licence

Proprietary. © KN Softic.
