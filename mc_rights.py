"""Rights rater: is each item public domain, free with attribution, personal, proprietary or unknown?

  python mc.py rights [--online] [--force]     rate the library (offline evidence; --online adds Wikidata + Internet Archive)
  python mc.py sharepack                      zip every free-to-share item with a CREDITS.txt into media_center/share/
  python mc.py save [--free|<id>...]          copy items into media_center/library so they travel with the app

Evidence, strongest first:
  1. licence files next to the media (LICENSE, *.license, credits.txt, .xmp sidecars)
  2. embedded tags (ID3 TCOP/WCOP, Vorbis LICENSE/COPYRIGHT, MP4 cprt, EXIF Copyright/Artist, XMP dc:rights / cc:license,
     ffprobe copyright/license tags)
  3. publication year vs. the US 95-year rule (sound recordings: 100 years)
  4. online catalogues (--online): Wikidata copyright status / licence, Internet Archive licence URL
  5. commercial catalogue match from 'enrich' (iTunes/TMDB/Deezer)  ->  proprietary
This is guidance, not legal advice.
"""
import datetime
import json
import os
import re
import shutil
import subprocess
import time
import urllib.parse
import zipfile

from mc_common import (ROOT, Library, abs_path, http_json, local_tool, log, media_id, progress, store_path)

try:
    import mutagen
except ImportError:
    mutagen = None
try:
    from PIL import Image
except ImportError:
    Image = None

YEAR = datetime.date.today().year
PD_WORK_YEAR = YEAR - 96        # US: works published 95+ years ago (published <= 1930 in 2026)
PD_SOUND_YEAR = YEAR - 101      # US: sound recordings, 100 years (recorded <= 1925 in 2026)
SHARE_DIR = os.path.join(ROOT, "share")
LIB_DIR = os.path.join(ROOT, "library")

CC = [  # (regex, licence name, status)
    (r"creativecommons\.org/publicdomain/zero|\bcc0\b|cc-zero", "CC0 1.0", "public_domain"),
    (r"creativecommons\.org/publicdomain/mark|public domain mark|\bpdm\b", "Public Domain Mark", "public_domain"),
    (r"public[\s-]*domain|domaine public|dominio p[uú]blico|gemeinfrei", "Public domain", "public_domain"),
    (r"creativecommons\.org/licenses/by-nc-nd|cc[\s-]*by[\s-]*nc[\s-]*nd", "CC BY-NC-ND", "attribution"),
    (r"creativecommons\.org/licenses/by-nc-sa|cc[\s-]*by[\s-]*nc[\s-]*sa", "CC BY-NC-SA", "attribution"),
    (r"creativecommons\.org/licenses/by-nc/|cc[\s-]*by[\s-]*nc\b", "CC BY-NC", "attribution"),
    (r"creativecommons\.org/licenses/by-nd|cc[\s-]*by[\s-]*nd", "CC BY-ND", "attribution"),
    (r"creativecommons\.org/licenses/by-sa|cc[\s-]*by[\s-]*sa", "CC BY-SA", "attribution"),
    (r"creativecommons\.org/licenses/by/|cc[\s-]*by\b|attribution 4\.0|attribution 3\.0", "CC BY", "attribution"),
    (r"free art licen[cs]e|licence art libre", "Free Art License", "attribution"),
    (r"\bgnu (general public|free documentation) licen[cs]e|\bgfdl\b|\bgpl\b", "GNU licence", "attribution"),
    (r"all rights reserved|todos los derechos|tous droits|alle rechte", "All rights reserved", "proprietary"),
]
CC_URL = {"CC0 1.0": "https://creativecommons.org/publicdomain/zero/1.0/",
          "Public Domain Mark": "https://creativecommons.org/publicdomain/mark/1.0/",
          "CC BY": "https://creativecommons.org/licenses/by/4.0/", "CC BY-SA": "https://creativecommons.org/licenses/by-sa/4.0/",
          "CC BY-ND": "https://creativecommons.org/licenses/by-nd/4.0/", "CC BY-NC": "https://creativecommons.org/licenses/by-nc/4.0/",
          "CC BY-NC-SA": "https://creativecommons.org/licenses/by-nc-sa/4.0/", "CC BY-NC-ND": "https://creativecommons.org/licenses/by-nc-nd/4.0/"}


