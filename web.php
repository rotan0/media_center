<?php
/**
 * Online features (only when the user switches Media Center to Online mode):
 *  - search free/licensed media: Internet Archive, Wikimedia Commons, Openverse
 *  - subtitle / lyrics sidecar files for the offline players
 * Everything here refuses to run in Offline mode.
 */
require_once __DIR__ . '/lib.php';

function web_http_json($url, $label) {
    if (!mc_online()) throw new Exception('offline');
    $host = parse_url($url, PHP_URL_HOST);
    $ua = 'LocalMediaCenter/2.0 (personal media library; https://github.com/)';
    $body = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 20, CURLOPT_USERAGENT => $ua,
              CURLOPT_HTTPHEADER => ['Accept: application/json']];
        if (defined('CURLSSLOPT_NATIVE_CA')) $o[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
        curl_setopt_array($ch, $o);
        $body = curl_exec($ch);
        curl_close($ch);
    }
    if ($body === false) {
        $ctx = stream_context_create(['http' => ['header' => "User-Agent: $ua\r\nAccept: application/json\r\n", 'timeout' => 20]]);
        $body = @file_get_contents($url, false, $ctx);
    }
    if ($body === false) return null;
    mc_net_add($host, strlen($body), $label);
    return json_decode($body, true);
}

/** public_domain / attribution from a licence name or URL. */
function web_status($lic) {
    $l = strtolower((string)$lic);
    if (preg_match('/publicdomain|public domain|cc0|\bpd\b|pdm/', $l)) return 'public_domain';
    return 'attribution';
}

function web_license_name($url) {
    if (preg_match('#publicdomain/zero#', $url)) return 'CC0 1.0';
    if (preg_match('#publicdomain/mark#', $url)) return 'Public Domain Mark';
    if (preg_match('#licenses/([a-z-]+)/([\d.]+)#', $url, $m)) return 'CC ' . strtoupper($m[1]) . ' ' . $m[2];
    return $url;
}

function web_credit($title, $creator, $license, $url) {
    return trim('“' . $title . '”' . ($creator ? " by $creator" : '') . ($license ? " — $license" : '') . ($url ? " — $url" : ''));
}

function web_search_ia($q, $type) {
    $mt = ['movie' => 'movies', 'music' => 'audio', 'image' => 'image'][$type];
    $query = "($q) AND mediatype:($mt) AND (licenseurl:* OR collection:(feature_films OR prelinger OR publicdomainmovies))";
    $url = 'https://archive.org/advancedsearch.php?' . http_build_query(['q' => $query, 'rows' => 24, 'output' => 'json'])
        . '&fl[]=identifier&fl[]=title&fl[]=creator&fl[]=year&fl[]=licenseurl&fl[]=collection&fl[]=downloads&sort[]=downloads+desc';
    $j = web_http_json($url, 'Internet Archive');
    $out = [];
    foreach (($j['response']['docs'] ?? []) as $d) {
        $lu = $d['licenseurl'] ?? '';
        $creator = is_array($d['creator'] ?? '') ? implode(', ', $d['creator']) : ($d['creator'] ?? '');
        $lic = $lu ? web_license_name($lu) : 'Public domain (Internet Archive collection)';
        $page = 'https://archive.org/details/' . $d['identifier'];
        $title = is_array($d['title'] ?? '') ? $d['title'][0] : ($d['title'] ?? $d['identifier']);
        $out[] = ['src' => 'ia', 'source' => 'Internet Archive', 'id' => $d['identifier'], 'type' => $type, 'title' => $title,
            'creator' => $creator, 'year' => $d['year'] ?? '', 'license' => $lic, 'license_url' => $lu,
            'status' => $lu ? web_status($lu) : 'public_domain', 'restricted' => (bool)preg_match('/-nc|-nd/', $lu),
            'thumb' => 'https://archive.org/services/img/' . rawurlencode($d['identifier']), 'page_url' => $page,
            'embed' => 'https://archive.org/embed/' . rawurlencode($d['identifier']), 'media_url' => '',
            'credit' => web_credit($title, $creator, $lic, $page)];
    }
    return $out;
}

