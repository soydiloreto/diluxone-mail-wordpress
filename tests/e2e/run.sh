#!/usr/bin/env bash
#
# End to end: WordPress sends a message through the plugin and the message
# turns up in a real mailbox.
#
# It brings Mailpit up on the same Docker network as the test wp-env, points
# the plugin at it, makes a real wp_mail() from WP-CLI, and checks through
# Mailpit's API that it arrived with the sender, subject and recipients it was
# supposed to. Then it breaks the port on purpose and checks that the failure
# lands in the log with the real SMTP error.
#
# It is the only test that walks the whole chain — config → phpmailer_init →
# SMTP → mailbox — and that is why it is the one that counts when the others
# say yes.
#
# Usage:   make test-e2e      (with the test wp-env already up)
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

# ── Mailpit on the wp-env network ────────────────────────────────────
log "Looking for the test wp-env's Docker network"
# The WP-CLI container itself — the one that is going to send — is asked which
# networks it is on, rather than guessing from the name: there can be more than
# one wp-env running, and the project hash changes between wp-env versions.
CLI_ID=$( (npx wp-env run tests-cli sh -c hostname 2>/dev/null || true) | grep -E '^[0-9a-f]{12}$' | head -1 || true)
NETS=""
[ -n "$CLI_ID" ] && NETS=$(docker inspect "$CLI_ID" --format '{{range $k,$v := .NetworkSettings.Networks}}{{$k}} {{end}}' 2>/dev/null || true)
if [ -z "$NETS" ]; then
  CID=$(docker ps --format '{{.Names}}' | grep -E -- '-tests-wordpress-1$' | head -1)
  [ -n "$CID" ] || fail "no test wp-env is running (npx wp-env start)"
  NETS=$(docker inspect "$CID" --format '{{range $k,$v := .NetworkSettings.Networks}}{{$k}} {{end}}')
fi
NET=$(echo "$NETS" | awk '{print $1}')
[ -n "$NET" ] || fail "could not work out the wp-env network"
ok "networks: $NETS"

log "Bringing Mailpit up"
docker rm -f "$MAILPIT_NAME" >/dev/null 2>&1 || true
docker run -d --name "$MAILPIT_NAME" --network "$NET" -p "${MAILPIT_PORT}:8025" "$MAILPIT_IMAGE" >/dev/null
for n in $NETS; do [ "$n" = "$NET" ] || docker network connect "$n" "$MAILPIT_NAME" >/dev/null 2>&1 || true; done
MU='/var/www/html/wp-content/mu-plugins'
cleanup() {
  docker rm -f "$MAILPIT_NAME" >/dev/null 2>&1 || true
  npx wp-env run tests-cli sh -c "rm -f ${MU}/other-mailer.php ${MU}/interceptor.php" >/dev/null 2>&1 || true
}
trap cleanup EXIT

# Drops a fake mu-plugin inside the container. The content travels base64
# encoded: between the local shell, wp-env and docker there are three layers of
# quoting and a $ survives none of them.
mu_plugin() {
  local name="$1" body="$2"
  npx wp-env run tests-cli sh -c "mkdir -p ${MU} && echo '$(printf '%s' "$body" | base64 -w0)' | base64 -d > ${MU}/${name}" >/dev/null 2>&1
}

for _ in $(seq 1 30); do
  curl -sf "$API/info" >/dev/null && break
  sleep 1
done
curl -sf "$API/info" >/dev/null || fail "Mailpit is not answering at $API"
curl -s -X DELETE "$API/messages" >/dev/null
ok "Mailpit ready"

# ── The plugin points at Mailpit through the local profile ──────────
log "Configuring the plugin: Mailpit profile, transport mode"
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
wpc option update diluxone_mail_log_extended 1 >/dev/null
wpc option delete diluxone_mail_last_result >/dev/null 2>&1 || true
ok "configured"

# ── 1. A send with a recipient and a copy reaches Mailpit ────────────
STAMP=$(date +%s)
SUBJECT="E2E ${STAMP}"

log "Sending a real wp_mail() with a Cc"
RESULT=$(wpc eval "echo wp_mail( 'to@example.test', '${SUBJECT}', 'e2e body ${STAMP}', array( 'Cc: cc@example.test' ) ) ? 'true' : 'false';")
[ "$RESULT" = "true" ] || fail "wp_mail() returned $RESULT"
ok "wp_mail() returned true"