def classify_text(text):
    t = (text or "").lower()
    for rx, name, status in CC:
        if re.search(rx, t):
            return status, name
    if re.search(r"(©|\(c\)|\bcopyright\b|\b℗|\(p\))", t):
        return "proprietary", "Copyright"
    return None, None


def holder_from(text):
    m = re.search(r"(?:©|\(c\)|copyright|℗|\(p\))\s*(?:\d{4}(?:\s*[-–]\s*\d{4})?)?\s*,?\s*([^\n.;|]{2,80})", text or "", re.I)
    return m.group(1).strip(" ,") if m else ""


# ---------------- evidence collectors ----------------
SIDE_NAMES = ("license", "licence", "license.txt", "licence.txt", "license.md", "copying", "credits.txt",
              "attribution.txt", "readme.txt", "readme.md", "rights.txt", "copyright.txt")


def sidecar_text(path):
    out = []
    base = os.path.splitext(path)[0]
    for ext in (".license", ".licence", ".txt", ".xmp", ".rights", ".credits"):
        p = base + ext
        if os.path.isfile(p) and os.path.getsize(p) < 400000:
            out.append(open(p, encoding="utf-8", errors="ignore").read())
    folder = os.path.dirname(path)
    try:
        for f in os.listdir(folder):
            if f.lower() in SIDE_NAMES and os.path.getsize(os.path.join(folder, f)) < 400000:
                out.append(open(os.path.join(folder, f), encoding="utf-8", errors="ignore").read())
    except OSError:
        pass
    return "\n".join(out)


def audio_tags(path):
    info = {}
    if not mutagen:
        return info
    try:
        f = mutagen.File(path)
        if not f or not f.tags:
            return info
        tags = f.tags
        texts = []
        for k in list(tags.keys()):
            kl = str(k).lower()
            if any(w in kl for w in ("tcop", "wcop", "copyright", "license", "licence", "cprt", "rights", "tpub", "wpub")):
                v = tags[k]
                v = getattr(v, "text", None) or getattr(v, "url", None) or v
                texts.append(" ".join(map(str, v)) if isinstance(v, (list, tuple)) else str(v))
            if kl in ("tpe1", "artist", "\xa9art", "author"):
                v = tags[k]
                info["artist"] = str(getattr(v, "text", [v])[0] if hasattr(v, "text") else (v[0] if isinstance(v, list) else v))
        info["text"] = "\n".join(texts)
    except Exception:
        pass
    return info


def image_tags(path):
    info = {}
    try:
        with open(path, "rb") as fh:
            head = fh.read(512 * 1024)
        m = re.search(rb"<x:xmpmeta.*?</x:xmpmeta>", head, re.S)
        if m:
            info["text"] = m.group(0).decode("utf-8", "ignore")
    except OSError:
        pass
    if Image:
        try:
            with Image.open(path) as im:
                ex = im.getexif()
                if ex.get(33432):
                    info["text"] = (info.get("text", "") + "\n© " + str(ex.get(33432))).strip()
                if ex.get(315):
                    info["artist"] = str(ex.get(315)).strip()
                if ex.get(271) or ex.get(272):
                    info["camera"] = f"{ex.get(271) or ''} {ex.get(272) or ''}".strip()
                dt = ex.get(36867) or ex.get_ifd(0x8769).get(36867) or ex.get(306)
                if dt:
                    info["year"] = int(str(dt)[:4]) if str(dt)[:4].isdigit() else None
        except Exception:
            pass
    return info


