"""Language file generator.

  lang scan              find every t('key', 'English') / T('key', 'English') in the app and update en.json
  lang gen de ja ...     create/complete data/lang/<code>.json by AI translation
  lang update            translate missing words in every existing language file

Translation providers (config translate_provider = auto|claude|openai|ollama|mymemory):
  claude   -> config set anthropic_api_key sk-ant-...
  openai   -> config set openai_api_key sk-...
  ollama   -> local offline AI (https://ollama.com), config set ollama_model llama3.1
  mymemory -> free web translation, no key (daily limit)
"""
import json
import os
import re
import time

from mc_common import LANG_DIR, ROOT, config, http_json, log, progress, read_json, write_json

NAMES = {
    "en": "English", "es": "Español", "fr": "Français", "de": "Deutsch", "zh": "中文 (简体)", "hi": "हिन्दी",
    "ar": "العربية", "pt": "Português", "ru": "Русский", "ja": "日本語", "ko": "한국어", "it": "Italiano",
    "bn": "বাংলা", "ur": "اردو", "id": "Bahasa Indonesia", "tr": "Türkçe", "vi": "Tiếng Việt", "pl": "Polski",
    "nl": "Nederlands", "uk": "Українська", "fa": "فارسی", "he": "עברית", "th": "ไทย", "sw": "Kiswahili",
    "ta": "தமிழ்", "te": "తెలుగు", "mr": "मराठी", "tl": "Filipino", "el": "Ελληνικά", "sv": "Svenska",
}
ENGLISH_NAMES = {
    "es": "Spanish", "fr": "French", "de": "German", "zh": "Simplified Chinese", "hi": "Hindi", "ar": "Arabic",
    "pt": "Portuguese", "ru": "Russian", "ja": "Japanese", "ko": "Korean", "it": "Italian", "bn": "Bengali",
    "ur": "Urdu", "id": "Indonesian", "tr": "Turkish", "vi": "Vietnamese", "pl": "Polish", "nl": "Dutch",
    "uk": "Ukrainian", "fa": "Persian", "he": "Hebrew", "th": "Thai", "sw": "Swahili", "ta": "Tamil",
    "te": "Telugu", "mr": "Marathi", "tl": "Filipino", "el": "Greek", "sv": "Swedish",
}
RTL = {"ar", "he", "fa", "ur"}
CALL = re.compile(r"""(?<![\w.$])[tT]\(\s*(['"])([A-Za-z0-9_.]+)\1\s*(?:,\s*(['"])((?:\\.|(?!\3).)*)\3)?""", re.S)
PLACEHOLDER = re.compile(r"\{[a-zA-Z_]+\}")


def en_path():
    return os.path.join(LANG_DIR, "en.json")


def scan(quiet=False):
    found = {}
    for root, dirs, files in os.walk(ROOT):
        dirs[:] = [d for d in dirs if d not in ("data", "node_modules", ".git", "__pycache__", "thumbnails", "covers",
                                                "backdrops", "trailers", "backend", "tools", "tools_src", "build",
                                                "dist", "library", "share", "backup")]
        for f in files:
            if not f.endswith((".php", ".js", ".html", ".hta")):
                continue
            try:
                txt = open(os.path.join(root, f), encoding="utf-8", errors="ignore").read()
            except OSError:
                continue
            for m in CALL.finditer(txt):
                key, default = m.group(2), m.group(4)
                if default is not None:
                    default = default.replace("\\'", "'").replace('\\"', '"').replace("\\\\", "\\")
                if key not in found or (found[key] is None and default):
                    found[key] = default
    en = read_json(en_path(), {}) or {}
    en.setdefault("_meta", {"name": "English", "english": "English", "dir": "ltr"})
    added, conflicts = [], []
    for k, d in sorted(found.items()):
        if k not in en:
            en[k] = d if d else k.replace("_", " ").capitalize()
            added.append(k)
        elif d and en[k] != d:
            conflicts.append(k)
    unused = [k for k in en if k != "_meta" and k not in found]
    write_json(en_path(), dict(sorted(en.items(), key=lambda kv: (kv[0] != "_meta", kv[0]))))
    if not quiet or added:
        log(f"Scanned app: {len(found)} words/phrases in use, {len(en) - 1} in en.json.")
        if added:
            log(f"  added {len(added)} new: {', '.join(added[:30])}{' …' if len(added) > 30 else ''}")
        if unused:
            log(f"  {len(unused)} not used by the app (kept): {', '.join(unused[:15])}{' …' if len(unused) > 15 else ''}")
        if conflicts and not quiet:
            log(f"  {len(conflicts)} keys where en.json text differs from the code default (en.json wins).")
    return en


# ---------------- providers ----------------
def _json_from(text):
    text = text.strip()
    text = re.sub(r"^```(?:json)?\s*|\s*```$", "", text)
    s, e = text.find("{"), text.rfind("}")
    return json.loads(text[s:e + 1])


def _prompt(batch, lang):
    return (f"Translate the JSON values from English into {ENGLISH_NAMES.get(lang, lang)} ({lang}) for the user "
            "interface of a home media-center app (movies, music, pictures). Keep the JSON keys unchanged. "
            "Keep placeholders like {n} or {q} exactly as they are. Keep symbols, quotes and command words in "
            "double quotes (like \"help\", \"enrich\") unchanged. Use short, natural UI wording. "
            "Return ONLY a JSON object.\n\n" + json.dumps(batch, ensure_ascii=False, indent=0))


