# Deploying to Hostinger shared hosting

This guide takes the app from a fresh Hostinger **shared/Premium/Business** plan to a working booking site. Everything runs on plain PHP + MySQL: no Docker, no Node.js, no Redis and no long-running processes. Background work (emails, reminders, calendar retries) runs from **one cron job**.

Throughout, replace:

| Placeholder | Meaning |
|-------------|---------|
| `book.example.com` | the (sub)domain the app will live on |
| `u123456789` | your Hostinger SSH/FTP username (hPanel → **Advanced → SSH Access**) |
| `~/apps/marki` | where the application code lives, **outside** the web root |
| `DOCROOT` | the web root hPanel created for the domain, usually `~/domains/book.example.com/public_html` |

> Time needed: about 45 minutes, plus Google's review time if you want to remove the "unverified app" warning (see §8).

---

## 1. Requirements and hPanel settings

1. **Plan with SSH.** Premium, Business or Cloud plans include SSH. Enable it in hPanel → **Advanced → SSH Access** and note the host, port (usually `65002`) and username.
2. **PHP 8.4.** hPanel → **Websites → Manage → Advanced → PHP Configuration** → choose **8.4** (8.5 also works). The locked dependencies require **PHP ≥ 8.4.1**.
3. **PHP extensions** (same screen, *PHP extensions* tab). These are usually on by default; make sure they are ticked:
   `pdo_mysql`, `mbstring`, `openssl`, `curl`, `fileinfo`, `tokenizer`, `ctype`, `dom`, `xml`, `gd` (with WebP), `exif`.
4. **HTTPS.** hPanel → **Security → SSL** → install the free certificate for `book.example.com`, then enable **Force HTTPS**.

Check the command-line PHP once you are connected over SSH:

```bash
ssh -p 65002 u123456789@YOUR_SERVER_IP
php -v
```

If `php -v` does not report 8.4, the CLI uses a different version from the website. Use the full path instead everywhere below (and in the cron job):

```bash
ls /opt/alt/            # lists the installed versions, e.g. php84
/opt/alt/php84/usr/bin/php -v
```

> Tip: `alias php=/opt/alt/php84/usr/bin/php` in `~/.bashrc` saves typing.

---

## 2. Database

1. hPanel → **Databases → MySQL Databases** → create a database and a user with a **strong password** (Hostinger prefixes both with `u123456789_`).
2. Note the database name, user, password and host. On Hostinger the host is `localhost`.

Hostinger runs MariaDB/MySQL; both are supported (the app uses generated columns and row locks, which both provide).

---

## 3. Upload the code

Keep the application **outside** the web root so `.env`, `vendor/` and `storage/` can never be served. Only `public/` will be exposed (§5).

**Option A, Git (recommended).** Hostinger has `git` over SSH:

```bash
mkdir -p ~/apps
git clone https://github.com/kbbahaPro/marki.git ~/apps/marki
cd ~/apps/marki
```

For a private repository, add a deploy key: generate one with `ssh-keygen -t ed25519` on the server and add `~/.ssh/id_ed25519.pub` to the repository's deploy keys.

**Option B, ZIP upload.** On your computer run `git archive -o marki.zip HEAD`, upload it with hPanel's **File Manager** into `~/apps/`, then extract it to `~/apps/marki`.

The front-end is **pre-built and committed** (`public/build/`), so the server never needs Node.js. If you change any CSS/JS or Blade classes, run `npm run build` locally and commit the result before deploying.

---

## 4. Install PHP dependencies

Composer is pre-installed on Hostinger (`composer` or `composer2`):

```bash
cd ~/apps/marki
composer install --no-dev --optimize-autoloader --no-interaction
```

If the CLI PHP is not 8.4 (see §1), run Composer with the right binary:

```bash
/opt/alt/php84/usr/bin/php $(which composer) install --no-dev --optimize-autoloader --no-interaction
```

The Google API client is trimmed to the Calendar and OAuth2 services during install, which keeps `vendor/` small.

---

## 5. Serve `public/` as the web root

The domain must point at `~/apps/marki/public`, never at the project root.

### Option A: symlink (recommended)

Replace the web root folder that hPanel created with a symlink to `public/`:

