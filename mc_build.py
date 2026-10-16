"""Build the Windows installer and the portable zip.

  python mc.py build [--with-tools]

Output (in media_center/dist):
  MediaCenter-Setup.exe      self-extracting 7-Zip archive (icon = hundo) that opens the setup screen (setup.hta)
  MediaCenter-Portable.zip   unzip to an SD card / USB stick and double-click "Media Center.hta"

--with-tools also packs tools/ (PHP, Python, ffmpeg …) so the install needs no internet at all.
The 7-Zip pieces (7zr.exe, 7zSD.sfx) are downloaded into tools/ the first time, from the official 7-Zip GitHub releases.
"""
import datetime
import glob
import json
import os
import shutil
import subprocess
import zipfile

from mc_common import ROOT, TOOLS, download, log, progress, read_json
import mc_peicon

DIST = os.path.join(ROOT, "dist")
BUILD = os.path.join(ROOT, "build")
SEVENZIP_VER = "25.01"
GH = f"https://github.com/ip7z/7zip/releases/download/{SEVENZIP_VER}/"
ROOT_FILES = ["index.php", "lib.php", "api.php", "stream.php", "maint.php", "add_directory.php", "scan.php", "db.php", "web.php",
              "mc.py", "mc_common.py", "mc_scan.py", "mc_enrich.py", "mc_lang.py", "mc_rights.py", "mc_build.py", "mc_web.py", "mc_share.py",
              "mc_peicon.py", "resources.py", "scan.py", "README.md", "start.bat", "Media Center.hta", "uninstall.hta"]
ROOT_DIRS = ["assets", "backend", "data/lang"]
EMPTY_DIRS = ["library", "thumbnails", "covers", "backdrops", "trailers", "tools", "tools/pylib", "share", "backup"]
DEFAULT_CONFIG = {"python": "python", "ffmpeg": "ffmpeg", "tmdb_api_key": "", "anthropic_api_key": "", "openai_api_key": "",
                  "translate_provider": "auto", "download_trailers": True, "use_ytdlp": True, "auto_scan_on_add": True,
                  "auto_setup": True, "metadata_language": "en"}


def _no_window():
    return {"creationflags": 0x08000000} if os.name == "nt" else {}


def find_7z():
    cands = [os.path.join(TOOLS, "7zr.exe"), os.path.join(ROOT, "tools_src", "7zr.exe")] if os.name == "nt" else []
    for n in ("7zz", "7z", "7za", "7zr"):
        p = shutil.which(n)
        if p:
            cands.append(p)
    cands += [os.path.join(ROOT, "..", "7z", "7zz"), (read_json(os.path.join(ROOT, "data", "config.json"), {}) or {}).get("sevenzip", "")]
    for c in cands:
        if c and os.path.isfile(c):
            return c
    if os.name == "nt":
        log("  downloading 7zr.exe (7-Zip) …")
        if download(GH + "7zr.exe", os.path.join(TOOLS, "7zr.exe"), kind="application"):
            return os.path.join(TOOLS, "7zr.exe")
    raise RuntimeError("7-Zip not found (install 7-Zip or run on Windows so 7zr.exe can be downloaded)")

def find_sfx(seven):
    os.makedirs(TOOLS, exist_ok=True)
    sfx = os.path.join(TOOLS, "7zSD.sfx")
    if os.path.isfile(sfx):
        return sfx
    src = os.path.join(ROOT, "tools_src", "7zSD.sfx")
    if os.path.isfile(src):
        shutil.copy(src, sfx)
        return sfx
    log("  downloading the 7-Zip installer module (LZMA SDK) …")
    arc = os.path.join(TOOLS, "_lzma.7z")
    if not download(GH + f"lzma{SEVENZIP_VER.replace('.', '')}.7z", arc, kind="application"):
        raise RuntimeError("could not download the LZMA SDK")
    tmp = os.path.join(TOOLS, "_lzma")
    subprocess.run([seven, "x", "-y", "-o" + tmp, arc, "bin/7zSD.sfx"], check=True, capture_output=True, **_no_window())
    shutil.copy(os.path.join(tmp, "bin", "7zSD.sfx"), sfx)
    shutil.rmtree(tmp, ignore_errors=True)
    os.remove(arc)
    return sfx


