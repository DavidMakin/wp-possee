#!/usr/bin/env python3

import os
import re
import subprocess

POSTS_DIR = "/home/david/workspace/DavidMakin.github.io/_posts"
IMAGES_DIR = "/home/david/workspace/DavidMakin.github.io/assets/img"
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
            fm[k.strip()] = v.strip().split("#")[0].strip()
    return fm, body.strip()


def wpcli(cmd):
    script = f"cd {COMPOSE_DIR}\ndocker compose run --rm wpcli {cmd} 2>/dev/null\n"
    result = subprocess.run(
        ["ssh", "homeip", "bash"],
        input=script,
        capture_output=True,
        text=True,
    )
    return result.stdout.strip()


def upload_image(image_filename, post_id, title=""):
    local_path = os.path.join(IMAGES_DIR, image_filename)
    if not os.path.exists(local_path):
        print(f"    Image not found locally: {image_filename}")
        return None

    remote_tmp = f"/tmp/{image_filename}"
    subprocess.run(["scp", "-q", local_path, f"homeip:{remote_tmp}"], check=True)

    safe_title = (title or image_filename).replace("'", "")
    script = f"""cd {COMPOSE_DIR}
docker compose run --rm -v {remote_tmp}:{remote_tmp} wpcli media import {remote_tmp} --post_id={post_id} --title='{safe_title}' --porcelain 2>/dev/null
rm -f {remote_tmp}
"""
    result = subprocess.run(
        ["ssh", "homeip", "bash"],
        input=script,
        capture_output=True,
        text=True,
    )
    attachment_id = result.stdout.strip()
    if attachment_id.isdigit():
        return attachment_id
    print(f"    Upload error: {result.stderr.strip() or result.stdout.strip()}")
    return None


def process_post(filename):
    date_match = re.match(r"(\d{4}-\d{2}-\d{2})-(.+)\.markdown", filename)
    if not date_match:
        return
    slug = date_match.group(2)
    post_id = SLUG_TO_ID.get(slug)
    if not post_id:
        return

    filepath = os.path.join(POSTS_DIR, filename)
    with open(filepath) as f:
        content = f.read()

    fm, body = parse_front_matter(content)
    featured_img = fm.get("img", "").strip()
    title = fm.get("title", slug)

    print(f"\n[{post_id}] {slug}")

    if featured_img:
        print(f"  Uploading featured image: {featured_img}")
        att_id = upload_image(featured_img, post_id, title)
        if att_id:
            wpcli(f"post meta set {post_id} _thumbnail_id {att_id}")
            print(f"  Featured image set (attachment {att_id})")

    inline_imgs = re.findall(r"!\[[^\]]*\]\(/assets/img/([^)]+)\)", body)
    for img_file in inline_imgs:
        print(f"  Uploading inline image: {img_file}")
        att_id = upload_image(img_file, post_id, img_file)
        if att_id:
            wp_url = wpcli(f"post get {att_id} --field=guid")
            new_body = body.replace(f"/assets/img/{img_file}", wp_url)
            remote_tmp = f"/tmp/wp_body_{slug}.md"
            write_script = f"cat > {remote_tmp}"
            subprocess.run(
                ["ssh", "homeip", "bash", "-c", write_script],
                input=new_body,
                text=True,
            )
            update_script = f"""cd {COMPOSE_DIR}
docker compose run --rm -v {remote_tmp}:{remote_tmp} wpcli post update {post_id} --post_content="$(cat {remote_tmp})" 2>/dev/null
rm -f {remote_tmp}
"""
            subprocess.run(
                ["ssh", "homeip", "bash"],
                input=update_script,
                capture_output=True,
                text=True,
            )
            print(f"  Inline image URL updated in post body")
            body = new_body


def main():
    files = sorted(f for f in os.listdir(POSTS_DIR) if f.endswith(".markdown"))
    for filename in files:
        process_post(filename)
    print("\nDone.")


if __name__ == "__main__":
    main()
