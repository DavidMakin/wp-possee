# Incident Log

## 2026-06-16: Missing Images on Bluesky Syndication

### Summary
Post https://www.sleep-er.co.uk/notes/2026-06-12-19-26/ syndicates text to Bluesky but images don't appear. Root cause: Micropub photo extraction pipeline was incomplete — images added to posts via editor after creation weren't being captured into `mf2_photo` post meta required for syndication.

### Timeline

**June 12 19:26 UTC - Post Created**
- Note post created via Micropub (Quill) with only text, no images
- Syndicated to Bluesky immediately (post_id 3787)

**June 16 09:58 UTC - Images Added**
- Post edited in WordPress admin, two Gutenberg image blocks added
- Images visible on blog (rendered from post_content)
- **But not captured into `mf2_photo` post meta** (syndication requires this)

**June 16 14:26 UTC - Issue Reported**
- User notices images missing from Bluesky post while present on blog
- Post too old for automatic re-syndication (Syndication Links blocks posts >3.7 days)

### Root Cause

The Micropub photo extraction pipeline had a critical gap:

1. **Micropub post creation**: When posts created via Micropub with Gutenberg images → images NOT extracted to `mf2_photo`
2. **Post editing**: When posts edited in wp-admin to add images → images NOT extracted to `mf2_photo`
3. **Rendering**: Photos rendered from `mf2_photo` meta only (if it existed), ignoring post_content Gutenberg blocks

This meant:
- Micropub photos with images: not captured for syndication
- Posts edited to add images: image changes never propagated to syndication

### Impact

- Post 3787 images missing from Bluesky
- Syndication "too old" check prevented re-sending with images
- Future posts with Gutenberg images would have same issue

### Resolution

Implemented photo extraction in two paths:

1. **`possee_extract_micropub_gutenberg_photos()`** — hooks `pre_insert_micropub_post` priority 2
   - Runs during Micropub post creation
   - Parses Gutenberg `<!-- wp:image {id:...} -->` blocks
   - Extracts image URLs into `meta_input['mf2_photo']` before post insert