def video_tags(path):
    probe = local_tool("ffprobe") or shutil.which("ffprobe")
    if not probe:
        return {}
    try:
        out = subprocess.run([probe, "-v", "error", "-show_entries", "format_tags", "-of", "json", path],
                             capture_output=True, text=True, timeout=30,
                             **({"creationflags": 0x08000000} if os.name == "nt" else {})).stdout
        tags = {k.lower(): v for k, v in (json.loads(out).get("format", {}).get("tags", {}) or {}).items()}
        text = " ".join(v for k, v in tags.items() if any(w in k for w in ("copyright", "license", "licence", "rights", "comment")))
        return {"text": text, "artist": tags.get("artist") or tags.get("director") or ""}
    except Exception:
        return {}


# ---------------- online (only with --online) ----------------
def wikidata(title, year, kind):
    klass = "wd:Q11424" if kind == "movie" else "wd:Q105543609 wd:Q482994 wd:Q134556 wd:Q7366"
    q = f"""SELECT ?item ?status ?statusLabel ?licLabel ?date WHERE {{
      VALUES ?cls {{ {klass} }}
      ?item rdfs:label "{title.replace('"', '')}"@en; wdt:P31/wdt:P279* ?cls.
      OPTIONAL {{ ?item wdt:P6216 ?status. }} OPTIONAL {{ ?item wdt:P275 ?lic. }} OPTIONAL {{ ?item wdt:P577 ?date. }}
      SERVICE wikibase:label {{ bd:serviceParam wikibase:language "en". }} }} LIMIT 5"""
    d = http_json("https://query.wikidata.org/sparql", {"query": q, "format": "json"}, timeout=40, retries=1)
    rows = ((d or {}).get("results") or {}).get("bindings") or []
    for r in rows:
        y = int(r["date"]["value"][:4]) if r.get("date") else None
        if year and y and abs(y - year) > 1:
            continue
        lic = (r.get("licLabel") or {}).get("value", "")
        st = (r.get("statusLabel") or {}).get("value", "")
        if "public domain" in st.lower() or "public domain" in lic.lower() or "CC0" in lic:
            return {"status": "public_domain", "license": lic or "Public domain", "source": "Wikidata", "url": r["item"]["value"]}
        if lic:
            s, name = classify_text(lic)
            if s:
                return {"status": s, "license": name or lic, "source": "Wikidata", "url": r["item"]["value"]}
        if "copyright" in st.lower():
            return {"status": "proprietary", "license": "Copyrighted", "source": "Wikidata", "url": r["item"]["value"]}
    return None


def internet_archive(title, kind):
    mt = "movies" if kind == "movie" else ("audio" if kind == "music" else "image")
    d = http_json("https://archive.org/advancedsearch.php", {
        "q": f'title:("{title}") AND mediatype:({mt}) AND licenseurl:*', "fl[]": ["identifier", "licenseurl", "creator", "year", "title"],
        "rows": 5, "output": "json"}, timeout=30, retries=1)
    for doc in ((d or {}).get("response") or {}).get("docs") or []:
        lic = doc.get("licenseurl") or ""
        s, name = classify_text(lic)
        if s:
            creator = doc.get("creator")
            creator = ", ".join(creator) if isinstance(creator, list) else (creator or "")
            return {"status": s, "license": name, "source": "Internet Archive", "attribute_to": creator,
                    "url": "https://archive.org/details/" + doc["identifier"], "license_url": lic}
    return None


