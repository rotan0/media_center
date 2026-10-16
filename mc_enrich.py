"""Online metadata: posters, backdrops, synopsis, album covers and trailers.

Sources (no key needed): iTunes Search API, Deezer API, Wikipedia.
Optional: TMDB (config set tmdb_api_key ...) for richer movie data, yt-dlp for YouTube trailers.
Only runs when started from the console — the app itself stays offline.
"""
import os
import re
import shutil
import subprocess
import sys
import time
import urllib.parse

from mc_common import (require_online, BACKDROP_DIR, COVER_DIR, TRAILER_DIR, Library, config, download, http_json, log,
                       media_id, norm_words, progress, rel, similarity)

GENERIC_FOLDERS = {"music", "downloads", "desktop", "documents", "songs", "mp3", "audio", "new folder", "misc",
                   "various", "unknown", "itunes", "itunes media", "media", "my music", ""}
_last_call = {}


def throttle(name, gap):
    wait = _last_call.get(name, 0) + gap - time.time()
    if wait > 0:
        time.sleep(wait)
    _last_call[name] = time.time()


def lang_country():
    lang = (config().get("metadata_language") or "en").split("-")[0]
    country = {"en": "US", "es": "ES", "fr": "FR", "de": "DE", "zh": "CN", "hi": "IN", "ar": "SA", "ja": "JP",
               "pt": "BR", "it": "IT", "ru": "RU", "ko": "KR"}.get(lang, "US")
    return lang, country


# ---------------- TMDB ----------------
def tmdb(path, params=None):
    key = config().get("tmdb_api_key", "").strip()
    if not key:
        return None
    params = dict(params or {})
    headers = {}
    if key.startswith("eyJ"):  # v4 read access token
        headers["Authorization"] = "Bearer " + key
    else:
        params["api_key"] = key
    throttle("tmdb", 0.25)
    return http_json("https://api.themoviedb.org/3" + path, params, headers)


def tmdb_movie(title, year):
    lang, _ = lang_country()
    res = tmdb("/search/movie", {"query": title, "year": year or "", "language": lang, "include_adult": "false"})
    results = (res or {}).get("results") or []
    if not results and year:
        results = ((tmdb("/search/movie", {"query": title, "language": lang}) or {}).get("results")) or []
    best = pick(results, title, year, lambda r: r.get("title") or r.get("original_title"),
                lambda r: (r.get("release_date") or "")[:4])
    if not best:
        return None
    d = tmdb(f"/movie/{best['id']}", {"language": lang, "append_to_response": "videos,credits,release_dates",
                                        "include_video_language": f"{lang},en,null"}) or best
    out = {
        "title": d.get("title"), "year": int(d["release_date"][:4]) if d.get("release_date") else None,
        "synopsis": d.get("overview"), "tagline": d.get("tagline"),
        "genre": ", ".join(g["name"] for g in d.get("genres", [])[:3]),
        "runtime": d.get("runtime"), "rating": d.get("vote_average"), "tmdb_id": d.get("id"),
        "poster_url": "https://image.tmdb.org/t/p/w500" + d["poster_path"] if d.get("poster_path") else None,
        "backdrop_url": "https://image.tmdb.org/t/p/w1280" + d["backdrop_path"] if d.get("backdrop_path") else None,
    }
    crew = (d.get("credits") or {}).get("crew") or []
    out["director"] = ", ".join(c["name"] for c in crew if c.get("job") == "Director")[:80]
    out["cast"] = ", ".join(c["name"] for c in ((d.get("credits") or {}).get("cast") or [])[:6])
    vids = [v for v in (d.get("videos") or {}).get("results", []) if v.get("site") == "YouTube"]
    vids.sort(key=lambda v: (v.get("type") != "Trailer", not v.get("official"), v.get("iso_639_1") != lang))
    if vids:
        out["youtube"] = vids[0]["key"]
    return out


