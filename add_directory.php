<?php
// Kept for compatibility with old forms: add a folder by POSTing "directory".
require __DIR__ . '/lib.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dir = mc_norm_path($_POST['directory'] ?? '');
    if ($dir !== '' && is_dir($dir)) {
        $dirs = mc_dirs();
        if (!in_array($dir, $dirs, true)) { $dirs[] = $dir; mc_write_json(MC_DIRS, $dirs); }
        mc_index_directories([$dir]);
        if (empty(mc_job()['running']) && mc_python_ok()) mc_run_python(['scan', '--dir', $dir]);
    }
}
header('Location: index.php');
exit;
