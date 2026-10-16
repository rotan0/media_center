<?php
/**
 * Maintenance: pass/fail checklist for every part of media_center, with fixes.
 *
 * Goal: media_center is fully portable. Tools (Python, ffmpeg, yt-dlp, PHP, Python packages) live in
 * media_center/tools. If a tool is found elsewhere on this computer it is copied in; if not, it is downloaded.
 * The internet is only used for tool downloads and when new media needs covers/info.
 *
 * Web:  api.php?action=maint_check | maint_run
 * CLI:  php maint.php fix <id> [id...] | deploy | repair | check
 */
require_once __DIR__ . '/lib.php';

const MAINT_APP_FILES = ['index.php', 'lib.php', 'api.php', 'stream.php', 'maint.php', 'web.php', 'assets/app.js', 'assets/app.css', 'assets/theater.js', 'assets/online.js',
    'mc.py', 'mc_common.py', 'mc_scan.py', 'mc_enrich.py', 'mc_lang.py', 'mc_rights.py', 'mc_web.py', 'mc_share.py', 'mc_build.py', 'mc_peicon.py',
    'Media Center.hta', 'uninstall.hta', 'assets/hta_common.js', 'installer/setup.hta', 'data/lang/en.json'];
const MAINT_ASSETS = ['assets/img/hundo.jpg', 'assets/img/hundo_sm.jpg', 'assets/img/hundo.ico', 'assets/img/logo.gif'];
const MAINT_DIRS = ['data', 'data/lang', 'thumbnails', 'covers', 'backdrops', 'trailers', 'tools', 'tools/pylib', 'library', 'backup', 'share'];
const MAINT_ORDER = ['folders', 'json', 'job', 'log', 'app', 'assets', 'lang_en', 'index', 'art', 'php', 'phpini', 'python', 'pylib', 'ffmpeg', 'ytdlp', 'start', 'backup'];
const MAINT_PY_VERSION = '3.12.10';
const MAINT_PHP_EXT = ['curl', 'fileinfo', 'mbstring', 'openssl', 'zip', 'gd', 'exif'];
const MAINT_UA = 'LocalMediaCenter/2.0 (maintenance)';

/* ====================================================================== helpers */

function maint_progress($text) {
    static $last = 0;
    if (microtime(true) - $last < 0.8) return;
    $last = microtime(true);
    $job = mc_read_json(MC_JOB, []);
    $job['progress'] = $text;
    $job['heartbeat'] = time();
    mc_write_json(MC_JOB, $job);
}

function maint_say($line) { mc_log($line); }

function maint_human($b) {
    $u = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($b >= 1024 && $i < 4) { $b /= 1024; $i++; }
    return ($i ? number_format($b, $b < 10 ? 1 : 0) : (int)$b) . ' ' . $u[$i];
}

function maint_home() {
    $h = getenv('USERPROFILE') ?: getenv('HOME');
    if (!$h && preg_match('#^([A-Za-z]:/Users/[^/]+)#i', mc_norm_path(MC_ROOT), $m)) $h = $m[1];
    return $h ? mc_norm_path($h) : '';
}

function maint_inside($path) { return stripos(mc_norm_path($path), mc_norm_path(MC_ROOT) . '/') === 0; }

function maint_rrmdir($dir) {
    if (!is_dir($dir)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

function maint_copy_tree($src, $dst, $exclude = []) {
    $src = rtrim(mc_norm_path($src), '/');
    $files = [];
    $bytes = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST, RecursiveIteratorIterator::CATCH_GET_CHILD);
    foreach ($it as $f) {
        $rel = substr(mc_norm_path($f->getPathname()), strlen($src) + 1);
        $top = strtolower(explode('/', $rel)[0]);
        if (in_array($top, $exclude, true) || strpos($rel, '__pycache__') !== false) continue;
        foreach ($exclude as $x) if (strpos($x, '/') !== false && stripos($rel . '/', $x . '/') === 0) continue 2;
        if ($f->isFile()) { $files[] = $rel; $bytes += $f->getSize(); }
    }
    $done = 0;
    foreach ($files as $i => $rel) {
        $to = "$dst/$rel";
        @mkdir(dirname($to), 0777, true);
        if (!@copy("$src/$rel", $to)) maint_say("  could not copy $rel");
        $done += (int)@filesize($to);
        if ($i % 20 === 0) maint_progress(t('m_copying', 'Copying') . ' ' . maint_human($done) . ' / ' . maint_human($bytes));
    }
    return $done;
}

/* ---------- downloads (counted by the network meter) ---------- */

function maint_download($url, $dest, $label) {
    if (!mc_online()) { maint_say('  ' . t('off_blocked', 'Offline mode: nothing is downloaded. Switch to Online to use this.')); return false; }
    $host = parse_url($url, PHP_URL_HOST);
    $part = $dest . '.part';
    @mkdir(dirname($dest), 0777, true);
    maint_say("  ↓ $label — $url");
    $ok = false;
    if (function_exists('curl_init')) {
        $fp = fopen($part, 'wb');
        $ch = curl_init($url);
        $last = 0;
        $opts = [
            CURLOPT_FILE => $fp, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 10, CURLOPT_USERAGENT => MAINT_UA,
            CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_LOW_SPEED_LIMIT => 1, CURLOPT_LOW_SPEED_TIME => 60,
            CURLOPT_NOPROGRESS => false, CURLOPT_FAILONERROR => true,
            CURLOPT_XFERINFOFUNCTION => function ($ch, $dlTotal, $dl) use (&$last, $host, $label) {
                if ($dl > $last) { mc_net_add($host, $dl - $last, $label); $last = $dl; }
                maint_progress("$label " . maint_human($dl) . ($dlTotal ? ' / ' . maint_human($dlTotal) : ''));
                return 0;
            },
        ];
        if (defined('CURLSSLOPT_NATIVE_CA')) $opts[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA; // use the Windows certificate store
        curl_setopt_array($ch, $opts);
        $ok = curl_exec($ch) !== false;
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        if (!$ok) maint_say("    PHP curl failed: $err — trying the system curl");
    }
    if (!$ok) $ok = maint_download_syscurl($url, $part, $host, $label);
    if ($ok && filesize($part) > 0) {
        @unlink($dest);
        rename($part, $dest);
        maint_say('    ✓ ' . maint_human(filesize($dest)));
        return true;
    }
    @unlink($part);
    maint_say('    ✗ ' . t('m_download_failed', 'Download failed. Check the internet connection and try again.'));
    return false;
}

/** Windows 10+ ships curl.exe, which uses the Windows certificate store. Progress is read from the growing file. */
function maint_download_syscurl($url, $part, $host, $label) {
    $bin = MC_IS_WIN ? mc_norm_path((getenv('SystemRoot') ?: 'C:/Windows') . '/System32/curl.exe') : mc_which('curl');
    if (!$bin || !is_file($bin)) return false;
    @unlink($part);
    $proc = proc_open([$bin, '-L', '-s', '-f', '-A', MAINT_UA, '-o', $part, $url], [], $pipes);
    if (!is_resource($proc)) return false;
    $last = 0;
    do {
        usleep(400000);
        clearstatcache();
        $size = is_file($part) ? filesize($part) : 0;
        if ($size > $last) { mc_net_add($host, $size - $last, $label); $last = $size; }
        maint_progress("$label " . maint_human($size));
        $st = proc_get_status($proc);
    } while ($st['running']);
    $code = $st['exitcode'];
    proc_close($proc);
    return $code === 0 && is_file($part);
}

function maint_get_json($url, $label) {
    $tmp = MC_DATA . '/dl_' . md5($url) . '.json';
    if (!maint_download($url, $tmp, $label)) return null;
    $j = json_decode(file_get_contents($tmp), true);
    @unlink($tmp);
    return $j;
}

/** Extract entries of a zip whose path matches $pattern (or everything) into $dest (flattened when $flatten). */
function maint_unzip($zip, $dest, $pattern = null, $flatten = false) {
    @mkdir($dest, 0777, true);
    if (class_exists('ZipArchive')) {
        $z = new ZipArchive();
        if ($z->open($zip) !== true) return 0;
        $n = 0;
        for ($i = 0; $i < $z->numFiles; $i++) {
            $name = $z->getNameIndex($i);
            if (substr($name, -1) === '/') continue;
            if ($pattern && !preg_match($pattern, $name)) continue;
            $to = $dest . '/' . ($flatten ? basename($name) : $name);
            @mkdir(dirname($to), 0777, true);
            $in = $z->getStream($name);
            $out = fopen($to, 'wb');
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
            $n++;
            if ($n % 50 === 0) maint_progress(t('m_extracting', 'Extracting') . " $n");
        }
        $z->close();
        return $n;
    }
    // fallback: the tar that ships with Windows 10+ can read zip files
    $tmp = $dest . '/_unzip';
    @mkdir($tmp, 0777, true);
    exec('tar -xf ' . escapeshellarg($zip) . ' -C ' . escapeshellarg($tmp) . ' 2>&1', $o, $rc);
    if ($rc !== 0) return 0;
    $n = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS)) as $f) {
        $rel = substr(mc_norm_path($f->getPathname()), strlen(mc_norm_path($tmp)) + 1);
        if ($pattern && !preg_match($pattern, $rel)) continue;
        $to = $dest . '/' . ($flatten ? basename($rel) : $rel);
        @mkdir(dirname($to), 0777, true);
        rename($f->getPathname(), $to);
        $n++;
    }
    maint_rrmdir($tmp);
    return $n;
}

