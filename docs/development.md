# Development

How to run the plugin from source, what tools you need, and the day-to-day commands you'll use.

For contribution rules (branch naming, commit conventions, PR workflow), see [`CONTRIBUTING.md`](../CONTRIBUTING.md). For the test-and-quality stack, see [`testing-and-quality.md`](testing-and-quality.md). For releases, see [`release.md`](release.md).

## What you need

| Tool | Why |
| --- | --- |
| **Docker** | Runs `wp-env` (the local WordPress stack), Mailpit for the end-to-end suite, and the PHP toolchain (PHPCS, PHPStan, Psalm, PHPUnit, WP-CLI) without installing matching PHP extensions on the host. |
| **Node.js 20+** and **npm** | Boots `wp-env`. |
| **`make`** | Wraps every task behind a short target. `make help` lists them. |
| **`gh`** (GitHub CLI) | Optional — issues, PRs, CI logs. |

You do **not** need PHP on the host. Every PHP-based command runs inside an official Docker image, mounted as your host UID so `vendor/` is never root-owned. With a full local PHP CLI you can opt out via `make DOCKER=0 ...`.

## First run

```bash
git clone https://github.com/soydiloreto/diluxone-mail-wordpress.git
cd diluxone-mail-wordpress
make install     # composer install — dev tooling into vendor/
npm install      # wp-env
make env-up      # WordPress at http://localhost:8888, tests site at :8889
```

Log in with `admin` / `password`. The repository is mounted at `wp-content/plugins/diluxone-mail/` (the mapping in `.wp-env.json` — the repo folder is named `diluxone-mail-wordpress`, the plugin is `diluxone-mail`). Activate it from **Plugins**.

If ports 8888/8889 are taken on your machine, create `.wp-env.override.json` (ignored by git):

```json
{ "port": 8890, "testsPort": 8891 }
```

## Configuring the transport locally

The fastest way is the environment, which is also how production is meant to be configured. wp-env accepts PHP constants in `.wp-env.override.json`:

```json
{
  "config": {
    "DILUXONE_MAIL_PROVIDER": "mailpit",
    "DILUXONE_MAIL_HOST": "host.docker.internal",
    "DILUXONE_MAIL_PORT": "1025",
    "DILUXONE_MAIL_ENCRYPTION": "none",
    "DILUXONE_MAIL_FROM": "dev@example.test"
  }
}
```

Those show up read-only in the settings screen with the note "defined by the environment — PHP constant DILUXONE_MAIL_HOST". Or use the settings screen: pick the **Mailpit** profile, click *Use this profile*, save.

For a real provider, keep the credential out of the repository: a `.env`-style file outside the tree, exported into the shell that runs `wp-env`, or PHP constants in the override file above (which is git-ignored).

## Day-to-day commands

| Command | What it does |
| --- | --- |
| `make help` | List every target (the default). |
| `make install` | `composer install`. |
| `make env-up` / `make env-down` / `make env-clean` | Start / stop / destroy wp-env. |
| `make lint` / `make lint-fix` | PHPCS with WordPress Coding Standards / PHPCBF. |
| `make stan` | PHPStan level 8, no baseline. |
| `make psalm` | Psalm taint analysis. |
| `make i18n` | Regenerate `languages/diluxone-mail.pot` and compile the `.po` files to `.mo`. |
| `make test-unit` | Unit suite (no WordPress). |
| `make test-integration` | Integration suite inside the wp-env tests container. |
| `make test-e2e` | End-to-end: a real `wp_mail()` into Mailpit, verified through its API. |
| `make test-multisite` | Converts the tests site to a network and runs the multisite suite. Run it last. |
| `make test-all` | The four suites, in order. |
| `make coverage` | Unit-suite line coverage with the ratchet threshold. |
| `make check` | Every gate CI runs on a plain push: lint + stan + psalm + unit tests + coverage. |
| `make deploy-test` | Copy what ships into a real site (`SITE=`, defaults to `~/repos/cst-website`) for a manual smoke test. |
| `make release` | `make check` plus the version-alignment dry-run. |
| `make clean` | Wipe caches and build artefacts. |

## WP-CLI

```bash
npx wp-env run cli wp diluxone-mail status
npx wp-env run cli wp diluxone-mail test you@example.test
npx wp-env run cli wp diluxone-mail dns example.com --fresh
npx wp-env run cli wp diluxone-mail log list --email=you@example.test
```

## Where the plugin can be extended from outside

The plugin is one piece and nothing extends it today. It is built so that it
could be split without a refactor: every file in `includes/` only registers
hooks and is loaded by `glob()` in alphabetical order, so a file being here or
in a separate plugin is the same thing to everything else. Three lists the
plugin draws its own screens from are registries rather than literals, which is
the part that does not come for free:

| Filter | What it decides |
|---|---|
| `diluxone_mail_screens` | The screens under the menu, in order, each with the function that renders it. |
| `diluxone_mail_settings_tabs` | The row of tabs: the label, the position in the chain (`step`/`needs`), the settings groups the tab's form may save, and which screen it belongs to. |
| `diluxone_mail_providers` | The SMTP profiles in the dropdown. `diluxone_mail_api_providers` is the same for the ones reachable over HTTPS. |

All three validate what comes back, because anything arriving through a filter
was written by somebody else and a half-written entry fails far away from
whoever wrote it — on somebody's dashboard, on the next click. A screen whose
callback does not exist is dropped rather than put in the menu; a profile
missing a key never reaches the dropdown; a tab may only name groups of
settings the plugin itself declares, since the save routine writes whatever the
current tab's groups name. `custom` can be edited but not removed: it is what
an unknown provider key falls back to.

The delivery path has its own: `diluxone_mail_config`, `diluxone_mail_option`,
`diluxone_mail_should_send`, `diluxone_mail_atts`,
`diluxone_mail_connection_mailer`, and the `diluxone_mail_failover` action,
which fires with the provider about to be tried and the one that refused.

## Docker image overrides

| Variable | Default | Used by |
| --- | --- | --- |
| `COMPOSER_IMAGE` | `composer:2` | install, lint, stan, unit tests |
| `WP_CLI_IMAGE` | `wordpress:cli` | i18n |
| `PHP_IMAGE` | `php:8.3-cli` | psalm |
| `MAILPIT_IMAGE` | `axllent/mailpit:v1.21` | E2E |
| `DOCKER_NET` | `--network host` | i18n |

`--network host` is not supported on Docker Desktop for macOS or Windows; set `DOCKER_NET=` there. The integration, E2E and multisite suites do not need it — they run through `npx wp-env run`.
