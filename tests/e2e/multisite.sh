#!/usr/bin/env bash
#
# Converts the test wp-env into a network, creates a second site, runs the
# PHPUnit multisite suite and then checks through WP-CLI, from site 2, that the
# network/site precedence and the shared log work with real WordPress options.
#
# It is destructive to the test site — it converts it to a network — and that is
# why it runs after the integration suite and the single-site E2E, never
# before.
#
# Usage:   make test-multisite
#
set -euo pipefail

log()  { printf '\n\033[1;34m▶ %s\033[0m\n' "$*"; }
ok()   { printf '\033[1;32m✔ %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31m✖ %s\033[0m\n' "$*" >&2; exit 1; }

wpc() { npx wp-env run tests-cli wp "$@" 2>/dev/null; }

log "Converting the test site into a network"
if [ "$(wpc eval 'echo is_multisite() ? 1 : 0;')" = "1" ]; then
  ok "already a network"
else
  wpc core multisite-convert --title="E2E Network" >/dev/null
  ok "converted"
fi

log "Activating the plugin network-wide and creating the second site"
wpc plugin activate diluxone-mail --network >/dev/null || true
if [ "$(wpc site list --format=count)" -lt 2 ]; then
  wpc site create --slug=two --title="Site Two" >/dev/null
fi
SITE2=$(wpc site list --field=url | sed -n '2p')
[ -n "$SITE2" ] || fail "there is no second site"
ok "second site: $SITE2"

log "PHPUnit multisite suite"
npx wp-env run tests-cli ./wp-content/plugins/diluxone-mail/vendor/bin/phpunit -c ./wp-content/plugins/diluxone-mail/phpunit-multisite.xml
ok "multisite suite"

log "Network/site precedence through WP-CLI, from site 2"
wpc site option update diluxone_mail_host smtp.network.test >/dev/null
wpc site option update diluxone_mail_network_allow_override 0 >/dev/null
wpc --url="$SITE2" option update diluxone_mail_host smtp.site2.test >/dev/null

SRC=$(wpc --url="$SITE2" diluxone-mail status --format=json | python3 -c "import json,sys; print({r['key']:r['value'] for r in json.load(sys.stdin)}['host'])")
[[ "$SRC" == smtp.network.test* ]] || fail "without permission, site 2 should see the network host: $SRC"
[[ "$SRC" == *"set by the network"* ]] || fail "the provenance should say network: $SRC"
ok "without permission the network wins: $SRC"

wpc site option update diluxone_mail_network_allow_override 1 >/dev/null
SRC=$(wpc --url="$SITE2" diluxone-mail status --format=json | python3 -c "import json,sys; print({r['key']:r['value'] for r in json.load(sys.stdin)}['host'])")
[[ "$SRC" == smtp.site2.test* ]] || fail "with permission, site 2 should see its own host: $SRC"
ok "with permission the site overrides: $SRC"

log "A send from site 2 lands in the shared table with its site_id"
wpc site option update diluxone_mail_mode observe >/dev/null
wpc --url="$SITE2" eval "add_filter('pre_wp_mail', fn(\$p) => true); wp_mail('network@example.test', 'From two', 'x');" >/dev/null
ROW=$(wpc diluxone-mail log list --format=json --email=network@example.test --limit=1)
python3 - "$ROW" <<'PY'
import json, sys
rows = json.loads(sys.argv[1])
assert len(rows) == 1 and rows[0]["status"] == "intercepted", rows
print("   row from site 2 visible from the network:", rows[0]["to"], rows[0]["status"])
PY
ok "log shared across the network"

printf '\n\033[1;32m✔ Multisite complete.\033[0m\n'
