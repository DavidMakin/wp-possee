# External Services

n8n, third-party plugins, and related operational knowledge.

## Hardcover book backfill: Bearer prefix double-prepend

Hardcover API keys from https://hardcover.app/account/api are **full Authorization header values** (starting with `Bearer `).

### Hardcover GraphQL: `cached_tags` is a custom scalar

The `cached_tags(path: "Genre")` field on the `book` type is a **custom scalar**, not an object type. Attempting a subselection (`{ tag category }`) causes a validation error:

```
"unexpected subselection set for non-object field"
```

The resolver still returns objects with `tag`, `category`, `count`, `tagSlug`, `categorySlug`, and `spoilerRatio` fields — but GraphQL schema validation rejects the subselection. **Omit the subselection**:

```graphql
# WRONG — validation error:
cached_tags(path: "Genre") { tag category }

# CORRECT — works, returns same structured data:
cached_tags(path: "Genre")
``` When using them in PHP `wp_remote_post`, strip the prefix before passing to the script, or check if it's already present:
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

### n8n sqlite: READONLY crash after docker cp

`docker cp` of a modified database.sqlite into a stopped n8n container leaves the file owned by `root:root`. On restart, n8n (runs as UID 1000) crashes with `SQLITE_READONLY: attempt to write a readonly database`. Fix by chowning the volume file directly (bypassing docker cp):

```bash
docker stop n8n
cp /tmp/modified.sqlite /var/lib/docker/volumes/n8n_n8n_data/_data/database.sqlite
chown 1000:1000 /var/lib/docker/volumes/n8n_n8n_data/_data/database.sqlite
chmod 644 /var/lib/docker/volumes/n8n_n8n_data/_data/database.sqlite
docker start n8n
```

The n8n container was started standalone (`docker run`), not via docker-compose. The volume name is `n8n_n8n_data`, mounted at `/home/node/.n8n`.

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

### n8n `$workflow.staticData`: production-only

`$workflow.staticData` is only available in **production execution mode** (scheduled triggers). In manual/test mode it returns `undefined`. Both read and write must guard against this:

```javascript
// Read — optional chaining + fallback
const lastChecked = $workflow.staticData?.lastChecked;
const result = lastChecked || fallbackValue;

// Write — guard before setting
if ($workflow.staticData) {
  $workflow.staticData.lastChecked = now;
}
```

Without the guard, manual executions crash with `Cannot set properties of undefined (setting 'lastChecked')`.

### n8n "Published" tag: direct SQL bypasses UI tracking

n8n's "Published" tag (shown next to a workflow name in the editor list) tracks whether the workflow was last **saved through the UI**. Modifying workflow nodes, `active` flag, or `versionCounter` directly in the SQLite `workflow_entity` table bypasses this — the workflow runs fine but the UI won't show "Published". The tag correlates with entries in `workflow_publish_history` (events: `activated`, `deactivated`), which direct DB writes don't create.

**Fix**: Open the workflow in the n8n editor and click Save once. That writes the publish history record and the tag appears.

### n8n `$getWorkflowStaticData` unreliable for persisted state

n8n's `$getWorkflowStaticData('global')` does **not** reliably persist between executions in all deployment setups (Docker, SQLite backend). Once written, it may never update or may revert on container restart. This caused the "Hardcover → WordPress (finished books)" workflow to re-fetch the same 8 books every hour because `lastChecked` was stuck at `2026-05-18T21:53:04Z`.

**Fix**: Delegate persistence to WordPress via REST endpoint:
1. Register `GET/POST /wp-json/possee/v1/last-checked` in `microformats.php`
2. `GET` returns `possee_n8n_last_checked` WP option
3. If option empty, fall back to `most_recent_book_post_date − 24h` (self-healing on restart)
4. `POST` writes new timestamp (optional — GET fallback is sufficient)
5. n8n "Get last checked time" code node fetches from WP endpoint instead of `$getWorkflowStaticData`
6. n8n "Update last checked time" becomes no-op (GET endpoint self-heals)

