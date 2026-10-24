<p align="center"><img src="public/brand/mark-128.png" width="72" alt="Marki"></p>

<h1 align="center">Marki</h1>
<p align="center"><strong>Mark that time.</strong></p>

Marki is a self-hosted scheduling app for teams, in the spirit of Calendly: each team member shares a link, guests pick a free time, and the meeting lands in everyone's calendar with a Google Meet link.

It is built to run on **inexpensive shared hosting**: plain PHP and MySQL, no Docker, no Node.js at runtime, no Redis and no long-running workers. One cron job does all the background work.

> Marki is the default identity. The name, logo, colours and favicon are configuration, not code, so you can run it under your own brand (see [Branding](#branding)).

---

## Features

**For guests**
- Public booking pages at `/{username}/{event}` showing free times in the guest's own time zone (detected, with a picker).
- Booking form with custom questions per event type.
- Confirmation email with a calendar invitation (`.ics`) and a Google Meet link.
- Private links to **cancel or reschedule** without an account.
- Reminder emails before the meeting (for example 24 hours and 1 hour).

**For hosts**
- Weekly working hours, date overrides (days off, different hours) and a time zone per host.
- Event types: duration, location (Google Meet, phone, or custom), buffers before and after, minimum notice, booking window, daily limit and reminders.
- **Google Calendar sync**: busy times hide slots, bookings create events with Meet links, and cancellations and reschedules update them.
- Dashboard with upcoming, past and cancelled bookings; host-side cancel and reschedule.
- Profile photo, job title and company shown on booking pages.

**For admins**
- Invite-only team: admins send invitations, and there is no public sign-up.

---

## How it works

**No double bookings.** Every booking or reschedule for a host runs in a database transaction that first locks that host's row (`SELECT … FOR UPDATE`). Two requests for the same host are therefore handled one after the other, and the second re-checks availability after the first has committed. This also catches overlaps a unique index could not, such as different start times or durations, and buffers. A unique index on confirmed bookings remains as a last line of defence. A test races two separate PHP processes against MySQL to prove it.

**Times are always UTC.** Working hours are stored as local wall-clock times plus an IANA time zone and turned into UTC for each specific date. That keeps them correct across daylight-saving changes, which are covered by tests for both the US and EU switch dates.

**Fail closed.** If a host's Google Calendar cannot be read (access revoked, or an API outage), their booking page shows no times rather than risk a double booking. The host gets one email asking them to reconnect.

**Cron-only background work.** `php artisan schedule:run` runs every minute. It queues due reminders and then works the database queue (emails and calendar retries) for up to about 50 seconds.

---

## Tech stack

| Layer | Choice |
|-------|--------|
| Backend | Laravel 13, PHP 8.4+ |
| Database | MySQL 8 or MariaDB 10.4+ (SQLite for most tests) |
| Front-end | Blade, Alpine.js, Tailwind CSS (built ahead of time and committed) |
| Calendar | Google Calendar API via the official `google/apiclient` |
| Email | Any SMTP server (guide covers Hostinger) |
| Queue and cache | Laravel's database drivers |

---

## Local development

### Requirements

- PHP **8.4.1+** with `pdo_mysql`, `mbstring`, `openssl`, `curl`, `fileinfo`, `gd` (with WebP) and `exif`
- Composer 2
- Node.js 20.19+ or 22.12+ (only to rebuild CSS/JS)
- MySQL or MariaDB (SQLite also works for trying it out)

### Set up

```bash
git clone https://github.com/kbbahaPro/marki.git
cd marki

composer install
cp .env.example .env
php artisan key:generate
```

Point `.env` at a database (or keep the default SQLite), then:

```bash
php artisan migrate --seed      # creates the tables and two demo users
php artisan storage:link        # serves uploaded profile photos
npm install && npm run build    # only needed if you change CSS/JS or Blade classes
php artisan serve
```

Open <http://localhost:8000> and sign in as **`admin@example.com` / `password`** (a seeded demo account, for local use only). The demo booking page is <http://localhost:8000/admin-user/intro-call>.

### Background work while developing

Emails, reminders and calendar retries are queued. Run the scheduler in a second terminal; it works the queue every minute, just as cron does in production:

```bash
php artisan schedule:work
```

With the default `MAIL_MAILER=log`, emails are written to `storage/logs/laravel.log` instead of being sent. To see how they look, sign in and open `/_preview/mail/guest`, `/_preview/mail/host`, `/_preview/mail/meeting-link` or `/_preview/mail/ics`. These routes exist only when `APP_ENV=local`.

### Creating accounts

There is no public sign-up. Create the first administrator from the command line, then invite everyone else from **Team**:

```bash
php artisan app:create-admin
```

### Google Calendar (optional)

Create an OAuth client as described in [DEPLOYMENT.md §8](DEPLOYMENT.md#8-google-calendar-oauth), add `http://localhost:8000/settings/calendar/google/callback` as a redirect URI, and put the client ID and secret in `.env`. Everything else works without Google; hosts without a connected calendar simply get no Meet links.

> On Windows, if connecting fails with `cURL error 60`, your PHP has no CA bundle. Point `curl.cainfo` and `openssl.cafile` in `php.ini` at a certificate bundle (Git for Windows ships one at `C:/Program Files/Git/mingw64/etc/ssl/certs/ca-bundle.crt`).

---

## Configuration

Besides the usual Laravel settings (`APP_*`, `DB_*`, `MAIL_*`), these are specific to this app. Full annotated examples are in [`.env.example`](.env.example).

| Variable | Default | Purpose |
|----------|---------|---------|
| `APP_NAME` | `Marki` | Product name in page titles, emails and calendar invites |
| `BRAND_*` | Marki identity | Wordmark, tagline, colours, logo and favicon; see [Branding](#branding) |
| `BRAND_SUPPORT_EMAIL` | (empty) | Support contact |
| `DEFAULT_HOST_TIMEZONE` | `UTC` | Pre-selected time zone for new team members |
| `INVITATION_EXPIRE_DAYS` | `7` | How long team invitations stay valid |
| `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` | (empty) | Google OAuth client for calendar sync |
| `GOOGLE_REDIRECT_URI` | `${APP_URL}/settings/calendar/google/callback` | Must match the URI registered with Google |
| `GOOGLE_FREEBUSY_CACHE_SECONDS` | `180` | How long Google free/busy answers are reused |

Defaults that are not secrets (working hours for new members, reminder choices, event-type defaults, reserved usernames) live in [`config/booking.php`](config/booking.php).

---

## Testing

```bash
php artisan test
```

The suite (190+ tests) covers the slot engine (buffers, notice, DST, time zones), booking validation and rate limits, Google Calendar (the real client against faked HTTP), emails and `.ics` output, cancel/reschedule, reminders, the dashboard and profile photos.

**Concurrency tests** need a real MySQL/MariaDB database, because SQLite has no row locks. They run automatically when one is reachable and are skipped otherwise. The database is **wiped** on every run, so its name must contain `test`:

```bash
# defaults: 127.0.0.1:3306, database calendar_testing, user root, empty password
TEST_MYSQL_DATABASE=calendar_testing TEST_MYSQL_USERNAME=root TEST_MYSQL_PASSWORD= php artisan test --testsuite=Concurrency
```

Code style is enforced with Laravel Pint:

```bash
vendor/bin/pint
```

---

## Project layout

| Path | What lives there |
|------|------------------|
| `app/Services/Scheduling/` | `SlotEngine` (pure, fully unit-tested), `AvailabilityService`, DST-safe `WeeklyAvailability` |
| `app/Services/Booking/BookingService.php` | Booking, cancelling and rescheduling under the per-host lock |
| `app/Services/Calendar/` | `CalendarProvider` interface and the Google implementation |
| `app/Services/Ics/IcsGenerator.php` | RFC 5545 calendar files |
| `app/Mail/`, `resources/views/emails/` | Guest and host emails (HTML and plain text) |
| `app/Listeners/`, `app/Jobs/` | Calendar sync, emails and reminders, wired in `AppServiceProvider` |
| `routes/console.php` | Everything the every-minute cron job runs |
| `resources/views/public/` | Booking, confirmation and manage pages guests see |
| `tests/Concurrency/` | The multi-process double-booking tests |

---

## Deployment

[**DEPLOYMENT.md**](DEPLOYMENT.md) is a step-by-step guide for Hostinger shared hosting. It covers PHP settings, uploading, Composer, pointing the domain at `public/`, the production `.env`, migrations, Google Cloud OAuth (including publishing and verification), SMTP with SPF/DKIM, the cron job, updates, backups and troubleshooting. The same steps apply to most cPanel-style hosts.

## Branding

Out of the box the app is **Marki**: a bookmark (the time slot) holding a calendar dot grid with one dot marked in orange (the chosen time). To run it under your own brand, set these in `.env`; no code changes are needed:

| Variable | Marki default | What it changes |
|----------|---------------|-----------------|
| `APP_NAME` | `Marki` | Page titles, emails, calendar invites |
| `BRAND_WORDMARK` | `marki` | Text beside the mark (empty uses `APP_NAME`) |
| `BRAND_TAGLINE` | `Mark that time.` | Sign-in page and email footer (empty hides it) |
| `BRAND_PRIMARY` | `#1D5FAF` | Buttons, links, selected states |
| `BRAND_MARKER` | `#F4A93C` | The chosen thing: selected date, your time, next meeting |
| `BRAND_LOGO_URL` | (empty) | Your logo image, replacing the mark and wordmark (pages and emails) |
| `BRAND_FAVICON_URL` | (empty) | Your favicon, replacing the built-in ones |

Colours are checked for accessibility at runtime (`App\Support\BrandPalette`). If a colour is too light for white button text or readable links, it is darkened along the same hue (or lightened in dark mode) until it meets WCAG AA. A pale brand colour still works, just a shade deeper.

The built-in favicons and email logo are generated from the mark by `php resources/brand/build-icons.php [primary] [marker]`.

## Design

[**DESIGN.md**](DESIGN.md) describes the design system: semantic colour tokens with automatic dark mode, the single accent colour, the time-slot "pill" motif, typography, shared components and the accessibility checklist. Please read it before changing the UI.

---

## Security

- Google tokens are encrypted at rest with `APP_KEY`. Guests' cancel/reschedule links are 64-character random tokens stored as a SHA-256 hash, plus an encrypted copy used only to put the links in reminder emails.
- Public endpoints are rate-limited and CSRF-protected, and the booking form has a spam honeypot. All user input is escaped in pages, emails and calendar files.
- Uploaded photos are re-encoded server-side, which strips metadata such as GPS location and rejects anything that is not an image.

If you find a vulnerability, please report it privately to the maintainers rather than opening a public issue.

---

## Roadmap

These are intentionally out of scope for now, and the code is structured to allow them:

- Round-robin and collective (multi-host) event types (`event_types.scheduling_type` is reserved for this)
- Outlook / Microsoft 365 calendars (implement `CalendarProvider`)
- Payments for paid meetings
- An embeddable booking widget and a public API
- Per-host branding

## Contributing

Issues and pull requests are welcome. Please:

1. Run `php artisan test` and `vendor/bin/pint` before opening a pull request.
2. Add tests for behaviour changes, especially anything touching availability, time zones or bookings.
3. Follow [DESIGN.md](DESIGN.md) for UI changes, and rebuild assets with `npm run build`, committing `public/build/`.

## License

MIT. See [LICENSE](LICENSE). Copyright (c) 2026 TPLUS INNOVATION.
