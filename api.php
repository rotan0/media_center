<?php
/**
 * JSON API used by the web UI and the in-browser console.
 */
require __DIR__ . '/lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$in = $_POST;
if (empty($in)) {
    $raw = file_get_contents('php://input');
    if ($raw) $in = json_decode($raw, true) ?: [];
}

function out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function counts($media) {
    $c = ['movie' => 0, 'music' => 0, 'image' => 0];
    foreach ($media as $m) if (isset($c[$m['type']])) $c[$m['type']]++;
    return $c;
}

function add_dir($path) {
    $abs = mc_abs(mc_norm_path($path));
    if ($abs === '' || !is_dir($abs)) return ['ok' => false, 'error' => t('err_folder_missing', 'Folder not found:') . ' ' . $path];
    $path = mc_store($abs);
    $dirs = mc_dirs();
    foreach ($dirs as $d) {
        if (strcasecmp($d, $path) === 0) return ['ok' => false, 'error' => t('err_folder_exists', 'That folder is already in your library.')];
    }
    $dirs[] = $path;
    mc_write_json(MC_DIRS, $dirs);
    $total = mc_index_directories([$path]);
    mc_log("Added folder $path — library now has $total items");
    $cfg = mc_config();
    $job = mc_job();
    $started = false;
    if ($cfg['auto_scan_on_add'] && empty($job['running']) && mc_python_ok()) {
        mc_run_python(['scan', '--dir', $path]);
        $started = true;
    }
    return ['ok' => true, 'total' => $total, 'scan_started' => $started, 'dirs' => mc_dirs()];
}

function remove_dir($path) {
    $path = mc_store($path);
    $dirs = array_values(array_filter(mc_dirs(), function ($d) use ($path) { return strcasecmp($d, $path) !== 0; }));
    mc_write_json(MC_DIRS, $dirs);
    $media = array_values(array_filter(mc_read_json(MC_MEDIA, []), function ($m) use ($dirs) {
        foreach ($dirs as $d) if (mc_under($m['path'], $d)) return true;
        return false;
    }));
    mc_write_json(MC_MEDIA, $media);
    mc_log("Removed folder $path");
    return ['ok' => true, 'dirs' => $dirs];
}

function browse_roots() {
    $roots = [['name' => t('portable_library', 'media_center/library (portable — travels with the app)'), 'path' => mc_norm_path(MC_LIBRARY), 'kind' => 'place']];
    if (MC_IS_WIN) {
        foreach (range('C', 'Z') as $L) if (@is_dir("$L:/")) $roots[] = ['name' => "$L:", 'path' => "$L:/", 'kind' => 'drive'];
        $home = getenv('USERPROFILE');
        if (!$home) {
            // Apache as a service has no USERPROFILE; infer from this app's own path (C:/Users/NAME/...)
            if (preg_match('#^([A-Za-z]:/Users/[^/]+)#i', mc_norm_path(MC_ROOT), $m)) $home = $m[1];
        }
    } else {
        $roots[] = ['name' => '/', 'path' => '/', 'kind' => 'drive'];
        $home = getenv('HOME') ?: null;
    }
    if ($home) {
        $home = mc_norm_path($home);
        $places = ['Videos', 'Movies', 'Music', 'Pictures', 'Downloads', 'Desktop', 'OneDrive/Videos', 'OneDrive/Music', 'OneDrive/Pictures', 'OneDrive/Documents'];
        foreach ($places as $p) if (is_dir("$home/$p")) $roots[] = ['name' => $p, 'path' => "$home/$p", 'kind' => 'place'];
        $roots[] = ['name' => basename($home), 'path' => $home, 'kind' => 'place'];
    }
    return $roots;
}

