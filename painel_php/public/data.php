<?php

declare(strict_types=1);
require dirname(__DIR__) . '/db.php';
initialiseDatabase();
header('Cache-Control: no-store');
if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    $data = publicData();
    $revision = hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    echo json_encode(['revision' => $revision, 'data' => ($_GET['revision'] ?? '') === $revision ? null : $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
header('Content-Type: application/javascript; charset=utf-8');
echo 'window.COPA_DATA = ' . json_encode(publicData(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';';
