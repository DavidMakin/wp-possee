# AGENTS.md — wp-possee

Self-hosted WordPress with POSSE syndication to Mastodon and Bluesky, with backfeed via Brid.gy.

## Quick Reference

- **Stack**: WordPress (PHP-FPM) + Nginx + Cloudflare Tunnel + MariaDB
- **Theme**: Blocksy + Blocksy Companion (font/color via Customizer GUI — do not override in mu-plugin)
- **mu-plugins**: `microformats.php`, `comments.php`, `theme-styles.php`, `loopback-fix.php`, `books.php`, `post-types.php`, `homepage-highlights.php`, `header-post-counts.php`, `mermaid.php`, `pretty-archives.php`
- **Deploy**: `scp mu-plugins/foo.php homeip:...` → restart wordpress + nginx → purge WP-Optimize cache
- **WP-CLI**: `docker run --rm --user 65532 ... wordpress:cli-php8.3 wp --allow-root <cmd>`
- **Backups**: `ssh homeip docker exec mariadb mysqldump ... | gzip > backup.sql.gz`
- **Remote shell is fish** — use `ssh homeip bash << 'EOF' ... EOF` for multi-line commands

## Git commit conventions

- Load `caveman-commit` skill before writing commit message
- Follow **Conventional Commits**: `<type>(<scope>): <summary>`
- Types: `feat`, `fix`, `refactor`, `perf`, `docs`, `test`, `chore`, `style`
- Subject ≤50 chars, imperative mood, no trailing period
- **One commit = one thing** — never bundle unrelated changes
- Body only when *why* isn't obvious; wrap at 72 chars
- No AI attribution, no "this commit does X", no emoji

## NEVER

- **Never set `font-family` in mu-plugin CSS** — Blocksy Customizer owns typography. Exception: `monospace` for code elements.
- **Never run multi-line commands over `ssh homeip` without `bash << 'EOF' ... EOF`** — remote shell is fish.
- **Never edit mu-plugin files directly on server** — edit locally, `scp`, restart. Direct edits overwritten on next deploy.
- **Never skip clearing all three caches after PHP change** — OPcache, nginx fastcgi cache, WP-Optimize disk cache are independent.
- **Never omit `--user 65532` from WP-CLI containers** — uploads dir owned by that UID; omitting silently breaks any file write.
- **Never use `$post->post_excerpt` to check for native excerpt** — use `has_excerpt($post_id)`. Our `get_the_excerpt` filter can return non-empty strings even without native excerpt.
- **Never edit n8n workflows directly on the production instance** — all changes go through git (`n8n-hardcover-workflow.json`) and deployed via n8n import. Direct edits cause drift and silent failures. See [`docs/incidents.md`](docs/incidents.md) for June 16 outage details.

## Blog post drafting

- Read [VOICE.md](./VOICE.md) and fetch 2–3 existing published posts via WP-CLI before writing. Style is specific; generic "developer voice" is noticeably wrong.
- Gutenberg headings need `class="wp-block-heading"` or render oddly in editor.
- Cross-links: use `/?p=ID` format. Permanent regardless of permalink structure.
- Excerpts: 1–2 sentences stating the specific thing reader will learn. Not a tease.

## Documentation

Detailed operational knowledge is organized in `docs/`:

| Topic | File |
|---|---|
| **Operations** (deploy, WP-CLI, caches, SSH, Docker networks) | [`docs/operations.md`](docs/operations.md) |
| **Blocksy patterns** (card layout, customizer, meta, CSS quirks, filter priorities) | [`docs/blocksy.md`](docs/blocksy.md) |
| **Micropub & microformats** (e-content nesting, Bridgy Bluesky content) | [`docs/micropub.md`](docs/micropub.md) |
| **Bridgy incidents** (all failure modes + recovery) | [`docs/bridgy.md`](docs/bridgy.md) |
| **mu-plugins reference** (per-file documentation) | [`docs/mu-plugins.md`](docs/mu-plugins.md) |
| **Infrastructure** (stack, PHP config, fonts, colophon) | [`docs/infrastructure.md`](docs/infrastructure.md) |
| **Features** (header post counts, book display, Open Library API) | [`docs/features.md`](docs/features.md) |
| **External services** (n8n, plugin notes) | [`docs/external-services.md`](docs/external-services.md) |

<skills_system priority="1">

## Available Skills

<usage>
When users ask you to perform tasks, check if any of the available skills below can help complete the task more effectively. Skills provide specialized capabilities and domain knowledge.
</usage>

<available_skills>

<skill>
<name>agent-md-refactor</name>
<description>Refactor bloated AGENTS.md, CLAUDE.md, or similar agent instruction files to follow progressive disclosure principles.</description>
<location>global</location>
</skill>

<skill>
<name>caveman</name>
<description>Ultra-compressed communication mode. Cuts token usage ~75%.</description>
<location>global</location>
</skill>

<skill>
<name>caveman-commit</name>
<description>Ultra-compressed commit message generator. Conventional Commits format.</description>
<location>global</location>
</skill>

<skill>
<name>caveman-compress</name>
<description>Compress natural language memory files into caveman format.</description>
<location>global</location>
</skill>

<skill>
<name>caveman-review</name>
<description>Ultra-compressed code review comments.</description>
<location>global</location>
</skill>

<skill>
<name>mermaid-diagrams</name>
<description>Comprehensive guide for creating software diagrams using Mermaid syntax.</description>
<location>global</location>
</skill>

</available_skills>

</skills_system>
