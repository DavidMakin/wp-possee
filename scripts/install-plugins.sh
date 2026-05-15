#!/bin/bash
set -e

if [ ! -f .env ]; then
  echo "Error: .env not found."
  exit 1
fi

source .env

WP="docker compose --profile tools run --rm --no-deps wpcli wp --allow-root --path=/var/www/html"

echo "Waiting for WordPress to be ready..."
until $WP core is-installed 2>/dev/null; do
  sleep 5
done

echo "Installing WordPress core..."
$WP core install \
  --url="https://${DOMAIN}" \
  --title="My Site" \
  --admin_user="${WP_ADMIN_USER}" \
  --admin_password="${WP_ADMIN_PASSWORD}" \
  --admin_email="${WP_ADMIN_EMAIL}" \
  --skip-email

echo "Setting permalinks to post name..."
$WP rewrite structure '/%postname%/' --hard

echo "Installing theme..."
$WP theme install blocksy --activate

echo "Installing and activating plugins..."

# IndieWeb stack
$WP plugin install indieweb --activate
$WP plugin install indieauth --activate
$WP plugin install micropub --activate
$WP plugin install webmention --activate
$WP plugin install syndication-links --activate
$WP plugin install simple-location --activate

# Post types and webmention display
$WP plugin install https://github.com/dshanske/indieweb-post-kinds/archive/refs/heads/trunk.zip --activate
$WP plugin install https://github.com/pfefferle/semantic-linkbacks/archive/refs/heads/master.zip --activate

# ActivityPub (Mastodon federation) — installed but not activated by default;
# enable manually after configuring your actor URL.
$WP plugin install activitypub

# Theme companion
$WP plugin install blocksy-companion --activate

# Custom post types UI
$WP plugin install custom-post-type-ui --activate

# GDPR-friendly local Google Fonts
$WP plugin install local-google-fonts --activate

# OpenGraph meta tags
$WP plugin install opengraph --activate

# Performance / caching
$WP plugin install wp-optimize --activate

# Google Site Kit (analytics) — activate manually after connecting your account
$WP plugin install google-site-kit

echo ""
echo "Plugins installed."
echo ""
echo "Next steps:"
echo "  1. Go to https://${DOMAIN}/wp-admin"
echo "  2. ActivityPub: activate plugin, then Settings → ActivityPub — note your Actor URL"
echo "  3. Google Site Kit: activate plugin, then Settings → Site Kit — connect your account"
echo "  4. Simple Location: Settings → Location — configure your map provider"
echo "  5. Syndication Links: Settings → Syndication Links — add your social profiles"
echo "  6. Brid.gy backfeed:"
echo "     - Visit https://brid.gy and sign in with Mastodon + Bluesky"
echo "     - Authorize Brid.gy to poll your accounts"
echo "     - Reactions will arrive as Webmentions automatically"
