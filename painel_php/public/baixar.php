<?php
declare(strict_types=1);
require dirname(__DIR__).'/version.php';
require dirname(__DIR__).'/export_ui.php';
header('Content-Type: text/html; charset=utf-8');
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Baixar tabela · Copa Lago de Pedra</title><link rel="stylesheet" href="asset.php?file=styles.css"><?= exportAssets() ?></head><body><main class="export-page"><a href="index.php">← Voltar à área pública</a><h1>Baixar tabela</h1><?= exportForm() ?></main><?= copaHelpFooter() ?></body></html>