/* ====================================================================== detection on this computer */

function maint_globs($patterns) {
    $out = [];
    foreach ($patterns as $p) foreach ((glob($p) ?: []) as $g) $out[] = mc_norm_path($g);
    return $out;
}

function maint_find_ffmpeg() {
    $home = maint_home();
    $local = $home ? "$home/AppData/Local" : '';
    $exe = mc_exe('ffmpeg');
    $c = [];
    if ($w = mc_which('ffmpeg')) $c[] = $w;
    if (MC_IS_WIN) {
        $c = array_merge($c, maint_globs([
            "$local/Microsoft/WinGet/Packages/*FFmpeg*/*/bin/ffmpeg.exe",
            "$local/Microsoft/WinGet/Links/ffmpeg.exe",
            'C:/ProgramData/chocolatey/lib/ffmpeg*/tools/*/bin/ffmpeg.exe',
            "$home/scoop/apps/ffmpeg*/current/bin/ffmpeg.exe",
            'C:/ffmpeg/bin/ffmpeg.exe', 'C:/ffmpeg*/bin/ffmpeg.exe', 'C:/tools/ffmpeg*/bin/ffmpeg.exe',
            'C:/Program Files/ffmpeg*/bin/ffmpeg.exe', 'C:/Program Files (x86)/ffmpeg*/bin/ffmpeg.exe',
            "$home/Downloads/ffmpeg*/bin/ffmpeg.exe", "$home/Downloads/*/ffmpeg*/bin/ffmpeg.exe",
            "$home/Desktop/ffmpeg*/bin/ffmpeg.exe", 'C:/xampp/ffmpeg*/bin/ffmpeg.exe',
            'D:/ffmpeg*/bin/ffmpeg.exe', 'D:/tools/ffmpeg*/bin/ffmpeg.exe',
        ]));
    } else {
        $c = array_merge($c, ['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/opt/homebrew/bin/ffmpeg']);
    }
    foreach (array_unique($c) as $p) {
        if (!is_file($p) || maint_inside($p)) continue;
        $real = mc_norm_path(realpath($p) ?: $p);
        $dir = dirname($real);
        // skip package-manager shims: tiny exe without the ffmpeg DLLs next to it
        if (MC_IS_WIN && filesize($real) < 1000000 && !glob("$dir/avcodec*.dll")) continue;
        return $real;
    }
    return null;
}

function maint_find_ytdlp() {
    $home = maint_home();
    $c = [];
    if ($w = mc_which('yt-dlp')) $c[] = $w;
    if (MC_IS_WIN) {
        $c = array_merge($c, maint_globs([
            "$home/AppData/Local/Microsoft/WinGet/Packages/yt-dlp*/yt-dlp.exe",
            "$home/AppData/Local/Microsoft/WinGet/Links/yt-dlp.exe",
            "$home/scoop/apps/yt-dlp/current/yt-dlp.exe", 'C:/ProgramData/chocolatey/lib/yt-dlp/tools/yt-dlp.exe',
            "$home/Downloads/yt-dlp.exe", "$home/Desktop/yt-dlp.exe", 'C:/yt-dlp/yt-dlp.exe', 'C:/tools/yt-dlp.exe',
        ]));
    } else {
        $c = array_merge($c, ['/usr/local/bin/yt-dlp', '/usr/bin/yt-dlp']);
    }
    foreach (array_unique($c) as $p) {
        if (!is_file($p) || maint_inside($p)) continue;
        $real = mc_norm_path(realpath($p) ?: $p);
        if (filesize($real) > 1000000) return $real; // standalone build, not a pip launcher
    }
    return null;
}

/** A python.org style installation folder that can be copied as a whole. */
function maint_find_python() {
    if (!MC_IS_WIN) return null;
    $home = maint_home();
    $c = [];
    foreach (['python', 'py'] as $n) if ($w = mc_which($n)) $c[] = $w;
    $c = array_merge($c, maint_globs([
        "$home/AppData/Local/Programs/Python/Python3*/python.exe", 'C:/Python3*/python.exe',
        'C:/Program Files/Python3*/python.exe', 'C:/Program Files (x86)/Python3*/python.exe',
        "$home/anaconda3/python.exe", "$home/miniconda3/python.exe",
    ]));
    $cfg = mc_config()['python'];
    if (is_file($cfg)) array_unshift($c, mc_norm_path($cfg));
    foreach (array_unique($c) as $p) {
        if (stripos($p, 'WindowsApps') !== false || maint_inside($p) || !is_file($p)) continue;
        $dir = dirname($p);
        if (basename($p) !== 'python.exe' || !is_file("$dir/Lib/os.py") || !glob("$dir/python3*.dll")) continue;
        return $p;
    }
    return null;
}

