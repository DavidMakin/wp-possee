#!/usr/bin/env python3
"""Import Jekyll posts into WordPress via WP-CLI."""

import os
import re
import subprocess
import sys
import tempfile

POSTS_DIR = "/home/david/workspace/DavidMakin.github.io/_posts"
WP_CLI = ["docker", "compose", "run", "--rm", "wpcli"]
COMPOSE_DIR = "/storage/Docker/wp-possee"


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


def parse_tags(fm):
    raw = fm.get("tags", "")
    raw = raw.strip("[]")
    return [t.strip().strip("'\"") for t in raw.split(",") if t.strip()]


def fix_internal_links(body):
    return re.sub(
        r"\]\((/[^)]+)\)", lambda m: f"](https://www.sleep-er.co.uk{m.group(1)})", body
    )


def import_post(filepath):
    filename = os.path.basename(filepath)
    date_match = re.match(r"(\d{4}-\d{2}-\d{2})-(.+)\.markdown", filename)
    if not date_match:
        print(f"  Skipping {filename} — unexpected filename format")
        return

    with open(filepath) as f:
        content = f.read()

    fm, body = parse_front_matter(content)

    title = fm.get("title", "Untitled").strip("'\"")
    date = fm.get("date", date_match.group(1))
    date = date.split()[0]
    slug = date_match.group(2)
    tags = parse_tags(fm)

    body = fix_internal_links(body)
    body = re.sub(r"\{:\s*\.language-\w+\}", "", body)
    body = re.sub(r"\{%[^%]+%\}", "", body)

    print(f"  Importing: {title} ({date})")

    with tempfile.NamedTemporaryFile(mode="w", suffix=".md", delete=False) as tmp:
        tmp.write(body)
        tmp_path = tmp.name

    try:
        cmd = [
            "ssh",
            "homeip",
            "bash",
            "-c",
            f"""cd {COMPOSE_DIR} && docker compose run --rm wpcli post create \
  --post_title={subprocess.list2cmdline([title])} \
  --post_status=publish \
  --post_date='{date} 12:00:00' \
  --post_name='{slug}' \
  --post_content="$(cat /dev/stdin)" \
  --porcelain \
  2>/dev/null""",
        ]

        remote_tmp = f"/tmp/wp_import_{slug}.md"
        subprocess.run(["scp", "-q", tmp_path, f"homeip:{remote_tmp}"], check=True)

        result = subprocess.run(
            ["ssh", "homeip", "bash", "-s"],
            input=f"""
cd {COMPOSE_DIR}
CONTENT=$(cat {remote_tmp})
docker compose run --rm wpcli post create \
  --post_title={subprocess.list2cmdline([title])} \
  --post_status=publish \
  --post_date='{date} 12:00:00' \
  --post_name='{slug}' \
  --post_content-file={remote_tmp} \
  --porcelain \
  2>/dev/null
rm -f {remote_tmp}
""",
            capture_output=True,
            text=True,
        )

        post_id = result.stdout.strip()
        if not post_id.isdigit():
            print(f"    ERROR: {result.stderr.strip() or result.stdout.strip()}")
            return

        print(f"    Created post ID {post_id}")

        if tags:
            tags_arg = ",".join(tags)
            subprocess.run(
                [
                    "ssh",
                    "homeip",
                    "bash",
                    "-c",
                    f"cd {COMPOSE_DIR} && docker compose run --rm wpcli post term set {post_id} post_tag {subprocess.list2cmdline([tags_arg])} 2>/dev/null",
                ],
                capture_output=True,
            )
            print(f"    Tags: {tags_arg}")

    finally:
        os.unlink(tmp_path)


def main():
    files = sorted(f for f in os.listdir(POSTS_DIR) if f.endswith(".markdown"))
    print(f"Found {len(files)} posts to import\n")
    for filename in files:
        import_post(os.path.join(POSTS_DIR, filename))
    print("\nDone.")


if __name__ == "__main__":
    main()
