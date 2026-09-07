# Deploying to aaPanel — `email.knbazaar.com`

A step-by-step guide for this exact server layout:

| | |
|---|---|
| Panel | aaPanel (BT Panel) |
| Domain | `email.knbazaar.com` |
| Site path | `/www/wwwroot/email.knbazaar.com` |
| Document root | `/www/wwwroot/email.knbazaar.com/public` |
| PHP | 8.2 (`/www/server/php/82/bin/php`) |
| Web user | `www` |

Every command below can be pasted as-is. Where aaPanel has a screen for
something, both the screen and the shell equivalent are given, because panel
menus move between versions and the shell does not.

> **Read §3 before anything else.** aaPanel ships PHP with functions disabled
> that Laravel and Composer require. Skipping it is the reason most aaPanel
> Laravel deployments fail on the first command, with an error that does not
> mention aaPanel at all.

---

## 1. Create the site

**aaPanel → Website → Add site**

| Field | Value |
|---|---|
| Domain | `email.knbazaar.com` |
| Root directory | `/www/wwwroot/email.knbazaar.com` |
| PHP version | 8.2 |
| Database | create one now — see §4 |

Then set the running directory, which is the part people miss:

**Website → `email.knbazaar.com` → Site directory → Running directory → `/public`** → Save

Laravel's front controller is `public/index.php`. If the running directory
stays at the site root, the whole application — including `.env` — is served as
static files over the internet.

Also on that screen, **turn OFF "Anti-XSS attack" / cross-site protection**
(`open_basedir`) or add the site's own path to it. It confines PHP to the site
directory, and Composer writes to `~/.cache` outside it.

---

## 2. Required PHP extensions

**aaPanel → App Store → PHP 8.2 → Settings → Install extensions**

Install if not already present:

```
fileinfo   mbstring   openssl   pdo_mysql   tokenizer   xml   ctype   json
bcmath     curl       zip       gd          intl        opcache
```

`fileinfo` is the one that is off by default and it is needed for uploads —
without it a contact-list import fails validation with an unhelpful message.

**IMAP is not needed.** Mailbox syncing uses `webklex/php-imap` in native
protocol mode, which speaks IMAP over an ordinary socket. Do not install the
`imap` extension looking for it.

---

## 3. Disabled functions — do this first

**aaPanel → App Store → PHP 8.2 → Settings → Disabled functions**

Remove these from the list:

```
proc_open        proc_get_status      proc_close
putenv           pcntl_signal         pcntl_alarm
symlink          readlink             shell_exec        exec
```

Why each one matters:

| Function | What breaks without it |
|---|---|
| `proc_open`, `proc_close`, `proc_get_status` | **Composer will not run at all.** `composer install` fails immediately. |
| `putenv` | Composer and several Symfony components fail in confusing ways. |
| `symlink`, `readlink` | `php artisan storage:link` fails. |
| `pcntl_signal`, `pcntl_alarm` | The queue worker cannot handle timeouts or graceful restarts. |
| `shell_exec`, `exec` | Some Composer plugins and diagnostics fail. |

Click **Save**, then restart PHP:

```bash
/etc/init.d/php-fpm-82 restart
```

---

## 4. The database

**aaPanel → Databases → Add database**

| Field | Value |
|---|---|
| Database name | `knsoftic_mail` |
| Username | `knsoftic_mail` |
| Password | generate one and keep it |
| Charset | `utf8mb4` |

Confirm the collation is `utf8mb4_unicode_ci`. Every indexed string column in
this application is capped at 191 characters precisely because of MariaDB's
767-byte index limit under `utf8mb4`, so a different charset will produce index
errors during migration.

---

## 5. Get the code

aaPanel creates the site directory with a placeholder `index.html`. Clear it
first, or git will refuse to clone into a non-empty directory:

```bash
cd /www/wwwroot/email.knbazaar.com
rm -f index.html .user.ini 404.html

git clone https://github.com/knsoftic/Email_Markting.git .
```

If the repository is private, use a deploy key or a personal access token —
never a password in the URL, because it stays in the shell history and in
`.git/config`.

