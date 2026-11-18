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

npx wp-env run cli wp eval-file wp-content/plugins/nanopost-email-check/tests/integration.php