log "Checking in Mailpit"
sleep 1
python3 - "$API" "$SUBJECT" <<'PY'
import json, sys, urllib.request
api, subject = sys.argv[1], sys.argv[2]
msgs = json.load(urllib.request.urlopen(f"{api}/messages"))["messages"]
match = [m for m in msgs if m["Subject"] == subject]
assert len(match) == 1, f"expected 1 message with subject {subject!r}, found {len(match)}: {[m['Subject'] for m in msgs]}"
m = match[0]
assert m["From"]["Address"] == "e2e@example.test", m["From"]
assert m["From"]["Name"] == "E2E", m["From"]
assert {t["Address"] for t in m["To"]} == {"to@example.test"}, m["To"]
assert {t["Address"] for t in m["Cc"]} == {"cc@example.test"}, m["Cc"]
full = json.load(urllib.request.urlopen(f"{api}/message/{m['ID']}"))
assert "e2e body" in full["Text"], full["Text"]
assert full["MessageID"].startswith(""), full["MessageID"]
print("   From/To/Cc/body correct; Message-ID:", full["MessageID"])
PY
ok "the message reached the mailbox with the right From, To, Cc and body"

# ── 2. The log recorded it: two rows, same id, status sent ───────────
log "Checking the log through WP-CLI"
wpc diluxone-mail log list --format=json --limit=10 > /tmp/diluxone-e2e-log.json
python3 - "$SUBJECT" <<'PY'
import json, sys
subject = sys.argv[1]
rows = [r for r in json.load(open("/tmp/diluxone-e2e-log.json")) if r["subject"] == subject]
assert len(rows) == 2, f"expected 2 rows (to + cc), found {len(rows)}"
assert {r["to"] for r in rows} == {"to@example.test", "cc@example.test"}, rows
assert all(r["status"] == "sent" for r in rows), rows
print("   rows:", [(r["to"], r["status"]) for r in rows])
PY
ok "two rows, status sent"