---

## 6. Install dependencies

### PHP

```bash
cd /www/wwwroot/email.knbazaar.com

# aaPanel's own PHP, not the system one — the system php is often 7.x
/www/server/php/82/bin/php /usr/bin/composer install --no-dev --optimize-autoloader
```

If `composer` is not installed:

```bash
curl -sS https://getcomposer.org/installer | /www/server/php/82/bin/php
mv composer.phar /usr/local/bin/composer
```

> If this step fails with **"proc_open() has been disabled"**, go back to §3.
> That is the error, every time.

### The front-end build — do not skip this

`public/build` is deliberately **not** in the repository (it is generated
output, and committing build artefacts makes every deploy a merge conflict). A
fresh clone therefore has **no compiled CSS or JavaScript**, and the site will
load as unstyled HTML with nothing working.

Pick one of these two. Both are fine.

**Option A — build on the server.** Needs Node 20+:

```bash
# aaPanel → App Store → Node.js Version Manager → install 20.x
cd /www/wwwroot/email.knbazaar.com
npm ci
npm run build
```

**Option B — build on your machine and upload.** Better on a small VPS, where
`npm ci` can exhaust the RAM:

```bash
# on your own computer, in the project
npm ci && npm run build
```

Then upload the whole `public/build` folder to
`/www/wwwroot/email.knbazaar.com/public/build` with aaPanel's File manager.

Either way, `public/build/manifest.json` must exist when you are done. If it
does not, every page will throw `Vite manifest not found`.

---

## 7. Configure `.env`

```bash
cd /www/wwwroot/email.knbazaar.com
cp .env.example .env
/www/server/php/82/bin/php artisan key:generate
```

