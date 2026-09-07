# KN Softic — Local Setup (XAMPP / Windows)

## Requirements found on this machine

| Item | Version |
|---|---|
| PHP | 8.2.12 (XAMPP) |
| Composer | 2.10.2 |
| MySQL | MariaDB 10.4.32 (XAMPP) |
| Node / npm | 24.18.0 / 11.16.0 |

The `imap` PHP extension is **not** required — the platform uses
`webklex/php-imap` in native mode (a pure-PHP IMAP client).

---

## 1. First-time install

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Create the database (XAMPP MySQL must be running):

```bash
"C:/xampp/mysql/bin/mysql.exe" -u root -e "CREATE DATABASE IF NOT EXISTS knsoftic_mail CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

Then:

```bash
php artisan migrate --seed
php artisan storage:link
npm run build
```

`--seed` creates the permission catalogue, the three system roles, the
Starter/Business/Pro plans, the KN Softic branding settings and the first
super admin.

**Default super admin:** `admin@knsoftic.com` / `password`
Change it immediately — set `KNS_ADMIN_EMAIL` and `KNS_ADMIN_PASSWORD` in
`.env` before seeding, or change the password after logging in.

---

## 2. Running it

### Dev server

```bash
php artisan serve --port=8090
```

Port 8000 is already used by another app on this machine, so 8090 is used.
Open http://127.0.0.1:8090

### Vite (only while editing CSS/JS)

```bash
npm run dev
```

While `npm run dev` is running, do **not** run `php artisan view:clear`. Tailwind's content globs
include `storage/framework/views/*.php`, so deleting the compiled views under a live watcher leaves
PostCSS reading a file that no longer exists and the page loses its styling until Vite restarts.
Stop Vite first, or just reload after restarting it. This does not affect `npm run build`.

### Queue worker — required for campaigns, imports and mailbox sync

Bulk sending never happens in the web request. Keep a worker running:

```bash
php artisan queue:work --tries=3 --timeout=120
```

Windows has no `pcntl`, so the worker cannot be signalled for a graceful
restart — stop it with Ctrl+C and start it again after deploying code. For
production, run it under a Windows service (NSSM) or Supervisor on Linux.

### Scheduler — required for scheduled campaigns and auto IMAP sync

There is no cron on Windows. Create a Task Scheduler entry that runs every
minute:

- **Program:** `C:\xampp\php\php.exe`
- **Arguments:** `artisan schedule:run`
- **Start in:** `C:\xampp\htdocs\email markting`
- **Trigger:** daily, repeat every 1 minute, indefinitely

On Linux the equivalent crontab line is:

```
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

Without the scheduler nothing time-based happens at all. Four commands run on
it, every minute:

| Command | Without it |
|---|---|
| `campaigns:dispatch-scheduled` | A campaign set to go out at 09:00 never starts. |
| `automations:tick` | Automations enrol people and then never advance them: a wait step never ends. |
| `campaigns:decide-ab` | A split test never picks a winner, so the held-back audience is never sent anything. |
| `mailboxes:sync` | No mail is ever fetched, so the inbox and campaign replies stay empty. |

Each does almost nothing most minutes — it counts what is due first and queues
work only when there is some. Check they are registered:

```bash
php artisan schedule:list
```

Any of them can be run by hand while you are watching:

```bash
php artisan automations:tick --sync     # advance automation runs right now
php artisan mailboxes:sync --force      # ignore each mailbox's interval
php artisan campaigns:decide-ab --campaign=12
```

### Sending speed

Two `.env` values control the pace of a bulk send:

| Variable | Default | What it does |
|---|---|---|
| `KNS_CAMPAIGN_CHUNK` | 500 | Recipients claimed per pass. Clamped to 1–1000. A bigger chunk is fewer queue round-trips but makes **Pause** take longer to bite, because a pass finishes what it claimed. |
| `KNS_SEND_RATE_PER_MINUTE` | 120 | Install-wide ceiling. The job spaces the next chunk out to respect it rather than sleeping inside the worker. |

Per-account and per-SMTP limits are separate from these and always apply on top
— set them on the SMTP account itself, so one provider's hourly cap cannot be
exceeded by raising a global value here.

---

## 3. Useful commands

```bash
php artisan migrate:fresh --seed     # rebuild the database from scratch
php artisan db:seed --class=DemoDataSeeder   # add the demo account and 60 contacts
php artisan test                     # run the test suite
php artisan optimize:clear           # clear config/route/view caches
php artisan queue:failed             # inspect failed jobs
```

`migrate:fresh --seed` gives you permissions, roles, the three plans, system
settings, a super admin and ten system templates — everything the application
needs and no sample data. `DemoDataSeeder` is separate and opt-in for exactly
that reason: it creates `demo@knsoftic.test` / `Password123!` with contacts,
lists, a campaign and an SMTP record, which is what you want on a laptop and
never what you want on a server.

### Test database

The suite runs against **MySQL/MariaDB, not SQLite**. Create the test database
once:

```bash
"C:/xampp/mysql/bin/mysql.exe" -u root -e "CREATE DATABASE IF NOT EXISTS knsoftic_mail_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

The load tests are **not** part of that run. They live in `tests/Load`, which
`phpunit.xml` does not declare as a testsuite, because one of them sends to ten
thousand recipients through the real database queue and takes about four
minutes. Run them deliberately, when the send path has been touched:

```bash
php vendor/bin/phpunit tests/Load/TenThousandRecipientsTest.php
```

This is deliberate. The segment engine depends on behaviour that differs
between engines — JSON path queries on the `subscribers.custom` column, and
backslash escaping inside `LIKE` — so testing on SQLite would have passed
while production failed. `RefreshDatabase` wraps each test in a transaction,
so the suite is slower than in-memory SQLite but is exercising the real engine.

---

## 4. Mail during development

`MAIL_MAILER=log` — password resets, verification links and system notices are
written to `storage/logs/laravel-*.log` instead of being sent. Campaign and
inbox mail always uses the per-account SMTP records stored in the database, not
this mailer.

---

## 5. Notes

- All indexed string columns are capped at 191 characters for MariaDB's
  767-byte index limit; `Schema::defaultStringLength(191)` enforces this.
- `Model::preventLazyLoading()` is on in local, so an N+1 query throws during
  development instead of silently shipping.
- SMTP and IMAP passwords are stored with Laravel's `encrypted` cast and are
  hidden from model serialisation. Rotating `APP_KEY` will make existing
  stored credentials unreadable.