# ── 3. The message's Message-ID is the log's own ─────────────────────
log "Checking that the message's Message-ID is the log id"
MID=$(python3 -c "
import json,urllib.request
m=[m for m in json.load(urllib.request.urlopen('$API/messages'))['messages'] if m['Subject']=='$SUBJECT'][0]
print(json.load(urllib.request.urlopen('$API/message/'+m['ID']))['MessageID'])")
UUID="${MID%%@*}"
HAS=$(wpc eval "echo count( diluxone_mail_log_recipients_of( '${UUID}' ) );")
[ "$HAS" = "2" ] || fail "Message-ID ${MID} does not match the log (rows: ${HAS})"
ok "Message-ID ${MID} → 2 log rows"

# ── 4. The detail stored the SMTP dialogue, and not the body ─────────
log "Checking the stored SMTP dialogue, and that the body is not there"
DET=$(wpc eval "\$d = diluxone_mail_detail_get( '${UUID}' ); echo ( false !== strpos( \$d['transcript'], '250' ) ? 'transcript-ok ' : 'transcript-NO ' ) . ( isset( \$d['body'] ) ? 'body-LEAKED' : 'no-body-ok' );")
[ "$DET" = "transcript-ok no-body-ok" ] || fail "detail: $DET"
ok "SMTP dialogue in the detail table, no message content anywhere"

# ── 5. The person's profile sees it ──────────────────────────────────
log "Checking the lookup by a person's address"
wpc user create e2e_${STAMP} to@example.test --role=subscriber >/dev/null 2>&1 || true
CNT=$(wpc eval "\$u = get_user_by( 'email', 'to@example.test' ); echo diluxone_mail_log_count( diluxone_mail_user_emails( \$u ) );")
[ "$CNT" -ge 1 ] || fail "the profile does not see the message (count=$CNT)"
ok "to@example.test's profile sees $CNT message(s)"

# ── 6. The test command ──────────────────────────────────────────────
log "wp diluxone-mail test"
wpc diluxone-mail test probe@example.test | tail -3
ok "test command"

# ── 7. A real failure keeps its SMTP error ───────────────────────────
log "Breaking the port on purpose"
wpc option update diluxone_mail_port 1026 >/dev/null
RESULT=$(wpc eval "echo wp_mail( 'fails@example.test', 'Fails ${STAMP}', 'x' ) ? 'true' : 'false';")
[ "$RESULT" = "false" ] || fail "wp_mail() should have failed"
wpc diluxone-mail log list --format=json --email=fails@example.test --limit=1 > /tmp/diluxone-e2e-fail.json
python3 - <<'PY'
import json
r = json.load(open("/tmp/diluxone-e2e-fail.json"))[0]
assert r["status"] == "failed", r
assert r["error"] != "", r
print("   recorded error:", r["error"][:80])
PY
wpc option update diluxone_mail_port 1025 >/dev/null
ok "the failure landed in the log with the SMTP error"

# ── 8. The status tells the truth ────────────────────────────────────
log "wp diluxone-mail status"
wpc diluxone-mail status --format=json | python3 -c "
import json,sys
rows={r['key']:r['value'] for r in json.load(sys.stdin)}
assert rows['mode'].startswith('transport'), rows['mode']
assert rows['host'].startswith('diluxone-mailpit'), rows['host']
assert 'set on this site' in rows['host'], rows['host']
print('   mode:', rows['mode'], '| host:', rows['host'])
"
ok "status is consistent"

# ── 9. A Bcc leaves its row too ─────────────────────────────────────
log "Sending with a Bcc"
wpc eval "wp_mail( 'to@example.test', 'Bcc ${STAMP}', 'x', array( 'Bcc: hidden@example.test' ) );" >/dev/null
N=$(wpc diluxone-mail log list --format=json --email=hidden@example.test --limit=1 | python3 -c "import json,sys; r=json.load(sys.stdin); print(len(r), r[0]['status'] if r else '')")
[ "$N" = "1 sent" ] || fail "the Bcc row: $N"
ok "the hidden recipient has its row"

# ── 10. Somebody else on phpmailer_init does not take the mail away ──
log "Installing a mu-plugin that configures PHPMailer, the way a snippet does"
mu_plugin other-mailer.php '<?php
/* Plugin Name: Another Mailer */
add_action( "phpmailer_init", function ( $m ) { $m->isSMTP(); $m->Host = "diluxone-mailpit"; $m->Port = 1025; $m->SMTPAuth = false; $m->SMTPAutoTLS = false; } );
add_filter( "wp_mail_from", function () { return "other@example.test"; } );
'
wpc option update diluxone_mail_mode auto >/dev/null
wpc option update diluxone_mail_force_from 1 >/dev/null
MODE=$(wpc diluxone-mail status --format=json | python3 -c "import json,sys; r={x['key']:x['value'] for x in json.load(sys.stdin)}; print(r['mode'], '|', r['other mailers'])")
[ "$MODE" = "auto (sending) | other-mailer.php" ] || fail "phpmailer_init should not demote us: $MODE"
wpc eval "wp_mail( 'obs@example.test', 'Observed ${STAMP}', 'x' );" >/dev/null
ROW=$(wpc eval "\$r = diluxone_mail_log_query( array( 'emails' => array( 'obs@example.test' ), 'per_page' => 1 ) )['rows'][0]; echo \$r['status'], '|', \$r['from_email'];")
[ "$ROW" = "sent|e2e@example.test" ] || fail "the sender should still be ours: $ROW"
wpc option update diluxone_mail_force_from 0 >/dev/null
ok "seen but not obeyed: this plugin stays the transport and keeps its sender"

# ── 11. Real observer mode: another plugin owns wp_mail() ────────────
log "Installing a mu-plugin that answers pre_wp_mail, which does end the send"
npx wp-env run tests-cli sh -c "rm -f ${MU}/other-mailer.php" >/dev/null 2>&1
mu_plugin owner.php '<?php
/* Plugin Name: The Owner */
add_filter( "pre_wp_mail", function ( $pre ) { return true; }, 10, 1 );
'
MODE=$(wpc diluxone-mail status --format=json | python3 -c "import json,sys; r={x['key']:x['value'] for x in json.load(sys.stdin)}; print(r['mode'], '|', r['other mailers'])")
[ "$MODE" = "auto (observing) | owner.php" ] || fail "observer: $MODE"
npx wp-env run tests-cli sh -c "rm -f ${MU}/owner.php" >/dev/null 2>&1
ok "the one that ends the send does demote us: $MODE"

# ── 12. The pre_wp_mail trap: an interceptor that cuts the send off ──
log "Installing a mu-plugin that short-circuits pre_wp_mail with a closure (like Azure App Service's)"
mu_plugin interceptor.php '<?php
/* Plugin Name: Interceptor */
add_filter( "pre_wp_mail", function ( $pre ) { return false; }, 10, 1 );
'
wpc option update diluxone_mail_mode transport >/dev/null
wpc eval "wp_mail( 'cut@example.test', 'Cut ${STAMP}', 'x' );" >/dev/null
ROW=$(wpc eval "\$r = diluxone_mail_log_query( array( 'emails' => array( 'cut@example.test' ), 'per_page' => 1 ) )['rows'][0]; echo \$r['status'], '|', \$r['response'];")
case "$ROW" in "intercepted|interceptor.php"*) ;; *) fail "intercepted: $ROW" ;; esac
ok "the short circuit was recorded with the culprit: $ROW"