function web_search_commons($q, $type) {
    $ft = ['movie' => 'video', 'music' => 'audio', 'image' => 'bitmap'][$type];
    $url = 'https://commons.wikimedia.org/w/api.php?' . http_build_query([
        'action' => 'query', 'generator' => 'search', 'gsrsearch' => "$q filetype:$ft", 'gsrnamespace' => 6, 'gsrlimit' => 24,
        'prop' => 'imageinfo', 'iiprop' => 'url|extmetadata|mime|size', 'iiurlwidth' => 320,
        'iiextmetadatafilter' => 'LicenseShortName|Artist|LicenseUrl|ObjectName|DateTimeOriginal', 'format' => 'json']);
    $j = web_http_json($url, 'Wikimedia Commons');
    $out = [];
    foreach (($j['query']['pages'] ?? []) as $p) {
        $ii = $p['imageinfo'][0] ?? null;
        if (!$ii) continue;
        $em = $ii['extmetadata'] ?? [];
        $lic = trim(strip_tags($em['LicenseShortName']['value'] ?? ''));
        $creator = trim(html_entity_decode(strip_tags($em['Artist']['value'] ?? '')));
        $title = trim(strip_tags($em['ObjectName']['value'] ?? preg_replace('/^File:|\.\w+$/', '', $p['title'])));
        preg_match('/(1[5-9]\d\d|20\d\d)/', strip_tags($em['DateTimeOriginal']['value'] ?? ''), $y);
        $media = preg_replace('/\?.*$/', '', $ii['url']);
        $out[] = ['src' => 'commons', 'source' => 'Wikimedia Commons', 'id' => (string)$p['pageid'], 'type' => $type, 'title' => $title,
            'creator' => mb_substr($creator, 0, 120), 'year' => $y[1] ?? '', 'license' => $lic ?: 'see page',
            'license_url' => $em['LicenseUrl']['value'] ?? '', 'status' => web_status($lic), 'restricted' => (bool)preg_match('/NC|ND/', $lic),
            'thumb' => $ii['thumburl'] ?? '', 'page_url' => $ii['descriptionurl'] ?? '', 'embed' => '', 'media_url' => $media,
            'mime' => $ii['mime'] ?? '', 'size' => $ii['size'] ?? 0,
            'credit' => web_credit($title, $creator, $lic, $ii['descriptionurl'] ?? '')];
    }
    return $out;
}

function web_search_openverse($q, $type) {
    if ($type === 'movie') return [];
    $kind = $type === 'music' ? 'audio' : 'images';
    $j = web_http_json("https://api.openverse.org/v1/$kind/?" . http_build_query(['q' => $q, 'page_size' => 20]), 'Openverse');
    $out = [];
    foreach (($j['results'] ?? []) as $r) {
        $lic = strtoupper($r['license'] ?? '');
        $name = in_array($lic, ['CC0', 'PDM'], true) ? ($lic === 'CC0' ? 'CC0 1.0' : 'Public Domain Mark') : 'CC ' . $lic . ' ' . ($r['license_version'] ?? '');
        $out[] = ['src' => 'openverse', 'source' => 'Openverse · ' . ($r['source'] ?? ''), 'id' => $r['id'], 'type' => $type,
            'title' => $r['title'] ?: $r['id'], 'creator' => $r['creator'] ?? '', 'year' => '', 'license' => trim($name),
            'license_url' => $r['license_url'] ?? '', 'status' => web_status($lic), 'restricted' => (bool)preg_match('/NC|ND/', $lic),
            'thumb' => $r['thumbnail'] ?? ($type === 'image' ? $r['url'] : ''), 'page_url' => $r['foreign_landing_url'] ?? '',
            'embed' => '', 'media_url' => $r['url'] ?? '', 'size' => $r['filesize'] ?? 0,
            'credit' => $r['attribution'] ?? web_credit($r['title'] ?? '', $r['creator'] ?? '', $name, $r['foreign_landing_url'] ?? '')];
    }
    return $out;
}

function web_search($q, $type, $sources) {
    $q = trim(preg_replace('/[^\p{L}\p{N}\s\'".:-]/u', ' ', $q));
    if ($q === '') return ['results' => [], 'errors' => []];
    $all = [];
    $errors = [];
    $fns = ['ia' => 'web_search_ia', 'commons' => 'web_search_commons', 'openverse' => 'web_search_openverse'];
    foreach ($sources as $s) {
        if (!isset($fns[$s])) continue;
        try { $all = array_merge($all, $fns[$s]($q, $type)); }
        catch (Throwable $e) { $errors[] = "$s: " . $e->getMessage(); }
    }
    return ['results' => $all, 'errors' => $errors];
}

/* ---------- sidecars for the offline players ---------- */
function web_sidecars($m) {
    $path = mc_abs($m['path']);
    $dir = dirname($path);
    $base = pathinfo($path, PATHINFO_FILENAME);
    $subs = [];
    $lyrics = null;
    foreach ((glob($dir . '/*') ?: []) as $f) {
        $name = basename($f);
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (stripos($name, $base) !== 0) continue;
        if (in_array($ext, ['srt', 'vtt'], true)) {
            $mid = trim(substr(pathinfo($name, PATHINFO_FILENAME), strlen($base)), '._- ');
            $subs[] = ['i' => count($subs), 'name' => $name, 'lang' => $mid ?: 'default', 'file' => $f];
        } elseif (in_array($ext, ['lrc', 'txt'], true) && $lyrics === null && $m['type'] === 'music') {
            $lyrics = ['type' => $ext, 'text' => mb_substr((string)file_get_contents($f), 0, 60000)];
        }
    }
    return ['subs' => $subs, 'lyrics' => $lyrics];
}

function web_srt_to_vtt($text) {
    $text = preg_replace('/^\xEF\xBB\xBF/', '', str_replace("\r", '', $text));
    if (strpos($text, 'WEBVTT') === 0) return $text;
    $text = preg_replace('/(\d\d:\d\d:\d\d),(\d\d\d)/', '$1.$2', $text);
    $text = preg_replace('/^\d+\n(?=\d\d:\d\d)/m', '', $text);
    return "WEBVTT\n\n" . $text;
}
