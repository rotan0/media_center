<?php
/**
 * Media Center shared library: paths, JSON storage, config, language, indexer, background jobs.
 */
define('MC_ROOT', __DIR__);
define('MC_DATA', MC_ROOT . '/data');
define('MC_LANG_DIR', MC_DATA . '/lang');
define('MC_MEDIA', MC_DATA . '/media.json');
define('MC_DIRS', MC_DATA . '/directories.json');
define('MC_CONFIG', MC_DATA . '/config.json');
define('MC_JOB', MC_DATA . '/job.json');
define('MC_LOG', MC_DATA . '/console.log');
define('MC_IS_WIN', strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
define('MC_TOOLS', MC_ROOT . '/tools');
define('MC_NET', MC_DATA . '/net.json');
define('MC_LIBRARY', MC_ROOT . '/library');

const MC_EXT = [
    'movie' => ['mp4', 'm4v', 'mkv', 'avi', 'mov', 'wmv', 'webm', 'mpg', 'mpeg', 'flv', 'ts', '3gp'],
    'music' => ['mp3', 'wav', 'flac', 'm4a', 'aac', 'ogg', 'oga', 'opus', 'wma', 'aiff'],
    'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff', 'heic', 'svg'],
];

@mkdir(MC_DATA, 0777, true);
@mkdir(MC_LANG_DIR, 0777, true);
@mkdir(MC_LIBRARY, 0777, true);

/* ---------- JSON storage ---------- */
function mc_read_json($file, $default = []) {
    if (!is_file($file)) return $default;
    $raw = @file_get_contents($file);
    if ($raw === false || trim($raw) === '') return $default;
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw); // strip BOM
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $default;
}

function mc_write_json($file, $data) {
    $tmp = $file . '.tmp' . getmypid();
    file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), LOCK_EX);
    if (!@rename($tmp, $file)) { // Windows can refuse rename over an open file
        @copy($tmp, $file);
        @unlink($tmp);
    }
}

/* ---------- Config ---------- */
function mc_config() {
    $defaults = [
        'python' => MC_IS_WIN ? 'python' : 'python3',
        'ffmpeg' => 'ffmpeg',
        'tmdb_api_key' => '',
        'anthropic_api_key' => '',
        'anthropic_model' => 'claude-sonnet-4-5',
        'openai_api_key' => '',
        'openai_model' => 'gpt-4o-mini',
        'ollama_url' => 'http://localhost:11434',
        'ollama_model' => 'llama3.1',
        'translate_provider' => 'auto',
        'download_trailers' => true,
        'use_ytdlp' => true,
        'auto_scan_on_add' => true,
        'metadata_language' => 'en',
        'auto_setup' => true,
        'online' => false,
        'language' => '',
    ];
    return array_merge($defaults, mc_read_json(MC_CONFIG, []));
}

