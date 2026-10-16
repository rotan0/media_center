"""Shared helpers for the Media Center Python tools (standard library only)."""
import hashlib
import json
import os
import re
import sys
import threading
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = os.path.dirname(os.path.abspath(__file__))
DATA = os.path.join(ROOT, "data")
LANG_DIR = os.path.join(DATA, "lang")
MEDIA_FILE = os.path.join(DATA, "media.json")
DIRS_FILE = os.path.join(DATA, "directories.json")
CONFIG_FILE = os.path.join(DATA, "config.json")
JOB_FILE = os.path.join(DATA, "job.json")
LOG_FILE = os.path.join(DATA, "console.log")
THUMB_DIR = os.path.join(ROOT, "thumbnails")
COVER_DIR = os.path.join(ROOT, "covers")
BACKDROP_DIR = os.path.join(ROOT, "backdrops")
TRAILER_DIR = os.path.join(ROOT, "trailers")
TOOLS = os.path.join(ROOT, "tools")
NET_FILE = os.path.join(DATA, "net.json")
for _d in (DATA, LANG_DIR, THUMB_DIR, COVER_DIR, BACKDROP_DIR, TRAILER_DIR):
    os.makedirs(_d, exist_ok=True)

EXT = {
    "movie": {"mp4", "m4v", "mkv", "avi", "mov", "wmv", "webm", "mpg", "mpeg", "flv", "ts", "3gp"},
    "music": {"mp3", "wav", "flac", "m4a", "aac", "ogg", "oga", "opus", "wma", "aiff"},
    "image": {"jpg", "jpeg", "png", "gif", "webp", "bmp", "tif", "tiff", "heic", "svg"},
}

DEFAULT_CONFIG = {
    "python": "python", "ffmpeg": "ffmpeg", "tmdb_api_key": "",
    "anthropic_api_key": "", "anthropic_model": "claude-sonnet-4-5",
    "openai_api_key": "", "openai_model": "gpt-4o-mini",
    "ollama_url": "http://localhost:11434", "ollama_model": "llama3.1",
    "translate_provider": "auto", "download_trailers": True, "use_ytdlp": True,
    "auto_scan_on_add": True, "metadata_language": "en", "online": False,
}


# ---------------- JSON ----------------
def read_json(path, default=None):
    try:
        with open(path, "r", encoding="utf-8-sig") as f:
            txt = f.read()
        return json.loads(txt) if txt.strip() else default
    except (OSError, ValueError):
        return default