2. **`possee_extract_gutenberg_photos()`** — hooks `save_post` priority 10
   - Runs when posts edited in wp-admin
   - Extracts Gutenberg blocks into `mf2_photo` post meta
   - Skips if photos already exist (don't override Micropub payload)

Changes deployed June 16 14:26. Post 3787 backfilled with `mf2_photo` meta manually.

### Lessons Learned

#### Micropub Plugin Doesn't Extract Content to Meta
- Micropub plugin stores content as Gutenberg blocks in `post_content`
- It does NOT extract images, galleries, or other structured content to post meta
- Custom extraction needed for any meta-based syndication use cases

#### Multiple Content Creation Paths = Multiple Extraction Hooks
- Micropub API posts: need `pre_insert_micropub_post` hook
- WordPress admin posts: need `save_post` hook
- Both paths can add/modify Gutenberg blocks
- Single extraction point (e.g., only in rendering) insufficient

#### Syndication Age Limits Block Updates
- Syndication Links plugin refuses to re-send posts older than ~3.7 days
- Intentional to prevent spam, but blocks legitimate image updates
- Workaround: clear `syndication_log` meta to allow manual retry, or use `syn_syndication` action directly

### Prevention

1. Document photo extraction requirements in micropub.md (updated)
2. Add image extraction test for new Micropub posts
3. Monitor syndication_log for "too old" errors on edits

### Next Steps

- [ ] Manually trigger Bridgy to re-fetch post 3787 with updated mf2_photo
- [ ] Verify images appear on Bluesky post
- [ ] Test new Micropub posts with images to confirm extraction works
- [ ] Test wp-admin image edits to confirm extraction works

---

## 2026-06-16: Hardcover Sync Outage & Database Corruption

### Summary
n8n `hardcover-to-wordpress` workflow failed June 11 16:00 UTC and remained broken until manual intervention June 16 14:11. Corruption occurred during troubleshooting when Python script malformed SQLite JSON. Root cause: workflow deployed on server diverged from git repo, causing silent failure and database state mismatch.

### Timeline

**June 11 16:00 UTC - Initial Failure**
- Workflow execution 707 failed with `ECONNREFUSED` connecting to WordPress REST API
- All subsequent hourly executions (up to 707) failed identically
- Fugitive Telemetry failed to sync (marked as missing from /books/)
- Network Effect status stuck at "reading" (should be "finished")

**June 16 13:40-14:11 UTC - Investigation & Troubleshooting**
- Diagnosed root cause: deployed workflow contained non-existent endpoint `/wp-json/possee/v1/last-checked`
- Repo JSON `n8n-hardcover-workflow.json` used correct approach: `$workflow.staticData.lastChecked` (JavaScript Code node)
- Deployed workflow was outdated version, likely edited directly on server without git sync
- Attempted to fix via Python sqlite3 modifications
- **Python script corrupted workflow_entity.nodes JSON** during serialization (unterminated string at position 6971)
- Cascading failures: SQLite "Unterminated string", activeVersionId corruption, database factory-reset

**June 16 15:00+ UTC - Recovery**
- Restored backup `database.sqlite.backup-corrupted` (state before Python damage)
- Fixed file permissions (n8n runs as UID 1000, needed chown to 1000:1000)
- Workflow loaded successfully; manual trigger (execution 708) synced Fugitive Telemetry
- Network Effect status manually updated in MariaDB (`mf2_read-status` → "finished")
- Cloudflare full-page cache (~859s TTL) prevents display update

### Root Causes

1. **Deployment drift**: Workflow edited directly on production n8n instance without committing to git
2. **No change tracking**: SQLite blob changes bypassed version control
3. **Python serialization error**: `json.dumps()` on modified workflow nodes produced malformed JSON
4. **No backup validation**: Backup permissions weren't pre-validated before restore

### Impact

- 5 days of missing book sync
- Fugitive Telemetry missing from /books/ archive
- Network Effect stuck with stale status
- n8n offline during troubleshooting

### Resolution

✅ Fugitive Telemetry synced (post ID 3798, published 2026-06-16T14:42:24)  
✅ Network Effect status corrected in database (mf2_read-status = "finished")  
⏳ Awaiting Cloudflare cache expiry (~859s) for display to reflect changes

### Lessons Learned

#### Never Edit n8n Workflows on Server
- All workflow changes must go through git (`n8n-hardcover-workflow.json`)
- Direct server edits are invisible to version control and cause drift
- **Recommendation**: Enforce n8n in "read-only" mode on production; changes only via import

#### SQLite Serialization Requires Care
- Manual JSON modifications to sqlite3 blobs are error-prone
- Character encoding issues in Python `json.dumps()` can corrupt the database
- **Recommendation**: Use n8n's own export/import for workflow versioning, not direct DB edits

#### Database Backups Need Permission Metadata
- After `docker cp` of SQLite file, permissions become root:root
- n8n container (UID 1000) cannot write, causing silent `SQLITE_READONLY` errors
- **Recommendation**: Pre-validate backup permissions before restore, or use `sudo chown` in restore automation

#### Workflow State Machine Coupling
- n8n stores execution state in compressed reference format (not plain JSON)
- Manual inspection of execution data via `execution_data` table requires decompression
- **Recommendation**: Use n8n API for workflow state queries, not direct database inspection

### Prevention

1. **Version control enforcement**
   - Add pre-push hook to check `n8n-hardcover-workflow.json` against deployed workflow state
   - Document that n8n is a "pull-only" deployment (git → n8n, never n8n → git)

2. **Backup automation**
   - Store Cloudflare credentials in `.env` for cache purging
   - Script full backup + permission fix in one atomic operation
   - Test restore procedure monthly

3. **Monitoring**
   - Alert on n8n workflow execution failures (currently silent for 5 days)
   - Monitor `workflow_entity.versionId` changes for drift detection
   - Set up WP-CLI health check: `wp post list --post_type=book --fields=ID,post_title --limit=1`

4. **Hardcover sync resilience**
   - Query includes status_id [1, 2, 3] but Network Effect query didn't return it
   - Check if Hardcover's updated_at for Network Effect predates the 7-day lookback window
   - Consider adding explicit book ISBN/slug list for critical reads

### Next Steps

- [ ] Document n8n deployment constraints in AGENTS.md
- [ ] Set up n8n workflow drift detection
- [ ] Enable Cloudflare cache purge automation for /books/ after posts save
- [ ] Add n8n execution failure alert to monitoring stack
- [ ] Validate Hardcover API response for Network Effect on next sync

---

## Files Changed
- `docs/incidents.md` (this file)
- Manual DB update: `wp_postmeta` post_id=3774, mf2_read-status → "finished"
- n8n workflow: restored from git → deployment (execution 708)