function maint_find_php() {
    $p = mc_php_cli();
    if (!$p || maint_inside($p)) return null;
    return MC_IS_WIN ? $p : null; // Linux/mac PHP is not self-contained
}

function maint_py_info() {
    $py = mc_python_cmd();
    $out = trim((string)@shell_exec(mc_shell_exe($py) . ' -c "import sys,sysconfig;print(sys.version_info[0],sys.version_info[1],sysconfig.get_platform())" 2>&1'));
    if (!preg_match('/^3 (\d+) (\S+)$/m', $out, $m)) return null;
    return ['minor' => (int)$m[1], 'platform' => $m[2]];
}

/* ====================================================================== checks */

function maint_item($id, $group, $name, $status, $detail, $fix = null, $where = '', $bulk = true) {
    return compact('id', 'group', 'name', 'status', 'detail', 'fix', 'where', 'bulk');
}

function maint_manifest() { return mc_read_json(MC_DATA . '/manifest.json', []); }

function maint_code_keys() {
    $keys = [];
    $files = array_merge(glob(MC_ROOT . '/*.php'), glob(MC_ROOT . '/assets/*.js'), glob(MC_ROOT . '/*.hta'), glob(MC_ROOT . '/installer/*.hta'));
    $re = '/(?<![\w.$])[tT]\(\s*([\'"])([A-Za-z0-9_.]+)\1\s*(?:,\s*([\'"])((?:\\\\.|(?!\3).)*)\3)?/s';
    foreach ($files as $f) {
        if (preg_match_all($re, file_get_contents($f), $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $d = isset($m[4]) ? stripcslashes($m[4]) : null;
                if (!isset($keys[$m[2]]) || ($keys[$m[2]] === null && $d)) $keys[$m[2]] = $d;
            }
        }
    }
    return $keys;
}

