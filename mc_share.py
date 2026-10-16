"""Share a free-to-share library item on the Internet Archive (Online mode only).

  python mc.py ia_upload <item id>

Uses the Internet Archive S3-style API with your own keys (https://archive.org/account/s3.php),
saved in data/config.json as ia_access / ia_secret. Only public-domain, Creative-Commons or personal
items can be uploaded. The public page (archive.org/details/...) can then be posted to Facebook / X.
"""
import http.client
import os
import re
import time
import urllib.parse

from mc_common import NET, Library, abs_path, config, log, progress, require_online

COLLECTION = {"movie": "opensource_movies", "music": "opensource_audio", "image": "opensource_image"}
MEDIATYPE = {"movie": "movies", "music": "audio", "image": "image"}


def meta(v):
    return "uri(" + urllib.parse.quote(str(v), safe="") + ")"


def run(args):
    require_online()
    cfg = config()
    if not cfg.get("ia_access") or not cfg.get("ia_secret"):
        log("Internet Archive keys missing — add them in the Share dialog (from archive.org/account/s3.php).")
        return
    lib = Library()
    m = lib.items.get(args[0] if args else "")
    if not m:
        log("Item not found.")
        return
    r = m.get("rights") or {}
    if r.get("status") not in ("public_domain", "attribution", "personal"):
        log("Only public-domain, Creative-Commons or your own media can be uploaded. Run 'rights' first.")
        return
    path = abs_path(m["path"])
    slug = re.sub(r"[^A-Za-z0-9]+", "-", m.get("title") or "media").strip("-")[:40] or "media"
    ident = f"lmc-{slug}-{m['id'][:8]}"
    fname = re.sub(r"[^A-Za-z0-9._-]+", "_", os.path.basename(path))
    size = os.path.getsize(path)
    t = m.get("type", "movie")
    headers = {
        "authorization": f"LOW {cfg['ia_access']}:{cfg['ia_secret']}",
        "x-amz-auto-make-bucket": "1",
        "x-archive-queue-derive": "1",
        "x-archive-meta-mediatype": MEDIATYPE.get(t, "movies"),
        "x-archive-meta01-collection": COLLECTION.get(t, "opensource_movies"),
        "x-archive-meta-title": meta(m.get("title") or fname),
        "x-archive-meta-description": meta((r.get("credit") or "") + "\n\nShared with Local Media Center."),
        "Content-Length": str(size),
    }
    if r.get("license_url"):
        headers["x-archive-meta-licenseurl"] = meta(r["license_url"])
    if r.get("attribute_to") or m.get("artist") or m.get("director"):
        headers["x-archive-meta-creator"] = meta(r.get("attribute_to") or m.get("artist") or m.get("director"))
    if m.get("year"):
        headers["x-archive-meta-date"] = str(m["year"])
    log(f"Uploading “{m.get('title')}” to the Internet Archive as {ident} ({size / 1e6:.1f} MB) …")
    conn = http.client.HTTPSConnection("s3.us.archive.org", timeout=300)
    conn.putrequest("PUT", f"/{ident}/{urllib.parse.quote(fname)}")
    for k, v in headers.items():
        conn.putheader(k, v)
    conn.endheaders()
    sent = 0
    with open(path, "rb") as fh:
        while True:
            chunk = fh.read(262144)
            if not chunk:
                break
            conn.send(chunk)
            sent += len(chunk)
            NET.add("s3.us.archive.org", len(chunk), "Internet Archive (upload)")
            progress(f"upload {sent * 100 // max(1, size)}%")
    resp = conn.getresponse()
    body = resp.read().decode("utf-8", "ignore")
    if resp.status >= 300:
        log(f"  upload failed: HTTP {resp.status} {body[:300]}")
        return
    m["ia_url"] = f"https://archive.org/details/{ident}"
    m["shared_at"] = int(time.time())
    lib.touch(m)
    lib.save()
    log(f"  ✓ uploaded. Public page (ready in a few minutes): {m['ia_url']}")
