#!/bin/sh
# The gateway keeps team CAs in memory only, so every start is followed by a sync,
# or it would refuse every node. The gateway posts events to the agent name, which
# serves the team CA's certificate, so it trusts that CA alone for this.
set -e
CERTS=/var/lib/cloud-manager/certs
SSL_CERT_FILE=$CERTS/ca.crt gateway \
  -agent-addr 0.0.0.0:9443 -api-addr 0.0.0.0:9444 \
  -cert-file $CERTS/server.crt -key-file $CERTS/server.key \
  -token-file $CERTS/gateway.token \
  -events-url "https://$AGENT_HOST/api/gateway/events" &
pid=$!
trap 'kill $pid' TERM INT
sleep 2
(cd /srv/cloud-manager/control-plane && HOME=/tmp php artisan gateway:sync-organisations) || true
wait $pid
