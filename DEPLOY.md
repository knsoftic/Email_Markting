# Deploying KN Softic

For a Linux server running nginx or Apache, PHP 8.2+ and MySQL 8 / MariaDB 10.4+.

Read this in order. The two sections most likely to be skipped — the scheduler
and the queue worker — are the two the application cannot run without.

---

## 1. Requirements

| | Minimum | Notes |
|---|---|---|
| PHP | 8.2 | with `mbstring`, `openssl`, `pdo_mysql`, `intl`, `zip`, `gd`, `curl` |
| MySQL / MariaDB | 8.0 / 10.4 | InnoDB, `utf8mb4` |
| Node | 20+ | build only; not needed at runtime |
| Composer | 2 | |
| A supervisor | systemd, Supervisor | to keep the queue worker alive |

The IMAP PHP extension is **not** required. Mailbox syncing uses
`webklex/php-imap` in its native-protocol mode, which speaks IMAP over a plain
socket — one less thing to compile.

---

## 2. First deploy

```bash
git clone https://github.com/knsoftic/Email_Markting.git
cd Email_Markting

composer install --no-dev --optimize-autoloader
npm ci && npm run build

cp .env.example .env
php artisan key:generate
```

Then edit `.env`. The values that matter most:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://mail.yourdomain.com

DB_DATABASE=knsoftic_mail
DB_USERNAME=knsoftic
DB_PASSWORD=…

QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true

MAIL_MAILER=smtp     # for system mail only — see §6
```

**`APP_URL` is not cosmetic.** Every unsubscribe link, tracking pixel and click
redirect is a signed URL built from it. Left as `http://localhost`, a campaign
goes out with opt-out links nobody outside your network can reach — which is
both a broken promise to the recipient and a compliance problem. The application
refuses to send in production if it detects a local `APP_URL`, but set it
properly rather than relying on that.

**`APP_KEY` is irreversible.** SMTP and IMAP passwords are stored with Laravel's
`encrypted` cast. Rotating the key makes every stored credential unreadable, and
there is no recovery — the accounts have to be re-entered by hand. Back it up
with the database, and treat the two as one thing.

Then:

```bash
php artisan migrate --force
php artisan db:seed --force
```

`db:seed` installs permissions, roles, the three plans, system settings, a super
admin and the system email templates. It does **not** install demo data;
`DemoDataSeeder` is separate and deliberately not wired into `DatabaseSeeder`,
because a live server should never come up with a `demo@knsoftic.test` account
that has a known password.

Change the super admin's password at first sign-in.

Finally:

```bash
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

---

## 3. The web server

The document root is `public/`, never the project root — everything above it,
including `.env` and uploaded contact lists, must be unreachable over HTTP.

```nginx
server {
    listen 443 ssl http2;
    server_name mail.yourdomain.com;

    root /var/www/knsoftic/public;
    index index.php;

    client_max_body_size 64M;   # contact imports are capped at 50 MB

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known) { deny all; }
}
```

Permissions:

```bash
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache
```

Nothing else needs to be writable. `storage/app/private` holds uploaded contact
lists and mail attachments and is served only through a controller that checks
the tenant first — it is never exposed as a static path.

---

## 4. The scheduler

**The application does nothing time-based without this.** One crontab line:

```
* * * * * cd /var/www/knsoftic && php artisan schedule:run >> /dev/null 2>&1
```

It drives four minute-ticks, and each one matters:

| Command | Without it |
|---|---|
| `campaigns:dispatch-scheduled` | A campaign scheduled for 09:00 never starts. |
| `automations:tick` | Automations enrol people and never advance them — a wait step never ends. |
| `campaigns:decide-ab` | A split test never picks a winner, so the held-back audience is never sent anything at all. |
| `mailboxes:sync` | No mail is fetched: the inbox and campaign replies stay permanently empty. |

Each counts what is due before it queues anything, so a quiet install costs four
indexed counts a minute.

Verify after deploying:

```bash
php artisan schedule:list
```

---

## 5. The queue worker

Campaign sending, imports, IMAP fetches and automation ticks are all queued.
Without a worker they sit in the `jobs` table and nothing happens — with no
error anywhere, which is the confusing part.

`/etc/systemd/system/knsoftic-worker.service`:

```ini
[Unit]
Description=KN Softic queue worker
After=network.target mysql.service

[Service]
User=www-data
Restart=always
RestartSec=5
ExecStart=/usr/bin/php /var/www/knsoftic/artisan queue:work --sleep=1 --tries=3 --max-time=3600

[Install]
WantedBy=multi-user.target
```

```bash
systemctl enable --now knsoftic-worker
```

`--max-time=3600` restarts the worker hourly. That is intentional: a long-lived
PHP process holds its code in memory, so a worker started before a deploy keeps
running the old code until it is replaced.

**One worker is the safe default.** Running several is supported — every
recipient is claimed with a conditional `UPDATE` before it is sent, so two
workers cannot mail the same person twice — but more workers send faster than
your SMTP provider is likely to allow, and the per-account limits are what
should be raised first.

---

## 6. Mail

There are two entirely separate paths, and conflating them is the usual mistake.

- **System mail** — password resets, verification links, internal notices — uses
  the `MAIL_*` values in `.env`.
- **Campaign, automation and inbox mail** uses the SMTP accounts stored per
  tenant in the database. Changing `MAIL_MAILER` has no effect on it whatsoever.

For deliverability, the sending domain needs SPF, DKIM and DMARC configured with
whichever provider each tenant uses. That is a DNS job on the customer's domain,
not something this application can do for them.

---

## 7. Updating

```bash
cd /var/www/knsoftic
php artisan down

git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force

php artisan optimize:clear
php artisan config:cache && php artisan route:cache && php artisan view:cache

systemctl restart knsoftic-worker
php artisan up
```

Restarting the worker is not optional — see `--max-time` above.

Run the migrations before bringing the site up, and take a database backup
before running them. The listing-index migrations
(`2026_09_07_000193`, `..._000194`) add indexes to `subscribers`, `campaigns`,
`automations`, `campaign_logs` and `activity_logs`; on a large installation
those tables may be locked while the index builds, which is a reason to run the
update in a quiet window rather than a reason to skip it.

---

## 8. Backups

Three things, and all three are needed together:

1. **The database** — everything except uploaded files.
2. **`storage/app/private`** — uploaded contact lists and mail attachments.
3. **`APP_KEY`** — without the exact key that encrypted them, the SMTP and IMAP
   passwords in a restored database are unreadable.

A database dump alone is not a restorable backup of this application.

---

## 9. After deploying, check these

- [ ] `https://…` loads and sign-in works
- [ ] `php artisan schedule:list` shows all four minute-ticks
- [ ] `systemctl status knsoftic-worker` is active
- [ ] `APP_DEBUG=false` — confirm by requesting a URL that does not exist and
      checking no stack trace is shown
- [ ] `.env` is not reachable over HTTP
- [ ] Send a test campaign to yourself, then click the unsubscribe link in it
      and confirm it resolves on your real domain
