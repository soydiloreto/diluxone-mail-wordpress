# Testing & quality

What every quality gate enforces, why, and how to run each one locally.

## Quality stack at a glance

| Layer | Tool | Catches | CI workflow | Make target |
| --- | --- | --- | --- | --- |
| Unit tests | PHPUnit, WordPress stubs | Logic regressions: SPF lookup counting, DKIM/DMARC parsing, provider profiles, address parsing, environment/network precedence, password redaction. | `tests-unit.yml` | `make test-unit` |
| Coverage gate | PHPUnit + pcov | The unit suite covering less than it did. A ratchet, not a target. | `tests-unit.yml` (coverage job) | `make coverage` |
| Integration tests | PHPUnit inside wp-env | Behaviour against a real WordPress + MySQL: the log tables, one row per recipient, interception, suppression, body storage, privacy export/erase, purge. | `tests-integration.yml` | `make test-integration` |
| End-to-end | Bash + Mailpit + WP-CLI | A real `wp_mail()` through the plugin into a real mailbox, verified through Mailpit's API; the failure path with the real SMTP error; the per-user history; the CLI; the DNS diagnosis against a live domain. | `tests-e2e.yml` | `make test-e2e` |
| Multisite | PHPUnit + WP-CLI on the tests site converted to a network | Shared tables with `site_id`, the per-person history across sites, network-over-site precedence with real options. | `tests-e2e.yml` | `make test-multisite` |
| Coding style | PHP_CodeSniffer + WordPress Coding Standards | Style, naming, escaping, sanitisation, prepared statements, nonces. | `tests-style.yml` | `make lint` |
| Static analysis | PHPStan level 8 + szepeviktor/phpstan-wordpress | Type safety, unreachable code, undefined functions. **No baseline.** | `tests-stan.yml` | `make stan` |
| Security taint analysis | Psalm + humanmade/psalm-plugin-wordpress (taint-only mode) | User input reaching a dangerous sink without an escaper. | `psalm-taint.yml` | `make psalm` |
| i18n | `wp i18n make-pot` | Missing translator comments, dynamic text domains, concatenated strings. | `i18n-validate.yml` | `make i18n` |
| Plugin Check (wp.org) | wordpress/plugin-check | What the wp.org plugin team checks at review time. | `pr-checks.yml` | — |
| CodeQL | CodeQL (JS) | Common JS vulnerability patterns, once there is JavaScript to scan. | `codeql.yml` | — |

Every layer must pass before a PR can land on `main`.

## The four test suites, and why there are four

Each suite catches a class of bug the others cannot.

**Unit** (`tests/Unit/`) runs in plain PHP against stubs in `tests/stubs/wordpress-stubs.php`. It is where the logic lives: the SPF tree walker, the DKIM key-size estimate, the DMARC parser, the precedence chain. DNS is never queried in a unit test — it is *seeded*: each answer is written into the stubbed site-transient cache under the key the plugin would use, then the real analysis function runs. `tests/Unit/DiluxOneMail/DnsTestCase.php` has the helper.

**Integration** (`tests/Integration/`) runs inside the wp-env *tests* container against a real WordPress and database. No SMTP server: sends are short-circuited on `pre_wp_mail` the way an API-based mail plugin would, which exercises that path too. Every test starts with all plugin options deleted — site and network — so nothing leaks between tests.

**End-to-end** (`tests/e2e/run.sh`) is the only suite that walks the whole chain — config → `phpmailer_init` → SMTP → mailbox. It starts Mailpit on the wp-env Docker network, points the plugin at it with the local profile, and then, in order: sends a real `wp_mail()` with a Cc and checks through Mailpit's HTTP API that it arrived with the right From, To, Cc and body; checks the log has one row per recipient in `sent` and that the message's `Message-ID` header is the log's id; checks body and SMTP transcript were stored; checks a user's profile finds their mail; runs `wp diluxone-mail test`; breaks the port on purpose and checks the `failed` row carries the real SMTP error; checks `status`; resends from the log and finds the copy in the mailbox with its `X-DiluxOne-Mail-Resend-Of` header; sends with a Bcc; drops in a fake mail plugin (an mu-plugin on `phpmailer_init`) and checks observer mode detects it, stays out of the way, and still logs the send; drops in a fake `pre_wp_mail` interceptor with a closure and checks the log names it, then turns on the detach option and checks mail flows again; exports and erases a person's data; runs the purge; and diagnoses a live domain. It found a real bug on its first run — the From override was applied too late for sites on `localhost` — that no other suite could have seen.

