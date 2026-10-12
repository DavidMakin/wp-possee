# Bridgy Incidents & Recovery

Bridgy bridges Mastodon/Bluesky → Webmention backfeed. These are known failure modes and their fixes.

## "No webmention targets" for likes/replies

**Root cause**: Bridgy crawls the blog periodically and may hit a newly-published post **before** the 90-second discover cron fires and adds the Bluesky `u-syndication` link. Bridgy stores the post without a Bluesky mapping. When a like/reply arrives, it reports "No webmention targets" and sends nothing.

**Diagnosis**: Check `https://brid.gy/bluesky/<handle>` → Responses section. A like entry with "No webmention targets". The Bridgy like source page has only one `u-like-of` (Bluesky URL) instead of two.

**Permanent fix**: the `possee_bridgy_discover` cron calls `possee_ping_bridgy_discover()` 90 seconds after syndication runs. This POSTs to `https://brid.gy/discover` causing Bridgy to re-crawl and update the mapping.

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

## Like or reply not showing hours after it happened on Bluesky

**Root cause**: Bridgy backfeed is not real time. It polls each source on a 5 hour cycle. A reaction that lands after the last poll waits for the next one.

**Diagnosis**: compare the reaction time on Bluesky against the poll times on `https://brid.gy/bluesky/<handle>` (page shows "Polled X ago, next poll Y from now").

```
curl "https://public.api.bsky.app/xrpc/app.bsky.feed.getLikes?uri=at://<own DID>/app.bsky.feed.post/<RKEY>"
```

`indexedAt` is the reaction time. If the response is missing from Bridgy's Responses list but Bridgy already shows the post with the correct `Original:` link, the mapping is fine and this is poll lag, not "No webmention targets".

**Fix**: force the poll instead of hand building the webmention. POST the `key` from the "Poll now" form on the Bridgy user page to `https://brid.gy/poll-now`. The reaction is delivered within seconds.

**Verify**: comment row exists with `comment_type = 'like'` and `comment_approved = 1`, source `brid.gy/like/bluesky/<own DID>/<encoded AT-URI>/<encoded liker DID>`.

## Bridgy Fed: own POSSE'd post bouncing back as self-comment

**Root cause**: the POSSE'd Bluesky post comes back as a webmention against the post it links to. It arrives in two source shapes, sometimes both for one post:

- `https://brid.gy/post/bluesky/<own DID>/...` from Bridgy backfeed
- `https://bsky.brid.gy/convert/web/at://<own DID>/app.bsky.feed.post/<rkey>#bridgy-fed-create-...` from Bridgy Fed

**Fix**: two filters in `comments.php` share `possee_is_own_bsky_source()` and `possee_is_own_mastodon_source()`, which match the path plus the identity from the `possee_own_bluesky_did` and `possee_own_mastodon_acct` options. Matching the identity on its own would also kill real responses, because Bridgy puts our DID or acct in the path of every like and reply to our posts (`brid.gy/like/bluesky/<own DID>/...`).

**Why two filters**: `webmention_comment_data` is not enough on its own. `wp_new_comment()` overwrites `comment_approved` with the result of `wp_allow_comment()` (comment.php:2440), and the webmention plugin auto-approves Bridgy sources on `pre_comment_approved` at priority 11. The verdict has to be repeated on `pre_comment_approved` at a later priority to survive.

**One-off recovery**: `wp comment delete <ID> --force`

## Syndication using short URL (/?p=ID) instead of pretty permalink

**Root cause**: `get_permalink()` returns `/?p=ID` for posts in `future`, `draft`, or `pending` status. Syndication Links calls `get_permalink()` before sending the webmention. If the post was published as scheduled or had no slug yet, the wrong URL is sent.

**Diagnosis**: Mastodon/Bluesky post reads "Title: https://www.sleep-er.co.uk/?p=293" instead of pretty permalink.

**Fix**: `possee_syndication_prepare_post` in `microformats.php` hooks `syn_syndication` and `micropub_syndication` (priority 5). It clones the post with `post_status = 'publish'` and a computed slug so `get_permalink()` returns the pretty URL, and appends the permalink to any excerpt, because Syndication Links sends the excerpt verbatim with no backlink. Original restored on `shutdown`.

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

## Bridgy Publish failure over Micropub

**Root cause**: publish posts mf2 JSON to `https://brid.gy/micropub` with a per-network bearer token (`bridgy_bluesky_token`, `bridgy_mastodon_token` options). A missing, wrong or revoked token is rejected and nothing is posted. Reconnecting the account on Bridgy invalidates the old token.

