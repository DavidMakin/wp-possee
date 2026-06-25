# Bridgy Incidents & Recovery

Bridgy bridges Mastodon/Bluesky → Webmention backfeed. These are known failure modes and their fixes.

## "No webmention targets" for likes/replies

**Root cause**: Bridgy crawls the blog periodically and may hit a newly-published post **before** the 90-second delayed cron fires and adds the Bluesky `u-syndication` link. Bridgy stores the post without a Bluesky mapping. When a like/reply arrives, it reports "No webmention targets" and sends nothing.

**Diagnosis**: Check `https://brid.gy/bluesky/<handle>` → Responses section. A like entry with "No webmention targets". The Bridgy like source page has only one `u-like-of` (Bluesky URL) instead of two.

**Permanent fix**: `possee_bridgy_delayed_handler` calls `possee_ping_bridgy_discover()` after `syn_syndication` fires. This POSTs to `https://brid.gy/discover` causing Bridgy to re-crawl and update the mapping.

**Source key**: Stored in WP option `possee_bridgy_bluesky_source_key`. If you disconnect/reconnect the Bluesky account on Bridgy, get the new value from `view-source:https://brid.gy/bluesky/sleep-er.bsky.social` (search `discover-source-key`) and update: `wp option set possee_bridgy_bluesky_source_key '<value>'`

**One-off recovery** (for posts published before the fix):

```bash
# 1. Re-crawl via Bridgy discover
SOURCE_KEY=$(wp option get possee_bridgy_bluesky_source_key)
curl -X POST https://brid.gy/discover \
  -d "url=https://www.sleep-er.co.uk/YOUR-POST-URL/&source_key=${SOURCE_KEY}"

# 2. Wait ~15s, then get liker DIDs from Bluesky API
curl "https://public.api.bsky.app/xrpc/app.bsky.feed.getLikes?uri=at://did:plc:eemo37qp56jdqiier5krh537/app.bsky.feed.post/POST_RKEY"

# 3. Send webmention for each liker (double-encode AT URI and liker DID)
curl -X POST https://www.sleep-er.co.uk/wp-json/webmention/1.0/endpoint \
  --data-urlencode "source=https://brid.gy/like/bluesky/AUTHOR_DID/DOUBLE_ENCODED_AT_URI/DOUBLE_ENCODED_LIKER_DID" \
  --data-urlencode "target=https://www.sleep-er.co.uk/YOUR-POST-URL/"

# 4. Approve the resulting pending comments
wp comment approve <ID>
```

nginx already bypasses cache for Bridgy (`if ($http_user_agent ~* "bridgy|brid\.gy") { set $skip_cache 1; }`). Only WP-Optimize disk cache can serve stale content to Bridgy.

## Bridgy Fed: own POSSE'd post bouncing back as self-comment

**Root cause**: Bridgy Fed sends a webmention back for your own syndicated Bluesky post. Source URL pattern: `https://brid.gy/post/bluesky/did:plc:eemo37qp56jdqiier5krh537/...` — your own DID.

**Fix**: `possee_spam_bsky_self_comments` in `comments.php` matches `brid.gy/post/bluesky/did:plc:eemo37qp56jdqiier5krh537/` in `webmention_source_url` meta and marks it spam.

**One-off recovery**: `wp comment delete <ID> --force`

## Syndication using short URL (/?p=ID) instead of pretty permalink

**Root cause**: `get_permalink()` returns `/?p=ID` for posts in `future`, `draft`, or `pending` status. Syndication Links calls `get_permalink()` before sending the webmention. If the post was published as scheduled or had no slug yet, the wrong URL is sent.

**Diagnosis**: Mastodon/Bluesky post reads "Title: https://www.sleep-er.co.uk/?p=293" instead of pretty permalink.

**Fix**: `possee_syndication_force_pretty_permalink` in `microformats.php` hooks `pre_syndication_links_webmention` (priority 5). It clones the post with `post_status = 'publish'` and a computed slug so `get_permalink()` returns the pretty URL. Original restored on `shutdown`.

**One-off recovery**: Resend via Bridgy with cache-busting param (`?v=N` on source URL), then update `mf2_syndication` meta.

## Bridgy reply/comment webmention never arrives

**Root cause**: Reply posted before Bridgy learned the AT-URI → blog URL mapping. Bridgy skips the reply and never re-processes it.

**Diagnosis**: Comment doesn't exist in WP at all (not spam, not pending). `brid.gy/bluesky/sleep-er.bsky.social/post/{RKEY}` returns 404.

**Fix**: Trigger discover to establish the mapping, then insert the comment manually:

```bash
# 1. Trigger discover
SOURCE_KEY=$(wp option get possee_bridgy_bluesky_source_key)
curl -X POST https://brid.gy/discover \
  -d "url=https://www.sleep-er.co.uk/YOUR-POST-URL/&source_key=${SOURCE_KEY}"

# 2. Insert comment manually via wp eval-file (write PHP locally, scp, run)
```