def stage(dest, with_tools):
    if os.path.isdir(dest):
        shutil.rmtree(dest)
    os.makedirs(dest)
    for f in ROOT_FILES:
        if os.path.isfile(os.path.join(ROOT, f)):
            shutil.copy2(os.path.join(ROOT, f), os.path.join(dest, f))
    for d in ROOT_DIRS:
        shutil.copytree(os.path.join(ROOT, d), os.path.join(dest, d), ignore=shutil.ignore_patterns("__pycache__", "*.pyc", "*.part"),
                        dirs_exist_ok=True)
    for d in EMPTY_DIRS:
        os.makedirs(os.path.join(dest, d), exist_ok=True)
    data = os.path.join(dest, "data")
    json.dump(DEFAULT_CONFIG, open(os.path.join(data, "config.json"), "w"), indent=2)
    json.dump([], open(os.path.join(data, "media.json"), "w"))
    json.dump(["library"], open(os.path.join(data, "directories.json"), "w"))
    if os.path.isfile(os.path.join(ROOT, "data", "theme.json")):
        shutil.copy(os.path.join(ROOT, "data", "theme.json"), data)
    if with_tools and os.path.isdir(TOOLS):
        shutil.copytree(TOOLS, os.path.join(dest, "tools"), dirs_exist_ok=True,
                        ignore=shutil.ignore_patterns("_*", "*.part", "7zSD.sfx", "7zr.exe", "__pycache__", "vc_redist*"))
    n = sum(len(fs) for _, _, fs in os.walk(dest))
    size = sum(os.path.getsize(os.path.join(r, f)) for r, _, fs in os.walk(dest) for f in fs)
    return n, size


def run(args):
    with_tools = "--with-tools" in args
    version = datetime.date.today().strftime("%Y.%m.%d")
    os.makedirs(DIST, exist_ok=True)
    seven = find_7z()
    sfx = find_sfx(seven)
    log(f"Building installer {version}{' with tools' if with_tools else ''} …")

    # 1) payload
    work = os.path.join(BUILD, "sfx")
    progress("staging files")
    n, size = stage(os.path.join(work, "media_center"), with_tools)
    log(f"  payload: {n} files, {size / 1e6:.1f} MB")
    setup = open(os.path.join(ROOT, "installer", "setup.hta"), encoding="utf-8").read().replace("__VERSION__", version)
    open(os.path.join(work, "setup.hta"), "w", encoding="utf-8").write(setup)

    # 2) portable zip
    progress("portable zip")
    pz = os.path.join(DIST, "MediaCenter-Portable.zip")
    with zipfile.ZipFile(pz + ".part", "w", zipfile.ZIP_DEFLATED, compresslevel=9) as z:
        base = os.path.join(work, "media_center")
        for r, ds, fs in os.walk(base):
            relr = os.path.relpath(r, work)
            if not fs and not ds:
                z.writestr(relr.replace("\\", "/") + "/", "")
            for f in fs:
                z.write(os.path.join(r, f), os.path.join(relr, f))
    os.replace(pz + ".part", pz)
    log(f"  {os.path.relpath(pz, ROOT)}  {os.path.getsize(pz) / 1e6:.1f} MB")

    # 3) self-extracting installer
    progress("compressing installer")
    arc = os.path.join(BUILD, "payload.7z")
    if os.path.exists(arc):
        os.remove(arc)
    subprocess.run([seven, "a", "-t7z", "-mx=9", "-y", arc, "."], cwd=work, check=True, capture_output=True, **_no_window())
    stub, replaced = mc_peicon.replace_icon(open(sfx, "rb").read(), os.path.join(ROOT, "assets", "img", "hundo.jpg"))
    conf = (';!@Install@!UTF-8!\r\nTitle="Local Media Center"\r\nExecuteFile="mshta.exe"\r\nExecuteParameters="setup.hta"\r\n;!@InstallEnd@!\r\n').encode("utf-8")
    exe = os.path.join(DIST, "MediaCenter-Setup.exe")
    with open(exe, "wb") as out:
        out.write(stub)
        out.write(conf)
        out.write(open(arc, "rb").read())
    shutil.rmtree(BUILD, ignore_errors=True)
    log(f"  {os.path.relpath(exe, ROOT)}  {os.path.getsize(exe) / 1e6:.1f} MB (icon replaced: {replaced})")
    log("Installer ready. Upload dist/MediaCenter-Setup.exe and link to it; people run it after downloading.")
