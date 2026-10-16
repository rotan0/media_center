"""Scan library folders: index files, read music tags, make thumbnails. Works offline."""
import json
import os
import re
import shutil
import subprocess
import time

from mc_common import (COVER_DIR, THUMB_DIR, Library, abs_path, config, directories, local_tool, log,
                       media_id, media_type, norm_path, parse_movie, parse_music, progress, rel, store_path,
                       under)

try:
    from PIL import Image, ImageOps
except ImportError:
    Image = None
try:
    import mutagen
except ImportError:
    mutagen = None

EPISODE = re.compile(r"[Ss](\d{1,2})[ ._-]?[Ee](\d{1,3})")
FOLDER_YEAR = re.compile(r"^(.+?)\s*[\(\[]((?:19|20)\d{2})[\)\]]")


def _no_window():
    return {"creationflags": 0x08000000} if os.name == "nt" else {}  # CREATE_NO_WINDOW


def tool(name):
    local = local_tool(name)
    if local:
        return local
    cfg = config()
    exe = cfg.get(name) or name
    return shutil.which(exe) or (exe if os.path.isfile(exe) else None)


# ---------------- movies ----------------
def movie_title(item, path):
    base = os.path.splitext(os.path.basename(path))[0]
    parent = os.path.basename(os.path.dirname(path))
    ep = EPISODE.search(base)
    if ep:
        show = re.split(r"[ ._-]*[Ss]\d{1,2}[ ._-]?[Ee]\d", base)[0]
        show = parse_movie(show)[0] if show.strip(" ._-") else parse_movie(parent)[0]
        item.update(title=f"{show} S{int(ep.group(1)):02d}E{int(ep.group(2)):02d}", show=show, kind="episode",
                    season=int(ep.group(1)), episode=int(ep.group(2)))
        return
    title, year = parse_movie(base)
    fm = FOLDER_YEAR.match(parent)
    if not year and fm:  # "Movie Name (2010)/movie.mkv"
        title, year = parse_movie(parent)
    item.update(title=title, year=year)


def video_duration(ffprobe, path):
    try:
        out = subprocess.run([ffprobe, "-v", "error", "-show_entries", "format=duration", "-of", "json", path],
                             capture_output=True, text=True, timeout=30, **_no_window()).stdout
        return float(json.loads(out)["format"]["duration"])
    except Exception:
        return None


def video_thumb(ffmpeg, path, dest, duration):
    points = [max(3, duration * 0.12)] if duration else [60, 3]
    points.append(3)
    for sec in points:
        try:
            subprocess.run([ffmpeg, "-y", "-loglevel", "error", "-ss", str(int(sec)), "-i", path, "-frames:v", "1",
                            "-vf", "scale=640:-2", "-q:v", "4", dest],
                           capture_output=True, timeout=60, **_no_window())
            if os.path.isfile(dest) and os.path.getsize(dest) > 1000:
                return True
        except Exception as e:
            log("  ffmpeg error:", e)
    return False


# ---------------- music ----------------
def _first(tags, *keys):
    for k in keys:
        v = tags.get(k)
        if v:
            v = v[0] if isinstance(v, list) else v
            return str(v).strip()
    return ""


def _num(v):
    m = re.match(r"\d+", str(v or ""))
    return int(m.group()) if m else None


def music_tags(item, path):
    if not mutagen:
        return
    try:
        easy = mutagen.File(path, easy=True)
        if easy is None:
            return
        tags = easy.tags or {}
        if easy.info and getattr(easy.info, "length", None):
            item["duration"] = round(easy.info.length)
        title = _first(tags, "title")
        if title:
            item["title"] = title
        for key, src in (("artist", ("artist",)), ("album", ("album",)), ("album_artist", ("albumartist",)),
                         ("genre", ("genre",))):
            v = _first(tags, *src)
            if v:
                item[key] = v
        if _first(tags, "album"):
            item["album_from_folder"] = False
        t = _num(_first(tags, "tracknumber"))
        if t:
            item["track"] = t
        d = _num(_first(tags, "discnumber"))
        if d:
            item["disc"] = d
        y = _num(_first(tags, "date", "year"))
        if y and 1900 < y < 2100:
            item["year"] = y
    except Exception as e:
        log("  tag read failed:", os.path.basename(path), e)


def embedded_art(path):
    if not mutagen:
        return None
    try:
        f = mutagen.File(path)
        if f is None:
            return None
        if hasattr(f, "pictures") and f.pictures:  # FLAC / OGG
            return f.pictures[0].data
        tags = f.tags
        if tags is None:
            return None
        for k in list(tags.keys()):
            if k.startswith("APIC"):  # MP3
                return tags[k].data
        if "covr" in tags and tags["covr"]:  # M4A
            return bytes(tags["covr"][0])
        if "WM/Picture" in tags:  # WMA
            v = tags["WM/Picture"][0].value
            return v[v.find(b"\xff\xd8"):] if b"\xff\xd8" in v else None
    except Exception:
        return None
    return None


def album_key(item):
    return media_id((item.get("album") or "") + "|" + (item.get("album_artist") or item.get("folder") or ""))


def folder_cover(folder):
    for name in ("cover", "folder", "front", "album", "albumart", "AlbumArtLarge"):
        for ext in (".jpg", ".jpeg", ".png", ".webp"):
            for n in (name, name.capitalize()):
                p = os.path.join(folder, n + ext)
                if os.path.isfile(p):
                    return p
    return None


