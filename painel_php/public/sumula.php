<?php

declare(strict_types=1);

session_start();
require dirname(__DIR__) . '/db.php';
initialiseDatabase();

function h(string|int|null $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
requirePanelAccess();
$limitedPlayerId = hasFullAccess() ? null : (int) currentUser()['player_id'];
$gameId = filter_input(INPUT_GET, 'game', FILTER_VALIDATE_INT);
$game = $gameId ? gameById($gameId) : null;
if (!$game || !gameIsAccessible((int) $game['id'], $limitedPlayerId)) { http_response_code(404); exit('Jogo não encontrado.'); }
if ($game['score_a'] !== null || $game['score_b'] !== null) {
    http_response_code(409);
    exit('Este jogo já tem resultado salvo. Não é possível gerar uma súmula.');
}
$csrf = $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$matchDate = $_SERVER['REQUEST_METHOD'] === 'POST' ? (is_string($_POST['match_date'] ?? null) ? $_POST['match_date'] : '') : date('Y-m-d');
$error = '';
$link = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals($csrf, $_POST['csrf'])) {
        http_response_code(400);
        $error = 'Solicitação inválida. Tente novamente.';
    } else {
        try {
            $link = dailyRefereeLink((int) $game['id'], $matchDate);
        } catch (InvalidArgumentException $exception) {
            http_response_code(422);
            $error = $exception->getMessage();
        }
    }
}
if (!$link): ?>
<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Data do jogo · Gerar súmula</title><link rel="stylesheet" href="admin.css"></head>
<body><main class="login-shell"><section class="login"><h1>Qual é a data do jogo?</h1>
<p><strong><?= h($game['a']) ?> × <?= h($game['b']) ?></strong></p>
<p>Você pode imprimir com antecedência. Escolha o dia em que a partida será disputada.</p>
<p id="date-help">A data aparecerá na súmula e será registrada junto ao placar. O QR Code aceitará um único resultado, somente nesse dia.</p>
<?php if ($error): ?><p class="error" role="alert"><?= h($error) ?></p><?php endif ?>
<form class="user-form" method="post" action="sumula.php?game=<?= h($game['id']) ?>"><input type="hidden" name="csrf" value="<?= h($csrf) ?>">
<label for="match-date">Data do jogo</label><input id="match-date" type="date" name="match_date" min="<?= h(date('Y-m-d')) ?>" value="<?= h($matchDate) ?>" aria-describedby="date-help" required>
<button class="button button-primary" type="submit">Gerar súmula para impressão</button></form>
<p><a href="admin.php">Voltar ao painel</a></p></section></main></body></html>
<?php exit; endif;
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$refereeUrl = $base . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/arbitro.php?t=' . rawurlencode($link['token']);
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
?>
<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Súmula · Rodada <?= h($game['round_number']) ?> · I Copa Lago de Pedra</title><link rel="stylesheet" href="sumula.css?v=<?= filemtime(__DIR__ . '/sumula.css') ?>"></head>
<body><main class="sheet"><header class="brand"><img class="copa-logo" src="asset.php?file=lago_de_pedra.png" alt="I Copa Lago de Pedra"><div class="brand-title"><h1>I COPA LAGO DE PEDRA<br>DE FUTEBOL DE BOTÃO</h1></div><img class="liga-logo" src="asset.php?file=liga_mogiana.png" alt="Liga Mogiana de Futebol de Botão"></header>
<h2 class="official-title">SÚMULA OFICIAL DE PARTIDA - REGRA 12 TOQUES</h2>
<section class="info-grid"><div><b>RODADA</b><strong><?= h($game['round_number']) ?></strong></div><div class="blank"></div><div><b>TURNO</b><strong><?= (int) $game['turn_number'] === 1 ? '1º TURNO' : '2º TURNO' ?></strong></div><div class="blank"></div><div><b>DATA</b><strong><?= h(date('d/m/Y', strtotime($link['generated_on']))) ?></strong></div><div class="blank"></div><div><b>HORÁRIO</b><strong>__________</strong></div><div class="blank"></div><div><b>LOCAL</b><strong class="venue-blank" aria-label="Local a preencher"></strong></div><div><b>MESA Nº</b><strong>________</strong></div><div><b>ÁRBITRO</b><strong>____________________________</strong></div></section>
<section class="match-box"><div class="match-band"></div><div class="team"><b>JOGADOR A</b><strong><?= h($game['a']) ?></strong></div><div class="score"><b>PLACAR</b><div class="score-fields" role="img" aria-label="Placar: gols do jogador A e gols do jogador B"><span class="score-blank" aria-hidden="true"></span><span class="score-times" aria-hidden="true">×</span><span class="score-blank" aria-hidden="true"></span></div></div><div class="team"><b>JOGADOR B</b><strong><?= h($game['b']) ?></strong></div></section>
<table class="record"><thead><tr><th>REGISTRO DO JOGO</th><th>JOGADOR A</th><th>JOGADOR B</th></tr></thead><tbody><tr><th>1º TEMPO - GOLS</th><td></td><td></td></tr><tr><th>2º TEMPO - GOLS</th><td></td><td></td></tr><tr><th>PLACAR FINAL</th><td></td><td></td></tr></tbody></table>
<section class="notes"><h3>OCORRÊNCIAS / ADVERTÊNCIAS / OBSERVAÇÕES</h3><div></div><div></div><div></div></section>
<footer class="signatures"><div><b>ASSINATURA - JOGADOR A</b></div><div><b>ASSINATURA - ÁRBITRO</b></div><div><b>ASSINATURA - JOGADOR B</b></div></footer>
<aside class="qr"><div id="sumula-qr" data-url="<?= h($refereeUrl) ?>" role="img" aria-label="QR Code para o árbitro registrar o resultado"></div><b>REGISTRAR RESULTADO</b><small>Válido somente em <?= h(date('d/m/Y', strtotime($link['generated_on']))) ?></small></aside><p class="link-note">Link do árbitro: <?= h($refereeUrl) ?></p></main><div class="print-actions"><a href="sumula.php?game=<?= h($game['id']) ?>">Alterar data</a><button id="print-sumula" disabled>Imprimir súmula</button><a href="admin.php">Voltar ao painel</a></div><p id="sumula-qr-status" class="qr-status" role="status">Preparando QR Code. Se esta mensagem continuar, verifique se o JavaScript está habilitado e recarregue a página.</p><script src="vendor/qrcode-generator/qrcode.js?v=<?= filemtime(__DIR__.'/vendor/qrcode-generator/qrcode.js') ?>" defer></script><script src="sumula-qr.js?v=<?= filemtime(__DIR__.'/sumula-qr.js') ?>" defer></script></body></html>
