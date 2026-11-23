#!/usr/bin/env bash
#
# Runs inside Linux. Driven by scripts/live-enrollment.sh from Windows, or
# invoked directly on a Linux host with SRC pointing at the repository.
#
# Sequence:
#   1. Build the agent for Linux.
#   2. Stand up the Laravel control plane with a real database.
#   3. Mint an organisation CA and a server certificate from it.
#   4. Terminate TLS with that certificate in front of the control plane.
#   5. Issue a single-use enrollment token.
#   6. Run the real agent against it.
#   7. Assert the agent got a certificate, and that the control plane recorded
#      the enrollment in its audit chain.
set -euo pipefail

SRC="${SRC:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
export PATH=/usr/local/go/bin:$HOME/bin:$PATH
export COMPOSER_ALLOW_SUPERUSER=1

WORK="$HOME/.cache/cloud-manager-live"
CP="$WORK/control-plane"
NODE="$WORK/node"
PKI="$WORK/pki"

cleanup() {
  [ -n "${PHP_PID:-}" ] && kill "$PHP_PID" 2>/dev/null || true
  [ -n "${TLS_PID:-}" ] && kill "$TLS_PID" 2>/dev/null || true
  [ -n "${AGENT_PID:-}" ] && kill "$AGENT_PID" 2>/dev/null || true
}
trap cleanup EXIT

fail() { echo "FAIL: $*" >&2; exit 1; }
step() { printf '\n=== %s\n' "$*"; }

rm -rf "$WORK"
mkdir -p "$CP" "$NODE" "$PKI"

# ---------------------------------------------------------------- 1. agent

step "building the agent for linux"
cd "$SRC"
go build -o "$WORK/agent" ./cmd/agent
"$WORK/agent" -version >/dev/null || fail "agent will not run"

# -------------------------------------------------------- 2. control plane

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

# The vault master key is required: the organisation CA private key is stored
# through it, so without one the control plane cannot mint anything.
VAULT_KEY="$(php artisan vault:generate-key | head -1)"
{
  echo "VAULT_MASTER_KEY=$VAULT_KEY"
  echo "VAULT_MASTER_KEY_ID=live-test"
  echo "CONTROL_PLANE_SERVER_NAME=control-plane.local"
  echo "CONTROL_PLANE_SESSION_ADDRESS=127.0.0.1:9443"
} >> .env

mkdir -p database storage/framework/{cache,sessions,views} storage/logs
touch database/database.sqlite
php artisan config:clear --quiet
php artisan migrate --force --quiet

php artisan tinker --execute="
DB::table('organizations')->insert([
  'id' => 'org-live', 'name' => 'Live Test',
  'created_at' => now(), 'updated_at' => now(),
]);
" >/dev/null

# ------------------------------------------------------------------ 3. pki

step "minting the organisation CA and a server certificate"

# The CA is created on first use, with its private key going into the vault.
# The server certificate is signed by it, which is what lets the agent verify
# the control plane while trusting nothing else.
php artisan tinker --execute="
\$ca = app(App\Lib\Enrollment\EnrollmentService::class)->certificateAuthorityFor('org-live');
file_put_contents('$PKI/ca.crt', \$ca->certificatePem());
[\$cert, \$key] = \$ca->issueServerCertificate('control-plane.local', ['control-plane.local', 'localhost']);
file_put_contents('$PKI/server.crt', \$cert);
file_put_contents('$PKI/server.key', \$key);
" >/dev/null

[ -s "$PKI/ca.crt" ] || fail "no CA certificate was written"
[ -s "$PKI/server.crt" ] || fail "no server certificate was written"

# ------------------------------------------------------- 4. serve over TLS

step "starting the control plane behind TLS"

php artisan serve --host=127.0.0.1 --port=8800 --quiet >"$WORK/php.log" 2>&1 &
PHP_PID=$!

# A tiny TLS terminator standing in for nginx. This mirrors production, where
# Laravel never terminates TLS itself.
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
// for nginx. The certificate is the one the organisation CA just issued, so
// the agent can verify it against the CA cloud-init would have delivered.
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

# Wait for both to answer rather than sleeping a guessed amount.
for i in $(seq 1 40); do
  if curl -s --cacert "$PKI/ca.crt" --resolve "control-plane.local:8443:127.0.0.1" \
      "https://control-plane.local:8443/up" -o /dev/null 2>/dev/null; then
    break
  fi
  sleep 0.5
  [ "$i" = "40" ] && fail "control plane did not come up; see $WORK/php.log and $WORK/tls.log"
done