/* ---------- Time zone: log times match the computer's clock (XAMPP ships with Europe/Berlin) ---------- */
function mc_detect_timezone() {
    if (MC_IS_WIN) {
        $w = preg_replace('/_dstoff$/', '', trim((string)@shell_exec('tzutil /g 2>NUL')));
        if ($w === '') return '';
        if (class_exists('IntlTimeZone') && ($id = @IntlTimeZone::getIDForWindowsID($w))) return $id;
        $map = ['Pacific Standard Time' => 'America/Los_Angeles', 'Mountain Standard Time' => 'America/Denver',
            'US Mountain Standard Time' => 'America/Phoenix', 'Central Standard Time' => 'America/Chicago',
            'Eastern Standard Time' => 'America/New_York', 'Alaskan Standard Time' => 'America/Anchorage',
            'Hawaiian Standard Time' => 'Pacific/Honolulu', 'Atlantic Standard Time' => 'America/Halifax',
            'Central Standard Time (Mexico)' => 'America/Mexico_City', 'SA Pacific Standard Time' => 'America/Bogota',
            'E. South America Standard Time' => 'America/Sao_Paulo', 'Argentina Standard Time' => 'America/Buenos_Aires',
            'GMT Standard Time' => 'Europe/London', 'Greenwich Standard Time' => 'Atlantic/Reykjavik', 'UTC' => 'UTC',
            'W. Europe Standard Time' => 'Europe/Berlin', 'Romance Standard Time' => 'Europe/Paris',
            'Central Europe Standard Time' => 'Europe/Budapest', 'Central European Standard Time' => 'Europe/Warsaw',
            'E. Europe Standard Time' => 'Europe/Chisinau', 'FLE Standard Time' => 'Europe/Kiev', 'GTB Standard Time' => 'Europe/Bucharest',
            'Russian Standard Time' => 'Europe/Moscow', 'Turkey Standard Time' => 'Europe/Istanbul', 'Egypt Standard Time' => 'Africa/Cairo',
            'South Africa Standard Time' => 'Africa/Johannesburg', 'W. Central Africa Standard Time' => 'Africa/Lagos',
            'E. Africa Standard Time' => 'Africa/Nairobi', 'Arabic Standard Time' => 'Asia/Baghdad', 'Arab Standard Time' => 'Asia/Riyadh',
            'Arabian Standard Time' => 'Asia/Dubai', 'Iran Standard Time' => 'Asia/Tehran', 'Pakistan Standard Time' => 'Asia/Karachi',
            'India Standard Time' => 'Asia/Kolkata', 'Bangladesh Standard Time' => 'Asia/Dhaka', 'SE Asia Standard Time' => 'Asia/Bangkok',
            'China Standard Time' => 'Asia/Shanghai', 'Singapore Standard Time' => 'Asia/Singapore', 'Tokyo Standard Time' => 'Asia/Tokyo',
            'Korea Standard Time' => 'Asia/Seoul', 'AUS Eastern Standard Time' => 'Australia/Sydney', 'New Zealand Standard Time' => 'Pacific/Auckland'];
        return $map[$w] ?? '';
    }
    $l = @readlink('/etc/localtime');
    if ($l && preg_match('#zoneinfo/(.+)$#', $l, $m)) return $m[1];
    return trim((string)@file_get_contents('/etc/timezone'));
}

function mc_set_timezone() {
    $cfg = mc_read_json(MC_CONFIG, []);
    $tz = $cfg['timezone'] ?? '';                       // a fixed choice wins
    if ($tz === '') {
        $host = gethostname() ?: 'pc';                  // cached per computer, so the SD card can move between PCs
        $cache = mc_read_json(MC_DATA . '/tz.json', []);
        if (($cache['host'] ?? '') === $host) $tz = $cache['tz'] ?? '';
        else { $tz = mc_detect_timezone(); mc_write_json(MC_DATA . '/tz.json', ['host' => $host, 'tz' => $tz]); }
    }
    if ($tz !== '' && in_array($tz, timezone_identifiers_list(), true)) date_default_timezone_set($tz);
}
mc_set_timezone();

/* ---------- Online / Offline mode ---------- */
/** Offline (default): Media Center never contacts the internet. Online: enrich, downloads, web search, sharing. */
function mc_online() { return !empty(mc_config()['online']); }
function mc_set_online($on) {
    $c = mc_read_json(MC_CONFIG, []);
    $c['online'] = (bool)$on;
    mc_write_json(MC_CONFIG, $c);
    mc_log($on ? '🌐 Online mode on' : '🔒 Offline mode on — nothing leaves this computer');
}

/* ---------- Paths ---------- */
function mc_norm_path($p) {
    $p = str_replace('\\', '/', trim((string)$p));
    $p = preg_replace('#/+#', '/', $p);
    if (preg_match('#^[A-Za-z]:$#', $p)) $p .= '/';
    if (strlen($p) > 1 && substr($p, -1) === '/' && !preg_match('#^[A-Za-z]:/$#', $p)) $p = rtrim($p, '/');
    return $p;
}

/** Stored form of a path: relative when it is inside media_center, so the folder stays portable. */
function mc_store($p) {
    $p = mc_norm_path($p);
    $root = mc_norm_path(MC_ROOT);
    if (strcasecmp($p, $root) === 0) return '.';
    if (stripos($p, $root . '/') === 0) return substr($p, strlen($root) + 1);
    return $p;
}

/** Absolute path for a stored path. */
function mc_abs($p) {
    $p = mc_norm_path($p);
    if ($p === '' || preg_match('#^([A-Za-z]:)?/#', $p)) return $p;
    return $p === '.' ? mc_norm_path(MC_ROOT) : mc_norm_path(MC_ROOT . '/' . $p);
}