def via_claude(batch, lang, cfg):
    r = http_json("https://api.anthropic.com/v1/messages", headers={
        "x-api-key": cfg["anthropic_api_key"], "anthropic-version": "2023-06-01"},
        data={"model": cfg.get("anthropic_model") or "claude-sonnet-4-5", "max_tokens": 8000,
              "messages": [{"role": "user", "content": _prompt(batch, lang)}]}, timeout=120)
    return _json_from("".join(c.get("text", "") for c in r.get("content", [])))


def via_openai(batch, lang, cfg):
    r = http_json("https://api.openai.com/v1/chat/completions", headers={
        "Authorization": "Bearer " + cfg["openai_api_key"]},
        data={"model": cfg.get("openai_model") or "gpt-4o-mini", "response_format": {"type": "json_object"},
              "messages": [{"role": "user", "content": _prompt(batch, lang)}]}, timeout=120)
    return _json_from(r["choices"][0]["message"]["content"])


def via_ollama(batch, lang, cfg):
    r = http_json(cfg["ollama_url"].rstrip("/") + "/api/chat",
                  data={"model": cfg.get("ollama_model") or "llama3.1", "stream": False, "format": "json",
                        "messages": [{"role": "user", "content": _prompt(batch, lang)}]}, timeout=600, retries=0)
    return _json_from(r["message"]["content"])


def via_mymemory(batch, lang, cfg):
    out = {}
    target = "zh-CN" if lang == "zh" else lang
    for k, v in batch.items():
        ph = PLACEHOLDER.findall(v)
        text = v
        for i, p in enumerate(ph):
            text = text.replace(p, f"[{i}]", 1)
        time.sleep(0.4)
        r = http_json("https://api.mymemory.translated.net/get", {"q": text, "langpair": f"en|{target}"})
        tr = ((r or {}).get("responseData") or {}).get("translatedText") or v
        for i, p in enumerate(ph):
            tr = tr.replace(f"[{i}]", p)
        out[k] = tr
    return out


def ollama_up(cfg):
    try:
        http_json(cfg["ollama_url"].rstrip("/") + "/api/tags", timeout=2, retries=0)
        return True
    except Exception:
        return False


def provider():
    cfg = config()
    p = (cfg.get("translate_provider") or "auto").lower()
    if p == "auto":
        if cfg.get("anthropic_api_key"):
            p = "claude"
        elif cfg.get("openai_api_key"):
            p = "openai"
        elif ollama_up(cfg):
            p = "ollama"
        else:
            p = "mymemory"
    fn = {"claude": via_claude, "openai": via_openai, "ollama": via_ollama, "mymemory": via_mymemory}.get(p, via_mymemory)
    return p, fn, cfg


def valid(src, tr):
    return isinstance(tr, str) and tr.strip() and sorted(PLACEHOLDER.findall(src)) == sorted(PLACEHOLDER.findall(tr))


def translate_file(lang, en):
    lang = lang.lower().strip()
    if lang == "en" or not re.fullmatch(r"[a-z]{2,3}(-[a-z0-9]{2,4})?", lang):
        log("  skipping invalid language code:", lang)
        return
    path = os.path.join(LANG_DIR, f"{lang}.json")
    cur = read_json(path, {}) or {}
    cur["_meta"] = {"name": NAMES.get(lang, cur.get("_meta", {}).get("name") or lang.upper()),
                    "english": ENGLISH_NAMES.get(lang, cur.get("_meta", {}).get("english") or ""),
                    "dir": "rtl" if lang.split("-")[0] in RTL else "ltr"}
    missing = {k: v for k, v in en.items() if k != "_meta" and isinstance(v, str) and not cur.get(k)}
    if not missing:
        log(f"  {lang}: already complete ({len(en) - 1} words)")
        write_json(path, cur)
        return
    name, fn, cfg = provider()
    log(f"  {lang}: translating {len(missing)} phrase(s) with {name}…")
    items = list(missing.items())
    size = 1 if name == "mymemory" else 40
    done = 0
    for i in range(0, len(items), size):
        batch = dict(items[i:i + size])
        progress(f"{lang}: {done}/{len(items)}")
        try:
            res = fn(batch, lang, cfg)
        except Exception as e:
            log(f"    batch failed ({e}); will retry once")
            time.sleep(3)
            try:
                res = fn(batch, lang, cfg)
            except Exception as e2:
                log(f"    giving up on this batch: {e2}")
                continue
        for k, src in batch.items():
            tr = (res or {}).get(k)
            if valid(src, tr):
                cur[k] = tr.strip()
                done += 1
        write_json(path, dict(sorted(cur.items(), key=lambda kv: (kv[0] != "_meta", kv[0]))))
    write_json(path, dict(sorted(cur.items(), key=lambda kv: (kv[0] != "_meta", kv[0]))))
    left = len(missing) - done
    log(f"  {lang}: {done} translated" + (f", {left} still missing (English is shown for those; run again)" if left else "") +
        f" → data/lang/{lang}.json")


def run(args):
    sub = args[0] if args else "scan"
    en = scan(quiet=sub != "scan")
    if sub == "scan":
        return
    if sub == "gen":
        codes = [a for a in args[1:] if not a.startswith("-")]
        log(f"Generating language file(s): {', '.join(codes)}")
        for c in codes:
            translate_file(c, en)
    elif sub == "update":
        codes = [f[:-5] for f in os.listdir(LANG_DIR) if f.endswith(".json") and f != "en.json"]
        log(f"Updating {len(codes)} language file(s)…")
        for c in sorted(codes):
            translate_file(c, en)
    log("Language job finished. Reload the page to see new languages in the menu.")
