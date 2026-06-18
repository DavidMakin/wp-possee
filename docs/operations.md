# Operations

Deployment, WP-CLI, cache layers, verification, and SSH.

## Deploy workflow

1. Edit locally under `mu-plugins/`
2. `scp mu-plugins/foo.php homeip:/storage/Docker/wp-possee/mu-plugins/`
3. **PHP changes** — restart wordpress container: `ssh homeip docker compose -f /storage/Docker/wp-possee/docker-compose.yml up -d --force-recreate wordpress`
4. **Clear nginx cache**: `ssh homeip docker compose -f /storage/Docker/wp-possee/docker-compose.yml up -d --force-recreate nginx`
5. **Purge WP-Optimize disk cache** (24h TTL, survives nginx restarts): `ssh homeip rm -rf $(docker volume inspect wp-possee_wp_data --format '{{.Mountpoint}}')/wp-content/cache/wpo-cache/`
6. Wait ~5s for OPcache revalidation (`opcache.revalidate_freq = 0` means near-immediate, but network/TCP overhead adds a few seconds)

Remote shell is **fish** — use `ssh homeip bash << 'EOF' ... EOF` for multi-line commands.

## Critical: WP-CLI invocation

WordPress container has no shell. Two methods:

### Method A: docker compose (simpler)

The compose file has a `wpcli` service under `profiles: [tools]`. Run from server:

```bash
ssh homeip bash << 'EOF'
source /storage/Docker/wp-possee/.env
cd /storage/Docker/wp-possee
docker compose run --rm wpcli wp <command>
EOF
```

This handles volumes, env vars (`MYSQL_PASSWORD` etc.), networks (both `wp-possee` and `db`), and user (65532) automatically. Prefer this for most operations.

**Note**: `docker compose run --rm` recreates the container each time (restarts the wordpress service too via docker-compose's lifecycle). This is fine — the transient container exits after the command runs.

### Method B: standalone docker run (legacy)

```bash
docker run --rm \
  --user 65532 \
  -v wp-possee_wp_data:/var/www/html \
  -v /storage/Docker/wp-possee/mu-plugins:/var/www/html/wp-content/mu-plugins \
  --network db \
  -e WORDPRESS_DB_HOST=mariadb \
  -e WORDPRESS_DB_USER=wordpress \
  -e WORDPRESS_DB_PASSWORD=${MYSQL_PASSWORD} \
  -e WORDPRESS_DB_NAME=wordpress \
  wordpress:cli-php8.3 wp --allow-root <command>
```

**`--user 65532` mandatory** — uploads dir owned by that UID. Omitting breaks `media_sideload_image` and any file write.

**mu-plugins bind mount mandatory** — mu-plugins at `/storage/Docker/wp-possee/mu-plugins/` on host, not in named volume.

### WP-CLI quoting over SSH

**Never** embed multi-line PHP in `ssh homeip "bash -c '...'"`. Quoting breaks silently. Always: write PHP to local file → `scp` to server → `wp eval-file /tmp/file.php`. No exceptions beyond single short expressions.

### Docker compose note

`docker compose run --rm wpcli` may trigger a container recreate of wordpress (since wpcli `depends_on: wordpress`). This is harmless — the wordpress container restarts and the wpcli command executes against the restarted instance. If you see output like `Container wp-possee-wordpress-1 Recreated`, this is expected.

### PHP image version note

The compose wpcli service uses `wordpress:cli-php8.5`. The standalone docker run example above uses `wordpress:cli-php8.3`. Either works; prefer the compose version for consistency.

## Cache layers (3 independent caches)

| Cache | Reset method | Notes |
|---|---|---|---|
| OPcache | Recomputed ~5s after file change | `revalidate_freq = 0`, `validate_timestamps = 1` → checks mtime every request |
| nginx fastcgi | `docker compose up -d --force-recreate nginx` | Also clears on container recreate. Not persistent across restarts. |
| WP-Optimize disk cache | `rm -rf .../cache/wpo-cache/` on `wp-possee_wp_data` volume | 24h TTL. Survives nginx/wordpress restarts. Must be purged separately. |
| Cloudflare CDN | Cloudflare dashboard → Cache → Purge | HTML has `s-maxage=3600` (set by WP-Optimize). Cloudflare serves stale cached HTML for up to 1h after update. No API credentials configured — manual purge required. |

**Never skip clearing all three after a PHP change** — they are independent. A stale cache in any layer can mask your output. If Cloudflare shows `cf-cache-status: HIT` after a test, add a cache-busting query param (`?v=N`) or purge at the Cloudflare dashboard.

## Verifying PHP changes (bypassing Cloudflare)

The public domain goes through Cloudflare (cf-cache-status: HIT shows stale content). **Never use the public URL to verify PHP output.**

Use the internal Docker network to bypass everything:

```bash
ssh homeip bash << 'EOF'
docker run --rm \
  --network wp-possee_wp-possee \
  alpine:latest \
  wget -q -O - --header="Host: blog.sleep-er.co.uk" "http://nginx/your/path/" 2>&1
EOF
```

This bypasses Cloudflare, nginx fastcgi cache (reset on recreate), and WP-Optimize disk cache (cleared separately).

## Verifying nginx reachability from host

To check if nginx is running and reachable (bypassing Cloudflare from the host machine):

```bash
ssh homeip curl -s --resolve "blog.sleep-er.co.uk:80:172.28.0.4" "http://blog.sleep-er.co.uk/your/path/"
```

Get nginx IP from: `docker inspect wp-possee-nginx-1 | grep '"IPAddress"'`

## Docker networks

| Network | Used by | Purpose |
|---|---|---|
| `db` | WordPress container, WP-CLI | Reach MariaDB |
| `wp-possee_wp-possee` | WordPress, nginx, cloudflared | Internal service communication |

- **WP-CLI containers** need `--network db` (need to reach MariaDB at hostname `mariadb`)
- **Alpine verification containers** need `--network wp-possee_wp-possee` (need to reach nginx at hostname `nginx`)

WordPress container is on both networks? Actually the WordPress container is on `db` network. The verification Alpine container goes on `wp-possee_wp-possee` to reach nginx. WP-CLI throwaway containers need `--network db`.

## Inspecting files inside container

```bash
docker run --rm -v wp-possee_wp_data:/data alpine:latest cat /data/wp-content/...
docker run --rm -v wp-possee_wp_data:/data alpine:latest grep -rn "pattern" /data/wp-content/...
```

Alpine `grep` has no `--include`. Use `-r` with path instead.

## Backups

```bash
ssh homeip docker exec mariadb mysqldump -u wordpress -p"${MYSQL_PASSWORD}" wordpress | gzip > /storage/Docker/wp-possee/backups/wordpress-$(date +%Y%m%d-%H%M%S).sql.gz
```
