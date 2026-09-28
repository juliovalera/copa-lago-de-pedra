<?php

declare(strict_types=1);

$allowed = [
    'styles.css' => 'text/css; charset=utf-8',
    'app.js' => 'application/javascript; charset=utf-8',
    'icon.svg' => 'image/svg+xml',
    'lago_de_pedra.png' => 'image/png',
    'liga_mogiana.png' => 'image/png',
    'lago_de_pedra_256.png' => 'image/png',
    'liga_mogiana_256.png' => 'image/png',
];
$name = (string) ($_GET['file'] ?? '');
if (!isset($allowed[$name])) {
    http_response_code(404);
    exit('Arquivo não encontrado.');
}

$file = dirname(__DIR__, 2) . '/site/' . $name;
if (!is_file($file)) {
    http_response_code(404);
    exit('Arquivo não encontrado.');
}
header('Content-Type: ' . $allowed[$name]);
header('Cache-Control: public, max-age=31536000, immutable');
readfile($file);
