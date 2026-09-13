#!/usr/bin/env bash
#
# Convierte el wp-env de pruebas en una red, crea un segundo sitio, corre la
# suite multisitio de PHPUnit y después verifica por WP-CLI, desde el sitio
# 2, que la precedencia red/sitio y el historial compartido funcionan con
# las options de WordPress de verdad.
#
# Es destructivo para el sitio de pruebas —lo convierte a red— y por eso
# corre después de la integración y del E2E de un solo sitio, nunca antes.
#
# Uso:   make test-multisite
#
set -euo pipefail

log()  { printf '\n\033[1;34m▶ %s\033[0m\n' "$*"; }
ok()   { printf '\033[1;32m✔ %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31m✖ %s\033[0m\n' "$*" >&2; exit 1; }

wpc() { npx wp-env run tests-cli wp "$@" 2>/dev/null; }

log "Convirtiendo el sitio de pruebas en una red"
if [ "$(wpc eval 'echo is_multisite() ? 1 : 0;')" = "1" ]; then
  ok "ya es una red"
else
  wpc core multisite-convert --title="Red E2E" >/dev/null
  ok "convertido"
fi

log "Activando el plugin en la red y creando el segundo sitio"
wpc plugin activate diluxone-mail --network >/dev/null || true
if [ "$(wpc site list --format=count)" -lt 2 ]; then
  wpc site create --slug=dos --title="Sitio Dos" >/dev/null
fi
SITE2=$(wpc site list --field=url | sed -n '2p')
[ -n "$SITE2" ] || fail "no hay segundo sitio"
ok "segundo sitio: $SITE2"

log "Suite PHPUnit multisitio"
npx wp-env run tests-cli ./wp-content/plugins/diluxone-mail/vendor/bin/phpunit -c ./wp-content/plugins/diluxone-mail/phpunit-multisite.xml
ok "suite multisitio"

log "Precedencia red/sitio por WP-CLI, desde el sitio 2"
wpc site option update diluxone_mail_host smtp.red.test >/dev/null
wpc site option update diluxone_mail_network_allow_override 0 >/dev/null
wpc --url="$SITE2" option update diluxone_mail_host smtp.sitio2.test >/dev/null

SRC=$(wpc --url="$SITE2" diluxone-mail status --format=json | python3 -c "import json,sys; print({r['key']:r['value'] for r in json.load(sys.stdin)}['host'])")
[[ "$SRC" == smtp.red.test* ]] || fail "sin permiso, el sitio 2 tendría que ver el host de la red: $SRC"
[[ "$SRC" == *"set by the network"* ]] || fail "la procedencia tendría que decir red: $SRC"
ok "sin permiso, manda la red: $SRC"

wpc site option update diluxone_mail_network_allow_override 1 >/dev/null
SRC=$(wpc --url="$SITE2" diluxone-mail status --format=json | python3 -c "import json,sys; print({r['key']:r['value'] for r in json.load(sys.stdin)}['host'])")
[[ "$SRC" == smtp.sitio2.test* ]] || fail "con permiso, el sitio 2 tendría que ver su host: $SRC"
ok "con permiso, el sitio pisa: $SRC"

log "Un envío desde el sitio 2 queda en la tabla compartida con su site_id"
wpc site option update diluxone_mail_mode observe >/dev/null
wpc --url="$SITE2" eval "add_filter('pre_wp_mail', fn(\$p) => true); wp_mail('red@example.test', 'Desde dos', 'x');" >/dev/null
ROW=$(wpc diluxone-mail log list --format=json --email=red@example.test --limit=1)
python3 - "$ROW" <<'PY'
import json, sys
rows = json.loads(sys.argv[1])
assert len(rows) == 1 and rows[0]["status"] == "intercepted", rows
print("   fila desde el sitio 2 visible desde la red:", rows[0]["to"], rows[0]["status"])
PY
ok "historial compartido en la red"

printf '\n\033[1;32m✔ Multisitio completo.\033[0m\n'