# ---------------- iTunes ----------------
def itunes(params):
    _, country = lang_country()
    params = {"country": country, "limit": 10, **params}
    throttle("itunes", 3.1)  # iTunes allows ~20 requests/minute
    return (http_json("https://itunes.apple.com/search", params) or {}).get("results") or []


def big_art(url, size="600x600"):
    return re.sub(r"/\d+x\d+(bb|-\d+)?\.(jpg|png)$", f"/{size}bb.jpg", url or "") if url else None


def itunes_movie(title, year):
    res = itunes({"term": title, "media": "movie", "entity": "movie"})
    best = pick(res, title, year, lambda r: r.get("trackName"), lambda r: (r.get("releaseDate") or "")[:4])
    if not best:
        return None
    return {
        "title": best.get("trackName"), "year": int(best["releaseDate"][:4]) if best.get("releaseDate") else None,
        "synopsis": best.get("longDescription") or best.get("shortDescription"),
        "genre": best.get("primaryGenreName"), "director": best.get("artistName"),
        "certification": best.get("contentAdvisoryRating"),
        "runtime": round(best["trackTimeMillis"] / 60000) if best.get("trackTimeMillis") else None,
        "poster_url": big_art(best.get("artworkUrl100"), "600x900"),
        "trailer_url": best.get("previewUrl"),
    }


# ---------------- Wikipedia ----------------
def wikipedia(title, year):
    lang, _ = lang_country()
    tries = [f"{title} ({year} film)", f"{title} (film)", title] if year else [f"{title} (film)", title]
    for t in tries:
        throttle("wiki", 0.3)
        try:
            d = http_json(f"https://{lang}.wikipedia.org/api/rest_v1/page/summary/" + urllib.parse.quote(t.replace(" ", "_")))
        except Exception:
            d = None
        if not d or d.get("type") != "standard":
            continue
        desc = (d.get("description") or "").lower()
        if t == title and "film" not in desc and "movie" not in desc:
            continue
        return {"synopsis": d.get("extract"),
                "poster_url": (d.get("originalimage") or d.get("thumbnail") or {}).get("source")}
    return None


# ---------------- Deezer (music) ----------------
def deezer(path, q):
    throttle("deezer", 0.15)
    return (http_json("https://api.deezer.com/search/" + path, {"q": q}) or {}).get("data") or []


def pick(results, title, year, get_title, get_year, min_score=0.6):
    best, best_score = None, 0
    for r in results[:10]:
        s = similarity(title, get_title(r) or "")
        y = get_year(r)
        if year and y and str(y).isdigit():
            s += 0.25 if abs(int(y) - int(year)) == 0 else (0.1 if abs(int(y) - int(year)) == 1 else -0.2)
        if s > best_score:
            best, best_score = r, s
    return best if best_score >= min_score else None


# ---------------- movies ----------------
def enrich_movie(lib, m, force=False, search=None):
    if m.get("kind") == "episode":
        return False
    title = search or m.get("title") or ""
    year = None if search else m.get("year")
    info, source, errors = {}, [], 0
    try:
        t = tmdb_movie(title, year)
        if t:
            info.update({k: v for k, v in t.items() if v}); source.append("TMDB")
    except Exception as e:
        log("  TMDB error:", e); errors += 1
    try:
        it = itunes_movie(title, year)
        if it:
            for k, v in it.items():
                if v and not info.get(k):
                    info[k] = v
            source.append("iTunes")
    except Exception as e:
        log("  iTunes error:", e); errors += 1
    if not info.get("synopsis") or not info.get("poster_url"):
        try:
            w = wikipedia(info.get("title") or title, info.get("year") or year)
            if w:
                for k, v in w.items():
                    if v and not info.get(k):
                        info[k] = v
                source.append("Wikipedia")
        except Exception as e:
            log("  Wikipedia error:", e); errors += 1
    if not info and errors:
        log("  offline or blocked — will retry next time:", title)
        return False
    m["enriched"] = True
    m["enriched_at"] = int(time.time())
    if not info:
        log("  no match found:", title)
        m["source"] = ""
        lib.touch(m)
        return False
    for k in ("title", "year", "synopsis", "tagline", "genre", "runtime", "rating", "director", "cast",
              "certification", "tmdb_id", "youtube", "trailer_url"):
        if info.get(k):
            m[k] = info[k]
    m["source"] = ", ".join(source)
    if info.get("poster_url"):
        dest = os.path.join(COVER_DIR, m["id"] + ".jpg")
        if (force or not os.path.isfile(dest)) and download(info["poster_url"], dest):
            pass
        if os.path.isfile(dest):
            m["poster"] = rel(dest) + f"?v={int(os.path.getmtime(dest))}"
    if info.get("backdrop_url"):
        dest = os.path.join(BACKDROP_DIR, m["id"] + ".jpg")
        if (force or not os.path.isfile(dest)) and download(info["backdrop_url"], dest):
            pass
        if os.path.isfile(dest):
            m["backdrop"] = rel(dest) + f"?v={int(os.path.getmtime(dest))}"
    log(f"  ✓ {m['title']} ({m.get('year') or '?'}) — {m['source']}")
    lib.touch(m)
    if config().get("download_trailers"):
        get_trailer(lib, m, force)
    return True


