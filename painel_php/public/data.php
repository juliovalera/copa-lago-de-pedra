<?php

declare(strict_types=1);
require dirname(__DIR__) . '/db.php';
initialiseDatabase();
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-store');
echo 'window.COPA_DATA = ' . json_encode(publicData(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';';
