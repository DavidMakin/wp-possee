# wp-possee

Self-hosted WordPress with POSSE syndication to Mastodon and Bluesky, with backfeed via Brid.gy. Traffic is routed via a Cloudflare Tunnel — no open ports required.

**Publish on Own Site, Syndicate Elsewhere** — posts go out to Mastodon and Bluesky. Likes and replies come back as Webmentions and appear on your posts.

## Stack

| Component | Role |
|---|---|
| WordPress (PHP-FPM) | CMS |
| Nginx | Reverse proxy to PHP-FPM, fastcgi cache, gzip |
| Cloudflared | Cloudflare Tunnel — exposes nginx without open ports |
| ActivityPub plugin | Mastodon federation |
| Micropub plugin | Accepts Micropub posts (e.g. from OwnYourSwarm) |
| Webmention plugin | Receives backfeed reactions |
| Semantic Linkbacks | Displays webmentions as likes/comments |
| Syndication Links | Syndication link display + Bridgy publishing |
| Simple Location | Geo/checkin support with map display |
| Brid.gy | Polls Mastodon + Bluesky, sends Webmentions |

## Prerequisites

- Docker + Docker Compose
- An existing MariaDB container on a Docker network named `db`
- A Cloudflare Tunnel (create one at [one.dash.cloudflare.com](https://one.dash.cloudflare.com) → Zero Trust → Networks → Tunnels)

## Setup

### 1. Database

In your existing MariaDB instance:

```sql
CREATE DATABASE wordpress;
CREATE USER 'wordpress'@'%' IDENTIFIED BY 'your_password';
GRANT ALL PRIVILEGES ON wordpress.* TO 'wordpress'@'%';
FLUSH PRIVILEGES;
```

### 2. Configure

```bash
cp .env.example .env
nano .env  # set DOMAIN, DB credentials, tunnel token, admin credentials

cp cloudflared/config.yml.example cloudflared/config.yml
nano cloudflared/config.yml  # set your tunnel ID and hostname
```

### 3. Start

```bash
bash scripts/init.sh
bash scripts/install-plugins.sh
```

## Post-install configuration

### ActivityPub (Mastodon)

1. wp-admin → Settings → ActivityPub
2. Your Mastodon-compatible handle will be `@you@yourdomain.com`
3. Follow yourself from any Mastodon instance to test

### Micropub + OwnYourSwarm

1. wp-admin → Settings → Micropub — ensure endpoint is enabled
2. Add your site to [OwnYourSwarm](https://ownyourswarm.p3k.io) via IndieAuth

### Brid.gy (backfeed)

1. Visit [brid.gy](https://brid.gy)
2. Sign in with Mastodon and/or Bluesky — authorize Brid.gy
3. Reactions arrive as Webmentions automatically

### Simple Location (maps)

1. wp-admin → Settings → Location
2. Configure a map provider (Mapbox recommended — free tier sufficient)

## Maintenance

```bash
docker compose pull && docker compose up -d
```

## File structure

```
.
├── docker-compose.yml
├── .env.example
├── cloudflared/
│   └── config.yml.example    # copy to config.yml and fill in tunnel ID
├── mu-plugins/
│   ├── comments.php          # comment & webmention handling
│   ├── loopback-fix.php      # fixes WordPress loopback requests inside Docker
│   ├── microformats.php      # h-entry, IndieWeb microformats, checkin handling
│   └── theme-styles.php      # custom styles
├── nginx/
│   └── nginx.conf            # fastcgi cache + gzip
├── php/
│   └── uploads.ini           # upload size limits
└── scripts/
    ├── init.sh               # start the stack
    ├── install-plugins.sh    # WordPress install + plugin setup
    └── deploy.sh             # rsync to remote host + restart
```
