<?php
// Kept for compatibility: starts a background scan and returns to the app.
require __DIR__ . '/lib.php';
$job = mc_job();
if (empty($job['running']) && mc_python_ok()) mc_run_python(['scan']);
header('Location: index.php#folders');
exit;
