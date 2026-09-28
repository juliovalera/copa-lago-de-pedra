<?php

declare(strict_types=1);

session_start();
require dirname(__DIR__) . '/db.php';
initialiseDatabase();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function reply(bool $ok, string $message, array $extra = []): never
{
    http_response_code($ok ? 200 : (int) ($extra['status'] ?? 403));
    unset($extra['status']);
    echo json_encode(['ok' => $ok, 'message' => $message] + $extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') reply(false, 'Solicitação inválida.');
$gameId = filter_input(INPUT_POST, 'game_id', FILTER_VALIDATE_INT);
$login = trim((string) ($_POST['login'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
if (!$gameId || $password === '') reply(false, 'Informe login/e-mail e senha.');
$game = gameById($gameId);
if (!$game) reply(false, 'Jogo não encontrado.');

try {
    $attempt = authenticateWithLimit($login, $password, 'Súmula');
} catch (Throwable $exception) {
    reply(false, 'Não foi possível verificar o acesso agora. Tente novamente em instantes.', ['status'=>503]);
}
$user = $attempt['user'];
if (!$user) {
    if ($attempt['retry_after']) header('Retry-After: ' . $attempt['retry_after']);
    reply(false, $attempt['message'], ['status'=>$attempt['retry_after'] ? 429 : 403]);
}

$playerId = $user['player_id'] === null ? null : (int) $user['player_id'];
if (!gameIsAccessible($gameId, $playerId)) reply(false, 'Sua conta não tem permissão para gerar a súmula deste jogo.');
if ($game['score_a'] !== null || $game['score_b'] !== null) reply(false, 'Este jogo já tem resultado salvo. Não é possível gerar uma súmula.');

session_regenerate_id(true);
if ($login === '') {
    $_SESSION['legacy_admin'] = true;
    unset($_SESSION['user_id']);
} else {
    $_SESSION['user_id'] = (int) $user['id'];
    unset($_SESSION['legacy_admin']);
}
reply(true, 'Acesso autorizado.', ['url' => 'sumula.php?game=' . $gameId]);