# ---------------- rating ----------------
def rate(m, online=False):
    path = abs_path(m.get("path"))
    kind = m.get("type")
    ev = {"text": "", "artist": ""}
    side = sidecar_text(path)
    tags = audio_tags(path) if kind == "music" else image_tags(path) if kind == "image" else video_tags(path)
    who = tags.get("artist") or m.get("artist") or m.get("director") or ""
    title = m.get("title") or os.path.basename(path)
    year = m.get("year") or tags.get("year")

    def result(status, license_="", reason="", source="", confidence="medium", url="", attribute_to=None, license_url="",
               key="", kv=None):
        attribute_to = who if attribute_to is None else attribute_to
        credit = ""
        if status in ("attribution", "public_domain"):
            parts = [f"“{title}”"]
            if attribute_to:
                parts.append(f"by {attribute_to}")
            if year:
                parts.append(f"({year})")
            lic = license_ or ("Public domain" if status == "public_domain" else "")
            credit = " ".join(parts) + (f" — {lic}" if lic else "") + (f" — {license_url or CC_URL.get(license_, '') or url}" if (license_url or CC_URL.get(license_) or url) else "")
        return {"status": status, "license": license_, "attribute_to": attribute_to, "reason": reason, "source": source,
                "confidence": confidence, "url": url, "license_url": license_url or CC_URL.get(license_, ""),
                "credit": credit.strip(), "rated_at": int(time.time()), "reason_key": key, "reason_vars": kv or {}}

    # 1) licence files beside the media
    s, name = classify_text(side)
    if s:
        return result(s, name, "licence file next to the media", "sidecar", "high",
                      attribute_to=holder_from(side) or who if s != "public_domain" else who, key="rr_sidecar")
    # 2) embedded metadata
    s, name = classify_text(tags.get("text", ""))
    if s:
        holder = holder_from(tags.get("text", "")) or who
        return result(s, name, "embedded copyright/licence tag", "tags", "high", attribute_to=holder, key="rr_tags")
    # 3) age
    if year:
        try:
            y = int(year)
            limit = PD_SOUND_YEAR if kind == "music" else PD_WORK_YEAR
            if y <= limit:
                return result("public_domain", "Public domain (US, expired)",
                              f"published {y}; US copyright lasts 95 years ({'100 for sound recordings' if kind == 'music' else 'published works'})",
                              "age", "medium", key="rr_age_music" if kind == "music" else "rr_age_work", kv={"y": y})
        except (TypeError, ValueError):
            pass
    # 4) online catalogues
    if online and kind in ("movie", "music") and title:
        for fn in (lambda: wikidata(title, year, kind), lambda: internet_archive(title, kind)):
            try:
                r = fn()
            except Exception as e:
                log("    online check failed:", e)
                r = None
            if r:
                return result(r["status"], r.get("license", ""), "found in " + r["source"], r["source"], "medium",
                              r.get("url", ""), r.get("attribute_to") or None, r.get("license_url", ""),
                              key="rr_online", kv={"src": r["source"]})
    # 5) commercial catalogue match
    src = (m.get("source") or "")
    if m.get("enriched") and any(x in src for x in ("iTunes", "TMDB", "Deezer")):
        return result("proprietary", "Commercial release", f"sold/listed commercially ({src})", "catalogue", "medium",
                      attribute_to=m.get("director") or m.get("artist") or who, key="rr_catalogue", kv={"src": src})
    # 6) personal photos / home videos
    if kind == "image" and tags.get("camera"):
        return result("personal", "", f"camera photo ({tags['camera']}) without a copyright tag — yours if you took it",
                      "camera", "low", attribute_to="", key="rr_camera", kv={"cam": tags["camera"]})
    return result("unknown", "", "no licence information found", "", "low", key="rr_none")


def run(args):
    online = "--online" in args
    if online:
        from mc_common import require_online
        require_online()
    force = "--force" in args
    lib = Library()
    items = [m for m in lib.values() if force or not (m.get("rights") or {}).get("rated_at")]
    log(f"Rating rights for {len(items)} item(s){' (with online catalogues)' if online else ' (offline)'}…")
    counts = {}
    for n, m in enumerate(items, 1):
        progress(f"rights {n}/{len(items)}: {m.get('title')}")
        r = rate(m, online)
        m["rights"] = r
        counts[r["status"]] = counts.get(r["status"], 0) + 1
        lib.touch(m)
        lib.save(force=False)
    lib.save()
    log("Rights: " + ", ".join(f"{k} {v}" for k, v in sorted(counts.items())) if counts else "Rights: nothing new to rate")
    log("  Free to share = public_domain + attribution (give credit). Guidance only, not legal advice.")


def shareable(m):
    r = m.get("rights") or {}
    return r.get("status") in ("public_domain", "attribution", "personal") and "ND" not in (r.get("license") or "")


