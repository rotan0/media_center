"""Save free media found online (Online mode) into media_center/library.

  python mc.py webget        process data/webget.json (queued by the "Find free media" page)

Only public-domain / Creative-Commons sources are searched (Internet Archive, Wikimedia Commons, Openverse).
Each saved file gets a .license file next to it, so its rights rating travels with it.
"""
import json
import os
import re
import time
import urllib.parse

from mc_common import (DATA, ROOT, Library, directories, download, http_json, log, progress, read_json,
                       store_path, write_json, DIRS_FILE)

QUEUE = os.path.join(DATA, "webget.json")
LIB = os.path.join(ROOT, "library")
SUB = {"movie": "Movies", "music": "Music", "image": "Pictures"}
MAX = 3 * 1024 ** 3


def safe(name, n=80):
    name = re.sub(r'[<>:"/\\|?*\x00-\x1f]+', " ", str(name or "")).strip(" .")
    return re.sub(r"\s+", " ", name)[:n] or "untitled"


def ia_files(identifier, type_):
    meta = http_json("https://archive.org/metadata/" + urllib.parse.quote(identifier)) or {}
    files = meta.get("files") or []
    pick = []
    if type_ == "movie":
        prefs = ["h.264", "MPEG4", "h.264 IA", "512Kb MPEG4", "Ogg Video"]
        for fmt in prefs:
            c = [f for f in files if f.get("format") == fmt and int(f.get("size") or 0) < MAX]
            if c:
                pick = [max(c, key=lambda f: int(f.get("size") or 0))]
                break
    elif type_ == "music":
        for fmt in ("VBR MP3", "128Kbps MP3", "MP3", "Ogg Vorbis", "Flac"):
            pick = [f for f in files if f.get("format") == fmt][:60]
            if pick:
                break
    else:
        pick = [f for f in files if f.get("format") in ("JPEG", "PNG") and f.get("source") == "original"][:60]
    return [("https://archive.org/download/" + urllib.parse.quote(identifier) + "/" + urllib.parse.quote(f["name"]),
             os.path.basename(f["name"])) for f in pick]


def license_text(it):
    return (f"License: {it.get('license') or ''}\nLicense URL: {it.get('license_url') or ''}\n"
            f"Credit: {it.get('credit') or ''}\nSource: {it.get('page_url') or ''}\n"
            f"Status: {(it.get('status') or '').replace('_', ' ')}\n")


def run(args):
    saved = {}
    total = 0
    while True:
        q = read_json(QUEUE, []) or []
        if not q:
            break
        it = q.pop(0)
        write_json(QUEUE, q)
        t = it.get("type") if it.get("type") in SUB else "movie"
        title = safe(it.get("title"))
        folder = os.path.join(LIB, SUB[t], title if (it.get("src") == "ia" and t != "movie") else "")
        os.makedirs(folder, exist_ok=True)
        log(f"Saving “{it.get('title')}” from {it.get('src')} ({it.get('license')}) …")
        try:
            if it.get("src") == "ia":
                files = ia_files(it["id"], t)
            else:
                url = it.get("media_url") or ""
                ext = os.path.splitext(urllib.parse.urlparse(url).path)[1] or {"movie": ".mp4", "music": ".mp3", "image": ".jpg"}[t]
                files = [(url, title + ext)] if url else []
        except Exception as e:
            log("  could not read the item:", e)
            continue
        if not files:
            log("  no downloadable file found")
            continue
        if t == "movie" and len(files) == 1:   # name a single film after its title, not the archive's file name
            files = [(files[0][0], title + os.path.splitext(files[0][1])[1])]
        for n, (url, name) in enumerate(files, 1):
            dest = os.path.join(folder, safe(os.path.splitext(name)[0]) + os.path.splitext(name)[1].lower())
            progress(f"{title} {n}/{len(files)}")
            if not os.path.exists(dest) and not download(url, dest, kind="", max_bytes=MAX):
                continue
            with open(os.path.splitext(dest)[0] + ".license", "w", encoding="utf-8") as fh:
                fh.write(license_text(it))
            saved[store_path(dest)] = it
            total += os.path.getsize(dest)
            log(f"  ✓ {store_path(dest)}")
        time.sleep(0.5)
    if not saved:
        log("Nothing was saved.")
        return
    # add to the library, with the rights we already know
    dirs = directories()
    if "library" not in dirs:
        write_json(DIRS_FILE, dirs + ["library"])
    import mc_scan
    mc_scan.run(["--dir", "library"])
    lib = Library()
    for m in lib.values():
        it = saved.get(m.get("path"))
        if not it:
            continue
        m["source_url"] = it.get("page_url")
        m["rights"] = {"status": it.get("status") or "attribution", "license": it.get("license") or "",
                       "license_url": it.get("license_url") or "", "attribute_to": it.get("creator") or "",
                       "credit": it.get("credit") or "", "reason": "found in " + (it.get("src") or ""),
                       "reason_key": "rr_online", "reason_vars": {"src": {"ia": "Internet Archive", "commons": "Wikimedia Commons",
                                                                         "openverse": "Openverse"}.get(it.get("src"), it.get("src"))},
                       "source": it.get("src"), "confidence": "high", "rated_at": int(time.time())}
        if it.get("year") and not m.get("year"):
            try:
                m["year"] = int(str(it["year"])[:4])
            except ValueError:
                pass
        lib.touch(m)
    lib.save()
    log(f"Saved {len(saved)} file(s), {total / 1e6:.1f} MB, into media_center/library.")