function maint_checks() {
    $I = [];
    $G_APP = t('m_group_app', 'App files');
    $G_DATA = t('m_group_data', 'Data & library');
    $G_TOOLS = t('m_group_tools', 'Portable tools (inside media_center/tools)');
    $G_LANG = t('m_group_lang', 'Languages');

    /* --- app files --- */
    $missing = [];
    $changed = [];
    $man = maint_manifest();
    foreach (MAINT_APP_FILES as $f) {
        if (!is_file(MC_ROOT . "/$f")) $missing[] = $f;
        elseif (isset($man[$f]) && $man[$f] !== sha1_file(MC_ROOT . "/$f")) $changed[] = $f;
    }
    $hasBackup = is_file(MC_ROOT . '/backup/app.zip');
    if ($missing) $I[] = maint_item('app', $G_APP, t('m_app', 'Program files'), 'fail', t('m_missing', 'Missing:') . ' ' . implode(', ', $missing), $hasBackup ? t('m_fix_restore', 'Restore from backup') : null);
    elseif ($changed) $I[] = maint_item('app', $G_APP, t('m_app', 'Program files'), 'warn', t('m_changed', 'Changed since the last deploy:') . ' ' . implode(', ', $changed), $hasBackup ? t('m_fix_restore', 'Restore from backup') : null, '', false);
    else $I[] = maint_item('app', $G_APP, t('m_app', 'Program files'), 'pass', t('m_app_ok', '{n} files present', ['n' => count(MAINT_APP_FILES)]));

    $I[] = $hasBackup
        ? maint_item('backup', $G_APP, t('m_backup', 'Backup of program files'), 'pass', 'backup/app.zip · ' . maint_human(filesize(MC_ROOT . '/backup/app.zip')) . ' · ' . date('Y-m-d H:i', filemtime(MC_ROOT . '/backup/app.zip')))
        : maint_item('backup', $G_APP, t('m_backup', 'Backup of program files'), 'warn', t('m_backup_none', 'No backup yet — Repair cannot restore deleted files.'), t('m_fix_backup', 'Create backup'));

    $bat = is_file(MC_ROOT . '/start.bat') ? file_get_contents(MC_ROOT . '/start.bat') : '';
    $I[] = strpos($bat, 'tools\\php') !== false
        ? maint_item('start', $G_APP, t('m_start', 'Launcher (start.bat)'), 'pass', t('m_start_ok', 'Uses the portable PHP when present.'))
        : maint_item('start', $G_APP, t('m_start', 'Launcher (start.bat)'), $bat ? 'warn' : 'fail', $bat ? t('m_start_old', 'Old launcher — does not use media_center/tools/php.') : t('m_missing', 'Missing:') . ' start.bat', t('m_fix_rewrite', 'Rewrite'));

    $missA = array_values(array_filter(MAINT_ASSETS, function ($f) { return !is_file(MC_ROOT . "/$f"); }));
    $I[] = maint_item('assets', $G_APP, t('m_assets', 'Artwork (hundo.jpg, icon, logo)'), $missA ? 'fail' : 'pass',
        $missA ? t('m_missing', 'Missing:') . ' ' . implode(', ', $missA) : implode(', ', array_map('basename', MAINT_ASSETS)),
        $hasBackup ? t('m_fix_restore', 'Restore from backup') : null);

    $setupExe = MC_ROOT . '/dist/MediaCenter-Setup.exe';
    $I[] = maint_item('installer', $G_APP, t('m_installer', 'Installer (MediaCenter-Setup.exe)'), is_file($setupExe) ? 'pass' : 'warn',
        is_file($setupExe) ? 'dist/MediaCenter-Setup.exe · ' . maint_human(filesize($setupExe)) . ' · ' . date('Y-m-d H:i', filemtime($setupExe)) : t('m_installer_none', 'Not built yet — build it to share Media Center with a download link.'),
        mc_python_ok() ? t('m_fix_build', 'Build installer') : null, '', false);

    $ext = ['openssl' => extension_loaded('openssl'), 'curl' => extension_loaded('curl'), 'zip' => class_exists('ZipArchive'), 'mbstring' => extension_loaded('mbstring')];
    $miss = array_keys(array_filter($ext, function ($v) { return !$v; }));
    $I[] = maint_item('phpinfo', $G_APP, t('m_php', 'PHP web server'), version_compare(PHP_VERSION, '7.4', '<') ? 'fail' : ($miss ? 'warn' : 'pass'),
        'PHP ' . PHP_VERSION . ' · ' . PHP_SAPI . ($miss ? ' · ' . t('m_php_ext', 'extensions off: {x} (enable in php.ini; fallbacks are used)', ['x' => implode(', ', $miss)]) : ''), null, '', false);

    /* --- data --- */
    $bad = [];
    foreach (MAINT_DIRS as $d) if (!is_dir(MC_ROOT . "/$d") || !is_writable(MC_ROOT . "/$d")) $bad[] = $d;
    $I[] = maint_item('folders', $G_DATA, t('m_folders', 'Working folders'), $bad ? 'fail' : 'pass',
        $bad ? t('m_notwritable', 'Missing or read-only:') . ' ' . implode(', ', $bad) : implode(', ', MAINT_DIRS), t('m_fix_create', 'Create'));

    $badJson = [];
    foreach (array_merge([MC_MEDIA, MC_DIRS, MC_CONFIG], glob(MC_LANG_DIR . '/*.json')) as $f) {
        if (!is_file($f)) continue;
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', (string)file_get_contents($f));
        if (trim($raw) !== '' && !is_array(json_decode($raw, true))) $badJson[] = mc_store($f);
    }
    $I[] = maint_item('json', $G_DATA, t('m_json', 'Data files (JSON)'), $badJson ? 'fail' : 'pass',
        $badJson ? t('m_corrupt', 'Damaged:') . ' ' . implode(', ', $badJson) : t('m_json_ok', 'All readable'), t('m_fix_reset', 'Repair / reset'));

    $job = mc_read_json(MC_JOB, []);
    $stuck = !empty($job['running']) && time() - ($job['heartbeat'] ?? 0) > 180;
    $I[] = maint_item('job', $G_DATA, t('m_job', 'Background jobs'), $stuck ? 'fail' : 'pass',
        $stuck ? t('m_job_stuck', 'Stuck job: {cmd}', ['cmd' => $job['cmd'] ?? '?']) : (!empty($job['running']) ? t('m_job_running', 'Running: {cmd}', ['cmd' => $job['cmd'] ?? '']) : t('m_job_idle', 'Idle')), t('m_fix_reset', 'Repair / reset'));

    $logSize = is_file(MC_LOG) ? filesize(MC_LOG) : 0;
    $I[] = maint_item('log', $G_DATA, t('m_log', 'Console log'), $logSize > 5000000 ? 'warn' : 'pass', maint_human($logSize), t('m_fix_trim', 'Trim'));

    $gone = [];
    foreach (mc_dirs() as $d) if (!is_dir(mc_abs($d))) $gone[] = $d;
    $I[] = maint_item('libdirs', $G_DATA, t('m_libdirs', 'Library folders reachable'), $gone ? 'warn' : 'pass',
        $gone ? t('m_libdirs_gone', 'Not found (drive unplugged?):') . ' ' . implode(', ', $gone) : t('m_libdirs_ok', '{n} folder(s) OK', ['n' => count(mc_dirs())]),
        t('m_fix_forget', 'Forget missing folders'), '', false);

    $media = mc_read_json(MC_MEDIA, []);
    $lost = 0;
    foreach ($media as $m) if (!is_file(mc_abs($m['path'] ?? ''))) $lost++;
    $okDirs = array_filter(mc_dirs(), function ($d) { return is_dir(mc_abs($d)); });
    $I[] = maint_item('index', $G_DATA, t('m_index', 'Library index'), $lost ? 'fail' : (count($media) || !$okDirs ? 'pass' : 'warn'),
        $lost ? t('m_index_lost', '{n} entries point to files that no longer exist', ['n' => $lost]) : t('m_index_ok', '{n} items indexed', ['n' => count($media)]), t('m_fix_reindex', 'Re-index'));

    [$broken, $orphans, $orphanBytes] = maint_art_scan($media);
    $I[] = maint_item('art', $G_DATA, t('m_art', 'Covers, thumbnails & trailers'), $broken ? 'fail' : ($orphans ? 'warn' : 'pass'),
        t('m_art_detail', '{b} broken links · {o} unused files ({s})', ['b' => $broken, 'o' => $orphans, 's' => maint_human($orphanBytes)]), t('m_fix_clean', 'Clean up'));

    /* --- tools --- */
    $I[] = maint_tool_item('python', $G_TOOLS, 'Python 3', mc_tool_local('python'), maint_find_python(), MC_IS_WIN, function () {
        return mc_python_ok() ? 'Python ' . mc_python_version() . ' · ' . mc_python_cmd() : '';
    });
    $pyOk = mc_python_ok();
    $haveLib = is_dir(MC_TOOLS . '/pylib/PIL') && is_dir(MC_TOOLS . '/pylib/mutagen');
    $I[] = maint_item('pylib', $G_TOOLS, t('m_pylib', 'Python packages (Pillow, mutagen)'), $haveLib ? 'pass' : 'fail',
        $haveLib ? 'tools/pylib' : ($pyOk ? t('m_pylib_missing', 'Not installed — needed for picture thumbnails and music tags.') : t('m_needs_python', 'Install Python first.')),
        $pyOk ? t('m_fix_download', 'Download') : null, $haveLib ? 'inside' : 'missing');
    $I[] = maint_tool_item('ffmpeg', $G_TOOLS, 'ffmpeg + ffprobe', mc_tool_local('ffmpeg'), maint_find_ffmpeg(), MC_IS_WIN);
    $I[] = maint_tool_item('ytdlp', $G_TOOLS, t('m_ytdlp', 'yt-dlp (trailer downloader)'), mc_tool_local('ytdlp'), maint_find_ytdlp(), true);
    $I[] = maint_tool_item('php', $G_TOOLS, t('m_portable_php', 'Portable PHP'), mc_tool_local('php'), maint_find_php(), MC_IS_WIN);
    $off = maint_phpini_off();
    if ($off !== null) $I[] = maint_item('phpini', $G_TOOLS, t('m_phpini', 'Portable PHP settings (php.ini)'), $off ? 'warn' : 'pass',
        $off ? t('m_phpini_off', 'Extensions switched off: {x}', ['x' => implode(', ', $off)]) : t('m_phpini_ok', 'Needed extensions are on'),
        t('m_fix_enable', 'Switch on'), 'inside');

    $unrated = 0;
    foreach ($media as $m) if (empty($m['rights']['rated_at'])) $unrated++;
    $I[] = maint_item('rights', $G_DATA, t('m_rights', 'Rights ratings (public domain / credit / proprietary)'), $unrated ? 'warn' : 'pass',
        $unrated ? t('m_rights_todo', '{n} items not rated yet', ['n' => $unrated]) : t('m_rights_ok', 'All items rated'),
        mc_python_ok() ? t('m_fix_rate', 'Rate now') : null, '', false);

    /* --- languages --- */
    $en = mc_read_json(MC_LANG_DIR . '/en.json', []);
    $code = maint_code_keys();
    $missEn = array_diff(array_keys($code), array_keys($en));
    $I[] = maint_item('lang_en', $G_LANG, t('m_lang_en', 'English word list (en.json)'), $missEn ? 'fail' : 'pass',
        $missEn ? t('m_lang_en_missing', '{n} words used by the app are missing', ['n' => count($missEn)]) : t('m_lang_en_ok', '{n} words, all used words present', ['n' => count($en) - 1]), t('m_fix_add', 'Add missing words'));
    $parts = [];
    $incomplete = false;
    foreach (mc_languages() as $c => $info) {
        if ($c === 'en') continue;
        $L = mc_read_json(MC_LANG_DIR . "/$c.json", []);
        $have = 0;
        foreach ($en as $k => $v) {
            if ($k === '_meta' || !is_string($v)) continue;
            preg_match_all('/\{\w+\}/', $v, $a);
            preg_match_all('/\{\w+\}/', (string)($L[$k] ?? ''), $b);
            sort($a[0]); sort($b[0]);
            if (!empty($L[$k]) && $a[0] === $b[0]) $have++;
        }
        $pct = count($en) > 1 ? floor($have * 100 / (count($en) - 1)) : 100;
        if ($pct < 100) $incomplete = true;
        $parts[] = "$c $pct%";
    }
    $I[] = maint_item('lang_tr', $G_LANG, t('m_lang_tr', 'Translations'), $incomplete ? 'fail' : 'pass', implode(' · ', $parts),
        $incomplete && $pyOk ? t('m_fix_translate', 'Translate missing (online AI)') : null, '', false);
    return $I;
}

