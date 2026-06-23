#!/usr/bin/env python3

import os
import re
import subprocess

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


def wpcli(cmd):
    script = f"cd {COMPOSE_DIR}\ndocker compose run --rm wpcli {cmd} 2>/dev/null\n"
    result = subprocess.run(
        ["ssh", "homeip", "bash"], input=script, capture_output=True, text=True
    )
    return result.stdout.strip()


def parse_tags(content):
    match = re.search(r"^tags:\s*\[([^\]]+)\]", content, re.MULTILINE)
    if not match:
        return []
    return [t.strip() for t in match.group(1).split(",")]


def ensure_category(name):
    existing = wpcli(f"term list category --name='{name}' --field=term_id --format=csv")
    first = existing.splitlines()[0] if existing else ""
    if not first.isdigit():
        wpcli(f"term create category '{name}'")


def main():
    files = sorted(f for f in os.listdir(POSTS_DIR) if f.endswith(".markdown"))
    for filename in files:
        date_match = re.match(r"(\d{4}-\d{2}-\d{2})-(.+)\.markdown", filename)
        if not date_match:
            continue
        slug = date_match.group(2)
        post_id = SLUG_TO_ID.get(slug)
        if not post_id:
            continue

        with open(os.path.join(POSTS_DIR, filename)) as f:
            content = f.read()

        tags = parse_tags(content)
        if not tags:
            continue

        print(f"\n[{post_id}] {slug}")
        for tag in tags:
            ensure_category(tag)

        quoted = " ".join(f"'{t}'" for t in tags)
        wpcli(f"post term set {post_id} category {quoted}")
        print(f"  categories: {', '.join(tags)}")

    print("\nDone.")


if __name__ == "__main__":
    main()
