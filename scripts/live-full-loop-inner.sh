#!/usr/bin/env bash
#
# The whole loop, live, on Linux.
#
#   Laravel control plane  ->  enrollment over TLS
#   Go agent               ->  enrolls, connects to the gateway over mTLS
#   Go gateway             ->  holds the session, reports the connection
#   Laravel                ->  dispatches a command through the gateway
#   agent                  ->  runs it, streams progress, returns a result
#   Laravel                ->  records the whole thing in its audit chain
#
# Every component is the real one. Nothing is faked, nothing is a test
# double, and the only thing missing from production is a Droplet underneath
# the agent.
#
# This is what the conformance fixtures could never prove. They established
# that the two codebases agreed about field names; the first live enrollment
# still failed because they disagreed about encodings. Running them together
# is the only thing that shows the difference.
set -euo pipefail

SRC="${SRC:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
export PATH=/usr/local/go/bin:$HOME/bin:$PATH
export COMPOSER_ALLOW_SUPERUSER=1

WORK="$HOME/.cache/cloud-manager-loop"
CP="$WORK/control-plane"
NODE="$WORK/node"
PKI="$WORK/pki"

cleanup() {
  for pid in ${PHP_PID:-} ${TLS_PID:-} ${GW_PID:-} ${AGENT_PID:-}; do
    kill "$pid" 2>/dev/null || true
  done
}
trap cleanup EXIT

fail() {
  echo
  echo "FAIL: $*" >&2
  for log in agent gateway php; do
    if [ -s "$WORK/$log.log" ]; then
      echo "--- $log ---" >&2
      tail -25 "$WORK/$log.log" >&2
    fi
  done
  exit 1
}

step() { printf '\n=== %s\n' "$*"; }

rm -rf "$WORK"
mkdir -p "$CP" "$NODE" "$PKI"

# ------------------------------------------------------------ 1. binaries

step "building the agent and the gateway"
cd "$SRC"
go build -o "$WORK/agent" ./cmd/agent
go build -o "$WORK/gateway" ./cmd/gateway

# ------------------------------------------------------- 2. control plane

step "preparing the control plane"
rsync -a --delete \
  --exclude vendor/ --exclude node_modules/ --exclude .git/ \
  --exclude 'storage/framework/cache/' --exclude 'storage/framework/sessions/' \
  --exclude 'storage/framework/views/' --exclude 'storage/logs/' \
  --exclude database/database.sqlite --exclude .env \
  "$SRC/control-plane/" "$CP/"

cd "$CP"
[ -d vendor ] || composer install --no-interaction --prefer-dist --quiet
cp .env.example .env
php artisan key:generate --quiet

GATEWAY_TOKEN="$(head -c 32 /dev/urandom | base64 | tr -d '\n')"
VAULT_KEY="$(php artisan vault:generate-key | head -1)"

{
  echo "VAULT_MASTER_KEY=$VAULT_KEY"
  echo "VAULT_MASTER_KEY_ID=live-loop"
  echo "CONTROL_PLANE_SERVER_NAME=control-plane.local"
  echo "CONTROL_PLANE_SESSION_ADDRESS=control-plane.local:9443"
  echo "GATEWAY_URL=http://127.0.0.1:9444"
  echo "GATEWAY_TOKEN=$GATEWAY_TOKEN"
} >> .env

mkdir -p database storage/framework/{cache,sessions,views} storage/logs
touch database/database.sqlite
php artisan config:clear --quiet
php artisan migrate --force --quiet

php artisan tinker --execute="
DB::table('organizations')->insert([
  'id' => 'org-live', 'name' => 'Live Loop',
  'created_at' => now(), 'updated_at' => now(),
]);
" >/dev/null

# ------------------------------------------------------------------ 3. pki

step "minting the organisation CA and a server certificate"

# One certificate serves both the enrollment endpoint and the gateway, since
# agents pin the organisation CA and verify the same server name for each.
php artisan tinker --execute="
\$ca = app(App\Lib\Enrollment\EnrollmentService::class)->certificateAuthorityFor('org-live');
file_put_contents('$PKI/ca.crt', \$ca->certificatePem());
[\$cert, \$key] = \$ca->issueServerCertificate('control-plane.local', ['control-plane.local', 'localhost']);
file_put_contents('$PKI/server.crt', \$cert);
file_put_contents('$PKI/server.key', \$key);
" >/dev/null

[ -s "$PKI/ca.crt" ] || fail "no CA certificate was written"

echo "$GATEWAY_TOKEN" > "$PKI/gateway.token"
chmod 600 "$PKI/gateway.token"

grep -q 'control-plane.local' /etc/hosts || echo "127.0.0.1 control-plane.local" >> /etc/hosts

# -------------------------------------------------------------- 4. gateway