/** Standard pass/warn/fail for a portable tool. */
function maint_tool_item($id, $group, $name, $local, $found, $canDownload, $extra = null) {
    if (is_file($local)) {
        $d = mc_store($local);
        if ($extra && ($x = $extra())) $d = $x;
        return maint_item($id, $group, $name, 'pass', $d, null, 'inside');
    }
    if ($found) return maint_item($id, $group, $name, 'warn', t('m_found_outside', 'Found outside media_center: {p}', ['p' => $found]), t('m_fix_import', 'Copy into media_center'), 'outside');
    if ($canDownload) return maint_item($id, $group, $name, 'fail', t('m_not_found', 'Not found on this computer.'), t('m_fix_download', 'Download'), 'missing');
    $sys = $id === 'php' ? t('m_php_system', 'Using the system PHP (portable copy is Windows-only).') : t('m_not_found', 'Not found on this computer.');
    if ($id === 'python' && mc_python_ok()) $sys = t('m_py_system', 'Using system Python {v} (portable copy is Windows-only).', ['v' => mc_python_version()]);
    return maint_item($id, $group, $name, 'warn', $sys, null, 'outside', false);
}

function maint_art_scan($media) {
    $refs = [];
    $broken = 0;
    foreach ($media as $m) {
        foreach (['thumbnail', 'cover', 'poster', 'backdrop', 'trailer'] as $k) {
            if (empty($m[$k])) continue;
            $p = preg_replace('/\?.*$/', '', $m[$k]);
            $refs[$p] = true;
            if (!is_file(MC_ROOT . '/' . $p)) $broken++;
        }
    }
    $orph = 0;
    $bytes = 0;
    foreach (['thumbnails', 'covers', 'backdrops', 'trailers'] as $d) {
        foreach ((glob(MC_ROOT . "/$d/*") ?: []) as $f) {
            if (is_file($f) && !isset($refs["$d/" . basename($f)])) { $orph++; $bytes += filesize($f); }
        }
    }
    return [$broken, $orph, $bytes];
}

function maint_summary($items) {
    $s = ['pass' => 0, 'warn' => 0, 'fail' => 0];
    foreach ($items as $i) $s[$i['status']]++;
    return $s;
}

/* ====================================================================== fixes */

function maint_fix($id) {
    switch ($id) {
        case 'folders':
            foreach (MAINT_DIRS as $d) { @mkdir(MC_ROOT . "/$d", 0777, true); @chmod(MC_ROOT . "/$d", 0777); }
            maint_say('  folders ready');
            return true;
        case 'json':
            $defaults = [MC_MEDIA => [], MC_DIRS => [], MC_CONFIG => new stdClass()];
            foreach (array_merge(array_keys($defaults), glob(MC_LANG_DIR . '/*.json')) as $f) {
                if (!is_file($f)) continue;
                $raw = preg_replace('/^\xEF\xBB\xBF/', '', (string)file_get_contents($f));
                if (trim($raw) !== '' && is_array(json_decode($raw, true))) continue;
                copy($f, $f . '.damaged-' . date('Ymd-His'));
                $rel = mc_store($f);
                if (maint_restore_from_backup([$rel])) { maint_say("  restored $rel from backup"); continue; }
                mc_write_json($f, $defaults[$f] ?? ['_meta' => ['name' => basename($f, '.json')]]);
                maint_say("  reset $rel (old copy kept as .damaged)");
                if ($f === MC_MEDIA) mc_index_directories();
            }
            return true;
        case 'job':
            mc_write_json(MC_JOB, ['running' => false, 'reset' => time()]);
            return true;
        case 'log':
            if (is_file(MC_LOG)) file_put_contents(MC_LOG, substr(file_get_contents(MC_LOG), -200000));
            return true;
        case 'libdirs':
            $keep = array_values(array_filter(mc_dirs(), function ($d) { return is_dir(mc_abs($d)); }));
            mc_write_json(MC_DIRS, $keep);
            mc_index_directories();
            maint_say('  library folders: ' . count($keep));
            return true;
        case 'index':
            $n = mc_index_directories();
            maint_say("  re-indexed: $n items");
            return true;
        case 'art':
            return maint_fix_art();
        case 'app':
            return maint_fix_app();
        case 'backup':
            return maint_make_backup();
        case 'start':
            return maint_write_start();
        case 'lang_en':
            $en = mc_read_json(MC_LANG_DIR . '/en.json', []);
            $n = 0;
            foreach (maint_code_keys() as $k => $d) {
                if (!isset($en[$k])) { $en[$k] = $d ?: ucfirst(str_replace('_', ' ', $k)); $n++; }
            }
            $meta = $en['_meta'] ?? ['name' => 'English', 'dir' => 'ltr'];
            unset($en['_meta']);
            ksort($en);
            mc_write_json(MC_LANG_DIR . '/en.json', ['_meta' => $meta] + $en);
            maint_say("  en.json: added $n");
            return true;
        case 'python': return maint_fix_python();
        case 'pylib': return maint_fix_pylib();
        case 'ffmpeg': return maint_fix_ffmpeg();
        case 'ytdlp': return maint_fix_ytdlp();
        case 'php': return maint_fix_php();
        case 'assets': return maint_restore_from_backup(MAINT_ASSETS);
        case 'phpini': return maint_fix_phpini();
    }
    return false;
}

function maint_fix_art() {
    $media = mc_read_json(MC_MEDIA, []);
    $refs = [];
    $fixed = 0;
    foreach ($media as &$m) {
        foreach (['thumbnail', 'cover', 'poster', 'backdrop', 'trailer'] as $k) {
            if (empty($m[$k])) continue;
            $p = preg_replace('/\?.*$/', '', $m[$k]);
            if (is_file(MC_ROOT . '/' . $p)) { $refs[$p] = true; continue; }
            unset($m[$k]);
            $m['scanned'] = false;   // next scan rebuilds thumbnails / tags
            if (in_array($k, ['poster', 'backdrop', 'cover', 'trailer'], true)) unset($m['enriched']); // next enrich refetches
            $fixed++;
        }
    }
    unset($m);
    mc_write_json(MC_MEDIA, $media);
    $del = 0;
    $bytes = 0;
    foreach (['thumbnails', 'covers', 'backdrops', 'trailers'] as $d) {
        foreach ((glob(MC_ROOT . "/$d/*") ?: []) as $f) {
            if (is_file($f) && !isset($refs["$d/" . basename($f)])) { $bytes += filesize($f); @unlink($f); $del++; }
        }
    }
    maint_say("  fixed $fixed broken links, removed $del unused files (" . maint_human($bytes) . ')');
    if ($fixed) maint_say('  run "scan" and "enrich" in the console to rebuild the missing artwork');
    return true;
}

