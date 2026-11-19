#!/usr/bin/env bash
# Runs the integration tests inside wp-env, with Mailpit as a real SMTP server.
#
# Needs a running wp-env (npm run env:start). Starts a Mailpit container on the wp-env
# Docker network as "mailpit" if it is not there yet. The tests send through it and read
# the result from its API.
set -euo pipefail

cd "$(dirname "$0")/.."

MAILPIT_IMAGE="axllent/mailpit:v1.33.0"
MAILPIT_NAME="nanopost-email-check-mailpit"

network="$(docker network ls --format '{{.Name}}' | grep -E '^wp-env-nanopost-email-check-[0-9a-f]+_default$' | head -1 || true)"
if [ -z "$network" ]; then
  echo "No wp-env network found. Run: npm run env:start" >&2
  exit 1
fi

if ! docker ps --format '{{.Names}}' | grep -qx "$MAILPIT_NAME"; then
  docker rm -f "$MAILPIT_NAME" >/dev/null 2>&1 || true
  docker run -d --name "$MAILPIT_NAME" --network "$network" --network-alias mailpit "$MAILPIT_IMAGE" >/dev/null
  sleep 2
elif ! docker inspect -f '{{json .NetworkSettings.Networks}}' "$MAILPIT_NAME" | grep -q "\"$network\""; then
  docker network connect --alias mailpit "$network" "$MAILPIT_NAME"
fi

# Webhook receiver: a small HTTP server that records requests (tests/webhook-receiver.py).
# Recreated on every run, so it always serves the current script.
RECEIVER_IMAGE="python:3.13.17-alpine"
RECEIVER_NAME="nanopost-email-check-webhook"
docker rm -f "$RECEIVER_NAME" >/dev/null 2>&1 || true
docker run -d --name "$RECEIVER_NAME" --network "$network" --network-alias webhook \
  -v "$PWD/tests/webhook-receiver.py:/srv/receiver.py:ro" \
  "$RECEIVER_IMAGE" python -I /srv/receiver.py >/dev/null
for _ in $(seq 1 20); do
  docker exec "$RECEIVER_NAME" python -c "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8080/_requests')" >/dev/null 2>&1 && break
  sleep 0.5
done

# On multisite, the tests need a second site. Creating one adds tables (DDL), which would
# end the tests' database transaction, so it is created here, before the run.
if npx wp-env run cli wp eval 'echo is_multisite() ? "yes" : "no";' 2>/dev/null | grep -qx yes; then
  if [ "$(npx wp-env run cli wp site list --field=blog_id 2>/dev/null | grep -c '^[0-9]')" -lt 2 ]; then
    npx wp-env run cli wp site create --slug=second --title=Second --quiet
  fi
fi

npx wp-env run cli wp eval-file wp-content/plugins/nanopost-email-check/tests/integration.php
