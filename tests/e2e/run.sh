#!/usr/bin/env bash
#
# Prueba de punta a punta: WordPress manda un correo a través del plugin y
# el correo aparece en un buzón de verdad.
#
# Levanta Mailpit en la misma red Docker que el wp-env de pruebas, apunta el
# plugin a él, hace un wp_mail() real desde WP-CLI, y verifica por la API de
# Mailpit que llegó con el remitente, el asunto y los destinatarios que
# correspondían. Después rompe el puerto a propósito y verifica que el
# fallo quede en el historial con el error SMTP de verdad.
#
# Es el único test que recorre la cadena entera —config → phpmailer_init →
# SMTP → buzón— y por eso es el que vale cuando los demás dicen que sí.
#
# Uso:   make test-e2e      (con el wp-env de pruebas ya levantado)
#
set -euo pipefail

MAILPIT_IMAGE="${MAILPIT_IMAGE:-axllent/mailpit:v1.21}"
MAILPIT_NAME="diluxone-mailpit"
MAILPIT_PORT="${MAILPIT_PORT:-18025}"
API="http://localhost:${MAILPIT_PORT}/api/v1"

log()  { printf '\n\033[1;34m▶ %s\033[0m\n' "$*"; }
ok()   { printf '\033[1;32m✔ %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31m✖ %s\033[0m\n' "$*" >&2; exit 1; }

wpc() { npx wp-env run tests-cli wp "$@" 2>/dev/null; }

# ── Mailpit en la red del wp-env ─────────────────────────────────────
log "Buscando la red Docker del wp-env de pruebas"
CID=$(docker ps --format '{{.Names}}' | grep -E -- '-tests-wordpress-1$' | head -1)
[ -n "$CID" ] || fail "no hay un wp-env de pruebas corriendo (npx wp-env start)"
NET=$(docker inspect "$CID" --format '{{range $k,$v := .NetworkSettings.Networks}}{{$k}}{{end}}')
ok "red: $NET"

log "Levantando Mailpit"
docker rm -f "$MAILPIT_NAME" >/dev/null 2>&1 || true
docker run -d --name "$MAILPIT_NAME" --network "$NET" -p "${MAILPIT_PORT}:8025" "$MAILPIT_IMAGE" >/dev/null
trap 'docker rm -f "$MAILPIT_NAME" >/dev/null 2>&1 || true' EXIT

for _ in $(seq 1 30); do
  curl -sf "$API/info" >/dev/null && break
  sleep 1
done
curl -sf "$API/info" >/dev/null || fail "Mailpit no contesta en $API"
curl -s -X DELETE "$API/messages" >/dev/null
ok "Mailpit listo"

# ── El plugin apunta a Mailpit por el perfil local ───────────────────
log "Configurando el plugin: perfil Mailpit, modo transporte"
wpc plugin activate diluxone-mail >/dev/null || true
wpc option update diluxone_mail_provider mailpit >/dev/null
wpc option update diluxone_mail_host "$MAILPIT_NAME" >/dev/null
wpc option update diluxone_mail_port 1025 >/dev/null
wpc option update diluxone_mail_encryption none >/dev/null
wpc option update diluxone_mail_auth 0 >/dev/null
wpc option update diluxone_mail_mode transport >/dev/null
wpc option update diluxone_mail_from e2e@example.test >/dev/null
wpc option update diluxone_mail_from_name "E2E" >/dev/null
wpc option update diluxone_mail_log_enabled 1 >/dev/null
wpc option update diluxone_mail_log_body 1 >/dev/null
wpc option update diluxone_mail_log_extended 1 >/dev/null
wpc option delete diluxone_mail_last_result >/dev/null 2>&1 || true
ok "configurado"

# ── 1. Un envío con destinatario y copia llega a Mailpit ─────────────
STAMP=$(date +%s)
SUBJECT="E2E ${STAMP}"

log "Enviando un wp_mail() real con Cc"
RESULT=$(wpc eval "echo wp_mail( 'destino@example.test', '${SUBJECT}', 'cuerpo e2e ${STAMP}', array( 'Cc: copia@example.test' ) ) ? 'true' : 'false';")
[ "$RESULT" = "true" ] || fail "wp_mail() devolvió $RESULT"
ok "wp_mail() devolvió true"

log "Verificando en Mailpit"
sleep 1
python3 - "$API" "$SUBJECT" <<'PY'
import json, sys, urllib.request
api, subject = sys.argv[1], sys.argv[2]
msgs = json.load(urllib.request.urlopen(f"{api}/messages"))["messages"]
match = [m for m in msgs if m["Subject"] == subject]
assert len(match) == 1, f"esperaba 1 mensaje con asunto {subject!r}, hay {len(match)}: {[m['Subject'] for m in msgs]}"
m = match[0]
assert m["From"]["Address"] == "e2e@example.test", m["From"]
assert m["From"]["Name"] == "E2E", m["From"]
assert {t["Address"] for t in m["To"]} == {"destino@example.test"}, m["To"]
assert {t["Address"] for t in m["Cc"]} == {"copia@example.test"}, m["Cc"]
full = json.load(urllib.request.urlopen(f"{api}/message/{m['ID']}"))
assert "cuerpo e2e" in full["Text"], full["Text"]
assert full["MessageID"].startswith(""), full["MessageID"]
print("   From/To/Cc/cuerpo correctos; Message-ID:", full["MessageID"])
PY
ok "el correo llegó al buzón con From, To, Cc y cuerpo correctos"