def sharepack(args):
    lib = Library()
    items = [m for m in lib.values() if shareable(m) and not m.get("saved_copy")]
    if not items:
        log("Nothing is marked free to share yet — run: rights   (or rights --online)")
        return
    os.makedirs(SHARE_DIR, exist_ok=True)
    name = f"share-pack-{datetime.datetime.now():%Y%m%d-%H%M}.zip"
    dest = os.path.join(SHARE_DIR, name)
    credits = ["CREDITS — Media shared from Local Media Center", "=" * 48, ""]
    total = 0
    with zipfile.ZipFile(dest + ".part", "w", zipfile.ZIP_STORED) as z:
        for n, m in enumerate(items, 1):
            src = abs_path(m["path"])
            if not os.path.isfile(src):
                continue
            arc = f"{m['type']}/{os.path.basename(src)}"
            progress(f"share pack {n}/{len(items)}: {m.get('title')}")
            z.write(src, arc)
            total += os.path.getsize(src)
            r = m["rights"]
            credits.append(f"{arc}\n  {r.get('credit') or m.get('title')}\n  status: {r['status']}  licence: {r.get('license') or '-'}  ({r.get('reason')})\n")
        credits += ["", "Public domain items can be used freely. CC BY / BY-SA items must keep the credit line above.",
                    "NC licences: non-commercial use only. Personal items: share only what you created yourself."]
        z.writestr("CREDITS.txt", "\n".join(credits))
    os.replace(dest + ".part", dest)
    log(f"Share pack ready: share/{name} ({len(items)} items, {total / 1e6:.1f} MB)")


def save(args):
    """Copy items into media_center/library (keeps metadata, marks the original as saved)."""
    lib = Library()
    if "--free" in args or not args:
        items = [m for m in lib.values() if shareable(m)]
    else:
        items = [lib.items[i] for i in args if i in lib.items]
    items = [m for m in items if not str(m.get("path", "")).startswith("library/") and not m.get("saved_copy")]
    log(f"Saving {len(items)} item(s) into media_center/library …")
    for n, m in enumerate(items, 1):
        src = abs_path(m["path"])
        if not os.path.isfile(src):
            continue
        sub = {"movie": "Movies", "music": "Music", "image": "Pictures"}.get(m["type"], "Other")
        dst_dir = os.path.join(LIB_DIR, sub)
        os.makedirs(dst_dir, exist_ok=True)
        dst = os.path.join(dst_dir, os.path.basename(src))
        k = 1
        while os.path.exists(dst) and os.path.getsize(dst) != os.path.getsize(src):
            b, e = os.path.splitext(os.path.basename(src))
            dst = os.path.join(dst_dir, f"{b} ({k}){e}")
            k += 1
        progress(f"save {n}/{len(items)}: {m.get('title')}")
        if not os.path.exists(dst):
            shutil.copy2(src, dst)
        r = m.get("rights") or {}
        if r.get("status") in ("public_domain", "attribution"):   # keep the evidence with the copy
            side = os.path.splitext(dst)[0] + ".license"
            if not os.path.exists(side):
                with open(side, "w", encoding="utf-8") as fh:
                    fh.write(f"License: {r.get('license') or r['status'].replace('_', ' ')}\n"
                             f"Credit: {r.get('credit') or ''}\nLicense URL: {r.get('license_url') or ''}\n"
                             f"Why: {r.get('reason') or ''}\n")
        rel = store_path(dst)
        new = dict(m, id=media_id(rel), path=rel, folder=store_path(dst_dir), saved_from=m["path"])
        new.pop("saved_copy", None)
        lib.touch(new)
        m["saved_copy"] = new["id"]
        lib.touch(m)
        lib.save(force=False)
    lib.save()
    from mc_common import directories, write_json, DIRS_FILE
    dirs = directories()
    if "library" not in dirs:
        write_json(DIRS_FILE, dirs + ["library"])
    log("Saved. These copies travel with media_center (SD card / USB).")