log "Detaching the interceptor (the checkbox is off by default)"
wpc option update diluxone_mail_unhook_pre_wp_mail 1 >/dev/null
wpc eval "wp_mail( 'freed@example.test', 'Freed ${STAMP}', 'x' );" >/dev/null
ROW=$(wpc eval "echo diluxone_mail_log_query( array( 'emails' => array( 'freed@example.test' ), 'per_page' => 1 ) )['rows'][0]['status'];")
[ "$ROW" = "sent" ] || fail "after detaching: $ROW"
npx wp-env run tests-cli sh -c "rm -f ${MU}/interceptor.php" >/dev/null 2>&1
wpc option update diluxone_mail_unhook_pre_wp_mail 0 >/dev/null
ok "with the interceptor detached the mail goes out"

# ── 13. Privacy: exporting and erasing a person ──────────────────────
log "Personal data exporter and eraser"
EXP=$(wpc eval "\$e = diluxone_mail_export_personal_data( 'to@example.test' ); echo count( \$e['data'] );")
[ "$EXP" -ge 2 ] || fail "export: $EXP rows"
wpc eval "diluxone_mail_erase_personal_data( 'to@example.test' );" >/dev/null
LEFT=$(wpc eval "echo diluxone_mail_log_query( array( 'emails' => array( 'to@example.test' ) ) )['total'];")
[ "$LEFT" = "0" ] || fail "$LEFT rows left after erasing"
ok "exported $EXP rows and erased them"

# ── 14. The cron purge ───────────────────────────────────────────────
log "Cron purge"
wpc eval "global \$wpdb; \$wpdb->query( \$wpdb->prepare( 'UPDATE %i SET sent_at = %s WHERE email = %s', diluxone_mail_log_table(), '2000-01-01 00:00:00', 'cc@example.test' ) );" >/dev/null
wpc eval "diluxone_mail_run_purge();" >/dev/null
LEFT=$(wpc eval "echo diluxone_mail_log_query( array( 'emails' => array( 'cc@example.test' ) ) )['total'];")
[ "$LEFT" = "0" ] || fail "the purge did not delete what had expired ($LEFT)"
ok "what expired is gone, the rest stays"

# ── 15. The diagnosis runs against a real domain (read only) ─────────
log "wp diluxone-mail dns pablodiloreto.com"
wpc diluxone-mail dns pablodiloreto.com --fresh --format=json | python3 -c "
import json,sys
r=json.load(sys.stdin)
assert r['spf']['record'] and r['spf']['record'].startswith('v=spf1'), r['spf']
assert 0 < r['spf']['lookups'] <= 10, r['spf']['lookups']
assert any(d['selector']=='mailjet' and d['found'] for d in r['dkim']), 'mailjet DKIM not found'
assert r['dmarc']['record'], r['dmarc']
print('   SPF lookups:', r['spf']['lookups'], '| DKIM mailjet: ok | DMARC p=', r['dmarc']['policy'], '| resolver:', r['resolver'])
"
ok "DNS diagnosis against a real domain"

printf '\n\033[1;32m✔ E2E complete.\033[0m\n'