```bash
cd ~/domains/book.example.com
mv public_html public_html.bak                # keep the original, just in case
ln -s ~/apps/marki/public public_html
ls -la                                        # public_html -> /home/u123456789/apps/marki/public
```

Visit `https://book.example.com/up`; it should show Laravel's health page. If it does, delete `public_html.bak`.

> For a **subdomain**, hPanel shows its folder when you create it (often `~/domains/example.com/public_html/book`). Replace *that* folder with the symlink in the same way.

### Option B: copy `public/` (if symlinks are not allowed)

Some accounts block symlinked web roots. In that case:

1. Copy the *contents* of `~/apps/marki/public/` (including the hidden `.htaccess`) into `DOCROOT`.
2. Edit `DOCROOT/index.php` so it points at the app and tells Laravel where its public folder is now:

   ```php
   // Maintenance mode
   if (file_exists($maintenance = __DIR__.'/../../apps/marki/storage/framework/maintenance.php')) {
       require $maintenance;
   }

   require __DIR__.'/../../apps/marki/vendor/autoload.php';

   $app = require_once __DIR__.'/../../apps/marki/bootstrap/app.php';
   $app->usePublicPath(__DIR__);

   $app->handleRequest(Request::capture());
   ```

   Adjust the `../../` part so it reaches your home directory from `DOCROOT`.
3. After **every** deploy, copy `public/build/` into `DOCROOT/build/` again.

Option A avoids step 3, which is why it is preferred.

---

## 6. Configure `.env`

```bash
cd ~/apps/marki
cp .env.example .env
php artisan key:generate
chmod 600 .env
nano .env
```

Set at least the following (everything else can stay as in `.env.example`):

```dotenv
APP_NAME=Marki                       # shown in the header, emails and calendar invites
APP_ENV=production
APP_DEBUG=false                      # never true in production: it leaks secrets on errors
APP_URL=https://book.example.com     # exact scheme and host, no trailing slash

LOG_CHANNEL=daily                    # one file per day, so logs don't fill the disk
LOG_DAILY_DAYS=14
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=u123456789_marki
DB_USERNAME=u123456789_marki
DB_PASSWORD="your-database-password"

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true           # HTTPS-only session cookie
QUEUE_CONNECTION=database            # must stay "database": the cron job works the queue
CACHE_STORE=database

# Email: see §9
MAIL_MAILER=smtp
MAIL_SCHEME=smtps
MAIL_HOST=smtp.hostinger.com
MAIL_PORT=465
MAIL_USERNAME=bookings@example.com
MAIL_PASSWORD="mailbox-password"
MAIL_FROM_ADDRESS=bookings@example.com
MAIL_FROM_NAME="${APP_NAME}"

# Google Calendar: see §8
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI="${APP_URL}/settings/calendar/google/callback"

# Branding (optional): the defaults are the Marki identity. To rebrand, see README → Branding.
BRAND_WORDMARK=marki
BRAND_SUPPORT_EMAIL=
DEFAULT_HOST_TIMEZONE=Europe/Paris   # pre-selected for new team members

# CRM integration (optional): see §11
MARKI_INSTALL_ID=
CRM_WEBHOOK_URL=
CRM_WEBHOOK_SECRET=
CRM_API_URL=
CRM_API_KEY=
```

> **Protect `APP_KEY`.** It encrypts stored Google tokens and the guests' private cancel/reschedule links. If it changes, every host must reconnect Google Calendar and existing guest links stop working in reminder emails. Keep a copy of `.env` in your password manager, and never run `key:generate` again on a live site.

The application always stores and computes times in UTC (`config/app.php`); do not add an `APP_TIMEZONE`.

---

## 7. Database tables, storage link, first admin, caches

```bash
cd ~/apps/marki

php artisan migrate --force          # create the tables
php artisan storage:link             # serves profile photos from public/storage
php artisan app:create-admin         # prompts for name, email, username, time zone, password
php artisan optimize                 # cache config, routes, events and views
```

If `storage:link` fails because PHP's `symlink()` is disabled, create the link from the shell instead:

```bash
ln -s ~/apps/marki/storage/app/public ~/apps/marki/public/storage
```