def ytdlp_cmd():
    from mc_common import local_tool
    local = local_tool("ytdlp")
    if local:
        return [local] if os.name == "nt" else [sys.executable, local]
    exe = shutil.which("yt-dlp")
    if exe:
        return [exe]
    try:
        import yt_dlp  # noqa: F401
        return [sys.executable, "-m", "yt_dlp"]
    except ImportError:
        return None


def get_trailer(lib, m, force=False):
    for ext in ("mp4", "m4v", "webm"):
        p = os.path.join(TRAILER_DIR, f"{m['id']}.{ext}")
        if os.path.isfile(p) and not force:
            m["trailer"] = rel(p)
            lib.touch(m)
            return True
    if m.get("trailer_url"):
        dest = os.path.join(TRAILER_DIR, m["id"] + ".m4v")
        progress(f"trailer: {m['title']}")
        if download(m["trailer_url"], dest, kind="video"):
            m["trailer"] = rel(dest)
            lib.touch(m)
            log("    trailer saved (iTunes)")
            return True
    cmd = ytdlp_cmd() if config().get("use_ytdlp") else None
    if cmd:
        target = f"https://www.youtube.com/watch?v={m['youtube']}" if m.get("youtube") else \
            f"ytsearch1:{m['title']} {m.get('year') or ''} official trailer"
        out = os.path.join(TRAILER_DIR, m["id"] + ".%(ext)s")
        progress(f"trailer (yt-dlp): {m['title']}")
        try:
            subprocess.run(cmd + ["-q", "--no-playlist", "--no-warnings", "-f",
                                  "best[height<=720][ext=mp4]/best[ext=mp4]/best[height<=720]",
                                  "--max-filesize", "150M", "--match-filter", "duration < 420",
                                  "-o", out, target], timeout=600, capture_output=True,
                           **({"creationflags": 0x08000000} if os.name == "nt" else {}))
        except Exception as e:
            log("    yt-dlp error:", e)
        for ext in ("mp4", "webm", "m4v"):
            p = os.path.join(TRAILER_DIR, f"{m['id']}.{ext}")
            if os.path.isfile(p):
                m["trailer"] = rel(p)
                lib.touch(m)
                log("    trailer saved (YouTube)")
                return True
    return False


# ---------------- music ----------------
def album_groups(items):
    groups = {}
    for m in items:
        key = (m.get("album") or "") + "|" + (m.get("album_artist") or m.get("folder") or "")
        groups.setdefault(key, []).append(m)
    return groups


