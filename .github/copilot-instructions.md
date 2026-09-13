# Copilot custom instructions — DiluxOne Mail

This file is the project-wide context for GitHub Copilot (Code Review, Chat,
Coding Agent, and any other surface that reads
`.github/copilot-instructions.md`). It encodes domain knowledge, conventions
and review priorities specific to this codebase. It is **not** generic
WordPress advice — it reflects how this plugin is actually written.

When you review a pull request, follow these rules. When in doubt, prefer the
project's existing patterns over textbook WordPress patterns.

---

## What this repo is

A WordPress plugin that fixes a site's outgoing email: it connects the site to
an SMTP provider, logs every message sent, and reads the domain's DNS to
explain why mail is or is not being delivered.

**The distinguishing decision** is that SMTP transport and a basic send log are
treated as the floor, not the product. That market is saturated — WP Mail SMTP
alone has four million installs. The two features that justify this plugin's
existence are:

1. **A mail history hanging off each person's user profile.** Every competitor
   shows one global list. None lets you open a user and see what was sent to
   *that person*.
2. **DNS diagnostics** — SPF lookup counting, DKIM selector probing, DMARC
   policy explanation, in prose.

A pull request that sacrifices either of those to improve transport is going
the wrong way. A pull request that adds an HTTP API driver per provider is also
going the wrong way: SMTP is one code path that reaches every provider, and
per-provider API clients multiply the code for nothing.

**Explicitly out of scope:** building our own sending service. We connect to
the provider the user already has.

The second decision worth knowing: **the plugin does not fight for control of
the mail.** If another plugin is already handling delivery, this one goes into
observer mode — logs and diagnoses, does not touch sending — and says so, with
a button to take over. Being useful on day one without changing anything that
already works is the distribution strategy, not a courtesy.

---

## Architecture quick-reference

Procedural, no classes, no namespace. Every file in `includes/` is
independent and only registers hooks; they are loaded in alphabetical order
by a `glob()` in the main plugin file, so **nothing may depend on load
order** — if a file needs another to have run, that is a hook, not an
ordering assumption.

| Area | Files |
|---|---|
| Options and their defaults | `options.php` |
| Environment/constant/option precedence | `config.php` |
| Provider profiles (hosts, ports, DKIM selectors) | `providers.php` |
| PHPMailer configuration | `mailer.php` |
| Conflict detection, observer mode | `observer.php`, `pre-wp-mail.php` |
| Log schema, writes, reads, purge | `log.php`, `log-hooks.php` |
| The per-person history on a user profile | `user-profile.php` |
| DNS resolution and the DoH fallback | `dns.php` |
| SPF / DKIM / DMARC analysis | `dns-spf.php`, `dns-dkim.php`, `dns-dmarc.php` |
| Admin screens | `admin*.php` |
| Privacy exporters and erasers | `privacy.php` |
| WP-CLI | `cli.php` |
| Schema version and `dbDelta()` | `log.php` (`DILUXONE_MAIL_DB_VERSION`) |

---

## Hard rules — please flag any violation

### Secrets

This is the area where a defect is worst, because the plugin holds a live SMTP
credential.

- **The password never goes back to the browser.** The field renders empty with
  a placeholder; an empty submission keeps the stored value. A PR that
  populates the password input with the stored password is a defect.
- **The password is redacted everywhere it could surface** — the log, the
  status screen, the `SMTPDebug` dump, any export. Use
  `diluxone_mail_redact()`, which replaces both the literal value **and its
  base64 form**: the SMTP dialogue transmits credentials base64-encoded, so
  redacting only the literal leaves the whole credential in the dump.
- **A value that comes from a constant or an environment variable is never
  written to the database.** `diluxone_mail_save_options()` skips those keys.
  Removing that check puts production credentials into a table that any
  database dump carries off the server.

### Security

- `current_user_can( 'manage_options' )` plus a nonce on every admin action.
  The mail history of a given user is visible to whoever can edit that user —
  not to every administrator by a separate rule.