(With option B from §5, link `DOCROOT/storage` to `~/apps/marki/storage/app/public` instead.)

**Permissions.** Hostinger runs PHP as your own user, so the default permissions work. If you see "Permission denied" errors in the log:

```bash
chmod -R u+rwX storage bootstrap/cache
```

Now sign in at `https://book.example.com/login` with the admin you created, and invite the rest of the team from **Team**.

---

## 8. Google Calendar (OAuth)

Each host connects their own Google Calendar from **Calendar** in the app. You set up **one** OAuth client for the whole installation.

### 8.1 Create the OAuth client

1. Go to <https://console.cloud.google.com/> and create a project (for example "Marki").
2. **APIs & Services → Library**: enable **Google Calendar API**.
3. **Google Auth Platform → Branding** (older consoles: *OAuth consent screen*):
   - App name, support email, and your logo if you like.
   - **App domain**: home page `https://book.example.com`, privacy policy URL (required for publishing, see 8.3).
   - **Authorized domains**: `example.com`.
4. **Audience**: user type **External** (unless every host is in one Google Workspace domain; then choose **Internal** and skip 8.3 entirely).
5. **Data Access**: add the scopes the app requests:
   - `openid`, `.../auth/userinfo.email`
   - `.../auth/calendar.events` (create, move and delete the booking events)
   - `.../auth/calendar.freebusy` (read busy times only, not event details)
6. **Clients → Create client → Web application**:
   - **Authorized redirect URIs**: `https://book.example.com/settings/calendar/google/callback`
   - Copy the **Client ID** and **Client secret** into `.env` (`GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`), then run `php artisan optimize` again.

> If a client secret has ever been shared outside your password manager (chat, email, a screenshot), use **Reset secret** in the console and update `.env`.

### 8.2 Testing mode vs. production

While the app is in **Testing** mode only listed *test users* can connect, and **Google expires their connection after 7 days**. The app then marks the calendar as disconnected and emails the host to reconnect. That is fine for a trial, not for daily use.

### 8.3 Publish the app

Under **Audience → Publish app**, move it to **In production**. Calendar scopes are *sensitive* (not *restricted*), so:

- **Before verification:** hosts see an "unverified app" screen once when connecting (**Advanced → Go to …**), and the app is limited to 100 users. Connections no longer expire after 7 days.
- **To remove the warning**, submit for verification (free). Google asks for:
  - a public home page on your domain and a **privacy policy** explaining that the app reads free/busy times and creates events for bookings,
  - domain ownership verified in Google Search Console,
  - a short screen recording of the connect flow showing the consent screen and how the data is used.

  Review usually takes a few days to a few weeks.

---

## 9. Email (Hostinger SMTP)

1. hPanel → **Emails** → create a mailbox such as `bookings@example.com`.
2. Use the `MAIL_*` values from §6 (`smtp.hostinger.com`, port `465`, `smtps`, the full mailbox address as username).
3. Check deliverability: hPanel → **Emails → (your domain) → DNS records**. If the domain uses Hostinger's nameservers, SPF, DKIM and DMARC can be added in one click; if the DNS lives elsewhere, copy those records to your DNS provider. Without them, booking emails tend to land in spam.
4. Send a test message (this bypasses the queue):

   ```bash
   php artisan tinker --execute="Mail::raw('SMTP works.', fn (\$m) => \$m->to('you@example.com')->subject('Marki SMTP test'));"
   ```

Booking emails themselves are **queued** and sent by the cron job (§10), normally within a minute.

---

## 10. Cron: the background worker

One cron entry drives everything: due reminders, the email/calendar queue and housekeeping (see `routes/console.php`).

hPanel → **Advanced → Cron Jobs** → **Custom** command, run **every minute** (`* * * * *`):

```bash
/opt/alt/php84/usr/bin/php /home/u123456789/apps/marki/artisan schedule:run >> /dev/null 2>&1
```

Use the PHP path that reports 8.4 (§1) and your real home directory (`echo $HOME` over SSH).

Every minute the scheduler:

