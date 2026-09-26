# Changelog

All notable changes. Versions follow [semantic versioning](https://semver.org/).
To update an installation, see *Updating* in the README: replace the files,
keep `notifyme/data/`; database changes are applied automatically.

## [1.1.0] — 2026-09-26

### Added
- **Bounce handling**: permanent SMTP refusals at send time and bounce
  e-mails read from your mailbox over IMAP (built-in client, no PHP `imap`
  extension needed) deactivate dead addresses. Standard delivery reports and
  cPanel/Exim bounces are understood; delay warnings and out-of-office replies
  are ignored; other mail is left untouched. New admin page *Bounces*.
- **Scheduled sending**: a message can be scheduled for a date and time; its
  recipients are chosen when it starts. Change the date, send now or cancel
  from the campaign page.
- Optional *Return-Path* (address that receives the bounces).
- Release ZIP attached to each GitHub release; automated tests on PHP 7.4 to
  8.4 for every change.

### Fixed
- Several installations on the **same domain** (e.g. `/list-a/` and
  `/list-b/`) shared one PHP session: logging in to one admin could open the
  other, and a subscriber's self-service page could show another instance's
  subscriber. The session cookie is now named per installation and limited to
  its folder. Existing logins are asked to sign in again once after updating.
- Automatic cron setup: each installation now marks its own lines, so
  installing or removing one never touches another's.
- Closing an SMTP connection the server had already dropped could end the
  request with a fatal error on PHP 8.

### Upgrading from 1.0.0
Upload the new files over the old ones (keep `notifyme/data/`). The database
is upgraded automatically on the first page load.

## [1.0.0] — 2026-09-26

First release: signup form with consent trail, single/double opt-in,
magic-link login for admin and subscribers, self-service, one-click
unsubscribe, campaigns sent one by one with rate limits and quota detection,
RSS/Atom feed notifications, cron setup helper, CSV import/export, French and
English interface.