function maint_restore_from_backup($files) {
    $zip = MC_ROOT . '/backup/app.zip';
    if (!is_file($zip)) return false;
    $ok = false;
    foreach ($files as $f) {
        $pattern = '#^' . preg_quote($f, '#') . '$#';
        $tmp = MC_DATA . '/_restore';
        if (maint_unzip($zip, $tmp, $pattern) > 0 && is_file("$tmp/$f")) {
            @mkdir(dirname(MC_ROOT . "/$f"), 0777, true);
            copy("$tmp/$f", MC_ROOT . "/$f");
            $ok = true;
        }
        maint_rrmdir($tmp);
    }
    return $ok;
}

function maint_fix_app() {
    $want = [];
    $man = maint_manifest();
    foreach (MAINT_APP_FILES as $f) {
        if (!is_file(MC_ROOT . "/$f") || (isset($man[$f]) && $man[$f] !== sha1_file(MC_ROOT . "/$f"))) $want[] = $f;
    }
    if (!$want) return true;
    $ok = maint_restore_from_backup($want);
    maint_say($ok ? '  restored: ' . implode(', ', $want) : '  no backup to restore from');
    return $ok;
}

function maint_make_backup() {
    @mkdir(MC_ROOT . '/backup', 0777, true);
    $files = [];
    $man = [];
    foreach (array_unique(array_merge(MAINT_APP_FILES, MAINT_ASSETS, ['start.bat', 'README.md', 'resources.py', 'scan.py', 'scan.php', 'add_directory.php', 'db.php'],
        array_map('mc_store', glob(MC_LANG_DIR . '/*.json')))) as $f) {
        if (!is_file(MC_ROOT . "/$f")) continue;
        $files[] = $f;
        if (in_array($f, MAINT_APP_FILES, true)) $man[$f] = sha1_file(MC_ROOT . "/$f");
    }
    $tmp = MC_ROOT . '/backup/app.tmp.zip';
    @unlink($tmp);
    if (class_exists('ZipArchive')) {
        $z = new ZipArchive();
        $z->open($tmp, ZipArchive::CREATE);
        foreach ($files as $f) $z->addFile(MC_ROOT . "/$f", $f);
        $z->close();
    } else {
        // no PHP zip extension: use the portable Python, else the tar.exe built into Windows 10+
        chdir(MC_ROOT);
        $list = implode(' ', array_map('escapeshellarg', $files));
        if (mc_python_ok()) @shell_exec(mc_shell_exe(mc_python_cmd()) . ' -m zipfile -c ' . escapeshellarg($tmp) . ' ' . $list . ' 2>&1');
        if (!is_file($tmp)) @shell_exec('tar -a -c -f ' . escapeshellarg($tmp) . ' ' . $list . ' 2>&1');
    }
    if (!is_file($tmp) || filesize($tmp) < 100) { maint_say('  could not create backup/app.zip'); return false; }
    @unlink(MC_ROOT . '/backup/app.zip');
    rename($tmp, MC_ROOT . '/backup/app.zip');
    mc_write_json(MC_DATA . '/manifest.json', $man);
    maint_say('  backup/app.zip saved (' . count($man) . ' program files fingerprinted)');
    return true;
}

/** Switch on the extensions Media Center needs in media_center/tools/php/php.ini and make its paths portable. */
function maint_fix_phpini() {
    $ini = MC_TOOLS . '/php/php.ini';
    if (!is_file($ini)) { maint_php_ini(MC_TOOLS . '/php'); if (!is_file($ini)) return false; }
    $t = file_get_contents($ini);
    foreach (MAINT_PHP_EXT as $e) $t = preg_replace('/^;\s*extension\s*=\s*(php_)?' . $e . '(\.dll)?\s*$/mi', "extension=$e", $t);
    $winDst = str_replace('/', '\\', mc_norm_path(MC_TOOLS . '/php'));
    $t = preg_replace('/^(\s*extension_dir\s*=).*$/mi', '$1 "ext"', $t);
    $t = str_ireplace(['C:\\xampp\\php', '\\xampp\\php'], [$winDst, $winDst], $t);
    file_put_contents($ini, $t);
    maint_say('  php.ini: extensions on — ' . implode(', ', MAINT_PHP_EXT));
    return true;
}

function maint_phpini_off() {
    $ini = MC_TOOLS . '/php/php.ini';
    if (!is_file($ini)) return null;
    $t = file_get_contents($ini);
    $off = [];
    foreach (MAINT_PHP_EXT as $e) if (!preg_match('/^\s*extension\s*=\s*"?(php_)?' . $e . '(\.dll)?"?\s*$/mi', $t)) $off[] = $e;
    return $off;
}

function maint_write_start() {
    $bat = <<<'BAT'
@echo off
REM Local Media Center (console launcher). Double-click "Media Center.hta" for the normal launcher.
REM Uses media_center\tools\php (portable). If no PHP exists anywhere it is downloaded automatically.
cd /d "%~dp0"
set "PHP="
set "PHPARGS="
if not exist "tools\php\php.exe" (
  where php >nul 2>nul && set "PHP=php"
  if not defined PHP if exist "C:\xampp\php\php.exe" set "PHP=C:\xampp\php\php.exe"
)
if not defined PHP if not exist "tools\php\php.exe" (
  echo Downloading PHP into media_center\tools\php ...
  if not exist tools mkdir tools
  curl.exe -L -f -o "tools\_php.zip" "https://windows.php.net/downloads/releases/latest/php-8.4-nts-Win32-vs17-x64-latest.zip" || curl.exe -L -f -o "tools\_php.zip" "https://windows.php.net/downloads/releases/latest/php-8.3-nts-Win32-vs16-x64-latest.zip"
  if not exist "tools\php" mkdir "tools\php"
  tar.exe -xf "tools\_php.zip" -C "tools\php" && del "tools\_php.zip"
)
if exist "tools\php\php.exe" (
  set "PHP=%~dp0tools\php\php.exe"
  set PHPARGS=-d extension_dir="%~dp0tools\php\ext" -d extension=curl -d extension=openssl -d extension=zip -d extension=mbstring -d extension=fileinfo
  if exist "tools\php\php.ini" set PHPARGS=-c "%~dp0tools\php\php.ini" -d extension_dir="%~dp0tools\php\ext"
)
if not defined PHP (
  echo PHP could not be found or downloaded. Check the internet connection, or copy a PHP folder to media_center\tools\php
  pause
  exit /b 1
)
set PORT=8080
start "" http://127.0.0.1:%PORT%/index.php
"%PHP%" %PHPARGS% -S 127.0.0.1:%PORT% -t "%~dp0."
BAT;
    file_put_contents(MC_ROOT . '/start.bat', str_replace("\n", "\r\n", $bat));
    maint_say('  start.bat written');
    return true;
}