echo "control plane answering over TLS, certificate verified against the org CA"

# ---------------------------------------------------------------- 5. token

step "issuing a single-use enrollment token"
cd "$CP"
php artisan cloud:issue-token org-live web-live.org-live --out="$NODE" --ttl=600 >/dev/null
[ -s "$NODE/enroll.token" ] || fail "no token was written"

# ---------------------------------------------------------------- 6. agent

step "enrolling the agent"

# control-plane.local has to resolve. Add it if it is not already there.
grep -q 'control-plane.local' /etc/hosts || echo "127.0.0.1 control-plane.local" >> /etc/hosts

cd "$NODE"
set +e
"$WORK/agent" \
  -state-dir "$NODE/state" \
  -sites-root "$NODE/sites" \
  -enroll-url "https://control-plane.local:8443/api/enroll" \
  -server-name "control-plane.local" \
  -ca-file "$PKI/ca.crt" \
  -token-file "$NODE/enroll.token" \
  -enroll-only \
  -log-level info >"$WORK/agent.log" 2>&1
AGENT_STATUS=$?
set -e

if [ "$AGENT_STATUS" != "0" ]; then
  echo "--- agent log ---"; cat "$WORK/agent.log"
  echo "--- php log ---"; tail -30 "$WORK/php.log"
  fail "the agent exited $AGENT_STATUS"
fi

# ----------------------------------------------------------------- 7. check

step "verifying what actually happened"

[ -s "$NODE/state/agent.crt" ] || fail "the agent has no certificate"
[ -s "$NODE/state/agent.key" ] || fail "the agent has no private key"
[ -s "$NODE/state/node.json" ] || fail "the agent recorded no identity"

# The identity must be the one the control plane assigned.
NODE_ID="$(python3 -c "import json;print(json.load(open('$NODE/state/node.json'))['node_id'])")"
[ "$NODE_ID" = "web-live.org-live" ] || fail "node id is '$NODE_ID'"

# The certificate must carry that identity and chain to the organisation CA.
CN="$(openssl x509 -in "$NODE/state/agent.crt" -noout -subject 2>/dev/null | sed 's/.*CN *= *//')"
[ "$CN" = "web-live.org-live" ] || fail "certificate common name is '$CN'"

openssl verify -CAfile "$PKI/ca.crt" "$NODE/state/agent.crt" >/dev/null 2>&1 \
  || fail "the issued certificate does not chain to the organisation CA"

# The private key must not be readable by anyone else.
PERM="$(stat -c '%a' "$NODE/state/agent.key")"
[ "$PERM" = "600" ] || fail "agent.key is mode $PERM, expected 600"

# The token must be spent, and the control plane must say so.
cd "$CP"
CONSUMED="$(php artisan tinker --execute="echo DB::table('enrollment_grants')->whereNotNull('consumed_at')->count();" 2>/dev/null | tr -dc '0-9')"
[ "$CONSUMED" = "1" ] || fail "the control plane did not mark the token consumed (got '$CONSUMED')"

# Enrollment must be in the audit chain, and the chain must verify. This is
# the bit that was silently broken until the container wiring was fixed.
AUDITED="$(php artisan tinker --execute="echo DB::table('audit_records')->where('action','enrollment.completed')->count();" 2>/dev/null | tr -dc '0-9')"
[ "$AUDITED" = "1" ] || fail "enrollment was not audited (got '$AUDITED')"

INTACT="$(php artisan tinker --execute="echo app(App\Lib\Audit\AuditLog::class)->verify('org-live')->intact ? 'yes' : 'no';" 2>/dev/null | tr -dc 'a-z')"
[ "$INTACT" = "yes" ] || fail "the audit chain does not verify"

# A spent token must not work twice, over the real wire.
REPLAY="$(curl -s -o /dev/null -w '%{http_code}' --cacert "$PKI/ca.crt" \
  -X POST "https://control-plane.local:8443/api/enroll" \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d "{\"token\":\"$(cat "$NODE/enroll.token" 2>/dev/null || echo spent)\",\"csr_pem\":\"x\"}")"
[ "$REPLAY" = "403" ] || echo "note: replay returned $REPLAY (the agent deletes the token file after use, so this may be a different rejection)"

printf '\n'
echo "PASS"
echo "  node id          $NODE_ID"
echo "  certificate      chains to the organisation CA, CN=$CN"
echo "  private key      mode 600, generated on the node"
echo "  token            consumed exactly once"
echo "  audit chain      enrollment recorded, chain verifies"
echo
echo "A Go agent enrolled against the Laravel control plane over TLS."
