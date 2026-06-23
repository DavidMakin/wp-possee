#!/bin/bash
set -e

REMOTE_HOST="homeip"
REMOTE_PATH="/storage/Docker/wp-possee"

ssh "${REMOTE_HOST}" "mkdir -p ${REMOTE_PATH}"
rsync -av --exclude='.env' ./ "${REMOTE_HOST}:${REMOTE_PATH}/"

ssh "${REMOTE_HOST}" bash << 'ENDSSH'
  # DHI images require login to dhi.io
  echo 'NOTE: If pull fails, run: docker login dhi.io on the server first'

  cd /storage/Docker/wp-possee
  set -a; source .env; set +a
  envsubst '${DOMAIN}' < nginx/nginx.conf.template > nginx/nginx.conf

  # Fix volume ownership for DHI nonroot user (uid 65532)
  VOLUME_PATH=$(docker volume inspect wp-possee_wp_data --format '{{.Mountpoint}}' 2>/dev/null || echo '')
  if [ -n "$VOLUME_PATH" ]; then
    echo "Fixing wp_data volume ownership for DHI (uid 65532)..."
    chown -R 65532:65532 "$VOLUME_PATH"
  fi

  docker compose pull && docker compose up -d
ENDSSH