/* ---------- tools ---------- */

function maint_fix_ffmpeg() {
    $dst = MC_TOOLS . '/ffmpeg';
    @mkdir($dst, 0777, true);
    $found = maint_find_ffmpeg();
    if ($found) {
        $dir = dirname($found);
        maint_say("  found ffmpeg at $found — copying into media_center/tools/ffmpeg");
        foreach (glob("$dir/*") as $f) {
            $b = strtolower(basename($f));
            if (preg_match('/^(ffmpeg|ffprobe)(\.exe)?$|\.dll$/', $b)) copy($f, "$dst/" . basename($f));
        }
        if (!MC_IS_WIN) { @chmod("$dst/ffmpeg", 0755); @chmod("$dst/ffprobe", 0755); }
        if (!is_file("$dst/" . mc_exe('ffprobe')) && ($p = mc_which('ffprobe'))) copy($p, "$dst/" . mc_exe('ffprobe'));
        return is_file(mc_tool_local('ffmpeg'));
    }
    if (!MC_IS_WIN) { maint_say('  not found; install ffmpeg with your package manager'); return false; }
    $zip = MC_TOOLS . '/_ffmpeg.zip';
    $ok = maint_download('https://www.gyan.dev/ffmpeg/builds/ffmpeg-release-essentials.zip', $zip, 'ffmpeg')
        || maint_download('https://github.com/BtbN/FFmpeg-Builds/releases/download/latest/ffmpeg-master-latest-win64-gpl.zip', $zip, 'ffmpeg');
    if (!$ok) return false;
    maint_unzip($zip, $dst, '#/bin/(ffmpeg|ffprobe)\.exe$#i', true);
    @unlink($zip);
    return is_file(mc_tool_local('ffmpeg'));
}

function maint_fix_ytdlp() {
    $dst = mc_tool_local('ytdlp');
    @mkdir(dirname($dst), 0777, true);
    if ($found = maint_find_ytdlp()) {
        maint_say("  found yt-dlp at $found — copying into media_center/tools");
        copy($found, $dst);
    } else {
        $url = 'https://github.com/yt-dlp/yt-dlp/releases/latest/download/' . (MC_IS_WIN ? 'yt-dlp.exe' : 'yt-dlp');
        if (!maint_download($url, $dst, 'yt-dlp')) return false;
    }
    if (!MC_IS_WIN) @chmod($dst, 0755);
    return is_file($dst);
}

function maint_fix_python() {
    $dst = MC_TOOLS . '/python';
    if (!MC_IS_WIN) { maint_say('  a portable Python copy is only supported on Windows; the system Python is used'); return mc_python_ok(); }
    if ($found = maint_find_python()) {
        maint_say("  found Python at $found — copying into media_center/tools/python");
        maint_copy_tree(dirname($found), $dst, ['doc', 'tcl', 'tools', 'scripts', 'include', 'libs', 'test', 'share',
            'lib/site-packages', 'lib/test', 'lib/idlelib', 'lib/tkinter', 'lib/turtledemo']);
        @mkdir("$dst/Lib/site-packages", 0777, true);
    } else {
        $arch = stripos(php_uname('m'), 'arm') !== false ? 'arm64' : 'amd64';
        $v = MAINT_PY_VERSION;
        $zip = MC_TOOLS . '/_python.zip';
        if (!maint_download("https://www.python.org/ftp/python/$v/python-$v-embed-$arch.zip", $zip, 'Python')) return false;
        maint_unzip($zip, $dst);
        @unlink($zip);
    }
    $ok = is_file(mc_tool_local('python'));
    if ($ok) maint_say('  portable Python ready: tools/python');
    return $ok;
}

/** Install Pillow + mutagen wheels from PyPI straight into tools/pylib (no pip needed). */
function maint_fix_pylib() {
    $info = maint_py_info();
    if (!$info) { maint_say('  Python is not working — fix Python first'); return false; }
    $tag = 'cp3' . $info['minor'];
    $plat = $info['platform'];
    $platMatch = strpos($plat, 'win') === 0 ? str_replace('-', '_', $plat)
        : (strpos($plat, 'linux') === 0 ? 'manylinux' : (strpos($plat, 'macosx') === 0 ? 'macosx' : $plat));
    $arch = strpos($plat, 'linux') === 0 ? substr($plat, strrpos($plat, '-') + 1) : '';
    $dst = MC_TOOLS . '/pylib';
    @mkdir($dst, 0777, true);
    $ok = true;
    foreach (['pillow' => false, 'mutagen' => true] as $pkg => $pure) {
        $meta = maint_get_json("https://pypi.org/pypi/$pkg/json", "PyPI $pkg");
        if (!$meta) { $ok = false; continue; }
        $pick = null;
        foreach (($meta['urls'] ?? []) as $u) {
            if (($u['packagetype'] ?? '') !== 'bdist_wheel') continue;
            $fn = $u['filename'];
            if ($pure && strpos($fn, 'py3-none-any') !== false) { $pick = $u; break; }
            if (!$pure && strpos($fn, "-$tag-$tag") !== false && strpos($fn, $platMatch) !== false
                && ($arch === '' || strpos($fn, $arch) !== false) && strpos($fn, 'musllinux') === false) { $pick = $u; break; }
        }
        if (!$pick) { maint_say("  no $pkg wheel for Python 3.{$info['minor']} $plat"); $ok = false; continue; }
        $whl = MC_TOOLS . '/_' . $pick['filename'];
        if (!maint_download($pick['url'], $whl, $pkg)) { $ok = false; continue; }
        maint_unzip($whl, $dst);
        @unlink($whl);
        maint_say("  installed $pkg {$meta['info']['version']} into tools/pylib");
    }
    return $ok;
}

function maint_php_urls() {
    $urls = [];
    $j = maint_get_json('https://windows.php.net/downloads/releases/releases.json', 'PHP releases');
    if (is_array($j)) {
        uksort($j, 'version_compare');
        $rel = end($j);
        foreach (['nts-vs17-x64', 'nts-vs16-x64'] as $k) if (!empty($rel[$k]['zip']['path'])) $urls[] = 'https://windows.php.net/downloads/releases/' . $rel[$k]['zip']['path'];
    }
    $urls[] = 'https://windows.php.net/downloads/releases/latest/php-8.4-nts-Win32-vs17-x64-latest.zip';
    $urls[] = 'https://windows.php.net/downloads/releases/latest/php-8.3-nts-Win32-vs16-x64-latest.zip';
    return $urls;
}

