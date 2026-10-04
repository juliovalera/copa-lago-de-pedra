<?php
declare(strict_types=1);
session_start();
require dirname(__DIR__).'/db.php';
require dirname(__DIR__).'/confirmations.php';
initialiseDatabase(); requirePanelAccess();
if (!hasFullAccess()) { http_response_code(403); exit('Acesso exclusivo da administração.'); }
initialiseConfirmations();
header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer'); header('X-Robots-Tag: noindex, nofollow');
function h(mixed $value): string { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
function sheetTime(string $value): string { return date('d/m/Y H:i',strtotime($value)); }
try {
    $row=confirmationGet(is_string($_GET['id']??null)?$_GET['id']:'');
    if ($row['status']!=='final' || !$row['signature_a'] || !$row['signature_b'] || !$row['approved_at']) throw new InvalidArgumentException('A súmula precisa das duas assinaturas e do aval antes da impressão.');
} catch (InvalidArgumentException $e) { http_response_code(404); exit(h($e->getMessage())); }
$s=json_decode($row['snapshot'],true,512,JSON_THROW_ON_ERROR);
$current=confirmationCurrent($row);
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Súmula assinada · Rodada <?= h($s['round']) ?> · Copa Lago de Pedra</title><link rel="stylesheet" href="sumula.css?v=<?= filemtime(__DIR__.'/sumula.css') ?>"><link rel="stylesheet" href="sumula-confirmada.css?v=<?= copaVersion() ?>"><script src="confirmations.js?v=<?= copaVersion() ?>" defer></script></head>
<body><div class="print-actions"><a href="confirmacoes.php?id=<?= h($row['id']) ?>">← Voltar</a><button type="button" data-print>Imprimir / salvar PDF</button></div>
<main class="sheet confirmed-sheet"><header class="brand"><img class="copa-logo" src="asset.php?file=lago_de_pedra.png" alt="I Copa Lago de Pedra"><div class="brand-title"><h1>I COPA LAGO DE PEDRA<br>DE FUTEBOL DE BOTÃO</h1></div><img class="liga-logo" src="asset.php?file=liga_mogiana.png" alt="Liga Mogiana de Futebol de Botão"></header>
<h2 class="official-title">SÚMULA OFICIAL DE PARTIDA - REGRA 12 TOQUES</h2>
<?php if (!$current): ?><p class="historical-note">DOCUMENTO HISTÓRICO — o resultado ou a data foi alterado após esta confirmação. As assinaturas abaixo correspondem aos dados deste documento.</p><?php endif ?>
<section class="info-grid"><div><b>RODADA</b><strong><?= h($s['round']) ?></strong></div><div class="blank"></div><div><b>TURNO</b><strong><?= (int)$s['turn']===1?'1º TURNO':'RETURNO' ?></strong></div><div class="blank"></div><div><b>DATA</b><strong><?= h(date('d/m/Y',strtotime($s['date']))) ?></strong></div><div class="blank"></div><div><b>HORÁRIO</b><strong>__________</strong></div><div class="blank"></div><div><b>LOCAL</b><strong class="venue-blank" aria-label="Local não informado"></strong></div><div><b>MESA Nº</b><strong>________</strong></div><div><b>ÁRBITRO</b><strong class="no-referee">Partida sem árbitro</strong></div></section>
<section class="match-box"><div class="match-band"></div><div class="team"><b>JOGADOR A</b><strong><?= h($s['a']) ?></strong></div><div class="score"><b>PLACAR</b><div class="score-fields"><span><?= h($s['score_a']) ?></span><span class="score-times">×</span><span><?= h($s['score_b']) ?></span></div></div><div class="team"><b>JOGADOR B</b><strong><?= h($s['b']) ?></strong></div></section>
<table class="record"><thead><tr><th>REGISTRO DO JOGO</th><th>JOGADOR A</th><th>JOGADOR B</th></tr></thead><tbody><tr><th>1º TEMPO - GOLS</th><td></td><td></td></tr><tr><th>2º TEMPO - GOLS</th><td></td><td></td></tr><tr><th>PLACAR FINAL</th><td><?= h($s['score_a']) ?></td><td><?= h($s['score_b']) ?></td></tr></tbody></table>
<section class="notes"><h3>OCORRÊNCIAS / ADVERTÊNCIAS / OBSERVAÇÕES</h3><p>Partida realizada sem árbitro, confirmada posteriormente pelos dois jogadores. Aval registrado pela organização.</p><div></div><div></div></section>
<footer class="signatures confirmed-signatures"><div><b>ASSINATURA - JOGADOR A</b><?= digitalSignatureSvg(json_decode($row['signature_a'],true,512,JSON_THROW_ON_ERROR)) ?><strong><?= h($s['a']) ?></strong><small><?= h(sheetTime($row['signed_a'])) ?></small></div><div><b>AVAL DA ORGANIZAÇÃO</b><p><?= h($row['approved_by']) ?></p><small><?= h(sheetTime($row['approved_at'])) ?></small></div><div><b>ASSINATURA - JOGADOR B</b><?= digitalSignatureSvg(json_decode($row['signature_b'],true,512,JSON_THROW_ON_ERROR)) ?><strong><?= h($s['b']) ?></strong><small><?= h(sheetTime($row['signed_b'])) ?></small></div></footer>
<p class="document-reference">Documento <?= h($row['id']) ?> · Jogo <?= h($row['game_id']) ?></p>
</main></body></html>