**comment_type must be 'comment'**: Using 'webmention' causes the comment to be excluded from the standard thread query — it won't render even if approved. Use `comment_type = 'comment'` with `protocol = 'webmention'` meta instead.

**After inserting**: Clear nginx fastcgi cache AND WP-Optimize disk cache — both must be cleared or the page still shows the old version.

## Bridgy Publish failure: "Couldn't find link to brid.gy/publish/bluesky"

**Root cause**: Syndication Links fires the Bridgy webmention synchronously during the Micropub HTTP request — before caches warm up. Bridgy fetches stale content, fails, and **caches the failure** keyed on source+target URL pair.

**Diagnosis**: Check `syndication_log` post meta. A `status: 400` entry for `webmention-bluesky-bridgy` with `"Couldn't find link to brid.gy/publish/bluesky"`.

**One-off recovery**: Send the webmention with a cache-busting query param — Bridgy treats it as a new source+target pair:

```bash
curl -X POST https://brid.gy/publish/webmention \
  -d "source=https://www.sleep-er.co.uk/YOUR-POST-URL/?v=2&target=https://brid.gy/publish/bluesky"
```

Then update `mf2_syndication` post meta to replace `https://brid.gy/publish/bluesky` with the returned Bluesky URL, and add a success entry to `syndication_log`.

**Permanent fix**: `microformats.php` intercepts `micropub_syndication` at priorities 1 and 99, uses `pre_http_request` to block the immediate Bridgy send, and schedules `possee_bridgy_delayed` cron for 90 seconds later. Do not remove this — it prevents the race condition.

## Custom post types (note/checkin/book) not syndicated when created in wp-admin

**Root cause**: `Syndication_Links` depends on WordPress's `do_pings` cron action to process syndication queues. `do_pings` is only scheduled by `_publish_post_hook`, which only registers for the native `post` type. When a note/checkin/book is created/edited in wp-admin with syndication targets, Syndication Links stores `_syndicate-to` meta at priority 10 on `save_post`, but `do_pings` never fires for custom types — the post is stuck in the queue indefinitely.

**Diagnosis**: Note created in wp-admin appears on site with no syndication links. Check meta: `wp post meta get <ID> _syndicate-to` returns the UID, but `syndication_link` meta is empty. Check Bridgy log: post is not mentioned.

**Permanent fix**: `possee_syndicate_save_post` hook in `microformats.php` (priority 20 on `save_post`): (1) only processes `note`, `checkin`, `book` types, (2) skips unless status is `publish` or `future`, (3) reads `_syndicate-to` meta that Syndication Links set at priority 10, (4) calls `do_action('syn_syndication', $post_id, $syndicate_to)` directly, bypassing the broken `do_pings` cron system.

**One-off recovery** (for posts published before the fix): `wp post list --post_type=note --field=ID | xargs -I {} sh -c 'test -n "$(wp post meta get {} _syndicate-to 2>/dev/null)" && wp post update {} --edit'` — re-save each note with syndication targets, triggering the new handler.

## Notes syndicated without a link back

**Root cause**: note pages inject explicit `p-bridgy-mastodon-content` and `p-bridgy-bluesky-content` with the permalink, but Bridgy provider footer callbacks can also emit a second hidden Bluesky paragraph without the link. Bridgy may pick the wrong hidden text when both are present.

**Permanent fix**: `possee_note_bridgy_content` in `microformats.php` now runs at `wp_footer` priority `0` and removes any footer callback whose object is a `SynProvider_Webmention_Bridgy` before outputting the note-specific hidden paragraphs. `SynProvider_Webmention_Bridgy_Bluesky::wp_footer()` also skips `note` singles.

**Diagnosis**: view source for the note page with a cache-busting query param (for example `?v=2`) and confirm only these hidden paragraphs remain:

```html
<p class="p-bridgy-mastodon-content" style="display:none">… https://www.sleep-er.co.uk/notes/.../</p>
<p class="p-bridgy-bluesky-content" style="display:none">… https://www.sleep-er.co.uk/notes/.../</p>
```

If a third `p-bridgy-bluesky-content` still appears, purge WP-Optimize disk cache first. Cloudflare may also serve stale HTML unless you use a cache-busting query param or purge it manually.

## Delayed cron handler

`possee_bridgy_delayed_handler` sequence (fires 90s after Micropub post):

1. Calls `syn_syndication()` — triggers the actual syndication webmention
2. Calls `possee_clear_post_page_cache($post_id)` — clears WP-Optimize disk cache for the post URL
3. Calls `possee_ping_bridgy_discover($post_id)` — POSTs to Bridgy discover with post URL and source key

## Compose project location

`/storage/Docker/wp-possee` (lowercase `storage`, capital `D` in `Docker`).
