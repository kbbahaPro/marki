# Security policy

## Reporting a vulnerability

Please **do not open a public issue** for security problems.

Report them privately through GitHub instead:
[**Report a vulnerability**](https://github.com/kbbahaPro/marki/security/advisories/new)
(the repository's Security tab → "Report a vulnerability").

Include what you can of:

- what an attacker can do, and who is affected (guests, hosts, team admins, the server);
- the steps or a request that reproduces it;
- the commit or version you tested, and your PHP and database versions.

You can expect an acknowledgement within a few days. Once a fix is ready it is
released, and the advisory is published with credit to you unless you would
rather stay anonymous.

## Supported versions

Marki has no tagged releases yet. Fixes go to the `main` branch; please test
against it before reporting.

## Scope

Things we especially want to hear about:

- seeing or changing another person's bookings, event types, availability or
  calendar connection, or reaching a team's bookings without being its admin;
- guessing or reusing a guest's manage link (cancel/reschedule token);
- leaking Google OAuth tokens or other values from `.env`;
- double-booking a host despite the booking lock;
- injection (SQL, HTML/JS, email headers, `.ics` content) through booking forms
  or event type fields.

Out of scope: problems that need an already compromised server or `.env`,
missing rate limits beyond those the app sets, and reports from automated
scanners without a working proof of concept.

## For people running Marki

Keep `APP_DEBUG=false` and `APP_ENV=production` in production, serve it over
HTTPS only, keep `.env` outside the web root (see [DEPLOYMENT.md](DEPLOYMENT.md)),
and rotate the Google client secret if it is ever exposed.
