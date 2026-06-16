# Incident Log

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