def enrich_album(lib, tracks, force=False, search=None):
    first = tracks[0]
    album = first.get("album") or ""
    artists = [t.get("artist") for t in tracks if t.get("artist")]
    artist = first.get("album_artist") or (max(set(artists), key=artists.count) if artists else "")
    generic = first.get("album_from_folder") and norm_words(album) in GENERIC_FOLDERS
    cover_url, info, source = None, {}, ""
    try:
        if search or not generic:
            q = search or f"{artist} {album}".strip()
            res = deezer("album", q)
            best = pick(res, album or q, None, lambda r: r.get("title"), lambda r: None, 0.5) if not search else (res[0] if res else None)
            if best:
                cover_url = best.get("cover_xl") or best.get("cover_big")
                info = {"album": best.get("title"), "album_artist": (best.get("artist") or {}).get("name")}
                source = "Deezer"
            if not cover_url:
                res = itunes({"term": q, "media": "music", "entity": "album"})
                best = pick(res, album or q, None, lambda r: r.get("collectionName"), lambda r: None, 0.5) if not search else (res[0] if res else None)
                if best:
                    cover_url = big_art(best.get("artworkUrl100"))
                    info = {"album": best.get("collectionName"), "genre": best.get("primaryGenreName"),
                            "year": int(best["releaseDate"][:4]) if best.get("releaseDate") else None}
                    source = "iTunes"
    except Exception as e:
        log("  album lookup error:", e)
        return False

    if cover_url or info:
        key = media_id(album + "|" + (first.get("album_artist") or first.get("folder") or ""))
        dest = os.path.join(COVER_DIR, "album_" + key + ".jpg")
        if cover_url and (force or search or not os.path.isfile(dest)):
            download(cover_url, dest)
        for t in tracks:
            if os.path.isfile(dest):
                t["cover"] = rel(dest) + f"?v={int(os.path.getmtime(dest))}"
            for k in ("genre", "year"):
                if info.get(k) and not t.get(k):
                    t[k] = info[k]
            t["enriched"] = True
            t["source"] = source
            lib.touch(t)
        log(f"  ✓ album: {album} — {artist or '?'} ({source})")
        return True

    # per-song lookup (loose files, generic folders)
    for t in tracks:
        if t.get("enriched") and not force:
            continue
        progress(f"song: {t.get('title')}")
        q = search or f"{t.get('artist') or ''} {t.get('title') or ''}".strip()
        try:
            res = deezer("track", q)
            best = res[0] if res and similarity(t.get("title"), res[0].get("title")) > 0.5 else None
            if best:
                alb = best.get("album") or {}
                dest = os.path.join(COVER_DIR, f"deezer_{alb.get('id')}.jpg")
                if alb.get("cover_xl") and not os.path.isfile(dest):
                    download(alb["cover_xl"], dest)
                if os.path.isfile(dest):
                    t["cover"] = rel(dest)
                if not t.get("artist"):
                    t["artist"] = (best.get("artist") or {}).get("name")
                if t.get("album_from_folder") and alb.get("title"):
                    t["album"] = alb["title"]
                    t["album_from_folder"] = False
                t["source"] = "Deezer"
                log(f"  ✓ song: {t.get('artist')} — {t.get('title')}")
        except Exception as e:
            log("  song lookup error:", e)
            continue
        t["enriched"] = True
        lib.touch(t)
    return True


# ---------------- commands ----------------
def online():
    for url in ("https://itunes.apple.com/search?term=test&limit=1", "https://api.deezer.com/search?q=test&limit=1",
                "https://en.wikipedia.org/api/rest_v1/page/summary/Film"):
        try:
            http_json(url, timeout=8, retries=0)
            return True
        except Exception as e:
            last = e
    log("No internet connection (or blocked by a firewall/proxy):", last)
    return False