- **All `$_POST` / `$_GET` input** must be unslashed and sanitized:
  `sanitize_text_field( wp_unslash( $_POST['x'] ?? '' ) )`, `sanitize_key`,
  `sanitize_email`. Raw superglobals are a defect.
- **All output** must be escaped: `esc_html`, `esc_attr`, `esc_url`,
  `esc_textarea`. A template that echoes a variable unescaped is a defect.
- **All SQL** must use `$wpdb->prepare()`, always, including the log queries.
- **Never `remove_all_filters( 'pre_wp_mail' )`.** Detaching the plugin that
  intercepts sending is opt-in, off by default, and removes exactly one
  identified callback. Nuking the hook leaves a site with no mail at all.

### Privacy

- **The message body is not stored by default.** It is personal data, and it is
  what turns a technical log into a legal problem. Changing that default is a
  defect, not a convenience.
- The log is personal data: the WordPress exporters and erasers must keep
  covering it. A new column holding anything about a person has to reach both.

### WordPress conventions

- All user-facing strings go through translation functions with the text
  domain `diluxone-mail`. Translations ship with the plugin.
- HTTP calls use `wp_remote_*` with an explicit `timeout`. Never raw cURL. This
  includes the DNS-over-HTTPS fallback.
- `dns_get_record()` is disabled on a large share of shared hosting. Any new
  DNS lookup must go through `dns.php`, which falls back to DoH. A direct call
  to `dns_get_record()` in a new file is a defect.
- Every `.php` file starts with `defined( 'ABSPATH' ) || exit;`.
- **PHP 8.1 minimum**, WordPress 6.2 minimum (the log uses the `%i` identifier placeholder of `$wpdb->prepare()`).

### Data

- The log table holds **one row per recipient**, not per message. That is what
  keeps the per-person query on an index and what will let bounce webhooks mark
  the right row. A PR that collapses it to one row per message with the
  recipients in a serialized column is undoing the design.
- Schema changes require bumping `DILUXONE_MAIL_DB_VERSION` — an update by FTP
  or git never fires the activation hook.
- `dbDelta()` is silently picky about SQL formatting. Wrong formatting does not
  error; it recreates the table on every page load.

---

## Style — please DO NOT comment on

Save your review tokens for things that matter.

- **Yoda conditions ARE used** in comparisons against literals
  (`'' === $value`), following WPCS. Don't suggest the swap.
- **Spanish comments and English code.** Identifiers, hooks and strings are in
  English; the comments explaining *why* are in Spanish, and that is
  deliberate — the maintainer reads them. Don't suggest translating them.
- **Comments explain decisions, not mechanics.** A comment that says what the
  next line does is noise and gets removed; one that says why the obvious
  alternative was rejected stays. Don't ask for more of the first kind.
- **Procedural code with a `diluxone_mail_` prefix** is the convention. Don't
  suggest wrapping it in classes.

---

## What Copilot should actively look for

- **A credential reaching the browser, the database or a log** in any form.
- **A new place that prints the SMTP dialogue** without `diluxone_mail_redact()`.
- **Missing escaping on output**, especially in `templates/`.
- **Missing unslash + sanitize on input**, especially in new admin handlers.
- **A provider profile with a host, port or selector taken from memory rather
  than the provider's current documentation.** A wrong host is a plugin that
  does not send mail.
- **A new DNS lookup that bypasses the DoH fallback.**
- **Anything that makes the plugin take over sending when observer mode is
  active**, or that skips the conflict check.
- **New strings not wrapped in a translation function.**
- **`$wpdb` queries inside loops** — suggest batching. The log is the one table
  here that gets big.

---

## When you're not sure

Open a question in the review. Don't guess. Reference the existing pattern by
file and function. The maintainer
([Pablo Di Loreto](https://pablodiloreto.com/)) is the final reviewer of every
merge.

---

## Updates to this file

When the architecture, conventions or rules change, update this file in the
same pull request. A stale `copilot-instructions.md` is worse than none: it
makes Copilot give confidently-wrong reviews.