- queues reminder emails that are due (each one is claimed atomically, so it is never sent twice),
- every 5 minutes, marks meetings that ended over an hour ago as completed,
- works the database queue for up to ~50 seconds (confirmations, cancellations, calendar retries, CRM webhooks), and never runs two workers at once,
- once a day, prunes failed jobs older than 30 days.

**Check that it runs.** After a couple of minutes:

```bash
php artisan schedule:list            # shows the tasks and their next run
php artisan queue:failed             # should be empty
tail -n 50 storage/logs/laravel-$(date +%F).log
```

A quick end-to-end check is to book a test meeting on your own page: the confirmation email should arrive within about a minute.

---

## 11. Connect a CRM (optional)

Marki can tell a CRM about every booking through signed webhooks, and show hosts whether a guest is already a customer. It follows the CRM webhook contract v1: requests are `POST`ed as JSON with `X-Marki-Event`, `X-Marki-Delivery`, `X-Marki-Timestamp` and `X-Marki-Signature: v1=<hex HMAC-SHA256 of "{timestamp}.{body}">`. Sample bodies are in [`tests/Fixtures/crm-webhooks/`](tests/Fixtures/crm-webhooks/). Everything stays off until the keys below are set.

**Events.** `booking.created`, `booking.rescheduled` (with `previous_booking_id`), `booking.cancelled`, `booking.no_show` (a host clicks **Mark as no-show** on the booking) and `booking.completed` (a host clicks **Mark as completed**, or automatically `BOOKING_AUTO_COMPLETE_AFTER_MINUTES` after the meeting ends; default 60). Each event carries the whole booking; the guest's private manage link is never sent.

**1. Install ID.** Generate one and keep it for the life of this install (changing it disconnects you from the CRM):

```bash
php artisan crm:install-id      # prints MARKI_INSTALL_ID=01J…; it does not edit .env
```

**2. In the CRM**, open **Settings → Calendar integration**, enter that install ID and save. The CRM shows the webhook secret **once**; copy it. Optionally, in **Settings → API keys**, create a key with only the `lookup` ability.

**3. In `.env`:**

```dotenv
MARKI_INSTALL_ID=01J…                                                    # from step 1
CRM_WEBHOOK_URL=https://crm.example.com/api/v1/integrations/calendar/webhook
CRM_WEBHOOK_SECRET="secret-shown-once-by-the-crm"
# Optional "existing customer" badge on hosts' booking pages:
CRM_API_URL=https://crm.example.com
CRM_API_KEY="crm_…"                                                      # a key with only the lookup ability
```

Then `php artisan optimize:clear && php artisan optimize`.

**How delivery works.** Webhooks go through the database queue, so the cron job (§10) sends them within about a minute. Each attempt is signed with a fresh timestamp and times out after 10 seconds. Failures are retried after 1 min, 5 min, 30 min, 2 h and 6 h; a request the CRM rejects as invalid (`400 invalid_payload` or `401`) is not retried and is logged as an error. Events that gave up are listed by `php artisan queue:failed` and can be resent with `php artisan queue:retry all`. The CRM ignores repeats, so retrying is always safe.

**Clocks.** The CRM rejects requests whose timestamp is more than 5 minutes off its own clock. Hostinger servers keep NTP time; if you host the CRM elsewhere, keep it on NTP too.

**Secret rotation.** **Rotate secret** in the CRM keeps the old secret valid for 24 hours: put the new one in `CRM_WEBHOOK_SECRET` and run `php artisan optimize` within that window.

**The lookup** is called when a host opens a booking, with a 3-second timeout. Answers are cached for 10 minutes (`CRM_LOOKUP_CACHE_SECONDS`); if the CRM is unreachable the badge is simply not shown.

**Check it.** Book a test meeting, wait a minute, and open **Settings → Calendar integration → Recent webhook events** in the CRM: the event should show as processed. If not, check `php artisan queue:failed` and `storage/logs/`.

> Bookings made before you connected are not sent. Only changes from then on are.

---

## 12. Go-live checklist