function mc_dirs() {
    $dirs = [];
    foreach (mc_read_json(MC_DIRS, []) as $d) {
        $d = mc_store($d);
        if ($d === '' || $d === 'undefined' || $d === 'null') continue;
        if (!in_array($d, $dirs, true)) $dirs[] = $d;
    }
    return $dirs;
}

function mc_media_type($file) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    foreach (MC_EXT as $type => $list) if (in_array($ext, $list, true)) return $type;
    return null;
}

function mc_id($path) { return md5(mc_norm_path($path)); }

/* ---------- Language ---------- */
function mc_languages() {
    $out = [];
    foreach (glob(MC_LANG_DIR . '/*.json') as $f) {
        $code = basename($f, '.json');
        $j = mc_read_json($f, []);
        $out[$code] = [
            'code' => $code,
            'name' => $j['_meta']['name'] ?? strtoupper($code),
            'english' => $j['_meta']['english'] ?? '',
            'dir' => $j['_meta']['dir'] ?? 'ltr',
        ];
        $out[$code]['label'] = $out[$code]['name'] . ($out[$code]['english'] && $out[$code]['english'] !== $out[$code]['name'] ? ' — ' . $out[$code]['english'] : '');
    }
    uasort($out, function ($a, $b) { return $a['code'] === 'en' ? -1 : ($b['code'] === 'en' ? 1 : strcmp($a['english'] ?: $a['code'], $b['english'] ?: $b['code'])); });
    return $out;
}

/** Best match from the browser's Accept-Language header. */
function mc_browser_lang() {
    foreach (explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '') as $part) {
        $c = strtolower(substr(trim($part), 0, 2));
        if ($c && is_file(MC_LANG_DIR . "/$c.json")) return $c;
    }
    return 'en';
}

function mc_current_lang() {
    $lang = $_GET['lang'] ?? ($_COOKIE['mc_lang'] ?? (mc_read_json(MC_CONFIG, [])['language'] ?? '') ?: mc_browser_lang());
    $lang = preg_replace('/[^a-zA-Z_-]/', '', $lang);
    if (!is_file(MC_LANG_DIR . "/$lang.json")) $lang = 'en';
    if (isset($_GET['lang']) && !headers_sent()) {
        setcookie('mc_lang', $lang, time() + 31536000, '/');
        $c = mc_read_json(MC_CONFIG, []);
        if (($c['language'] ?? '') !== $lang) { $c['language'] = $lang; mc_write_json(MC_CONFIG, $c); }
    }
    return $lang;
}

function mc_strings($lang) {
    $en = mc_read_json(MC_LANG_DIR . '/en.json', []);
    $cur = $lang === 'en' ? [] : mc_read_json(MC_LANG_DIR . "/$lang.json", []);
    return array_merge($en, array_filter($cur, function ($v) { return $v !== '' && $v !== null; }));
}

$GLOBALS['MC_L'] = null;
/** Translate a UI key. Second argument is the English default (read by the language generator). */
function t($key, $default = null, $vars = []) {
    if ($GLOBALS['MC_L'] === null) $GLOBALS['MC_L'] = mc_strings(mc_current_lang());
    $s = $GLOBALS['MC_L'][$key] ?? ($default ?? $key);
    foreach ($vars as $k => $v) $s = str_replace('{' . $k . '}', $v, $s);
    return is_string($s) ? $s : $key;
}
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ---------- Title helpers ---------- */
function mc_clean_title($name) {
    $t = preg_replace('/[._]+/', ' ', $name);
    $t = preg_replace('/\[[^\]]*\]|\{[^}]*\}/', ' ', $t);
    // cut at typical release tags
    $t = preg_replace('/\b(2160p|1080p|720p|480p|4k|uhd|hdr|bluray|blu-ray|brrip|bdrip|webrip|web-dl|webdl|hdtv|dvdrip|x264|x265|h264|h265|hevc|aac|ac3|dts|remux|proper|repack|extended|unrated|yify|rarbg)\b.*$/i', '', $t);
    $t = trim(preg_replace('/\s+/', ' ', $t), " -()");
    return $t === '' ? $name : $t;
}

function mc_parse_movie($name) {
    $year = null;
    if (preg_match('/^(.*?)[\s._(\[-]+((?:19|20)\d{2})(?:[\s._)\]-]|$)/', $name, $m)) {
        $name = $m[1];
        $year = (int)$m[2];
    }
    return [mc_clean_title($name), $year];
}