function browse($path) {
    $path = mc_norm_path($path);
    if ($path === '') return ['path' => '', 'parent' => null, 'dirs' => browse_roots(), 'media' => ['movie' => 0, 'music' => 0, 'image' => 0]];
    if (!is_dir($path)) return ['error' => t('err_folder_missing', 'Folder not found:') . ' ' . $path];
    $dirs = [];
    $media = ['movie' => 0, 'music' => 0, 'image' => 0];
    $h = @opendir($path);
    if ($h) {
        while (($f = readdir($h)) !== false) {
            if ($f === '.' || $f === '..' || $f[0] === '.' || $f[0] === '$') continue;
            $full = rtrim($path, '/') . '/' . $f;
            if (@is_dir($full)) {
                if (in_array(strtolower($f), ['system volume information', 'windows', 'program files', 'program files (x86)', 'programdata', 'appdata'], true)) continue;
                $dirs[] = ['name' => $f, 'path' => mc_norm_path($full)];
            } else {
                $type = mc_media_type($f);
                if ($type) $media[$type]++;
            }
        }
        closedir($h);
    }
    usort($dirs, function ($a, $b) { return strnatcasecmp($a['name'], $b['name']); });
    $parent = dirname($path);
    if (preg_match('#^[A-Za-z]:/?$#', $path) || $path === '/') $parent = '';
    return ['path' => $path, 'parent' => mc_norm_path($parent), 'dirs' => $dirs, 'media' => $media];
}

/* ---------- Console ---------- */
function console_help() {
    return implode("\n", [
        t('con_help_title', 'Media Center console — commands:'),
        '  help                         ' . t('con_help_help', 'show this list'),
        '  status                       ' . t('con_help_status', 'tools found, library counts, current job'),
        '  setup                        ' . t('con_help_setup', 'put Python, packages, ffmpeg and yt-dlp into media_center/tools'),
        '  check                        ' . t('con_help_check', 'pass/fail checklist of every component'),
        '  deploy | repair | fix <id>   ' . t('con_help_deploy', 'make portable / fix failures / fix one item (see Maintenance)'),
        '  scan [--full]                ' . t('con_help_scan', 'rescan all folders, make thumbnails, read tags'),
        '  enrich [movies|music|all] [--force]  ' . t('con_help_enrich', 'search the internet for covers, synopsis, trailers'),
        '  fetch <title> [= search text]  ' . t('con_help_fetch', 're-match one item online'),
        '  search <text>                ' . t('con_help_search', 'look up a movie or album online (preview only)'),
        '  trailers                     ' . t('con_help_trailers', 'download missing trailers'),
        '  rights [--online] [--force]  ' . t('con_help_rights', 'rate every item: public domain, needs credit, personal or proprietary'),
        '  sharepack                    ' . t('con_help_sharepack', 'zip all free-to-share media with a CREDITS.txt into share/'),
        '  save [--free]                ' . t('con_help_save', 'copy free-to-share media into media_center/library (portable)'),
        '  build [--with-tools]         ' . t('con_help_build', 'build dist/MediaCenter-Setup.exe and the portable zip'),
        '  online | offline             ' . t('con_help_mode', 'switch Online mode on or off (Offline: nothing leaves this computer)'),
        '  dirs | dirs add <path> | dirs remove <path>',
        '  lang list                    ' . t('con_help_langlist', 'list language files'),
        '  lang scan                    ' . t('con_help_langscan', 'scan the app for words and update en.json'),
        '  lang gen <code> [code...]    ' . t('con_help_langgen', 'AI-translate into new language files (e.g. lang gen de ja)'),
        '  lang update                  ' . t('con_help_langupdate', 'translate missing words in every language file'),
        '  config | config set <key> <value>',
        '  theme                        ' . t('con_help_theme', 'download logo/theme art and rebuild colors'),
        '  log                          ' . t('con_help_log', 'show the full log'),
        '  stop                         ' . t('con_help_stop', 'forget a stuck job'),
        '  clear                        ' . t('con_help_clear', 'clear the screen'),
    ]);
}

