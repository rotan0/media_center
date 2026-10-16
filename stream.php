<?php
/**
 * Streams library files (which live outside the web folder) with HTTP Range support.
 * Only files that are in the library can be served.
 * Ranges are served in chunks so the single-threaded "php -S" server stays responsive.
 */
require __DIR__ . '/lib.php';

$id = preg_replace('/[^a-f0-9]/', '', $_GET['id'] ?? '');
$path = null;
foreach (mc_read_json(MC_MEDIA, []) as $m) {
    if (($m['id'] ?? '') === $id) { $path = $m['path']; break; }
}
$path = $path ? mc_abs($path) : null;
if (!$path || !is_file($path)) { http_response_code(404); exit('Not found'); }
if (isset($_GET['sub'])) {   // subtitles next to the video, served as WebVTT
    require __DIR__ . '/web.php';
    $sc = web_sidecars(['path' => mc_store($path), 'type' => 'movie']);
    $s = $sc['subs'][(int)$_GET['sub']] ?? null;
    if (!$s) { http_response_code(404); exit; }
    header('Content-Type: text/vtt; charset=utf-8');
    $raw = file_get_contents($s['file']);
    if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    echo web_srt_to_vtt($raw);
    exit;
}

$mime = [
    'mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'mov' => 'video/mp4', 'webm' => 'video/webm', 'mkv' => 'video/webm',
    'avi' => 'video/x-msvideo', 'wmv' => 'video/x-ms-wmv', 'mpg' => 'video/mpeg', 'mpeg' => 'video/mpeg', 'ts' => 'video/mp2t',
    'flv' => 'video/x-flv', '3gp' => 'video/3gpp',
    'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'flac' => 'audio/flac', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac',
    'ogg' => 'audio/ogg', 'oga' => 'audio/ogg', 'opus' => 'audio/ogg', 'wma' => 'audio/x-ms-wma', 'aiff' => 'audio/aiff',
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
    'bmp' => 'image/bmp', 'tif' => 'image/tiff', 'tiff' => 'image/tiff', 'heic' => 'image/heic', 'svg' => 'image/svg+xml',
];
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$type = $mime[$ext] ?? 'application/octet-stream';

$size = filesize($path);
$start = 0;
$end = $size - 1;
$chunk = 4 * 1024 * 1024;

@set_time_limit(0);
while (ob_get_level()) ob_end_clean();
header('Content-Type: ' . $type);
header('Accept-Ranges: bytes');
header('Cache-Control: private, max-age=86400');
if (isset($_GET['download'])) header('Content-Disposition: attachment; filename="' . basename($path) . '"');

if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $r)) {
    if ($r[1] === '' && $r[2] !== '') { $start = max(0, $size - (int)$r[2]); }
    else {
        $start = (int)$r[1];
        $end = $r[2] !== '' ? min((int)$r[2], $size - 1) : min($start + $chunk - 1, $size - 1);
    }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header("Content-Range: bytes */$size");
        exit;
    }
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
}
$len = $end - $start + 1;
header("Content-Length: $len");
if ($_SERVER['REQUEST_METHOD'] === 'HEAD') exit;

$fp = fopen($path, 'rb');
fseek($fp, $start);
$left = $len;
while ($left > 0 && !feof($fp) && !connection_aborted()) {
    $buf = fread($fp, min(262144, $left));
    echo $buf;
    flush();
    $left -= strlen($buf);
}
fclose($fp);
