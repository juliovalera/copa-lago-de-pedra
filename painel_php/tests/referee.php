<?php

declare(strict_types=1);

putenv('COPA_NOTIFY_EMAIL=organizer@example.invalid');
require dirname(__DIR__) . '/db.php';
function smtpSend(string $recipient, string $subject, string $text): void {
    if (db()->inTransaction()) throw new LogicException('Envio antes do commit.');
    if (($GLOBALS['testSmtpFailure'] ?? false) || ($GLOBALS['testFailRecipient'] ?? '') === $recipient) throw new RuntimeException('Falha SMTP simulada.');
    $GLOBALS['testEmails'][] = compact('recipient', 'subject', 'text');
}

// Banco exclusivo em memória: nunca conecta ao banco real.
$databaseConnection = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$databaseConnection->exec('PRAGMA foreign_keys = ON');
initialiseDatabase();
config();
$pdo = db();
$pdo->exec("INSERT INTO players(id, name) VALUES (1, 'Teste A'), (2, 'Teste B')");
$insertGame = $pdo->prepare('INSERT INTO games(id,round_number,game_number,turn_number,player_a_id,player_b_id) VALUES (?,1,?,1,1,2)');
foreach ([88, 99, 100, 101] as $id) $insertGame->execute([$id, $id]);
$insertLink = $pdo->prepare('INSERT INTO referee_links(id,game_id,token,generated_on) VALUES (?,?,?,?)');
$insertLink->execute([7, 88, 'teste-alvo', date('Y-m-d')]);
$insertLink->execute([88, 99, 'teste-outro', date('Y-m-d')]);
$insertLink->execute([9, 100, 'teste-falha', date('Y-m-d')]);
$insertLink->execute([10, 101, 'teste-expirado', date('Y-m-d', strtotime('-1 day'))]);

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo "OK: $message\n";
}

function rejected(string $token, int $a = 9, int $b = 9): bool
{
    try { saveRefereeResult($token, $a, $b); return false; }
    catch (RuntimeException | InvalidArgumentException $exception) { return true; }
}

$link = validRefereeLink('teste-alvo');
check($link['referee_link_id'] === 7 && $link['game_id'] === 88, 'IDs de link e jogo separados');
saveRefereeResult('teste-alvo', 2, 1);
$game = gameById(88);
check($game['score_a'] === 2 && $game['score_b'] === 1 && $game['played_at'] === date('Y-m-d'), 'placar salvo no jogo correto');
check(validRefereeLink('teste-alvo')['used_at'] !== null, 'link correto consumido');
check(validRefereeLink('teste-outro')['used_at'] === null && gameById(99)['score_a'] === null, 'outro link e outro jogo preservados');
check(rejected('teste-alvo'), 'segundo envio recusado');
check(gameById(88)['score_a'] === 2 && gameById(88)['score_b'] === 1, 'reenvio não altera placar');
check(rejected('teste-expirado') && gameById(101)['score_a'] === null, 'link expirado recusado');
check(rejected('inexistente'), 'link inexistente recusado');
check(rejected('teste-outro', -1, 0) && validRefereeLink('teste-outro')['used_at'] === null, 'placar negativo não consome link');
$pdo->exec("CREATE TRIGGER fail_game BEFORE UPDATE ON games WHEN OLD.id = 100 BEGIN SELECT RAISE(ABORT, 'falha simulada'); END");
check(rejected('teste-falha'), 'falha de gravação detectada');
check(validRefereeLink('teste-falha')['used_at'] === null && gameById(100)['score_a'] === null, 'falha reverte consumo e placar');
$pdo->exec('DROP TRIGGER fail_game');
saveRefereeResult('teste-falha', 0, 0);
check(gameById(100)['score_a'] === 0 && validRefereeLink('teste-falha')['used_at'] !== null, 'envio permitido após falha e empate zero a zero aceito');
$data = publicData();
check($data['playedGames'] === 2 && $data['totals']['goalsFor'] === 3, 'classificação recalculada');

// Link gerado antes de o administrador salvar o resultado não pode sobrescrevê-lo.
$pdo->exec("UPDATE games SET score_a = 0, score_b = 0, played_at = '2026-09-23' WHERE id = 99");
check(rejected('teste-outro'), 'QR antigo bloqueado após resultado salvo pelo painel');
check(gameById(99)['score_a'] === 0 && gameById(99)['score_b'] === 0, 'zero a zero salvo preservado');
check(validRefereeLink('teste-outro')['used_at'] === null, 'tentativa bloqueada não consome outro link');
$blocked = false;
try { dailyRefereeLink(99); } catch (RuntimeException $exception) { $blocked = true; }
check($blocked, 'geração de súmula bloqueada para jogo já salvo');
$pending = dailyRefereeLink(101);
check($pending['game_id'] === 101 && $pending['used_at'] === null, 'geração continua disponível para jogo sem resultado');

$tomorrow = date('Y-m-d', strtotime('+1 day'));
$future = dailyRefereeLink(101, $tomorrow);
check($future['generated_on'] === $tomorrow, 'súmula antecipada guarda a data escolhida');
check($future['token'] !== $pending['token'], 'datas diferentes possuem QR distintos');
check(dailyRefereeLink(101, $tomorrow)['token'] === $future['token'], 'reimpressão mantém o QR da mesma data');
check(validRefereeLink($future['token']) === null && rejected($future['token']), 'QR futuro não aceita resultado antes do dia do jogo');
check(gameById(101)['played_at'] === null && gameById(101)['score_a'] === null, 'impressão antecipada não lança data ou resultado no jogo');
$before = (int) $pdo->query('SELECT COUNT(*) FROM referee_links')->fetchColumn();
foreach (['', '2026-02-30', 'amanhã', date('Y-m-d', strtotime('-1 day'))] as $invalid) {
    $blocked = false;
    try { dailyRefereeLink(101, $invalid); } catch (InvalidArgumentException $exception) { $blocked = true; }
    check($blocked, 'data inválida ou passada recusada: ' . $invalid);
}
check((int) $pdo->query('SELECT COUNT(*) FROM referee_links')->fetchColumn() === $before, 'datas recusadas não criam links');
saveRefereeResult($pending['token'], 1, 0);
check(gameById(101)['played_at'] === $pending['generated_on'], 'resultado grava a mesma data indicada na súmula');
check(rejected($future['token']), 'QR de outra data não sobrescreve resultado salvo');