def run(args):
    require_online()
    if not online():
        return
    what = next((a for a in args if a in ("movies", "music", "all")), "all")
    force = "--force" in args
    lib = Library()
    movies = [m for m in lib.values("movie") if force or not m.get("enriched")]
    music = [m for m in lib.values("music") if force or not m.get("enriched")]
    if what in ("movies", "all"):
        log(f"Looking up {len(movies)} movie(s) online…")
        if not config().get("tmdb_api_key"):
            log("  tip: a free TMDB key gives backdrops, cast & YouTube trailers: config set tmdb_api_key YOUR_KEY")
        for n, m in enumerate(movies, 1):
            progress(f"movie {n}/{len(movies)}: {m.get('title')}")
            enrich_movie(lib, m, force)
            lib.save(force=False)
        lib.save()
    if what in ("music", "all"):
        groups = album_groups(music)
        log(f"Looking up {len(groups)} album(s) / {len(music)} song(s) online…")
        for n, (k, tracks) in enumerate(groups.items(), 1):
            progress(f"album {n}/{len(groups)}: {tracks[0].get('album')}")
            enrich_album(lib, tracks, force)
            lib.save(force=False)
        lib.save()
    log("Online lookup finished.")


def fetch(args):
    require_online()
    text = " ".join(args)
    search = None
    if "=" in text:
        text, search = [x.strip() for x in text.split("=", 1)]
        search = search or None
    lib = Library()
    words = norm_words(text).split()
    hits = [m for m in lib.values() if m["type"] in ("movie", "music") and all(w in norm_words(m.get("title")) for w in words)]
    if not hits:
        hits = [m for m in lib.values() if m["type"] == "music" and all(w in norm_words(m.get("album")) for w in words)]
    if not hits:
        log("No library item matches:", text)
        return
    m = sorted(hits, key=lambda x: -similarity(text, x.get("title")))[0]
    log(f"Re-matching '{m.get('title')}' using search: {search or m.get('title')}")
    if m["type"] == "movie":
        for k in ("synopsis", "tagline", "genre", "rating", "director", "cast", "youtube", "trailer_url", "certification"):
            m.pop(k, None)
        if search:
            for f in ("trailer",):
                m.pop(f, None)
            for d, ext in ((TRAILER_DIR, "m4v"), (TRAILER_DIR, "mp4"), (TRAILER_DIR, "webm")):
                p = os.path.join(d, f"{m['id']}.{ext}")
                if os.path.isfile(p):
                    os.remove(p)
        enrich_movie(lib, m, force=True, search=search)
    else:
        groups = album_groups(lib.values("music"))
        key = (m.get("album") or "") + "|" + (m.get("album_artist") or m.get("folder") or "")
        enrich_album(lib, groups.get(key, [m]), force=True, search=search)
    lib.save()


def search(args):
    require_online()
    q = " ".join(args)
    log(f"Online search: {q}")
    try:
        if config().get("tmdb_api_key"):
            for r in ((tmdb("/search/movie", {"query": q}) or {}).get("results") or [])[:5]:
                log(f"  [TMDB movie] {r.get('title')} ({(r.get('release_date') or '')[:4]}) — {(r.get('overview') or '')[:110]}")
        for r in itunes({"term": q, "media": "movie", "entity": "movie", "limit": 5})[:5]:
            log(f"  [iTunes movie] {r.get('trackName')} ({(r.get('releaseDate') or '')[:4]}) {'· has trailer' if r.get('previewUrl') else ''}")
        for r in deezer("album", q)[:5]:
            log(f"  [Deezer album] {r.get('title')} — {(r.get('artist') or {}).get('name')}")
    except Exception as e:
        log("  search error:", e)
    log('Use: fetch <library title> = <exact search text>   to apply a match.')


def trailers(args):
    require_online()
    if not online():
        return
    lib = Library()
    todo = [m for m in lib.values("movie") if not m.get("trailer") and m.get("kind") != "episode"]
    log(f"Downloading trailers for {len(todo)} movie(s)…")
    if not ytdlp_cmd():
        log("  yt-dlp not installed — only iTunes previews can be used (run: setup)")
    got = 0
    for n, m in enumerate(todo, 1):
        progress(f"trailer {n}/{len(todo)}: {m.get('title')}")
        if not m.get("enriched"):
            enrich_movie(lib, m)
        if get_trailer(lib, m):
            got += 1
        lib.save(force=False)
    lib.save()
    log(f"Trailers finished: {got} downloaded.")