# ── 2. El historial lo registró: dos filas, mismo id, estado sent ────
log "Verificando el historial por WP-CLI"
wpc diluxone-mail log list --format=json --limit=10 > /tmp/diluxone-e2e-log.json
python3 - "$SUBJECT" <<'PY'
import json, sys
subject = sys.argv[1]
rows = [r for r in json.load(open("/tmp/diluxone-e2e-log.json")) if r["subject"] == subject]
assert len(rows) == 2, f"esperaba 2 filas (to + cc), hay {len(rows)}"
assert {r["to"] for r in rows} == {"destino@example.test", "copia@example.test"}, rows
assert all(r["status"] == "sent" for r in rows), rows
print("   filas:", [(r["to"], r["status"]) for r in rows])
PY
ok "dos filas, estado sent"

# ── 3. El Message-ID del correo es el del historial ──────────────────
log "Verificando que el Message-ID del correo sea el id del historial"
MID=$(python3 -c "
import json,urllib.request
m=[m for m in json.load(urllib.request.urlopen('$API/messages'))['messages'] if m['Subject']=='$SUBJECT'][0]
print(json.load(urllib.request.urlopen('$API/message/'+m['ID']))['MessageID'])")
UUID="${MID%%@*}"
HAS=$(wpc eval "echo count( diluxone_mail_log_recipients_of( '${UUID}' ) );")
[ "$HAS" = "2" ] || fail "el Message-ID ${MID} no casa con el historial (filas: ${HAS})"
ok "Message-ID ${MID} → 2 filas del historial"

# ── 4. El detalle guardó cuerpo y diálogo SMTP ───────────────────────
log "Verificando cuerpo y diálogo SMTP guardados"
DET=$(wpc eval "\$d = diluxone_mail_detail_get( '${UUID}' ); echo ( false !== strpos( \$d['body'], 'cuerpo e2e' ) ? 'body-ok ' : 'body-NO ' ) . ( false !== strpos( \$d['transcript'], '250' ) ? 'transcript-ok' : 'transcript-NO' );")
[ "$DET" = "body-ok transcript-ok" ] || fail "detalle: $DET"
ok "cuerpo y diálogo SMTP en la tabla de detalle"

# ── 5. La ficha de la persona lo ve ──────────────────────────────────
log "Verificando la búsqueda por dirección de una persona"
wpc user create e2e_${STAMP} destino@example.test --role=subscriber >/dev/null 2>&1 || true
CNT=$(wpc eval "\$u = get_user_by( 'email', 'destino@example.test' ); echo diluxone_mail_log_count( diluxone_mail_user_emails( \$u ) );")
[ "$CNT" -ge 1 ] || fail "la ficha no ve el correo (count=$CNT)"
ok "la ficha de destino@example.test ve $CNT mensaje(s)"

# ── 6. El comando de prueba ──────────────────────────────────────────
log "wp diluxone-mail test"
wpc diluxone-mail test prueba@example.test | tail -3
ok "comando test"

# ── 7. Un fallo real queda con su error SMTP ─────────────────────────
log "Rompiendo el puerto a propósito"
wpc option update diluxone_mail_port 1026 >/dev/null
RESULT=$(wpc eval "echo wp_mail( 'falla@example.test', 'Falla ${STAMP}', 'x' ) ? 'true' : 'false';")
[ "$RESULT" = "false" ] || fail "wp_mail() tendría que haber fallado"
wpc diluxone-mail log list --format=json --email=falla@example.test --limit=1 > /tmp/diluxone-e2e-fail.json
python3 - <<'PY'
import json
r = json.load(open("/tmp/diluxone-e2e-fail.json"))[0]
assert r["status"] == "failed", r
assert r["error"] != "", r
print("   error registrado:", r["error"][:80])
PY
wpc option update diluxone_mail_port 1025 >/dev/null
ok "el fallo quedó en el historial con el error SMTP"

# ── 8. El estado dice la verdad ──────────────────────────────────────
log "wp diluxone-mail status"
wpc diluxone-mail status --format=json | python3 -c "
import json,sys
rows={r['key']:r['value'] for r in json.load(sys.stdin)}
assert rows['mode'].startswith('transport'), rows['mode']
assert rows['host'].startswith('diluxone-mailpit'), rows['host']
assert 'set on this site' in rows['host'], rows['host']
print('   mode:', rows['mode'], '| host:', rows['host'])
"
ok "estado coherente"

# ── 9. El diagnóstico corre contra un dominio real (sólo lectura) ────
log "wp diluxone-mail dns pablodiloreto.com"
wpc diluxone-mail dns pablodiloreto.com --fresh --format=json | python3 -c "
import json,sys
r=json.load(sys.stdin)
assert r['spf']['record'] and r['spf']['record'].startswith('v=spf1'), r['spf']
assert 0 < r['spf']['lookups'] <= 10, r['spf']['lookups']
assert any(d['selector']=='mailjet' and d['found'] for d in r['dkim']), 'mailjet DKIM no encontrado'
assert r['dmarc']['record'], r['dmarc']
print('   SPF lookups:', r['spf']['lookups'], '| DKIM mailjet: ok | DMARC p=', r['dmarc']['policy'], '| resolver:', r['resolver'])
"
ok "diagnóstico DNS contra un dominio real"

printf '\n\033[1;32m✔ E2E completo.\033[0m\n'
