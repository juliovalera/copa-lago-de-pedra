<?php
declare(strict_types=1);
// Somente linha de comando, banco vazio e confirmação explícita.
if (PHP_SAPI !== 'cli' || !in_array('--demo', $argv ?? [], true)) {
    http_response_code(403);
    exit("Use php painel_php/seed-demo.php --demo em uma instalação de demonstração.\n");
}
require __DIR__ . '/db.php';
initialiseDatabase();
$pdo = db();
$pdo->beginTransaction();
try {
    foreach (['players', 'games', 'users', 'audit_log'] as $table) {
        if ((int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() !== 0) {
            throw new RuntimeException('Demonstração permitida somente em banco vazio. Nenhum registro foi alterado.');
        }
    }
    $player = $pdo->prepare('INSERT INTO players(id,name) VALUES (?,?)');
    for ($id = 1; $id <= 26; $id++) $player->execute([$id, sprintf('Botonista Demo %02d', $id)]);
    $game = $pdo->prepare('INSERT INTO games(round_number,game_number,turn_number,player_a_id,player_b_id) VALUES (?,?,?,?,?)');
    $rotation = range(1, 26);
    for ($round = 1; $round <= 25; $round++) {
        for ($i = 0; $i < 13; $i++) {
            $a = $rotation[$i]; $b = $rotation[25 - $i];
            $game->execute([$round, $i + 1, 1, $a, $b]);
            $game->execute([$round + 25, $i + 1, 2, $b, $a]);
        }
        $last = array_pop($rotation);
        array_splice($rotation, 1, 0, [$last]);
    }
    $pdo->commit();
    echo "Demonstração criada: 26 nomes fictícios e 650 jogos sem resultados.\n";
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
