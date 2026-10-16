<?php
$pdo = new PDO(
    'mysql:host=localhost;dbname=media_center;charset=utf8mb4',
    'root',
    '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
?>