function maint_php_ini($dir) {
    if (is_file("$dir/php.ini") || !is_file("$dir/php.ini-production")) return;
    $t = file_get_contents("$dir/php.ini-production");
    $t = preg_replace('/^;\s*extension_dir\s*=\s*"ext"/m', 'extension_dir = "ext"', $t);
    foreach (['curl', 'fileinfo', 'gd', 'mbstring', 'openssl', 'zip', 'sockets', 'exif'] as $e) $t = preg_replace('/^;\s*extension\s*=\s*' . $e . '\s*$/m', "extension=$e", $t);
    $t = preg_replace('/^memory_limit\s*=.*$/m', 'memory_limit = 512M', $t);
    file_put_contents("$dir/php.ini", $t);
}

function maint_fix_php() {
    $src = maint_find_php();
    if (!$src && MC_IS_WIN) {
        $zip = MC_TOOLS . '/_php.zip';
        foreach (maint_php_urls() as $u) {
            if (!maint_download($u, $zip, 'PHP')) continue;
            maint_unzip($zip, MC_TOOLS . '/php');
            @unlink($zip);
            maint_php_ini(MC_TOOLS . '/php');
            maint_fix_phpini();
            maint_write_start();
            return is_file(mc_tool_local('php'));
        }
        return false;
    }
    if (!$src) { maint_say('  no self-contained PHP found to copy'); return false; }
    $from = dirname($src);
    $dst = MC_TOOLS . '/php';
    maint_say("  copying PHP from $from into media_center/tools/php");
    maint_copy_tree($from, $dst, ['dev', 'docs', 'extras', 'tests', 'tmp', 'logs', 'sessions', 'pear', 'www']);
    if (is_file("$dst/php.ini")) {
        $ini = file_get_contents("$dst/php.ini");
        $winFrom = str_replace('/', '\\', $from);
        $winDst = str_replace('/', '\\', mc_norm_path($dst));
        $ini = str_ireplace([$winFrom, $from], [$winDst, $winDst], $ini);
        file_put_contents("$dst/php.ini", $ini);
    }
    maint_fix_phpini();
    maint_write_start();
    return is_file(mc_tool_local('php'));
}

/* ====================================================================== runners */

function maint_ids_for($mode) {
    $items = maint_checks();
    $ids = [];
    foreach ($items as $i) {
        if (!$i['fix'] || !$i['bulk']) continue;
        if ($mode === 'repair' && $i['status'] === 'fail') $ids[] = $i['id'];
        if ($mode === 'deploy' && $i['status'] !== 'pass') $ids[] = $i['id'];
    }
    if ($mode === 'deploy') { $ids[] = 'start'; $ids[] = 'backup'; }
    $ids = array_values(array_unique($ids));
    usort($ids, function ($a, $b) {
        return array_search($a, MAINT_ORDER, true) <=> array_search($b, MAINT_ORDER, true);
    });
    return $ids;
}

function maint_execute($args) {
    $mode = $args[0] ?? 'check';
    $ids = $mode === 'fix' ? array_slice($args, 1) : maint_ids_for($mode);
    maint_say(strtoupper($mode) . ($ids ? ': ' . implode(', ', $ids) : ': ' . t('m_nothing', 'nothing to do')));
    $okAll = true;
    foreach ($ids as $n => $id) {
        maint_progress(($n + 1) . '/' . count($ids) . " $id");
        maint_say("• $id");
        try {
            $ok = maint_fix($id);
        } catch (Throwable $e) {
            $ok = false;
            maint_say('  error: ' . $e->getMessage());
        }
        maint_say($ok ? "  ✓ $id" : "  ✗ $id");
        $okAll = $okAll && $ok;
    }
    mc_net_done();
    $s = maint_summary(maint_checks());
    maint_say(t('m_result', 'Result: {p} passed, {w} warnings, {f} failed', ['p' => $s['pass'], 'w' => $s['warn'], 'f' => $s['fail']]));
    mc_disk_usage(true);
    return $okAll;
}

/** Start from the web UI: background PHP worker if possible, otherwise run right now. */
function maint_start($args) {
    $job = mc_job();
    if (!empty($job['running'])) return ['error' => t('con_busy', 'A job is already running:') . ' ' . ($job['cmd'] ?? '')];
    $pyFix = ['lang_tr' => ['lang', 'update'], 'rights' => ['rights'], 'installer' => ['build']];
    if (($args[0] ?? '') === 'fix' && isset($pyFix[$args[1] ?? ''])) {
        if (!mc_python_ok()) return ['error' => t('m_needs_python', 'Install Python first.')];
        mc_log('> ' . implode(' ', $pyFix[$args[1]]));
        mc_run_python($pyFix[$args[1]]);
        return ['started' => true];
    }
    array_unshift($args, 'run');
    if (mc_run_php_worker($args)) return ['started' => true];
    @set_time_limit(0);
    ignore_user_abort(true);
    mc_write_json(MC_JOB, ['running' => true, 'cmd' => implode(' ', array_slice($args, 1)), 'started' => time(), 'heartbeat' => time()]);
    maint_execute(array_slice($args, 1));
    mc_write_json(MC_JOB, ['running' => false, 'cmd' => implode(' ', array_slice($args, 1)), 'heartbeat' => time()]);
    return ['done' => true];
}

/** Quick reachability test of the online services (TCP connect only). */
function maint_net_test() {
    if (!mc_online()) return [];
    $hosts = ['itunes.apple.com' => 'iTunes', 'api.deezer.com' => 'Deezer', 'en.wikipedia.org' => 'Wikipedia',
        'api.themoviedb.org' => 'TMDB', 'pypi.org' => 'PyPI', 'github.com' => 'GitHub', 'www.gyan.dev' => 'ffmpeg builds',
        'www.python.org' => 'python.org'];
    $out = [];
    foreach ($hosts as $h => $name) {
        $t = microtime(true);
        $fp = @fsockopen('ssl://' . $h, 443, $errno, $err, 4);
        if ($fp) fclose($fp);
        $out[] = ['host' => $h, 'name' => $name, 'ok' => (bool)$fp, 'ms' => (int)((microtime(true) - $t) * 1000), 'error' => $fp ? '' : trim($err)];
    }
    return $out;
}

/* ====================================================================== CLI entry */
if (PHP_SAPI === 'cli' && isset($argv) && realpath($argv[0]) === realpath(__FILE__)) {
    $args = array_slice($argv, 1);
    if (($args[0] ?? '') === 'run') {       // background worker started by the web UI
        $args = array_slice($args, 1);
        $job = mc_read_json(MC_JOB, []);
        $job['alive'] = true;                // tell the web page we started
        $job['heartbeat'] = time();
        mc_write_json(MC_JOB, $job);
        try { maint_execute($args); }
        finally { mc_write_json(MC_JOB, ['running' => false, 'cmd' => $job['cmd'] ?? implode(' ', $args), 'heartbeat' => time()]); }
    } elseif (($args[0] ?? 'check') === 'check') {
        foreach (maint_checks() as $i) printf("%-5s %-10s %s — %s\n", strtoupper($i['status']), $i['id'], $i['name'], $i['detail']);
    } else {
        maint_execute($args);
    }
}