function mc_parse_music($name, $parentFolder) {
    $name = preg_replace('/[_]+/', ' ', $name);
    $track = null;
    if (preg_match('/^(\d{1,3})[\s.\-_]+(.*)$/', $name, $m)) { $track = (int)$m[1]; $name = $m[2]; }
    $artist = '';
    $title = $name;
    $album = $parentFolder;
    if (strpos($parentFolder, ' - ') !== false) { // "Artist - Album" folder
        [$artist, $album] = array_map('trim', explode(' - ', $parentFolder, 2));
    }
    if (strpos($name, ' - ') !== false) {
        [$artist, $title] = array_map('trim', explode(' - ', $name, 2));
    }
    return ['title' => trim($title), 'artist' => $artist, 'album' => $album, 'track' => $track];
}

/* ---------- Instant indexer (PHP only; Python adds thumbnails & metadata later) ---------- */
function mc_under($path, $dir) {
    $dir = rtrim($dir, '/');
    return $dir === '.' ? !preg_match('#^([A-Za-z]:)?/#', $path) : stripos($path, $dir . '/') === 0;
}

function mc_index_directories($dirs = null, $maxFiles = 50000) {
    $dirs = $dirs ?? mc_dirs();
    $old = [];
    foreach (mc_read_json(MC_MEDIA, []) as $m) if (isset($m['id'])) $old[$m['id']] = $m;
    $all = mc_dirs();
    $keep = [];
    // keep items from directories not re-indexed this time
    foreach ($old as $id => $m) {
        $inScanned = false;
        foreach ($dirs as $d) if (mc_under($m['path'], $d)) $inScanned = true;
        $inAny = false;
        foreach ($all as $d) if (mc_under($m['path'], $d)) $inAny = true;
        if (!$inScanned && $inAny) $keep[$id] = $m;
    }
    $count = 0;
    foreach ($dirs as $dir) {
        $absDir = mc_abs($dir);
        if (!is_dir($absDir)) continue;
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absDir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS),
                RecursiveIteratorIterator::SELF_FIRST,
                RecursiveIteratorIterator::CATCH_GET_CHILD
            );
        } catch (Exception $e) { continue; }
        foreach ($it as $f) {
            if ($count >= $maxFiles) break 2;
            if (!$f->isFile()) continue;
            $fname = $f->getFilename();
            if ($fname[0] === '.' || stripos($fname, 'sample') === 0) continue;
            $type = mc_media_type($fname);
            if (!$type) continue;
            $abs = mc_norm_path($f->getPathname());
            $path = mc_store($abs);          // relative when inside media_center (portable)
            $id = mc_id($path);
            $base = pathinfo($fname, PATHINFO_FILENAME);
            $parent = basename(dirname($abs));
            $item = $old[$id] ?? [
                'id' => $id, 'path' => $path, 'type' => $type, 'added' => time(),
            ];
            $item['ext'] = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
            $item['size'] = $f->getSize();
            $item['mtime'] = $f->getMTime();
            $item['folder'] = mc_store(dirname($abs));
            if (!isset($old[$id])) {
                if ($type === 'movie') {
                    [$title, $year] = mc_parse_movie($base);
                    // "Movie Name (2010)/movie.mkv" -> use the folder name
                    if (!$year && preg_match('/^(.+?)\s*[\(\[]((?:19|20)\d{2})[\)\]]/', $parent)) [$title, $year] = mc_parse_movie($parent);
                    $item['title'] = $title;
                    $item['year'] = $year;
                } elseif ($type === 'music') {
                    $item = array_merge($item, mc_parse_music($base, $parent));
                } else {
                    $item['title'] = $base;
                }
            }
            $keep[$id] = $item;
            $count++;
        }
    }
    $list = array_values($keep);
    mc_write_json(MC_MEDIA, $list);
    return count($list);
}

/* ---------- Portable tools (everything lives in media_center/tools) ---------- */
function mc_exe($name) { return $name . (MC_IS_WIN ? '.exe' : ''); }

/** Where each tool lives inside media_center. */
function mc_tool_local($tool) {
    switch ($tool) {
        case 'python': return MC_IS_WIN ? MC_TOOLS . '/python/python.exe' : MC_TOOLS . '/python/bin/python3';
        case 'ffmpeg': return MC_TOOLS . '/ffmpeg/' . mc_exe('ffmpeg');
        case 'ffprobe': return MC_TOOLS . '/ffmpeg/' . mc_exe('ffprobe');
        case 'ytdlp': return MC_TOOLS . '/' . mc_exe('yt-dlp');
        case 'php': return MC_TOOLS . '/php/' . mc_exe('php');
    }
    return null;
}