def write_json(path, data):
    tmp = f"{path}.{os.getpid()}.tmp"
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False, indent=2)
    for _ in range(20):  # Windows: the file may be open in PHP for a moment
        try:
            os.replace(tmp, path)
            return
        except PermissionError:
            time.sleep(0.1)
    with open(path, "w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False, indent=2)
    os.remove(tmp)


def config():
    cfg = dict(DEFAULT_CONFIG)
    cfg.update(read_json(CONFIG_FILE, {}) or {})
    return cfg


# ---------------- paths ----------------
def norm_path(p):
    p = str(p).strip().replace("\\", "/")
    p = re.sub(r"/+", "/", p)
    if re.fullmatch(r"[A-Za-z]:", p):
        p += "/"
    if len(p) > 1 and p.endswith("/") and not re.fullmatch(r"[A-Za-z]:/", p):
        p = p.rstrip("/")
    return p


_ROOT_N = None


def root_norm():
    global _ROOT_N
    if _ROOT_N is None:
        _ROOT_N = norm_path(ROOT)
    return _ROOT_N


def store_path(p):
    """Relative path when inside media_center (keeps the folder portable), else absolute."""
    p, r = norm_path(p), root_norm()
    if p.lower() == r.lower():
        return "."
    if p.lower().startswith(r.lower() + "/"):
        return p[len(r) + 1:]
    return p


def abs_path(p):
    p = norm_path(p or "")
    if not p or re.match(r"^([A-Za-z]:)?/", p):
        return p
    return root_norm() if p == "." else norm_path(os.path.join(ROOT, p))


def under(path, d):
    d = d.rstrip("/")
    if d == ".":
        return not re.match(r"^([A-Za-z]:)?/", path)
    return path.lower().startswith(d.lower() + "/")


def local_tool(name):
    """Path of a tool inside media_center/tools, or None."""
    win = os.name == "nt"
    p = {
        "ffmpeg": os.path.join(TOOLS, "ffmpeg", "ffmpeg.exe" if win else "ffmpeg"),
        "ffprobe": os.path.join(TOOLS, "ffmpeg", "ffprobe.exe" if win else "ffprobe"),
        "ytdlp": os.path.join(TOOLS, "yt-dlp.exe" if win else "yt-dlp"),
    }.get(name)
    return p if p and os.path.isfile(p) else None


def media_id(path):
    return hashlib.md5(norm_path(path).encode("utf-8")).hexdigest()


def media_type(name):
    ext = name.rsplit(".", 1)[-1].lower() if "." in name else ""
    for t, exts in EXT.items():
        if ext in exts:
            return t
    return None


def directories():
    out = []
    for d in read_json(DIRS_FILE, []) or []:
        d = store_path(d or "") if d else ""
        if d and d not in ("undefined", "null") and d not in out:
            out.append(d)
    return out


def rel(path):
    """Path relative to the app root with forward slashes (usable as a URL)."""
    return os.path.relpath(path, ROOT).replace("\\", "/")


# ---------------- library ----------------
class Library:
    """media.json with merge-on-save, so PHP and Python edits don't clobber each other."""

    def __init__(self):
        self.items = {m["id"]: m for m in (read_json(MEDIA_FILE, []) or []) if isinstance(m, dict) and "id" in m}
        self.dirty = set()
        self.removed = set()
        self.last_save = time.time()

    def values(self, type_=None):
        return [m for m in self.items.values() if type_ is None or m.get("type") == type_]

    def touch(self, item):
        self.items[item["id"]] = item
        self.dirty.add(item["id"])

    def remove(self, id_):
        self.items.pop(id_, None)
        self.removed.add(id_)

    def save(self, force=True):
        if not force and time.time() - self.last_save < 5:
            return
        disk = {m["id"]: m for m in (read_json(MEDIA_FILE, []) or []) if isinstance(m, dict) and "id" in m}
        for i in self.dirty:
            if i in self.items:
                disk[i] = {**disk.get(i, {}), **self.items[i]}
        for i in self.removed:
            disk.pop(i, None)
        write_json(MEDIA_FILE, list(disk.values()))
        self.items = disk
        self.dirty.clear()
        self.removed.clear()
        self.last_save = time.time()


# ---------------- job / log ----------------
class _Tee:
    def __init__(self, stream):
        self.stream = stream
        self.file = open(LOG_FILE, "a", encoding="utf-8", buffering=1)

    def write(self, s):
        self.file.write(s)
        self.file.flush()
        try:
            if self.stream:
                self.stream.write(s)
        except Exception:
            pass

    def flush(self):
        self.file.flush()


_job = {"cmd": "", "progress": ""}


def _heartbeat():
    while True:
        time.sleep(15)
        job_write()


def job_write(running=True):
    write_json(JOB_FILE, {"running": running, "cmd": _job["cmd"], "progress": _job["progress"],
                          "started": _job.get("started"), "heartbeat": int(time.time())})


def job_start(cmd):
    sys.stdout = _Tee(sys.__stdout__ if sys.__stdout__ and sys.__stdout__.isatty() else None)
    sys.stderr = sys.stdout
    _job.update(cmd=cmd, started=int(time.time()), progress="")
    job_write()
    threading.Thread(target=_heartbeat, daemon=True).start()


def progress(text):
    _job["progress"] = text
    now = time.time()
    if now - _job.get("_last", 0) > 1.5:
        _job["_last"] = now
        job_write()


def job_end():
    NET.done()
    _job["progress"] = ""
    job_write(False)


def log(*a):
    print(time.strftime("[%H:%M:%S]"), *a, flush=True)


# ---------------- network meter (shared with PHP via data/net.json) ----------------
class _Net:
    def __init__(self):
        self.pending = 0
        self.session = 0
        self.window = []
        self.last = 0.0
        self.host = ""
        self.service = ""
        self.lock = threading.Lock()

    def add(self, host, n, service="", final=False):
        with self.lock:
            now = time.time()
            self.pending += n
            self.session += n
            if host:
                self.host = host
            if service:
                self.service = service
            self.window.append((now, n))
            while self.window and now - self.window[0][0] > 3:
                self.window.pop(0)
            if not final and now - self.last < 0.5:
                return
            self.last = now
            span = max(0.5, now - self.window[0][0]) if self.window else 1
            rate = int(sum(b for _, b in self.window) / span)
            d = read_json(NET_FILE, {}) or {}
            d["total_bytes"] = int(d.get("total_bytes", 0)) + self.pending
            self.pending = 0
            d.update(active=not final, host=self.host, service=self.service, rate=0 if final else rate,
                     session_bytes=self.session, updated=int(now))
            try:
                write_json(NET_FILE, d)
            except OSError:
                pass

    def done(self):
        if self.session or self.pending:
            self.add("", 0, final=True)


NET = _Net()
SERVICES = {"itunes.apple.com": "iTunes", "api.deezer.com": "Deezer", "wikipedia.org": "Wikipedia",
            "themoviedb.org": "TMDB", "image.tmdb.org": "TMDB", "mzstatic.com": "iTunes", "dzcdn.net": "Deezer",
            "anthropic.com": "Claude AI", "openai.com": "OpenAI", "mymemory.translated.net": "MyMemory",
            "localhost": "Ollama", "apple.com": "iTunes"}


def _service(host):
    for k, v in SERVICES.items():
        if k in host:
            return v
    return host


# ---------------- HTTP ----------------
UA = "LocalMediaCenter/2.0 (personal offline media library)"


class OfflineError(RuntimeError):
    pass


def require_online():
    """Offline mode (the default) means Media Center never contacts the internet."""
    if not config().get("online"):
        raise OfflineError("Offline mode: nothing is downloaded. Switch to Online to use this.")


def http_get(url, params=None, headers=None, timeout=20, data=None, method=None):
    if (urllib.parse.urlparse(url).hostname or "") not in ("localhost", "127.0.0.1"):   # a local AI (Ollama) stays allowed
        require_online()
    if params:
        url += ("&" if "?" in url else "?") + urllib.parse.urlencode(params, doseq=True)
    h = {"User-Agent": UA, "Accept": "*/*"}
    h.update(headers or {})
    body = None
    if data is not None:
        body = json.dumps(data).encode("utf-8")
        h.setdefault("Content-Type", "application/json")
    req = urllib.request.Request(url, data=body, headers=h, method=method)
    host = urllib.parse.urlparse(url).hostname or ""
    if body:
        NET.add(host, len(body), _service(host))
    with urllib.request.urlopen(req, timeout=timeout) as r:
        raw = r.read()
        NET.add(host, len(raw) + 300, _service(host))
        return raw, r.headers.get("Content-Type", "")


def http_json(url, params=None, headers=None, timeout=20, data=None, retries=2):
    for attempt in range(retries + 1):
        try:
            raw, _ = http_get(url, params, headers, timeout, data)
            return json.loads(raw.decode("utf-8"))
        except urllib.error.HTTPError as e:
            if e.code in (403, 429, 503) and attempt < retries:
                time.sleep(8 * (attempt + 1))
                continue
            if e.code == 404:
                return None
            raise
        except (urllib.error.URLError, TimeoutError, ConnectionError):
            if attempt < retries:
                time.sleep(2)
                continue
            raise


def download(url, dest, kind="image", max_bytes=200 * 1024 * 1024):
    """Download url to dest if the server returns the expected content type."""
    require_online()
    try:
        req = urllib.request.Request(url, headers={"User-Agent": UA})
        host = urllib.parse.urlparse(url).hostname or ""
        with urllib.request.urlopen(req, timeout=60) as r:
            ctype = r.headers.get("Content-Type", "")
            if kind not in ctype and "octet-stream" not in ctype:
                return False
            tmp = dest + ".part"
            size = 0
            with open(tmp, "wb") as f:
                while True:
                    chunk = r.read(262144)
                    if not chunk:
                        break
                    size += len(chunk)
                    NET.add(host, len(chunk), _service(host))
                    if size > max_bytes:
                        break
                    f.write(chunk)
        if size < 500 or size > max_bytes:
            os.remove(tmp)
            return False
        os.replace(tmp, dest)
        return True
    except Exception as e:
        log("  download failed:", url, e)
        return False


# ---------------- titles ----------------
_TAGS = r"\b(2160p|1080p|720p|480p|4k|uhd|hdr|bluray|blu-ray|brrip|bdrip|webrip|web-dl|webdl|hdtv|dvdrip|x264|x265|h264|h265|hevc|aac|ac3|dts|remux|proper|repack|extended|unrated|yify|rarbg)\b.*$"


def clean_title(name):
    t = re.sub(r"[._]+", " ", name)
    t = re.sub(r"\[[^\]]*\]|\{[^}]*\}", " ", t)
    t = re.sub(_TAGS, "", t, flags=re.I)
    t = re.sub(r"\s+", " ", t).strip(" -()")
    return t or name


def parse_movie(name):
    m = re.match(r"^(.*?)[\s._(\[-]+((?:19|20)\d{2})(?:[\s._)\]-]|$)", name)
    if m and m.group(1).strip():
        return clean_title(m.group(1)), int(m.group(2))
    return clean_title(name), None


def parse_music(name, folder):
    name = name.replace("_", " ")
    track = None
    m = re.match(r"^(\d{1,3})[\s.\-_]+(.*)$", name)
    if m:
        track, name = int(m.group(1)), m.group(2)
    artist, title, album = "", name, folder
    if " - " in folder:  # "Artist - Album" folder
        artist, album = [x.strip() for x in folder.split(" - ", 1)]
    if " - " in name:
        artist, title = [x.strip() for x in name.split(" - ", 1)]
    return {"title": title.strip(), "artist": artist, "album": album, "track": track}


def norm_words(s):
    s = re.sub(r"[^\w\s]", " ", str(s or "").lower())
    s = re.sub(r"\b(the|a|an)\b", " ", s)
    return re.sub(r"\s+", " ", s).strip()


def similarity(a, b):
    import difflib
    return difflib.SequenceMatcher(None, norm_words(a), norm_words(b)).ratio()