# ---------------- images ----------------
def image_info(item, path, dest, make_thumb):
    if not Image or item.get("ext") in ("svg", "heic"):
        return
    try:
        with Image.open(path) as im:
            item["width"], item["height"] = im.size
            try:
                exif = im.getexif()
                dt = exif.get(36867) or exif.get_ifd(0x8769).get(36867) or exif.get(306)
                if dt:
                    item["taken"] = str(dt)[:10].replace(":", "-")
            except Exception:
                pass
            if make_thumb:
                im = ImageOps.exif_transpose(im)
                im.thumbnail((480, 480))
                if im.mode not in ("RGB", "L"):
                    im = im.convert("RGB")
                im.save(dest, "JPEG", quality=82)
                item["thumbnail"] = rel(dest)
    except Exception as e:
        log("  image error:", os.path.basename(path), e)


# ---------------- main ----------------
def run(args):
    only = None
    if "--dir" in args:
        i = args.index("--dir")
        only = [store_path(args[i + 1])] if i + 1 < len(args) else None
    full = "--full" in args
    dirs = directories()
    scan_dirs = only or dirs
    lib = Library()
    ffmpeg, ffprobe = tool("ffmpeg"), None
    if ffmpeg:
        probe = os.path.join(os.path.dirname(ffmpeg), "ffprobe" + (".exe" if os.name == "nt" else ""))
        ffprobe = local_tool("ffprobe") or (probe if os.path.isfile(probe) else shutil.which("ffprobe"))
        log("  ffmpeg:", store_path(ffmpeg))
    log(f"Scanning {len(scan_dirs)} folder(s)…")
    if not ffmpeg:
        log("  ffmpeg not found: video thumbnails skipped (install ffmpeg or: config set ffmpeg C:/ffmpeg/bin/ffmpeg.exe)")
    if not Image:
        log("  Pillow not installed: picture thumbnails skipped (run: setup)")
    if not mutagen:
        log("  mutagen not installed: music tags & embedded covers skipped (run: setup)")

    # 1) index files
    seen, todo = set(), []
    for d in scan_dirs:
        if not os.path.isdir(abs_path(d)):
            log("  missing folder:", d)
            continue
        for root, subdirs, files in os.walk(abs_path(d)):
            subdirs[:] = [s for s in subdirs if not s.startswith((".", "$"))]
            for f in files:
                t = media_type(f)
                if not t or f.startswith(".") or f.lower().startswith("sample"):
                    continue
                path = store_path(os.path.join(root, f))
                i = media_id(path)
                seen.add(i)
                try:
                    st = os.stat(abs_path(path))
                except OSError:
                    continue
                item = lib.items.get(i)
                new = item is None
                changed = new or full or item.get("mtime") != int(st.st_mtime) or not item.get("scanned")
                if new:
                    item = {"id": i, "path": path, "type": t, "added": int(time.time())}
                item.update(ext=f.rsplit(".", 1)[-1].lower(), size=st.st_size, mtime=int(st.st_mtime),
                            folder=store_path(root))
                if new or (full and not item.get("enriched")):
                    base = os.path.splitext(f)[0]
                    if t == "movie":
                        movie_title(item, abs_path(path))
                    elif t == "music":
                        item.update(parse_music(base, os.path.basename(root)))
                        item["album_from_folder"] = True
                    else:
                        item["title"] = base
                lib.touch(item)
                if changed:
                    todo.append(i)
        progress(f"indexed {len(seen)} files")

    # drop files that disappeared (inside scanned folders) or whose folder was removed
    for m in list(lib.values()):
        p = m.get("path", "")
        in_scan = any(under(p, d) for d in scan_dirs)
        in_any = any(under(p, d) for d in dirs)
        if (in_scan and m["id"] not in seen) or not in_any:
            lib.remove(m["id"])
    lib.save()
    log(f"  {len(seen)} media files, {len(todo)} new or changed")

    # 2) details & thumbnails
    covers_done = {}
    for n, i in enumerate(todo, 1):
        item = lib.items.get(i)
        if not item:
            continue
        progress(f"{n}/{len(todo)} {item.get('title', '')}")
        path, t = abs_path(item["path"]), item["type"]
        thumb = os.path.join(THUMB_DIR, i + ".jpg")
        if t == "movie":
            dur = video_duration(ffprobe, path) if ffprobe else None
            if dur:
                item["duration"] = round(dur)
                item.setdefault("runtime", round(dur / 60))
            if ffmpeg and (full or not os.path.isfile(thumb)):
                if video_thumb(ffmpeg, path, thumb, dur):
                    item["thumbnail"] = rel(thumb)
            elif os.path.isfile(thumb):
                item["thumbnail"] = rel(thumb)
        elif t == "music":
            music_tags(item, path)
            key = album_key(item)
            cover = os.path.join(COVER_DIR, "album_" + key + ".jpg")
            if key not in covers_done:
                ok = os.path.isfile(cover)
                if not ok:
                    fc = folder_cover(os.path.dirname(path))
                    data = embedded_art(path)
                    if data:
                        with open(cover, "wb") as f:
                            f.write(data)
                        ok = True
                    elif fc:
                        shutil.copyfile(fc, cover)
                        ok = True
                covers_done[key] = ok
            if covers_done[key]:
                item["cover"] = rel(cover)
        else:
            image_info(item, path, thumb, full or not os.path.isfile(thumb))
            if os.path.isfile(thumb):
                item["thumbnail"] = rel(thumb)
        item["scanned"] = True
        lib.touch(item)
        lib.save(force=False)
    lib.save()
    c = {"movie": 0, "music": 0, "image": 0}
    for m in lib.values():
        c[m["type"]] = c.get(m["type"], 0) + 1
    log(f"Scan complete: {c['movie']} movies, {c['music']} songs, {c['image']} images.")
    need = sum(1 for m in lib.values() if m["type"] in ("movie", "music") and not m.get("enriched"))
    if need:
        log(f"  {need} items have no online info yet — type 'enrich' to fetch covers, synopsis and trailers.")