function console_run($line) {
    $line = trim($line);
    if ($line === '') return ['text' => ''];
    $args = str_getcsv($line, ' ', '"', '\\');
    $args = array_values(array_filter($args, function ($a) { return $a !== ''; }));
    $cmd = strtolower(array_shift($args));
    $job = mc_job();
    $pyCmds = ['scan', 'enrich', 'fetch', 'search', 'trailers', 'theme', 'rights', 'sharepack', 'save', 'build', 'webget', 'ia_upload'];
    $netCmds = ['enrich', 'fetch', 'search', 'trailers', 'theme', 'webget', 'ia_upload'];
    if ((in_array($cmd, $netCmds, true) || ($cmd === 'rights' && in_array('--online', $args, true))
         || ($cmd === 'lang' && in_array($args[0] ?? '', ['gen', 'update'], true))) && !mc_online())
        return ['text' => t('off_blocked', 'Offline mode: nothing is downloaded. Switch to Online to use this.')];
    if ($cmd === 'online' || $cmd === 'offline') { mc_set_online($cmd === 'online'); return ['text' => $cmd === 'online' ? '🌐 ' . t('mode_now_online', 'Online mode on') : t('mode_now_offline', 'Offline mode on — nothing leaves this computer'), 'mode' => true]; }
    $maint = ['setup' => ['fix', 'python', 'pylib', 'ffmpeg', 'ytdlp'], 'deploy' => ['deploy'], 'repair' => ['repair']];
    if ($cmd === 'fix' && $args) $maint['fix'] = array_merge(['fix'], $args);
    if (isset($maint[$cmd])) {
        require_once __DIR__ . '/maint.php';
        mc_log("> $line");
        $r = maint_start($maint[$cmd]);
        return ['text' => $r['error'] ?? (t('con_started', 'Started:') . " $line"), 'job' => !empty($r['started']), 'reload' => !empty($r['done'])];
    }
    if ($cmd === 'check' || $cmd === 'maint') {
        require_once __DIR__ . '/maint.php';
        $rows = [];
        foreach (maint_checks() as $i) $rows[] = str_pad(strtoupper($i['status']), 6) . str_pad($i['id'], 9) . $i['name'] . ' — ' . $i['detail'];
        return ['text' => implode("\n", $rows)];
    }
    if ($cmd === 'lang' && in_array($args[0] ?? '', ['scan', 'gen', 'update'], true)) $pyCmds[] = 'lang';

    if (in_array($cmd, $pyCmds, true)) {
        if (!empty($job['running'])) return ['text' => t('con_busy', 'A job is already running:') . ' ' . $job['cmd'] . "\n" . t('con_busy_hint', 'Wait for it or type "stop".')];
        if (!mc_python_ok()) return ['text' => t('con_no_python', 'Python 3 was not found. Install it from python.org (tick "Add to PATH"), or type: config set python "C:/Path/To/python.exe"')];
        if ($cmd === 'fetch' && !$args) return ['text' => 'usage: fetch <title> [= search text]'];
        if ($cmd === 'search' && !$args) return ['text' => 'usage: search <text>'];
        if ($cmd === 'lang' && ($args[0] ?? '') === 'gen' && count($args) < 2) return ['text' => 'usage: lang gen <code> [code...]   e.g. lang gen de'];
        mc_log("> $line");
        mc_run_python(array_merge([$cmd], $args));
        return ['text' => t('con_started', 'Started:') . " $line", 'job' => true];
    }

    switch ($cmd) {
        case 'help': case '?':
            return ['text' => console_help()];
        case 'status':
            $cfg = mc_config();
            $media = mc_read_json(MC_MEDIA, []);
            $c = counts($media);
            $where = function ($tool, $fallback) {
                $l = mc_tool_local($tool);
                if (is_file($l)) return mc_store($l) . ' (portable)';
                return $fallback ?: 'not found — run "setup" or open Maintenance';
            };
            $lines = [
                "Python:  " . (mc_python_ok() ? mc_python_version() . ' · ' . mc_store(mc_python_cmd()) : 'NOT FOUND — run "setup" or open Maintenance'),
                "ffmpeg:  " . $where('ffmpeg', mc_which($cfg['ffmpeg'])),
                "yt-dlp:  " . $where('ytdlp', mc_which('yt-dlp')),
                "TMDB key: " . ($cfg['tmdb_api_key'] ? 'set' : 'not set (using iTunes + Wikipedia)'),
                "AI translate: " . ($cfg['anthropic_api_key'] ? 'Claude' : ($cfg['openai_api_key'] ? 'OpenAI' : 'Ollama/MyMemory fallback')),
                "Folders: " . count(mc_dirs()),
                "Library: {$c['movie']} movies, {$c['music']} songs, {$c['image']} images",
                "Job:     " . (!empty($job['running']) ? 'running — ' . $job['cmd'] : 'idle'),
            ];
            return ['text' => implode("\n", $lines)];
        case 'dirs':
            $sub = strtolower($args[0] ?? '');
            if ($sub === 'add') { $r = add_dir(implode(' ', array_slice($args, 1))); return ['text' => $r['ok'] ? "OK — {$r['total']} items" : $r['error'], 'reload' => true]; }
            if ($sub === 'remove') { remove_dir(implode(' ', array_slice($args, 1))); return ['text' => 'OK', 'reload' => true]; }
            $d = mc_dirs();
            return ['text' => $d ? implode("\n", $d) : t('no_folders', 'No folders yet.')];
        case 'config':
            $cfg = mc_config();
            if (strtolower($args[0] ?? '') === 'set' && isset($args[1])) {
                $key = $args[1];
                $val = implode(' ', array_slice($args, 2));
                if ($val === 'true') $val = true; elseif ($val === 'false') $val = false;
                $saved = mc_read_json(MC_CONFIG, []);
                $saved[$key] = $val;
                mc_write_json(MC_CONFIG, $saved);
                return ['text' => "$key = " . (is_bool($val) ? ($val ? 'true' : 'false') : (stripos($key, 'key') !== false ? '********' : $val))];
            }
            $show = [];
            foreach ($cfg as $k => $v) {
                if (stripos($k, 'key') !== false && $v) $v = substr($v, 0, 4) . '…';
                $show[] = str_pad($k, 20) . ' ' . (is_bool($v) ? ($v ? 'true' : 'false') : $v);
            }
            return ['text' => implode("\n", $show)];
        case 'lang':
            $l = [];
            foreach (mc_languages() as $code => $info) {
                $n = count(mc_read_json(MC_LANG_DIR . "/$code.json", [])) - 1;
                $l[] = str_pad($code, 6) . str_pad($info['name'], 18) . " $n words";
            }
            return ['text' => implode("\n", $l)];
        case 'log':
            return ['text' => is_file(MC_LOG) ? file_get_contents(MC_LOG) : ''];
        case 'stop':
            mc_write_json(MC_JOB, ['running' => false, 'stopped' => time()]);
            return ['text' => 'OK'];
        case 'index':
            $n = mc_index_directories();
            return ['text' => "Indexed $n items", 'reload' => true];
        default:
            return ['text' => t('con_unknown', 'Unknown command. Type "help".')];
    }
}