**Multisite** (`tests/e2e/multisite.sh` + `tests/Multisite/`) converts the tests site into a network, creates a second site and network-activates the plugin, then runs a PHPUnit suite and a few WP-CLI checks from the second site. It goes last because converting the site is destructive for the suites before it.

```bash
make env-up              # once
make test-unit
make test-integration
make test-e2e
make test-multisite      # last: converts the tests site to a network
make test-all            # all four, in that order
```

## Coverage

```bash
make coverage            # unit-suite line coverage + threshold
```

Neither `composer:2` nor `php:8.3-cli` ship a coverage driver, so `make coverage` builds a small image with pcov once (`tools/coverage.Dockerfile`) and caches it. CI uses `setup-php` with `coverage: pcov`.

The threshold (`COVERAGE_MIN` in the Makefile, the same number in `tests-unit.yml`) is a **ratchet**: it sits just under what the unit suite actually covers and is only ever raised. It measures line coverage of `includes/` by the unit suite alone; it sits above 95 % because the stubs in `tests/stubs/` are good enough to run the admin screens, the `admin_post` handlers, the list table, WP-CLI and the PHPMailer configuration without WordPress: a minimal hook system with the real shape of `$wp_filter`, a recording `$wpdb`, a `wp_mail()` that replays WordPress's hook sequence, `wp_safe_redirect()`/`wp_die()` that throw instead of exiting, and small `WP_List_Table`, `WP_CLI` and `PHPMailer` classes. What is left uncovered is glue that only means something with WordPress running (`exit`, `dbDelta`, `debug_backtrace` frames), and the integration and E2E suites exercise that.

## PHPCS / WordPress Coding Standards

Configuration: [`phpcs.xml.dist`](../phpcs.xml.dist).

```bash
make lint           # report
make lint-fix       # auto-fix what PHPCBF can
```

Two project-specific points:

- Every `admin_post` handler calls `check_admin_referer()` on its first line, in plain sight, and only then `diluxone_mail_settings_authorize()` for the capability. The wp.org Plugin Check runs its own PHPCS without this repo's configuration and does not follow calls into helpers, so the nonce check has to be where the sniff can see it.
- Table names go through the `%i` identifier placeholder of `$wpdb->prepare()` (WordPress 6.2+), so no query interpolates a table name. `includes/log.php` still disables the direct-database-query sniffs for the whole file, in the file docblock, with the reason; every `phpcs:enable` below names the sniff it re-enables, because a bare `phpcs:enable` would re-enable those too.

## PHPStan

```bash
make stan
```

Level 8, no baseline. The WordPress extension teaches PHPStan the core API. `phpstan-bootstrap.php` defines the plugin constants the analysis would otherwise not see.

## Psalm taint analysis

```bash
make psalm
```

Taint-only mode. Every value from `$_GET` / `$_POST` must pass through an escaper or sanitiser before reaching an output or a query. The templates in `templates/` are the usual place a finding shows up.

## i18n

```bash
make i18n           # languages/diluxone-mail.pot + compile languages/*.po to .mo
```

Strings in code are English; translations ship with the plugin in `languages/`. The Spanish (`es_AR`) translation is maintained by hand and is what makes the DNS diagnosis read as prose in Spanish. CI fails on any warning from `make-pot` — a translator comment separated from its `sprintf()` by a blank line is invisible to gettext, and that is the most common one.

## Running everything at once

```bash
make check     # lint + stan + psalm + unit tests + coverage gate
make test-all  # the four test suites against wp-env
make release   # make check + version-alignment dry-run
```
