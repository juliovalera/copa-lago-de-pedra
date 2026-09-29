<?php

declare(strict_types=1);

require dirname(__DIR__) . '/db.php';
initialiseDatabase();
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

function h(string|int|null $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function refereeCsrf(string $token): string { return hash_hmac('sha256', $token, config()['admin_password']); }
function page(string $title, string $body): never { echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#123e32"><title>' . h($title) . '</title><link rel="stylesheet" href="admin.css"></head><body><main class="login-shell"><section class="login">' . $body . '</section></main></body></html>'; exit; }

$token = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
$link = $token === '' ? null : validRefereeLink($token);
if (!$link) {
    page('Link indisponível', '<p class="eyebrow">SÚMULA DIGITAL</p><h1>Link indisponível</h1><p>Este QR Code não existe ou está fora da validade. Ele funciona somente na data do jogo indicada na súmula, mesmo que ela tenha sido impressa antes.</p>');
}
if ($link['used_at'] !== null || $link['score_a'] !== null || $link['score_b'] !== null) {
    page('Resultado já enviado', '<p class="eyebrow">SÚMULA DIGITAL</p><h1>Resultado já enviado</h1><p>Este jogo já tem resultado salvo ou o QR Code já foi utilizado. Caso seja necessário corrigir o placar, procure a organização.</p>');
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(refereeCsrf($token), (string) ($_POST['csrf'] ?? ''))) {
        $error = 'Solicitação inválida. Atualize a página e tente novamente.';
    } else {
        $a = filter_var($_POST['score_a'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $b = filter_var($_POST['score_b'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($a === false || $b === false || $_POST['score_a'] === '' || $_POST['score_b'] === '') {
            $error = 'Informe os dois placares com números iguais ou maiores que zero.';
        } else {
            try {
                saveRefereeResult($token, $a, $b);
                page('Resultado registrado', '<p class="eyebrow">SÚMULA DIGITAL</p><h1>Resultado registrado</h1><p><strong>' . h($link['a']) . ' ' . $a . ' × ' . $b . ' ' . h($link['b']) . '</strong></p><p>A classificação foi recalculada automaticamente. Obrigado.</p>');
            } catch (Throwable $exception) {
                $error = 'Não foi possível salvar o resultado. Tente novamente.';
            }
        }
    }
}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#123e32"><title>Registrar resultado · Copa Lago de Pedra</title><link rel="stylesheet" href="admin.css"></head><body><main class="login-shell"><section class="login" aria-labelledby="title"><p class="eyebrow">SÚMULA DIGITAL · RODADA <?= h($link['round_number']) ?></p><h1 id="title">Registrar resultado</h1><p><strong><?= h($link['a']) ?> × <?= h($link['b']) ?></strong></p><p>Link válido somente hoje: <?= h(date('d/m/Y', strtotime($link['generated_on']))) ?>.</p><p><a class="button button-outline-dark" href="sumula-digital.php?t=<?= rawurlencode($token) ?>">Preencher e assinar no celular</a></p><p>Ou registre somente o placar abaixo, se a súmula foi assinada no papel.</p><?php if ($error): ?><p class="error" role="alert"><?= h($error) ?></p><?php endif ?><form method="post"><input type="hidden" name="t" value="<?= h($token) ?>"><input type="hidden" name="csrf" value="<?= h(refereeCsrf($token)) ?>"><div class="game-form"><label for="score-a">Gols <?= h($link['a']) ?><input id="score-a" name="score_a" type="number" min="0" inputmode="numeric" required autofocus></label><label for="score-b">Gols <?= h($link['b']) ?><input id="score-b" name="score_b" type="number" min="0" inputmode="numeric" required></label><div class="save"><button class="button button-primary" type="submit">Confirmar resultado</button></div></div></form></section></main></body></html>