/* ---------- Routes ---------- */
switch ($action) {
    case 'media':
        $media = mc_read_json(MC_MEDIA, []);
        out(['media' => $media, 'counts' => counts($media), 'dirs' => mc_dirs(), 'version' => @filemtime(MC_MEDIA), 'online' => mc_online()]);
    case 'browse':
        out(browse($_GET['path'] ?? ''));
    case 'add_dir':
        out(add_dir($in['path'] ?? ''));
    case 'remove_dir':
        out(remove_dir($in['path'] ?? ''));
    case 'reindex':
        out(['ok' => true, 'total' => mc_index_directories()]);
    case 'status':
        $since = (int)($_GET['since'] ?? -1);
        $size = is_file(MC_LOG) ? filesize(MC_LOG) : 0;
        if ($size > 2000000) { // keep log small
            $tail = substr(file_get_contents(MC_LOG), -200000);
            file_put_contents(MC_LOG, $tail);
            $size = strlen($tail);
            $since = -1;
        }
        $text = '';
        if ($since >= 0 && $since < $size) $text = file_get_contents(MC_LOG, false, null, $since);
        clearstatcache();
        $net = mc_read_json(MC_NET, []);
        if (!empty($net['active']) && time() - ($net['updated'] ?? 0) > 5) { $net['active'] = false; $net['rate'] = 0; }
        $u = mc_disk_usage();
        out(['job' => mc_job(), 'log' => $text, 'size' => $size, 'version' => @filemtime(MC_MEDIA), 'net' => $net, 'disk' => $u['total'] ?? 0, 'online' => mc_online()]);
    case 'console':
        out(console_run($in['line'] ?? ''));
    case 'maint_check':
        require_once __DIR__ . '/maint.php';
        $items = maint_checks();
        out(['items' => $items, 'summary' => maint_summary($items), 'usage' => mc_disk_usage(!empty($_GET['refresh'])),
             'net' => mc_read_json(MC_NET, []), 'worker' => (bool)mc_php_cli(), 'root' => mc_norm_path(MC_ROOT), 'os' => PHP_OS_FAMILY]);
    case 'maint_run':
        require_once __DIR__ . '/maint.php';
        $mode = $in['mode'] ?? 'fix';
        $args = $mode === 'fix' ? array_merge(['fix'], array_map('strval', (array)($in['ids'] ?? []))) : [$mode];
        if (!in_array($mode, ['fix', 'deploy', 'repair'], true)) out(['error' => 'bad mode'], 400);
        mc_log('> ' . implode(' ', $args));
        out(maint_start($args));
    case 'autosetup':
        // First start: fetch the missing tools automatically (once), unless config auto_setup is false.
        require_once __DIR__ . '/maint.php';
        $flag = MC_DATA . '/autosetup.json';
        $cfg = mc_config();
        if (empty($cfg['auto_setup']) || is_file($flag) || !empty(mc_job()['running'])) out(['started' => false]);
        $need = [];
        foreach (maint_checks() as $i) if (in_array($i['id'], ['php', 'python', 'pylib', 'ffmpeg', 'ytdlp', 'folders'], true) && $i['status'] !== 'pass' && $i['fix']) $need[] = $i['id'];
        if ($need && !mc_online()) out(['started' => false, 'need_online' => true, 'items' => $need]);
        mc_write_json($flag, ['at' => time(), 'items' => $need]);
        if (!$need) out(['started' => false]);
        mc_log('> automatic setup: ' . implode(', ', $need));
        $r = maint_start(array_merge(['fix'], $need));
        out(['started' => !empty($r['started']) || !empty($r['done']), 'items' => $need]);
    case 'share_file':
        // Serve a finished share pack for download.
        $f = basename($_GET['f'] ?? '');
        $p = MC_ROOT . '/share/' . $f;
        if (!preg_match('/^share-pack-[\w-]+\.zip$/', $f) || !is_file($p)) out(['error' => 'not found'], 404);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $f . '"');
        header('Content-Length: ' . filesize($p));
        readfile($p);
        exit;
    case 'share_list':
        $out = [];
        foreach (glob(MC_ROOT . '/share/share-pack-*.zip') ?: [] as $f) $out[] = ['name' => basename($f), 'size' => filesize($f), 'time' => filemtime($f)];
        usort($out, function ($a, $b) { return $b['time'] - $a['time']; });
        out(['packs' => $out]);
    case 'mode':
        if (isset($in['online'])) mc_set_online(!empty($in['online']));
        out(['online' => mc_online()]);
    case 'web_search':
        require_once __DIR__ . '/web.php';
        if (!mc_online()) out(['error' => 'offline', 'results' => []]);
        $type = in_array($_GET['type'] ?? '', ['movie', 'music', 'image'], true) ? $_GET['type'] : 'movie';
        $src = array_values(array_intersect(explode(',', $_GET['sources'] ?? 'ia,commons,openverse'), ['ia', 'commons', 'openverse']));
        $r = web_search($_GET['q'] ?? '', $type, $src);
        mc_net_done();
        out($r);
    case 'web_get':
        // queue downloads of free media found online; mc.py webget saves them into media_center/library
        if (!mc_online()) out(['error' => t('off_blocked', 'Offline mode: nothing is downloaded. Switch to Online to use this.')]);
        $items = array_values(array_filter((array)($in['items'] ?? []), function ($i) { return is_array($i) && !empty($i['src']) && empty($i['restricted_nd']); }));
        $q = mc_read_json(MC_DATA . '/webget.json', []);
        foreach ($items as $i) $q[] = array_intersect_key($i, array_flip(['src', 'id', 'type', 'title', 'creator', 'year', 'license', 'license_url', 'status', 'page_url', 'media_url', 'credit', 'mime']));
        mc_write_json(MC_DATA . '/webget.json', $q);
        $job = mc_job();
        if (!empty($job['running'])) out(['queued' => count($q), 'busy' => $job['cmd']]);
        if (!mc_python_ok()) out(['error' => t('m_needs_python', 'Install Python first.')]);
        mc_log('> webget (' . count($q) . ')');
        mc_run_python(['webget']);
        out(['started' => true, 'queued' => count($q)]);
    case 'ia_upload':
        if (!mc_online()) out(['error' => t('off_blocked', 'Offline mode: nothing is downloaded. Switch to Online to use this.')]);
        $cfg = mc_config();
        if (!empty($in['ia_access'])) { $c = mc_read_json(MC_CONFIG, []); $c['ia_access'] = trim($in['ia_access']); $c['ia_secret'] = trim($in['ia_secret'] ?? ''); mc_write_json(MC_CONFIG, $c); $cfg = mc_config(); }
        if (empty($cfg['ia_access']) || empty($cfg['ia_secret'])) out(['need_keys' => true]);
        if (!empty(mc_job()['running'])) out(['error' => t('con_busy', 'A job is already running:') . ' ' . mc_job()['cmd']]);
        $id = preg_replace('/[^a-f0-9]/', '', $in['id'] ?? '');
        mc_log('> ia_upload ' . $id);
        mc_run_python(['ia_upload', $id]);
        out(['started' => true]);
    case 'ia_keys':
        $cfg = mc_config();
        out(['has_keys' => !empty($cfg['ia_access']) && !empty($cfg['ia_secret'])]);
    case 'reveal':
        // show the file in Windows Explorer (for uploading it by hand to YouTube etc.)
        $id = preg_replace('/[^a-f0-9]/', '', $in['id'] ?? '');
        foreach (mc_read_json(MC_MEDIA, []) as $m) if (($m['id'] ?? '') === $id) {
            $p = mc_abs($m['path']);
            if (MC_IS_WIN) pclose(popen('explorer /select,' . escapeshellarg(str_replace('/', '\\', $p)), 'r'));
            out(['ok' => true, 'path' => $p]);
        }
        out(['ok' => false]);
    case 'sidecars':
        require_once __DIR__ . '/web.php';
        $id = preg_replace('/[^a-f0-9]/', '', $_GET['id'] ?? '');
        foreach (mc_read_json(MC_MEDIA, []) as $m) if (($m['id'] ?? '') === $id) {
            $r = web_sidecars($m);
            foreach ($r['subs'] as &$sub) unset($sub['file']);
            out($r);
        }
        out(['subs' => [], 'lyrics' => null]);
    case 'net_test':
        require_once __DIR__ . '/maint.php';
        out(['results' => maint_net_test()]);
    case 'languages':
        out(mc_languages());
    default:
        out(['error' => 'unknown action'], 400);
}
