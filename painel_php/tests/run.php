<?php
declare(strict_types=1);
// CLI only. Never load the installation config, database or SMTP credentials.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Teste disponivel somente pela linha de comando.'); }
$testDirectory = sys_get_temp_dir() . '/copa-isolated-' . bin2hex(random_bytes(12));
if (!mkdir($testDirectory, 0700)) throw new RuntimeException('Cannot create isolated test directory.');
$testFiles = ['confirmations.php','complaints.php','db.php','audit.php','version.php','notifications.php','login_security.php','digital_sheet.php'];
$testPaths = [];
try {
    foreach ($testFiles as $file) {
        $target = $testDirectory . '/' . $file;
        if (!copy(dirname(__DIR__) . '/' . $file, $target)) throw new RuntimeException('Cannot copy test code.');
        $testPaths[] = $target;
    }
    $testPaths[] = $testDirectory . '/config.php';
    $testConfig = "<?php return ['database'=>__DIR__.'/test.sqlite','timezone'=>'America/Sao_Paulo','admin_password'=>'fictional-test-only','notification_email'=>'','base_url'=>'http://example.invalid'];";
    if (file_put_contents($testDirectory . '/config.php', $testConfig) === false) throw new RuntimeException('Cannot write isolated configuration.');
    function smtpSend(string $recipient, string $subject, string $text): void { throw new LogicException('Real email is forbidden in this test.'); }
    require $testDirectory . '/db.php';
    $databaseConnection = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $databaseConnection->exec('PRAGMA foreign_keys=ON');
initialiseDatabase();
$seedPlayer=db()->prepare('INSERT INTO players(id,name) VALUES (?,?)');
for ($id=1;$id<=26;$id++) $seedPlayer->execute([$id,sprintf('Botonista Ficticio %02d',$id)]);
$seedGame=db()->prepare('INSERT INTO games(round_number,game_number,turn_number,player_a_id,player_b_id) VALUES (?,?,?,?,?)');
$rotation=range(1,26);
for ($round=1;$round<=25;$round++) {
    for ($i=0;$i<13;$i++) {
        $a=$rotation[$i];$b=$rotation[25-$i];
        $seedGame->execute([$round,$i+1,1,$a,$b]);
        $seedGame->execute([$round+25,$i+1,2,$b,$a]);
    }
    $last=array_pop($rotation);array_splice($rotation,1,0,[$last]);
}
db()->exec("UPDATE games SET score_a=1,score_b=0,played_at='2026-09-20' WHERE id<=26");

$before = publicData();
if (count($before['players']) !== 26 || count($before['games']) !== 650 || $before['playedGames'] !== 26) {
    throw new RuntimeException('Base fictícia inicial inválida.');
}

$pdo = db();
$pending = $pdo->query('SELECT id FROM games WHERE score_a IS NULL LIMIT 1')->fetchColumn();
$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE games SET score_a = 2, score_b = 1, played_at = ? WHERE id = ?')->execute(['2026-09-22', $pending]);
    $after = publicData();
    if ($after['playedGames'] !== $before['playedGames'] + 1 || $after['totals']['goalsFor'] !== $before['totals']['goalsFor'] + 3) {
        throw new RuntimeException('Recálculo automático falhou.');
    }
    $pdo->rollBack();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    throw $exception;
}

$pdo->beginTransaction();
try {
    $playerId = (int) $pdo->query('SELECT id FROM players ORDER BY id LIMIT 1')->fetchColumn();
    $username = 'teste-' . bin2hex(random_bytes(4));
    $pdo->prepare('INSERT INTO users (name, username, email, player_id, created_at) VALUES (?, ?, ?, ?, ?)')->execute(['Usuário de teste', $username, $username . '@example.invalid', $playerId, date('c')]);
    $userId = (int) $pdo->lastInsertId();
    $token = bin2hex(random_bytes(16));
    $pdo->prepare('INSERT INTO user_invites (user_id, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?)')->execute([$userId, hash('sha256', $token), date('c', strtotime('+72 hours')), date('c')]);
    $ownGameStatement = $pdo->prepare('SELECT id FROM games WHERE player_a_id = ? OR player_b_id = ? LIMIT 1');
    $ownGameStatement->execute([$playerId, $playerId]);
    $ownGame = (int) $ownGameStatement->fetchColumn();
    if (!gameIsAccessible($ownGame, $playerId) || gameIsAccessible($ownGame, 999999)) {
        throw new RuntimeException('Restrição de jogos por jogador falhou.');
    }
    $invite = invitationByToken($token);
    if (!$invite || (int) $invite['user_id'] !== $userId) throw new RuntimeException('Convite de ativação falhou.');
    $pdo->rollBack();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    throw $exception;
}

echo "OK: 26 participantes, 650 jogos, recálculo, usuários e convites validados.\n";

} finally {
    if (isset($databaseConnection)) $databaseConnection = null;
    foreach ($testPaths as $path) if (is_file($path)) unlink($path);
    // Only these exact fallback files could be created by the isolated config.
    foreach (['test.sqlite','test.sqlite-journal','test.sqlite-wal','test.sqlite-shm'] as $file) {
        $path=$testDirectory.'/'.$file;
        if (is_file($path)) unlink($path);
    }
    rmdir($testDirectory);
}