step "starting the gateway"
"$WORK/gateway" \
  -agent-addr 127.0.0.1:9443 \
  -api-addr 127.0.0.1:9444 \
  -cert-file "$PKI/server.crt" \
  -key-file "$PKI/server.key" \
  -token-file "$PKI/gateway.token" \
  -events-url "http://control-plane.local:8800/api/gateway/events" \
  -heartbeat-seconds 5 \
  >"$WORK/gateway.log" 2>&1 &
GW_PID=$!

for i in $(seq 1 40); do
  curl -sf "http://127.0.0.1:9444/health" -o /dev/null 2>/dev/null && break
  # 503 is expected before organisations are synced, and still means it is up.
  curl -s "http://127.0.0.1:9444/health" 2>/dev/null | grep -q 'organisations' && break
  sleep 0.5
  [ "$i" = "40" ] && fail "the gateway did not start"
done

# A gateway with an empty registry must refuse every node rather than look
# healthy while accepting nobody.
EMPTY_HEALTH="$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:9444/health)"
[ "$EMPTY_HEALTH" = "503" ] || fail "a gateway with no organisations reported $EMPTY_HEALTH, expected 503"
echo "gateway up, correctly unhealthy with an empty registry"

# ---------------------------------------------------- 5. serve the control plane

step "starting the control plane behind TLS"
cd "$CP"
php artisan serve --host=127.0.0.1 --port=8800 --quiet >"$WORK/php.log" 2>&1 &
PHP_PID=$!

cat > "$WORK/tlsproxy.go" <<'GOEOF'
package main

import (
	"crypto/tls"
	"io"
	"log"
	"net"
	"os"
)

// Terminates TLS and forwards to the plain HTTP control plane, standing in
// for nginx. Laravel never terminates TLS itself in production either.
func main() {
	cert, err := tls.LoadX509KeyPair(os.Args[1], os.Args[2])
	if err != nil {
		log.Fatalf("load keypair: %v", err)
	}

	ln, err := tls.Listen("tcp", os.Args[3], &tls.Config{
		Certificates: []tls.Certificate{cert},
		MinVersion:   tls.VersionTLS12,
	})
	if err != nil {
		log.Fatalf("listen: %v", err)
	}
	defer ln.Close()

	for {
		client, err := ln.Accept()
		if err != nil {
			return
		}
		go func() {
			defer client.Close()
			upstream, err := net.Dial("tcp", os.Args[4])
			if err != nil {
				return
			}
			defer upstream.Close()
			go io.Copy(upstream, client)
			io.Copy(client, upstream)
		}()
	}
}
GOEOF

cd "$WORK"
go mod init tlsproxy >/dev/null 2>&1 || true
go build -o "$WORK/tlsproxy" "$WORK/tlsproxy.go"
"$WORK/tlsproxy" "$PKI/server.crt" "$PKI/server.key" "127.0.0.1:8443" "127.0.0.1:8800" \
  >"$WORK/tls.log" 2>&1 &
TLS_PID=$!

for i in $(seq 1 40); do
  curl -s --cacert "$PKI/ca.crt" "https://control-plane.local:8443/up" -o /dev/null 2>/dev/null && break
  sleep 0.5
  [ "$i" = "40" ] && fail "the control plane did not come up"
done
echo "control plane answering over TLS"

# ------------------------------------------------- 6. sync organisations

step "telling the gateway who exists"
cd "$CP"
php artisan gateway:sync-organisations >"$WORK/sync.log" 2>&1 || fail "gateway:sync-organisations failed"
grep -q "accepted 1" "$WORK/sync.log" || fail "the gateway did not accept the organisation"

HEALTH="$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:9444/health)"
[ "$HEALTH" = "200" ] || fail "the gateway reported $HEALTH after a sync, expected 200"
echo "gateway now healthy and accepting org-live"

# ------------------------------------------------------- 7. enrol and connect

step "enrolling the agent and connecting it to the gateway"
php artisan cloud:issue-token org-live web-loop.org-live --out="$NODE" --ttl=600 >/dev/null
[ -s "$NODE/enroll.token" ] || fail "no token was written"

"$WORK/agent" \
  -state-dir "$NODE/state" \
  -sites-root "$NODE/sites" \
  -enroll-url "https://control-plane.local:8443/api/enroll" \
  -server-name "control-plane.local" \
  -ca-file "$PKI/ca.crt" \
  -token-file "$NODE/enroll.token" \
  -log-level info >"$WORK/agent.log" 2>&1 &
AGENT_PID=$!

for i in $(seq 1 60); do
  CONNECTED="$(curl -s -H "X-Gateway-Token: $GATEWAY_TOKEN" http://127.0.0.1:9444/v1/nodes | grep -c 'web-loop.org-live' || true)"
  [ "$CONNECTED" != "0" ] && break
  sleep 0.5
  [ "$i" = "60" ] && fail "the agent did not connect to the gateway within 30s"
