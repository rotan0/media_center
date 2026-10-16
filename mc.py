"""Media Center background tasks. Normally started from the in-browser console.

  python mc.py scan [--dir PATH] [--full]
  python mc.py enrich [movies|music|all] [--force]
  python mc.py fetch <title> [= search text]
  python mc.py search <text>
  python mc.py trailers
  python mc.py lang scan | lang gen <codes...> | lang update
  python mc.py rights [--online] [--force] | sharepack | save [--free|<id>...]
  python mc.py build [--with-tools]
  python mc.py webget | ia_upload <id>          (Online mode only)
  python mc.py theme
  python mc.py setup
"""
import os
import runpy
import subprocess
import sys
import traceback

# Portable mode: the embedded Python in tools/python does not add the script folder to sys.path,
# and Python packages live in tools/pylib.
_HERE = os.path.dirname(os.path.abspath(__file__))
for _p in (os.path.join(_HERE, "tools", "pylib"), _HERE):
    if _p not in sys.path:
        sys.path.insert(0, _p)

import mc_common as C  # noqa: E402


def setup(_args):
    pkgs = ["pillow", "mutagen", "yt-dlp", "requests"]
    C.log("Installing Python packages:", ", ".join(pkgs))
    r = subprocess.run([sys.executable, "-m", "pip", "install", "--upgrade", *pkgs],
                       capture_output=True, text=True,
                       **({"creationflags": 0x08000000} if os.name == "nt" else {}))
    print(r.stdout[-3000:], r.stderr[-2000:])
    C.log("Setup finished." if r.returncode == 0 else "pip reported an error (see above).")
    C.log("ffmpeg is a separate program: https://ffmpeg.org/download.html (or: winget install ffmpeg)")


def theme(_args):
    runpy.run_path(os.path.join(C.ROOT, "resources.py"), run_name="__main__")


def main(argv):
    if not argv:
        print(__doc__)
        return
    cmd, args = argv[0].lower(), argv[1:]
    C.job_start(" ".join(argv))
    try:
        if cmd == "scan":
            import mc_scan
            mc_scan.run(args)
        elif cmd == "enrich":
            import mc_enrich
            mc_enrich.run(args)
        elif cmd == "fetch":
            import mc_enrich
            mc_enrich.fetch(args)
        elif cmd == "search":
            import mc_enrich
            mc_enrich.search(args)
        elif cmd == "trailers":
            import mc_enrich
            mc_enrich.trailers(args)
        elif cmd == "lang":
            import mc_lang
            mc_lang.run(args)
        elif cmd == "rights":
            import mc_rights
            mc_rights.run(args)
        elif cmd == "sharepack":
            import mc_rights
            mc_rights.sharepack(args)
        elif cmd == "save":
            import mc_rights
            mc_rights.save(args)
        elif cmd == "build":
            import mc_build
            mc_build.run(args)
        elif cmd == "webget":
            import mc_web
            mc_web.run(args)
        elif cmd == "ia_upload":
            import mc_share
            mc_share.run(args)
        elif cmd == "theme":
            theme(args)
        elif cmd == "setup":
            setup(args)
        else:
            C.log("Unknown task:", cmd)
    except C.OfflineError as e:
        C.log(str(e))
    except KeyboardInterrupt:
        C.log("Stopped.")
    except Exception:
        C.log("ERROR:")
        traceback.print_exc()
    finally:
        C.job_end()


if __name__ == "__main__":
    main(sys.argv[1:])