/** Command to run Python: portable copy first, then the configured/system one. */
function mc_python_cmd() {
    $local = mc_tool_local('python');
    if (is_file($local)) return $local;
    return mc_config()['python'];
}

function mc_shell_exe($exe) {
    return (strpbrk($exe, '/\\ ') !== false) ? escapeshellarg(MC_IS_WIN ? str_replace('/', '\\', $exe) : $exe) : $exe;
}

/* ---------- Background jobs ---------- */
function mc_job() {
    $job = mc_read_json(MC_JOB, ['running' => false]);
    // a crashed job shouldn't block forever
    if (!empty($job['running']) && !empty($job['heartbeat']) && time() - $job['heartbeat'] > 180) {
        $job['running'] = false;
        $job['stale'] = true;
    }
    return $job;
}

function mc_python_version() {
    static $v = null;
    if ($v === null) {
        $out = (string)@shell_exec(mc_shell_exe(mc_python_cmd()) . ' --version 2>&1');
        $v = preg_match('/Python (3\.\d+(\.\d+)?)/', $out, $m) ? $m[1] : '';
    }
    return $v;
}

/** True when Python 3 can be started (the Windows Store "python" alias fails this check). */
function mc_python_ok() { return mc_python_version() !== ''; }

function mc_spawn($cmdline) {
    chdir(MC_ROOT);
    // /D sets the working folder explicitly: under Apache or thread-safe PHP the real process folder can differ from chdir()
    if (MC_IS_WIN) pclose(popen('start "" /D ' . escapeshellarg(str_replace('/', '\\', MC_ROOT)) . ' /B ' . $cmdline, 'r'));
    else exec('cd ' . escapeshellarg(MC_ROOT) . ' && ' . $cmdline . ' > /dev/null 2>&1 &');
}

function mc_run_python($args) {
    $parts = [];
    foreach ($args as $a) $parts[] = escapeshellarg($a);
    mc_write_json(MC_JOB, ['running' => true, 'cmd' => implode(' ', $args), 'started' => time(), 'heartbeat' => time()]);
    mc_spawn(mc_shell_exe(mc_python_cmd()) . ' -X utf8 ' . escapeshellarg(MC_ROOT . '/mc.py') . ' ' . implode(' ', $parts));
}

/** Find a php CLI binary that can run background maintenance jobs. */
function mc_php_cli() {
    $c = [];
    // the PHP running this web server is known to work, so try it first
    if (PHP_BINARY && preg_match('/^php(\.exe)?$/i', basename(PHP_BINARY))) $c[] = PHP_BINARY;
    if (php_ini_loaded_file()) $c[] = dirname(php_ini_loaded_file()) . '/' . mc_exe('php');
    $c[] = PHP_BINDIR . '/' . mc_exe('php');
    $c[] = mc_tool_local('php');
    if (MC_IS_WIN) $c[] = 'C:/xampp/php/php.exe';
    else { $c[] = '/usr/bin/php'; $c[] = '/usr/local/bin/php'; }
    foreach ($c as $p) if ($p && is_file($p)) return mc_norm_path($p);
    return null;
}

/** Start maint.php in the background. Returns true only once the worker has really started. */
function mc_run_php_worker($args) {
    $php = mc_php_cli();
    if (!$php) return false;
    $parts = [];
    foreach ($args as $a) $parts[] = escapeshellarg($a);
    $ini = '';
    $same = PHP_BINARY && strcasecmp(mc_norm_path(PHP_BINARY), $php) === 0;
    if (php_ini_loaded_file() && ($same || strcasecmp(dirname(mc_norm_path(php_ini_loaded_file())), dirname($php)) === 0)) {
        $ini .= ' -c ' . escapeshellarg(php_ini_loaded_file());   // same PHP: reuse this server's configuration
        $ext = ini_get('extension_dir');
        if ($ext && is_dir($ext)) $ini .= ' -d ' . escapeshellarg('extension_dir=' . $ext);
    } elseif (preg_match('#/tools/php/php(\.exe)?$#i', $php)) {
        $ini .= ' -d ' . escapeshellarg('extension_dir=' . dirname($php) . '/ext');
    }
    $label = implode(' ', $args[0] === 'run' ? array_slice($args, 1) : $args);
    $token = bin2hex(random_bytes(6));
    mc_write_json(MC_JOB, ['running' => true, 'cmd' => $label, 'started' => time(), 'heartbeat' => time(), 'token' => $token]);
    mc_spawn(mc_shell_exe($php) . $ini . ' ' . escapeshellarg(MC_ROOT . '/maint.php') . ' ' . implode(' ', $parts));
    // wait up to 4 s for the worker to say hello; otherwise the caller runs the job itself
    for ($i = 0; $i < 20; $i++) {
        usleep(200000);
        clearstatcache();
        $j = mc_read_json(MC_JOB, []);
        if (!empty($j['alive']) && ($j['token'] ?? '') === $token) return true;
        if (empty($j['running'])) return true;   // already finished
    }
    mc_log('  background worker did not start (' . $php . ') — running here instead');
    return false;
}

