# Local Media Center

Movies, music and pictures from your own folders, in the browser. Works offline. It only goes online when you run a command in the console.

## Install and start
- **Installer:** run `dist/MediaCenter-Setup.exe`. It extracts Media Center to this computer or to an SD card/USB drive, adds a desktop icon (hundo) and a Start-menu entry, and registers an uninstaller.
- **Portable:** unzip `dist/MediaCenter-Portable.zip` anywhere, including an SD card, and double-click **Media Center.hta**.
- **Launcher (Media Center.hta):** finds PHP in `tools/php`, on PATH or in XAMPP. If there's none, it downloads PHP into `tools/php` automatically, along with the Visual C++ runtime if PHP needs it. It then starts the server and opens your browser. Closing the launcher window stops the server.
- `start.bat` does the same from a console window. XAMPP/Apache also works.
- **First start:** the app downloads anything still missing (Python, Python packages, ffmpeg, yt-dlp) into `tools/`. After that it runs offline. You can turn this off with `config set auto_setup false`.
- MySQL is not needed. The library is stored in JSON files in `data/`.

## Free to share (🤝)
`rights` rates every file as **public domain**, **free with credit** (Creative Commons, with a ready-made credit line), **personal** (camera photos), **proprietary** or **unknown**. It uses licence files next to the media, embedded tags, the publication year (the US 95-year rule, or 100 years for sound recordings) and, with `rights --online`, Wikidata and the Internet Archive.
- `sharepack` zips everything you're free to share into `share/`, together with a CREDITS.txt.
- `save --free` copies free media into `library/` so it travels with the app.
- The ratings are guidance, not legal advice.

## Offline / Online (🔒 / 🌐 button, top right)
Media Center starts **Offline**: nothing leaves your computer — no covers, searches, downloads or uploads.
Click the button (or type `online` / `offline` in the console) to switch. Online mode unlocks:
- 🌍 **Find free media** — search public-domain and Creative-Commons movies, music and pictures on the Internet Archive,
  Wikimedia Commons and Openverse; watch or listen in the online player (an embed of the source page) or ⬇ Save into
  media_center/library with a `.license` file next to each file.
- 📤 **Share** (button in a movie's details) — upload free items to the Internet Archive (your free keys from
  archive.org/account/s3.php), post the public link to Facebook or X, or open YouTube upload with the file and credit ready.
- Covers, synopsis and trailers (`enrich`), tool downloads, AI translation.

## Offline theater
- Movies: resume where you stopped, continue-watching row, up-next countdown, subtitles (put `Movie.srt` or
  `Movie.en.srt` next to the file), volume boost to 200 %, night mode, ambient light, picture controls, screenshot,
  picture-in-picture. Keys: Space play · ← → 5 s · J L 10 s · ↑ ↓ volume · F full screen · M mute · C subtitles ·
  , . frame · [ ] speed · S screenshot · N next · Esc close.
- Music: click the cover in the music bar for Now Playing — visualizer, 5-band equalizer, synced lyrics
  (`Song.lrc` or `Song.txt` next to the song), repeat, sleep timer.
- Images: zoom (wheel), drag, rotate, details, slideshow with slow zoom.

## Build the installer
`build` (console) or **Build installer** on the Maintenance page creates `dist/MediaCenter-Setup.exe` and `dist/MediaCenter-Portable.zip`. `build --with-tools` also packs `tools/` so the install works fully offline. The installer is a 7-Zip self-extractor with the hundo icon that opens `installer/setup.hta`, whose background is hundo.jpg. Because the exe is unsigned, Windows SmartScreen shows "More info → Run anyway" until it is code-signed. Browsers never start a downloaded program on their own, so people double-click the downloaded file to run it.

## Add media
Click **＋ Add folder**. The folder browser runs on the server, so it sees your real drives (C:, D:, …) and the usual folders (Videos, Music, Pictures). Open a folder and click **Add this folder**. The files show up right away, including everything in subfolders, sorted into Movies, Music and Images. After that, a background scan makes thumbnails and reads music tags.

## Maintenance page (🛠 in the sidebar)
This page checks every part of the app (program files, data files, library, tools and languages) and marks each one **PASS**, **WARN** or **FAIL**. Each item that isn't passing has its own fix button.
- **Deploy** makes the folder fully portable. It searches this computer for Python, ffmpeg, yt-dlp and PHP and copies them into `tools/`. If a tool isn't found, Deploy downloads it. Deploy also installs Pillow and mutagen into `tools/pylib`, writes `start.bat` and saves `backup/app.zip`.
- **Repair** fixes only the items marked FAIL. For example, it restores deleted program files from the backup, resets damaged data files, re-indexes the library and cleans broken artwork links.
- **Test internet** checks whether each online service can be reached.
- The **status bar** at the bottom shows when the app is online, the service it's talking to, the download speed, how much this task has downloaded, the total ever downloaded and the size of `media_center`.
- Put media in `library/` if you want it to travel with the app. Paths inside `media_center` are stored relative to the folder, so it can be moved.

## Console (press ` or click Console)
| Command | What it does |
|---|---|
| `setup` | copies or downloads Python, the Python packages, ffmpeg and yt-dlp into `tools/` |
| `check` / `deploy` / `repair` / `fix ffmpeg` | the Maintenance page, from the console |
| `status` | shows whether Python, ffmpeg and yt-dlp were found, plus library counts |
| `scan` / `scan --full` | rescan all folders |
| `enrich` / `enrich movies` / `enrich music --force` | gets posters, backdrops, synopsis, genres and album covers from the internet |
| `trailers` | downloads missing trailers into `trailers/` |
| `search the matrix` | shows online matches (preview only) |
| `fetch matrix = The Matrix 1999` | re-matches one item using your own search text |
| `lang scan` | scans the app for every `t('key','English')` and updates `en.json` |
| `lang gen de ja pt` | AI-translates new language files |
| `lang update` | fills in missing words in every language file |
| `config set tmdb_api_key KEY` | optional: adds backdrops, cast and YouTube trailers from TMDB |
| `config set anthropic_api_key KEY` | uses Claude for translations (also works: `openai_api_key`, a local Ollama, or free MyMemory) |
| `config set python "C:/Python312/python.exe"` | use this if `python` isn't on PATH |

Free sources that need no key: iTunes Search (movie posters, synopsis and trailer previews), Deezer (album covers) and Wikipedia.

## Languages
The app, the launcher, the installer and the uninstaller all come in 20 languages: English, 中文, हिन्दी, Español, Français, العربية, বাংলা, Português, Русский, Bahasa Indonesia, اردو, Deutsch, 日本語, Türkçe, 한국어, Tiếng Việt, Italiano, فارسی, Kiswahili and தமிழ். Arabic, Urdu and Persian display right-to-left. The files are in `data/lang/`. `lang gen <code>` adds more languages.

## Notes
- Browsers play mp4/m4v/webm, and most mkv files that use H.264. For avi and wmv the app offers a Download button instead.