**Secondary defence**: `possee_micropub_book_deduplicate` (hooked `pre_insert_micropub_post`) catches any remaining duplicates by title+author normalisation.

**Deploy**: update `possee_n8n_last_checked` via `wp option set possee_n8n_last_checked <ISO-datetime>`, restart WordPress.

### n8n task runner sandbox: no `fetch`, no `require`

n8n's JS Task Runner sandbox (n8n 2.x+ with task runner mode) blocks `fetch()` and `require('https')` in Code nodes. HTTP requests from a Code node are **not possible** in this mode. The only way to make HTTP requests is with an HTTP Request node.

**Fix**: Replace the Code node with an HTTP Request node (`n8n-nodes-base.httpRequest`). Set `method: GET`, `url: ...`, and the output `$json` will contain the parsed response body — no code needed.

### Internal Docker network: use http, not https for nginx

nginx inside the Docker network only listens on **port 80**, not 443. SSL terminates at the Cloudflare tunnel (external). Any container-to-container HTTP request targeting the blog must use `http://nginx/` with a `Host` header so WordPress resolves the correct vhost:

| Component | URL format |
|---|---|
| External/cloudflared | `https://www.sleep-er.co.uk/...` |
| Internal (Docker) | `http://nginx/...` with `Host: www.sleep-er.co.uk` |

This applies to n8n HTTP request nodes targeting the WordPress Micropub endpoint. Using `https://www.sleep-er.co.uk` from within the Docker network resolves to `172.28.0.4:443` (the nginx internal IP) which refuses the connection because no SSL listener exists on that interface.

The loopback-fix mu-plugin (`mu-plugins/loopback-fix.php`) does the same rewrite for WordPress-internal HTTP calls.

## WordPress MCP Server

Plugin: `mcp` (mcp-wp/mcp-server), installed from the nightly build ZIP (not the GitHub source archive, which ships without `vendor/`):

```bash
docker compose run --rm wpcli wp plugin install --activate https://mcp-wp.github.io/mcp-server/mcp.zip
```

**Endpoint:** `https://www.sleep-er.co.uk/wp-json/mcp/v1/mcp`  
**Auth:** HTTP Basic — user `adminman`, application password stored in `.opencode/opencode.jsonc`.  
**Protocol:** MCP Streamable HTTP. Clients must send `initialize` first; the response `Mcp-Session-Id` header must be carried on subsequent requests.

### Patched file: `RestApi.php`

The nightly build throws `Invalid type: float` on `initialize` because `sanitize_type()` has no mapping for `float`. A patched copy lives at `patches/mcp-RestApi.php` (added `'float' => 'number'` to the mapping). The patch lives only in the Docker volume and will be overwritten on plugin update. Reapply after any update:

```bash
ssh homeip docker cp /storage/Docker/wp-possee/patches/mcp-RestApi.php \
  wp-possee-wordpress-1:/var/www/html/wp-content/plugins/mcp/src/MCP/Servers/WordPress/Tools/RestApi.php
ssh homeip docker compose -f /storage/Docker/wp-possee/docker-compose.yml restart wordpress
```

*(The patch file on the server is at `/storage/Docker/wp-possee/patches/mcp-RestApi.php` — copy it there after a `git pull` if needed.)*

## Plugin notes

| Plugin | Note |
|---|---|
| `mcp` | MCP server — nightly build only; `patches/mcp-RestApi.php` must be reapplied after updates (see above) |
| `activitypub` | Installed but inactive — activate after configuring actor URL |
| `syndication-links` | `get_syndication_links($post_id, $args)` returns HTML or empty string |
| `simple-location` | Hooks `the_content` at priority 11 (map) and 12 (location text); `Loc_Config::map_provider()` renders static maps |
| `micropub` | Filter `pre_insert_micropub_post` for post manipulation before insert |
| `google-site-kit` | Installed but inactive — requires manual account connection |
| `semantic-linkbacks` | `get_linkbacks()` builds meta_query with `type__not_in` for mentions |
| `webmention` | `class-comment-walker.php` overwrites `type__not_in` at priority 10; sender hooks `publish_post` |