- [ ] `https://book.example.com/up` returns 200 and HTTP redirects to HTTPS.
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, and `.env` is not reachable (`https://book.example.com/.env` → 404).
- [ ] Admin can sign in; invitations arrive by email.
- [ ] A host connects Google Calendar; busy times disappear from their booking page.
- [ ] A test booking creates a Google event with a Meet link and two emails (guest and host) with the invite.
- [ ] Cancelling from the guest's email link removes the Google event and sends cancellation emails.
- [ ] If a CRM is connected (§11), the test booking shows as a processed event in the CRM.
- [ ] `php artisan queue:failed` is empty after the tests.
- [ ] Hostinger backups are on (hPanel → **Files → Backups**), and `.env` (with `APP_KEY`) is saved in a password manager.

---

## 13. Updating to a new version

```bash
cd ~/apps/marki
php artisan down --retry=60          # optional: show a maintenance page during the update
git pull                             # or upload and extract the new ZIP over the old files
composer install --no-dev --optimize-autoloader --no-interaction
php artisan migrate --force
php artisan optimize:clear
php artisan optimize
php artisan up
```

The queue worker restarts by itself every minute, so it picks up new code automatically. With option B from §5, also copy the new `public/build/` into `DOCROOT/build/`.

---

## 14. Backups

What must be backed up:

| What | Where | Why |
|------|-------|-----|
| Database | MySQL | bookings, users, settings, encrypted Google tokens |
| `.env` | `~/apps/marki/.env` | **`APP_KEY`** (needed to decrypt the above) and credentials |
| Profile photos | `~/apps/marki/storage/app/public/avatars` | uploaded images |

Hostinger's automatic backups cover files and databases; also download a database export from hPanel → **Databases → phpMyAdmin → Export** before major updates.

---

## 15. Troubleshooting

| Symptom | Likely cause and fix |
|---------|----------------------|
| **500 error, blank page** | Read `storage/logs/laravel-YYYY-MM-DD.log`. Common: wrong DB credentials, missing `APP_KEY`, or PHP < 8.4.1 (the platform check message says "Composer detected issues in your platform"). |
| **403 Forbidden / directory listing** | The domain points at the project root instead of `public/` (see §5). |
| **Page shows raw PHP or downloads a file** | PHP is not enabled for that folder; check the PHP version in hPanel for this website. |
| **Unstyled pages** | `public/build/` missing or stale: rebuild locally (`npm run build`), commit, deploy. With option B, copy `build/` into `DOCROOT`. |
| **`.env` changes have no effect** | Config is cached: `php artisan optimize:clear && php artisan optimize`. |
| **Emails never arrive** | Cron not running (`php artisan schedule:list`, check the cron command's PHP path), or SMTP settings wrong (run the tinker test in §9). Check `php artisan queue:failed`. |
| **Emails go to spam** | Add SPF, DKIM and DMARC records (§9), and send from a mailbox on the same domain as `MAIL_FROM_ADDRESS`. |
| **Google: `redirect_uri_mismatch`** | The redirect URI in the console must match `APP_URL` exactly: `https`, same host, no trailing slash. |
| **Google: "Access blocked" / not a test user** | App is in Testing mode: add the account under **Audience → Test users**, or publish it (§8.3). |
| **Host's calendar shows "Disconnected"** | Access was revoked or expired (Testing mode expires after 7 days). The host clicks **Reconnect** on the Calendar page. Booking pages show no times until then, by design. |
| **Booking page shows no times** | Check the host has weekly hours, the event type is on, and Google is connected and active (see the dashboard warnings). |
| **Links in emails use `http://` or the wrong host** | `APP_URL` is wrong. If you enabled a CDN/proxy in front of the site, also trust it in `bootstrap/app.php`: `$middleware->trustProxies(at: '*');` |
| **Profile photos 404** | Run `php artisan storage:link` (§7). |
| **CRM shows no booking events** | Check all three of `MARKI_INSTALL_ID`, `CRM_WEBHOOK_URL` and `CRM_WEBHOOK_SECRET` are set (a partial setup sends nothing and logs a warning), then `php artisan queue:failed` and the log. `invalid_signature` means the secret or install ID doesn't match the CRM's; `stale_timestamp` means a server clock is off. |
| **Upload rejected for large photos** | The app accepts up to 4 MB; also check `upload_max_filesize`/`post_max_size` in hPanel's PHP configuration (≥ 8M). |