**Diagnosis**: check `syndication_log` post meta for a non-2xx entry against `micropub-bluesky-bridgy` or `micropub-mastodon-bridgy`, with `syndication_link` meta left empty.

**Token**: enable publish on the Bridgy user page (`https://brid.gy/bluesky/<handle>`, `https://brid.gy/mastodon/<handle>`), press "Get token", then store it:

```bash
wp option set bridgy_bluesky_token '<value>'
```

**One-off recovery**: re-save the post with the target checked, which re-runs `syn_syndication`. Bridgy Micropub supports create and delete but not update, so a wrong post has to be deleted and re-posted.

## Custom post types (note/checkin/book) not syndicated when created in wp-admin

**Root cause**: `Syndication_Links` depends on WordPress's `do_pings` cron action to process syndication queues. `do_pings` is only scheduled by `_publish_post_hook`, which only registers for the native `post` type. When a note/checkin/book is created/edited in wp-admin with syndication targets, Syndication Links stores `_syndicate-to` meta at priority 10 on `save_post`, but `do_pings` never fires for custom types — the post is stuck in the queue indefinitely.

**Diagnosis**: Note created in wp-admin appears on site with no syndication links. Check meta: `wp post meta get <ID> _syndicate-to` returns the UID, but `syndication_link` meta is empty. Check Bridgy log: post is not mentioned.

**Permanent fix**: `possee_syndicate_save_post` hook in `microformats.php` (priority 20 on `save_post`): (1) only processes `note`, `checkin`, `book` types, (2) skips unless status is `publish` or `future`, (3) reads `_syndicate-to` meta that Syndication Links set at priority 10, (4) calls `do_action('syn_syndication', $post_id, $syndicate_to)` directly, bypassing the broken `do_pings` cron system.

**One-off recovery** (for posts published before the fix): `wp post list --post_type=note --field=ID | xargs -I {} sh -c 'test -n "$(wp post meta get {} _syndicate-to 2>/dev/null)" && wp post update {} --edit'` — re-save each note with syndication targets, triggering the new handler.

## Draft published after sitting for more than a day never syndicates

**Root cause**: `Post_Syndication::syndication()` rejects any post whose `post_date` is more than `DAY_IN_SECONDS` old (class-post-syndication.php:202). Publishing a draft that has sat for weeks trips the gate, because the check runs against the draft's original date. Nothing reschedules afterwards, so the post stays unsyndicated.

**Diagnosis**: `syndication_log` post meta holds `WP_Error` entries with code `too_old` and a message like `Post is 111.70203703704 days old - no syndication`. `mf2_syndication` is empty and `wp cron event list` shows no pending syndication.

**Fix**: publish first, then fire syndication by hand. By then `post_date` is current, so the gate passes:

```bash
wp post meta delete <ID> syndication_log
wp eval 'do_action("syn_syndication", <ID>, array("micropub-bluesky-bridgy","micropub-mastodon-bridgy"));'
wp post meta get <ID> mf2_syndication --format=json
```

Both `syn_syndication` and `micropub_syndication` route through the same gated method, so no hook skips it. `micropub.md` records the provider-level workaround that does.

## Removing a syndicated copy

Bridgy Micropub supports create and delete, not update. A wrong copy has to be deleted and re-posted:

```bash
wp eval '$p = new SynProvider_Micropub_Bridgy_Bluesky();
  $p->delete_micropub("https://bsky.app/profile/<handle>/post/<rkey>", "https://brid.gy/micropub");'
```

Then drop the stale URL from `mf2_syndication`, clear `syndication_log`, and fire `syn_syndication` again for that one target.

**Excerpt content**: Bluesky gets the excerpt as plain text. An `<a>` in it comes out as literal markup and the post is truncated, so keep the permalink a bare URL. Mastodon parses the HTML either way.

## Discover cron

`possee_bridgy_discover` runs 90s after syndication, with two callbacks in registration order:

1. `possee_clear_post_page_cache($post_id)` — clears WP-Optimize disk cache for the post URL
2. `possee_ping_bridgy_discover($post_id)` — POSTs to Bridgy discover with post URL and source key

Publish itself is not delayed. Micropub carries the content in the request, so Bridgy never crawls the page to publish. The discover ping only exists so backfeed can map responses to the post.

## Compose project location

`/storage/Docker/wp-possee` (lowercase `storage`, capital `D` in `Docker`).