Then edit `.env` (aaPanel's File manager, or `nano .env`):

```env
APP_NAME="KN Softic"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://email.knbazaar.com
APP_TIMEZONE=UTC

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=knsoftic_mail
DB_USERNAME=knsoftic_mail
DB_PASSWORD=the password from step 4

QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true

LOG_CHANNEL=stack
LOG_LEVEL=error

MAIL_MAILER=smtp          # system mail only — see §13
```

Three of these are worth stopping on.

**`APP_URL=https://email.knbazaar.com`.** Every unsubscribe link, tracking
pixel and click redirect is a *signed* URL built from this value, by a queue
worker that has no incoming request to infer it from. Left wrong, campaigns go
out with opt-out links nobody can reach — a broken promise to the recipient and
a compliance problem. The application refuses to send in production when it
detects a local `APP_URL`, but set it correctly rather than relying on that.

**`APP_DEBUG=false`.** With it on, any error page shows your database password.

**`APP_KEY` is irreversible.** SMTP and IMAP passwords are stored encrypted
with it. Regenerating the key later makes every stored credential unreadable,
with no recovery — they have to be re-entered by hand. Back it up with the
database and treat the two as one thing.

---

## 8. Migrate and seed

```bash
cd /www/wwwroot/email.knbazaar.com
/www/server/php/82/bin/php artisan migrate --force
/www/server/php/82/bin/php artisan db:seed --force
```

That installs permissions, roles, the three plans, system settings, a **super
admin** and the system email templates. The super admin's address is printed by
the seeder — sign in and change its password immediately.

**Do not run `DemoDataSeeder` on this server.** It creates
`demo@knsoftic.test` with a password published in a public repository. It is
deliberately not part of `db:seed` for that reason.

Then:

```bash
# Required, not optional: the branding logo and user avatars are stored on the
# `public` disk, and without this symlink both return 404 on every page.
/www/server/php/82/bin/php artisan storage:link

/www/server/php/82/bin/php artisan config:cache
/www/server/php/82/bin/php artisan route:cache
/www/server/php/82/bin/php artisan view:cache
```

---

## 9. Permissions

```bash
cd /www/wwwroot/email.knbazaar.com
chown -R www:www .
chmod -R 755 .
chmod -R 775 storage bootstrap/cache
```

Nothing outside `storage` and `bootstrap/cache` needs to be writable.

`storage/app/private` holds uploaded contact lists and mail attachments. It is
below the document root and is served only through a controller that checks the
tenant first — it is never a public path, and must not be made one.

---

## 10. Nginx: rewrite rules and protecting `.env`

**Website → `email.knbazaar.com` → Pseudo-static (URL rewrite) → choose `laravel`** → Save

If the preset is missing, paste this instead:

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

Then **Website → Config file** and add, inside the `server` block:

```nginx
# Contact imports are capped at 50 MB by the application; nginx has to allow it.
client_max_body_size 64M;

# .env, .git and friends must never be readable over HTTP.
location ~ /\.(?!well-known) {
    deny all;
    return 404;
}
```

Save, then check it from outside the server:

```bash
curl -I https://email.knbazaar.com/.env      # must be 404 or 403, never 200
```

---

## 11. SSL

**Website → `email.knbazaar.com` → SSL → Let's Encrypt** → select the domain →
Apply, then turn on **Force HTTPS**.

`SESSION_SECURE_COOKIE=true` in `.env` means the session cookie is only sent
over HTTPS. Set that *after* SSL is working, or you will not be able to sign in.

---

## 12. The scheduler — the application does nothing time-based without it

**aaPanel → Cron → Add task**

| Field | Value |
|---|---|
| Type | Shell Script |
| Name | `knsoftic scheduler` |
| Period | **N minutes → 1 minute** |
| Script | see below |

```bash
cd /www/wwwroot/email.knbazaar.com && /www/server/php/82/bin/php artisan schedule:run >> /dev/null 2>&1
```

That one line drives four things, and each one's absence is silent:

| Command | What breaks without it |
|---|---|
| `campaigns:dispatch-scheduled` | A campaign scheduled for 09:00 never starts. |
| `automations:tick` | Automations enrol people and never advance them — a wait step never ends. |
| `campaigns:decide-ab` | A split test never picks a winner, so the held-back audience is never sent anything at all. |
| `mailboxes:sync` | No mail is ever fetched: the inbox and campaign replies stay permanently empty. |

Nothing logs an error in any of those cases. The campaign simply sits there.

Verify:

```bash
cd /www/wwwroot/email.knbazaar.com
/www/server/php/82/bin/php artisan schedule:list
```

All four should be listed as running every minute.

---

## 13. The queue worker

Campaign sending, contact imports, IMAP fetches and automation ticks are all
queued. Without a worker they accumulate in the `jobs` table and nothing
happens — **with no error anywhere**, which is what makes it hard to diagnose.

**aaPanel → App Store → install "Supervisor Manager" → Add daemon**

| Field | Value |
|---|---|
| Name | `knsoftic-worker` |
| Run user | `www` |
| Run directory | `/www/wwwroot/email.knbazaar.com` |
| Start command | see below |
| Processes | `1` |

```bash
/www/server/php/82/bin/php artisan queue:work --sleep=1 --tries=3 --max-time=3600
```

`--max-time=3600` restarts the worker every hour, and that is deliberate: a
long-lived PHP process holds its code in memory, so a worker started before a
deploy keeps running the old code until it is replaced.

**One process is the right default.** More are safe — every recipient is
claimed with a conditional `UPDATE` before it is sent, so two workers cannot
mail the same person twice — but more workers send faster than your SMTP
provider is likely to allow. Raise the per-SMTP-account limits inside the
application first.

Check it is alive:

```bash
supervisorctl status knsoftic-worker
```

---

## 14. Mail — two separate paths

This is the usual confusion, so it is worth stating plainly.

- **System mail** — password resets, email verification, internal notices —
  uses the `MAIL_*` values in `.env`.
- **Campaign, automation and inbox mail** uses the SMTP accounts each tenant
  adds inside the application, stored encrypted in the database.

Changing `MAIL_MAILER` has **no effect whatsoever** on campaign sending.

For deliverability, `knbazaar.com` (and any other sending domain) needs SPF,
DKIM and DMARC set up with whichever provider is used. That is DNS work on the
sending domain — no application can do it for you.

---

## 15. First sign-in

Open `https://email.knbazaar.com`, sign in as the super admin printed by the
seeder, and immediately:

1. Change the super admin password.
2. **Admin → Settings → Branding** — set the product name, logo and support
   address.
3. **Admin → Plans** — review the three seeded plans and their limits.
4. Create your first account, then add an SMTP account to it and use the
   **Test** button before sending anything real.

---

## 16. Updating later

There is a script for this. It stops at the first failure rather than carrying
on and leaving the site half-updated:

```bash
cd /www/wwwroot/email.knbazaar.com && bash deploy.sh
```

It finds the right PHP binary itself, refuses to run without an `APP_KEY`, puts
the site into maintenance mode and brings it back up even if a step fails, and
stops outright if `public/build/manifest.json` is missing rather than leaving
you with an unstyled site.

The equivalent by hand, if you would rather see each step:

```bash
cd /www/wwwroot/email.knbazaar.com
/www/server/php/82/bin/php artisan down

git pull
/www/server/php/82/bin/php /usr/bin/composer install --no-dev --optimize-autoloader
npm ci && npm run build          # or upload public/build again
/www/server/php/82/bin/php artisan migrate --force

/www/server/php/82/bin/php artisan optimize:clear
/www/server/php/82/bin/php artisan config:cache
/www/server/php/82/bin/php artisan route:cache
/www/server/php/82/bin/php artisan view:cache

chown -R www:www .
supervisorctl restart knsoftic-worker
/www/server/php/82/bin/php artisan up
```

Restarting the worker is not optional — see `--max-time` in §13.

Take a database backup **before** running migrations.

---

## 17. Backups

Three things, and all three are needed together:

1. **The database** — aaPanel → Databases → Backup, and set a schedule.
2. **`/www/wwwroot/email.knbazaar.com/storage/app/private`** — uploaded contact
   lists and mail attachments.
3. **`APP_KEY` from `.env`** — without the exact key that encrypted them, the
   SMTP and IMAP passwords in a restored database are unreadable.

A database dump on its own is **not** a restorable backup of this application.

---

## 18. Final checklist

- [ ] `https://email.knbazaar.com` loads with styling (if unstyled, §6 build step)
- [ ] `curl -I https://email.knbazaar.com/.env` returns 404 or 403
- [ ] Requesting a URL that does not exist shows a plain error page, not a stack
      trace — that confirms `APP_DEBUG=false`
- [ ] `artisan schedule:list` shows all four minute-ticks
- [ ] `supervisorctl status knsoftic-worker` shows RUNNING
- [ ] Super admin password changed
- [ ] An SMTP account added and its **Test** button passes
- [ ] A test campaign sent to yourself, and the **unsubscribe link in it opens
      on `https://email.knbazaar.com`** — this is the single best proof that
      `APP_URL` is right

---

## 19. When something goes wrong

| Symptom | Cause |
|---|---|
| `proc_open() has been disabled` | §3 — disabled functions. |
| Site loads with no styling | §6 — `public/build` missing. Check `public/build/manifest.json` exists. |
| `Vite manifest not found` | Same as above. |
| 500 on every page, blank log | `storage` not writable — §9. |
| `.env` downloads in a browser | Running directory is not `/public` — §1. |
| Campaigns stay "Queued" forever | No queue worker — §13. |
| Scheduled campaign never starts | No cron — §12. |
| Inbox always empty | No cron; or the mailbox's own status is `failed` — open Mailboxes and read the reason on the card. |
| Unsubscribe links point at localhost | `APP_URL` — §7. Fix it, then `php artisan config:cache`. |
| Login redirects back to login | `SESSION_SECURE_COOKIE=true` without working HTTPS — §11. |
| `SQLSTATE[42000] ... key was too long` | Database is not `utf8mb4` — §4. |
| Logo and avatars show as broken images | `artisan storage:link` was not run — §8. |
| `Class "finfo" not found` on import | `fileinfo` extension missing — §2. |

Application logs: `/www/wwwroot/email.knbazaar.com/storage/logs/laravel-*.log`
Nginx logs: aaPanel → Website → `email.knbazaar.com` → Logs
