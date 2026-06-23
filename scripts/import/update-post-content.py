#!/usr/bin/env python3

import os
import re
import subprocess
import tempfile

import markdown

POSTS_DIR = "/home/david/workspace/DavidMakin.github.io/_posts"
COMPOSE_DIR = "/storage/Docker/wp-possee"

SLUG_TO_ID = {
    "simple-rest-with-silex-part1-of-3": "36",
    "joining-2-pdf-files": "38",
    "simple-rest-with-silex-part2-of-3": "40",
    "running-composer-via-phing-via-composer": "42",
    "migrating-munin-rrd": "44",
    "simple-rest-with-silex-part3-of-3": "46",
    "how-i-rest-from-work": "48",
}


def parse_front_matter(content):
    match = re.match(r"^---\n(.*?)\n---\n(.*)", content, re.DOTALL)
    if not match:
        return {}, content
    fm_raw, body = match.group(1), match.group(2)
    fm = {}
    for line in fm_raw.splitlines():
        if ":" in line:
            k, _, v = line.partition(":")
            fm[k.strip()] = v.strip()
    return fm, body.strip()


def fix_internal_links(body):
    return re.sub(r"https?://(?:www\.)?sleep-er\.co\.uk(/[^)\s\"']*)", r"\1", body)


def update_post_content(slug, post_id, filepath):
    with open(filepath) as f:
        content = f.read()

    _, body = parse_front_matter(content)
    body = fix_internal_links(body)
    body = re.sub(r"\{:\s*\.language-\w+\}", "", body)
    body = re.sub(r"\{%[^%]+%\}", "", body)
    body = markdown.markdown(body, extensions=["fenced_code", "tables", "nl2br"])

    with tempfile.NamedTemporaryFile(mode="w", suffix=".md", delete=False) as tmp:
        tmp.write(body)
        tmp_path = tmp.name

    remote_tmp = f"/tmp/wp_content_{slug}.md"
    subprocess.run(["scp", "-q", tmp_path, f"homeip:{remote_tmp}"], check=True)
    os.unlink(tmp_path)

    result = subprocess.run(
        ["ssh", "homeip", "bash", "-s"],
        input=f"""
cd {COMPOSE_DIR}
CONTENT=$(cat {remote_tmp})
docker compose run --rm wpcli post update {post_id} --post_content="$CONTENT" 2>/dev/null
rm -f {remote_tmp}
""",
        capture_output=True,
        text=True,
    )

    if "Success" in result.stdout or result.returncode == 0:
        preview = body[:80].replace("\n", " ")
        print(f"  [{post_id}] {slug}: OK — {preview}...")
    else:
        print(f"  [{post_id}] {slug}: ERROR — {result.stderr.strip()}")


def main():
    files = sorted(f for f in os.listdir(POSTS_DIR) if f.endswith(".markdown"))
    for filename in files:
        date_match = re.match(r"(\d{4}-\d{2}-\d{2})-(.+)\.markdown", filename)
        if not date_match:
            continue
        slug = date_match.group(2)
        post_id = SLUG_TO_ID.get(slug)
        if not post_id:
            print(f"  Skipping {slug} — no post ID mapped")
            continue
        update_post_content(slug, post_id, os.path.join(POSTS_DIR, filename))


if __name__ == "__main__":
    main()