function mc_log($line) {
    file_put_contents(MC_LOG, '[' . date('H:i:s') . '] ' . $line . "\n", FILE_APPEND | LOCK_EX);
}

function mc_which($bin) {
    $out = @shell_exec((MC_IS_WIN ? 'where ' : 'command -v ') . escapeshellarg($bin) . ' 2>' . (MC_IS_WIN ? 'NUL' : '/dev/null'));
    $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string)$out))));
    return $lines ? mc_norm_path($lines[0]) : '';
}

/* ---------- Network meter (shared with Python via data/net.json) ---------- */
$GLOBALS['MC_NET'] = ['pending' => 0, 'session' => 0, 'start' => 0, 'last' => 0, 'win' => [], 'host' => '', 'service' => ''];
function mc_net_add($host, $bytes, $service = '', $final = false) {
    $n = &$GLOBALS['MC_NET'];
    $now = microtime(true);
    if (!$n['start']) $n['start'] = $now;
    $n['pending'] += $bytes;
    $n['session'] += $bytes;
    $n['host'] = $host;
    if ($service) $n['service'] = $service;
    $n['win'][] = [$now, $bytes];
    while ($n['win'] && $now - $n['win'][0][0] > 3) array_shift($n['win']);
    if (!$final && $now - $n['last'] < 0.5) return;
    $n['last'] = $now;
    $sum = 0;
    foreach ($n['win'] as $w) $sum += $w[1];
    $span = $n['win'] ? max(0.5, $now - $n['win'][0][0]) : 1;
    $d = mc_read_json(MC_NET, []);
    $d['total_bytes'] = ($d['total_bytes'] ?? 0) + $n['pending'];
    $n['pending'] = 0;
    $d['active'] = !$final;
    $d['host'] = $n['host'];
    $d['service'] = $n['service'];
    $d['rate'] = $final ? 0 : (int)($sum / $span);
    $d['session_bytes'] = $n['session'];
    $d['updated'] = time();
    mc_write_json(MC_NET, $d);
}
function mc_net_done() { $n = $GLOBALS['MC_NET']; if ($n['start']) mc_net_add($n['host'], 0, '', true); }

/* ---------- Disk usage of media_center ---------- */
function mc_dir_size($dir) {
    $total = 0;
    if (!is_dir($dir)) return 0;
    try {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD);
        foreach ($it as $f) if ($f->isFile()) $total += $f->getSize();
    } catch (Exception $e) { }
    return $total;
}

function mc_disk_usage($refresh = false) {
    $cache = mc_read_json(MC_DATA . '/usage.json', []);
    $job = mc_read_json(MC_JOB, []);
    $stale = empty($cache['computed']) || time() - $cache['computed'] > 300
        || (empty($job['running']) && ($job['heartbeat'] ?? 0) > ($cache['computed'] ?? 0));
    if (!$refresh && !$stale) return $cache;
    $parts = [];
    $total = 0;
    foreach (scandir(MC_ROOT) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = MC_ROOT . '/' . $f;
        $size = is_dir($p) ? mc_dir_size($p) : filesize($p);
        $key = is_dir($p) ? $f : 'app';
        $parts[$key] = ($parts[$key] ?? 0) + $size;
        $total += $size;
    }
    arsort($parts);
    $cache = ['computed' => time(), 'total' => $total, 'parts' => $parts];
    mc_write_json(MC_DATA . '/usage.json', $cache);
    return $cache;
}
