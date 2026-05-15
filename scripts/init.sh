#!/bin/bash
set -e

if [ ! -f .env ]; then
  echo "Error: .env not found. Copy .env.example and fill in values."
  exit 1
fi

set -a
source .env
set +a

if [ -z "$DOMAIN" ]; then
  echo "Error: DOMAIN must be set in .env"
  exit 1
fi

if [ ! -f cloudflared/config.yml ]; then
  echo "Error: cloudflared/config.yml not found. Copy cloudflared/config.yml.example and fill in your tunnel ID."
  exit 1
fi

envsubst '${DOMAIN}' < nginx/nginx.conf.template > nginx/nginx.conf

docker compose up -d

mkdir -p backups

echo ""
echo "Stack is up."
echo "Cloudflare tunnel will route traffic to nginx automatically."
echo "Run: bash scripts/install-plugins.sh"