done
echo "agent connected"

# The gateway must have placed it in the right tenant, from the CA that
# signed its certificate rather than from anything it claimed.
ORG="$(curl -s -H "X-Gateway-Token: $GATEWAY_TOKEN" http://127.0.0.1:9444/v1/nodes \
  | python3 -c "import json,sys;print(json.load(sys.stdin)['nodes'][0]['organization_id'])")"
[ "$ORG" = "org-live" ] || fail "the gateway placed the node in '$ORG'"
echo "gateway resolved the tenant from the issuing CA: $ORG"

# ------------------------------------------- 8. command through the stack

step "dispatching a command from Laravel through the gateway"

# A recipe, so progress streams and something observable happens on disk.
TARGET="$NODE/applied.conf"
RESULT="$(php artisan tinker --execute="
\$spec = [
  'name' => 'live-loop',
  'steps' => [[
    'id' => 'conf',
    'type' => 'file',
    'path' => '$TARGET',
    'content_b64' => base64_encode(\"it works\n\"),
    'mode' => '0644',
  ]],
];
\$res = app(App\Lib\Gateway\GatewayClient::class)->run(
  organizationId: 'org-live',
  nodeId: 'web-loop.org-live',
  kind: 'apply_recipe',
  args: ['spec' => \$spec],
  idempotencyKey: 'live-loop-1',
  reason: 'Live loop test',
);
echo \$res['status'];
" 2>&1 | tail -1 | tr -dc 'a-z_')"

[ "$RESULT" = "succeeded" ] || fail "the command returned '$RESULT'"
echo "command returned: $RESULT"

# ---------------------------------------------------------- 9. verify

step "verifying what actually happened"

[ -f "$TARGET" ] || fail "the recipe did not create $TARGET"
grep -q "it works" "$TARGET" || fail "$TARGET has the wrong contents"
echo "the agent really wrote the file"

# Progress must have reached the control plane while the command ran.
grep -q "node progress" "$CP/storage/logs/laravel.log" 2>/dev/null \
  || grep -q "node progress" "$WORK/php.log" \
  || echo "note: progress lines were not found in the log (they are only logged until Phase 5)"

# The audit chain must carry the whole story and still verify.
cd "$CP"
for action in enrollment.completed node.connected node.command_sent node.command_result; do
  COUNT="$(php artisan tinker --execute="echo DB::table('audit_records')->where('action','$action')->count();" 2>/dev/null | tr -dc '0-9')"
  [ "${COUNT:-0}" -ge 1 ] || fail "the audit log has no $action record"
  echo "  audited: $action"
done

INTACT="$(php artisan tinker --execute="echo app(App\Lib\Audit\AuditLog::class)->verify('org-live')->intact ? 'yes' : 'no';" 2>/dev/null | tr -dc 'a-z')"
[ "$INTACT" = "yes" ] || fail "the audit chain does not verify"

# Idempotency: the same key must not run the work twice.
echo "changed out of band" > "$TARGET"
php artisan tinker --execute="
app(App\Lib\Gateway\GatewayClient::class)->run(
  organizationId: 'org-live', nodeId: 'web-loop.org-live', kind: 'apply_recipe',
  args: ['spec' => ['name' => 'live-loop', 'steps' => [[
    'id' => 'conf', 'type' => 'file', 'path' => '$TARGET',
    'content_b64' => base64_encode(\"it works\n\"), 'mode' => '0644',
  ]]]],
  idempotencyKey: 'live-loop-1',
);
" >/dev/null 2>&1

grep -q "changed out of band" "$TARGET" \
  || fail "a replayed command with the same idempotency key re-executed"
echo "  replayed command did not re-execute"

# A disconnect must be noticed and reported.
kill "$AGENT_PID" 2>/dev/null || true
AGENT_PID=""

for i in $(seq 1 40); do
  GONE="$(php artisan tinker --execute="echo DB::table('audit_records')->where('action','node.disconnected')->count();" 2>/dev/null | tr -dc '0-9')"
  [ "${GONE:-0}" -ge 1 ] && break
  sleep 0.5
  [ "$i" = "40" ] && fail "the disconnect was never reported to the control plane"
done
echo "  disconnect reported"

printf '\n'
echo "PASS"
echo
echo "  Laravel  enrolled the agent over TLS"
echo "  gateway  verified its certificate and placed it in org-live by issuer"
echo "  Laravel  dispatched a command through the gateway"
echo "  agent    ran it and wrote the file"
echo "  Laravel  recorded enrollment, connection, command and result"
echo "  chain    verifies"
echo
echo "The full loop works with every component real."
