<?php
require __DIR__ . '/lib.php';
$lang = mc_current_lang();
$langs = mc_languages();
$L = mc_strings($lang);
$dir = $langs[$lang]['dir'] ?? 'ltr';

// Accent colour from theme.json (falls back to blue when the theme is grey)
$theme = mc_read_json(MC_DATA . '/theme.json', []);
$accent = '#4f8cff';
if (!empty($theme['accent_color']) && preg_match('/(\d+)\D+(\d+)\D+(\d+)/', $theme['accent_color'], $m)) {
    if (max($m[1], $m[2], $m[3]) - min($m[1], $m[2], $m[3]) > 40) $accent = $theme['accent_color'];
}
$logo = is_file(MC_ROOT . '/assets/img/logo.gif') ? 'assets/img/logo.gif' : '';
$v = @filemtime(MC_ROOT . '/assets/app.js') . @filemtime(MC_ROOT . '/assets/app.css');
?><!DOCTYPE html>
<html lang="<?= e($lang) ?>" dir="<?= e($dir) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(t('title', 'Local Media Center')) ?></title>
<link rel="icon" href="assets/img/hundo.ico">
<link rel="stylesheet" href="assets/app.css?v=<?= $v ?>">
<style>:root{--accent:<?= e($accent) ?>}</style>
</head>
<body>
<div class="app">
  <aside class="sidebar">
    <div class="brand">
      <?php if ($logo): ?><img src="<?= e($logo) ?>" alt=""><?php endif; ?>
      <span><?= e(t('title', 'Local Media Center')) ?></span>
    </div>
    <?php if (is_file(MC_ROOT . '/assets/img/hundo_sm.jpg')): ?>
      <a class="side-art" href="#share" title="<?= e(t('nav_share', 'Free to share')) ?>"><img src="assets/img/hundo_sm.jpg" alt=""></a>
    <?php endif; ?>
    <nav class="nav">
      <a href="#home" data-view="home"><span class="ico">⌂</span><span><?= e(t('nav_home', 'Home')) ?></span></a>
      <a href="#movies" data-view="movies"><span class="ico">🎬</span><span><?= e(t('nav_movies', 'Movies')) ?></span><em data-count="movie"></em></a>
      <a href="#music" data-view="music"><span class="ico">♫</span><span><?= e(t('nav_music', 'Music')) ?></span><em data-count="music"></em></a>
      <a href="#images" data-view="images"><span class="ico">▣</span><span><?= e(t('nav_images', 'Images')) ?></span><em data-count="image"></em></a>
      <a href="#folders" data-view="folders"><span class="ico">📁</span><span><?= e(t('nav_folders', 'Folders')) ?></span></a>
      <a href="#discover" data-view="discover"><span class="ico">🌍</span><span><?= e(t('nav_discover', 'Find free media')) ?></span></a>
      <a href="#share" data-view="share"><span class="ico">🤝</span><span><?= e(t('nav_share', 'Free to share')) ?></span></a>
      <a href="#maint" data-view="maint"><span class="ico">🛠</span><span><?= e(t('nav_maint', 'Maintenance')) ?></span></a>
      <a href="#" id="consoleToggle"><span class="ico">&gt;_</span><span><?= e(t('nav_console', 'Console')) ?></span></a>
    </nav>
    <div class="side-foot">
      <label for="langSelect"><?= e(t('language', 'Language')) ?></label>
      <select id="langSelect">
        <?php foreach ($langs as $code => $info): ?>
          <option value="<?= e($code) ?>" <?= $code === $lang ? 'selected' : '' ?>><?= e($info['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </aside>

  <main class="main">
    <header class="topbar">
      <input type="search" id="search" placeholder="<?= e(t('search_placeholder', 'Search movies, music, images…')) ?>" autocomplete="off">
      <select id="sort" aria-label="<?= e(t('sort', 'Sort')) ?>">
        <option value="title"><?= e(t('sort_title', 'Title A–Z')) ?></option>
        <option value="added"><?= e(t('sort_added', 'Recently added')) ?></option>
        <option value="year"><?= e(t('sort_year', 'Year')) ?></option>
        <option value="rating"><?= e(t('sort_rating', 'Rating')) ?></option>
      </select>
      <select id="rightsFilter" aria-label="<?= e(t('r_filter', 'Rights')) ?>">
        <option value=""><?= e(t('r_all', 'All rights')) ?></option>
        <option value="free"><?= e(t('r_free', 'Free to share')) ?></option>
        <option value="public_domain"><?= e(t('r_pd', 'Public domain')) ?></option>
        <option value="attribution"><?= e(t('r_cc', 'Free with credit')) ?></option>
        <option value="personal"><?= e(t('r_me', 'Personal')) ?></option>
        <option value="proprietary"><?= e(t('r_pr', 'Proprietary')) ?></option>
        <option value="unknown"><?= e(t('r_un', 'Unknown')) ?></option>
      </select>
      <div id="jobPill" class="job-pill" hidden><span class="spin"></span><span id="jobText"></span></div>
      <button class="btn mode-btn" id="modeBtn" title="<?= e(t('mode_offline_tip', 'Offline: nothing leaves this computer. Click to go online.')) ?>">🔒 <?= e(t('mode_offline', 'Offline')) ?></button>
      <button class="btn primary" id="addFolderBtn">＋ <?= e(t('add_folder', 'Add folder')) ?></button>
    </header>
    <section id="view" class="view"></section>
  </main>
</div>

<!-- Folder browser -->
<div class="modal" id="browser" hidden>
  <div class="dialog">
    <div class="dlg-head">
      <h2><?= e(t('browse_title', 'Choose a media folder')) ?></h2>
      <button class="icon-btn" data-close>✕</button>
    </div>
    <div class="crumbs">
      <button class="btn" id="brUp">↑ <?= e(t('browse_up', 'Up')) ?></button>
      <input id="brPath" spellcheck="false" placeholder="<?= e(t('browse_path_ph', 'Type or paste a path, e.g. D:/Movies')) ?>">
      <button class="btn" id="brGo"><?= e(t('browse_go', 'Go')) ?></button>
    </div>
    <div class="br-info" id="brInfo"></div>
    <ul class="br-list" id="brList"></ul>
    <div class="dlg-foot">
      <span class="muted"><?= e(t('browse_hint', 'Subfolders are included automatically.')) ?></span>
      <button class="btn primary" id="brAdd"><?= e(t('browse_add', 'Add this folder')) ?></button>
    </div>
  </div>
</div>

<!-- Detail -->
<div class="modal" id="detail" hidden><div class="dialog wide" id="detailBody"></div></div>

<!-- Video player -->
<div class="player" id="videoPlayer" hidden>
  <button class="icon-btn close" data-close>✕</button>
  <div class="vp-title" id="vpTitle"></div>
  <video id="video" controls playsinline></video>
  <div class="vp-error" id="vpError" hidden>
    <p><?= e(t('format_unsupported', 'Your browser cannot play this file format.')) ?></p>
    <a class="btn" id="vpDownload" href="#"><?= e(t('download', 'Download')) ?></a>
  </div>
</div>

<!-- Image viewer -->
<div class="player" id="imgViewer" hidden>
  <button class="icon-btn close" data-close>✕</button>
  <button class="icon-btn nav-l" id="imgPrev">‹</button>
  <img id="imgFull" alt="">
  <button class="icon-btn nav-r" id="imgNext">›</button>
  <div class="iv-bar">
    <span id="imgCap"></span>
    <button class="btn" id="imgShow">▶ <?= e(t('slideshow', 'Slideshow')) ?></button>
    <a class="btn" id="imgDl" href="#"><?= e(t('download', 'Download')) ?></a>
  </div>
</div>

<!-- Music bar -->
<div class="musicbar" id="musicbar" hidden>
  <img id="mbArt" alt="">
  <div class="mb-meta"><b id="mbTitle"></b><span id="mbArtist"></span></div>
  <div class="mb-ctrl">
    <button class="icon-btn" id="mbShuffle" title="<?= e(t('shuffle', 'Shuffle')) ?>">⤮</button>
    <button class="icon-btn" id="mbPrev" title="<?= e(t('previous', 'Previous')) ?>">⏮</button>
    <button class="icon-btn big" id="mbPlay" title="<?= e(t('play', 'Play')) ?>">▶</button>
    <button class="icon-btn" id="mbNext" title="<?= e(t('next', 'Next')) ?>">⏭</button>
    <span class="mb-time" id="mbCur">0:00</span>
    <input type="range" id="mbSeek" min="0" max="1000" value="0">
    <span class="mb-time" id="mbDur">0:00</span>
  </div>
  <input type="range" id="mbVol" min="0" max="100" value="90" title="<?= e(t('volume', 'Volume')) ?>">
  <audio id="audio"></audio>
</div>

<!-- Console -->
<div class="console" id="console" hidden>
  <div class="con-head">
    <b>&gt;_ <?= e(t('nav_console', 'Console')) ?></b>
    <span class="muted"><?= e(t('console_hint', 'Type "help". Online lookups only happen when you run them here.')) ?></span>
    <button class="icon-btn" id="conClose">✕</button>
  </div>
  <pre id="conOut"></pre>
  <div class="con-in"><span>&gt;</span><input id="conInput" spellcheck="false" autocomplete="off"></div>
</div>

<div class="toast" id="toast" hidden></div>

<!-- Status bar: online activity + disk usage -->
<a class="netbar" id="netbar" href="#maint" title="<?= e(t('nav_maint', 'Maintenance')) ?>">
  <span class="dot"></span><span id="netText"><?= e(t('net_offline', 'Offline — everything is served from media_center')) ?></span>
  <span class="nb-right" id="netRight"></span>
</a>

<script>
window.MC = <?= json_encode(['L' => $L, 'lang' => $lang, 'dir' => $dir], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="assets/app.js?v=<?= $v ?>"></script>
<script src="assets/theater.js?v=<?= $v ?>"></script>
<script src="assets/online.js?v=<?= $v ?>"></script>
</body>
</html>
