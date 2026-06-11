# External Services

n8n, third-party plugins, and related operational knowledge.

## Hardcover book backfill: Bearer prefix double-prepend

Hardcover API keys from https://hardcover.app/account/api are **full Authorization header values** (starting with `Bearer `). When using them in PHP `wp_remote_post`, strip the prefix before passing to the script, or check if it's already present:
```php
$api_key = preg_replace( '/^Bearer\s+/i', '', $raw_key );
```
The backfill script (`scripts/backfill-books.php`) handles this via file-fallback: if no CLI arg or env var, reads `/tmp/hc_key.txt` and strips the prefix.

## n8n API key format

The n8n REST API (`/api/v1/`) requires the **full JWT token** in the `X-N8N-API-KEY` header, not the short key ID. The correct token is the long `eyJhbGci...` JWT stored in the `user_api_keys` table (`apiKey` column). The short `1QbCB7CcFeC2IM8C` string is the key ID, not the bearer value.

### n8n workflow inspection without API (sqlite)

If the n8n REST API is unresponsive, query the sqlite DB directly:

```bash
docker cp n8n:/home/node/.n8n/database.sqlite /tmp/n8n-check.sqlite
scp homeip:/tmp/n8n-check.sqlite /tmp/n8n-check.sqlite
python3 -c "
import sqlite3, json
conn = sqlite3.connect('/tmp/n8n-check.sqlite')
rows = conn.execute('SELECT id, name, active FROM workflow_entity').fetchall()
for r in rows: print(r)
"
```

`active: 1` = workflow enabled. `better-sqlite3` not available inside the container — copy out and use Python.

### Book post meta: patching missing fields via WP-CLI

When the n8n Hardcover→WordPress workflow creates a book post but nodes were out of date (missing series/cover/slug), patch meta directly:

```bash
WP="docker run --rm --user 65532 -v wp-possee_wp_data:/var/www/html -v /storage/Docker/wp-possee/mu-plugins:/var/www/html/wp-content/mu-plugins --network db -e WORDPRESS_DB_HOST=mariadb -e WORDPRESS_DB_USER=wordpress -e WORDPRESS_DB_PASSWORD=${MYSQL_PASSWORD} -e WORDPRESS_DB_NAME=wordpress wordpress:cli-php8.3 wp --allow-root"

$WP post meta update <POST_ID> mf2_book-series "Series Name"
$WP post meta update <POST_ID> mf2_book-series-position "2"
$WP post meta update <POST_ID> mf2_hardcover-cover "https://assets.hardcover.app/..."
$WP post meta update <POST_ID> mf2_hardcover-slug "book-slug"
$WP post meta update <POST_ID> mf2_finished-at "YYYY-MM-DD"
```

After patching: clear WP-Optimize disk cache and recreate nginx.

**To verify active workflow nodes have the right fields**, inspect sqlite:

```python
import sqlite3, json
conn = sqlite3.connect('/tmp/n8n-check.sqlite')
row = conn.execute('SELECT nodes FROM workflow_entity WHERE id="hardcover-to-wordpress"').fetchone()
nodes = json.loads(row[0])
for n in nodes:
    params = json.dumps(n.get('parameters', {}))
    if any(k in params.lower() for k in ['series', 'slug', 'graphql']):
        print(n['name'], '| series:', 'series' in params.lower(), '| slug:', 'slug' in params.lower())
```

The merged workflow (`Hardcover → WordPress (finished + reading)` in n8n) handles all three statuses (reading/want-to-read/finished) in a single pass via `status_id: { _in: [1, 2, 3] }` and dynamic `read-status` in JS. The `possee_micropub_book_deduplicate` filter (microformats.php) updates existing posts when the same ISBN arrives again. Old separate "currently reading" and "bulk import" workflows have been deleted.

## Plugin notes

| Plugin | Note |
|---|---|
| `activitypub` | Installed but inactive — activate after configuring actor URL |
| `syndication-links` | `get_syndication_links($post_id, $args)` returns HTML or empty string |
| `simple-location` | Hooks `the_content` at priority 11 (map) and 12 (location text); `Loc_Config::map_provider()` renders static maps |
| `micropub` | Filter `pre_insert_micropub_post` for post manipulation before insert |
| `google-site-kit` | Installed but inactive — requires manual account connection |
| `semantic-linkbacks` | `get_linkbacks()` builds meta_query with `type__not_in` for mentions |
| `webmention` | `class-comment-walker.php` overwrites `type__not_in` at priority 10; sender hooks `publish_post` |
