<?php

declare(strict_types=1);
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/version.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/login_security.php';

function config(): array
{
    static $config;
    if ($config === null) {
        $config = require __DIR__ . '/config.php';
        date_default_timezone_set($config['timezone']);
    }
    return $config;
}

function db(): PDO
{
    global $databaseConnection;
    if ($databaseConnection instanceof PDO) {
        return $databaseConnection;
    }

    $path = config()['database'];
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0775, true);
    }
    $databaseConnection = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $databaseConnection->exec('PRAGMA foreign_keys = ON');
    return $databaseConnection;
}

function closeDatabase(): void
{
    global $databaseConnection;
    $databaseConnection = null;
}

function initialiseDatabase(): void
{
    $pdo = db();
    $pdo->exec('CREATE TABLE IF NOT EXISTS players (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL UNIQUE
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS games (
        id INTEGER PRIMARY KEY,
        round_number INTEGER NOT NULL,
        game_number INTEGER NOT NULL,
        turn_number INTEGER NOT NULL,
        player_a_id INTEGER NOT NULL REFERENCES players(id),
        player_b_id INTEGER NOT NULL REFERENCES players(id),
        score_a INTEGER NULL CHECK(score_a >= 0),
        score_b INTEGER NULL CHECK(score_b >= 0),
        played_at TEXT NULL,
        UNIQUE(round_number, game_number)
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS referee_links (
        id INTEGER PRIMARY KEY,
        game_id INTEGER NOT NULL REFERENCES games(id) ON DELETE CASCADE,
        token TEXT NOT NULL UNIQUE,
        generated_on TEXT NOT NULL,
        used_at TEXT NULL,
        UNIQUE(game_id, generated_on)
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        username TEXT NOT NULL COLLATE NOCASE UNIQUE,
        email TEXT NOT NULL COLLATE NOCASE UNIQUE,
        password_hash TEXT NULL,
        player_id INTEGER NULL REFERENCES players(id) ON DELETE SET NULL,
        is_active INTEGER NOT NULL DEFAULT 0 CHECK(is_active IN (0, 1)),
        invited_at TEXT NULL,
        created_at TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS user_invites (
        id INTEGER PRIMARY KEY,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        token_hash TEXT NOT NULL UNIQUE,
        expires_at TEXT NOT NULL,
        used_at TEXT NULL,
        created_at TEXT NOT NULL
    )');
    initialiseAudit();
    initialiseNotifications();
    initialiseLoginSecurity();
    $linkColumns = array_column($pdo->query('PRAGMA table_info(referee_links)')->fetchAll(), 'name');
    if (!in_array('created_by', $linkColumns, true)) $pdo->exec('ALTER TABLE referee_links ADD COLUMN created_by TEXT NULL');
    $pdo->exec('CREATE INDEX IF NOT EXISTS games_round_idx ON games(round_number)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS referee_links_token_idx ON referee_links(token)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS users_player_idx ON users(player_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS user_invites_token_idx ON user_invites(token_hash)');
}

function currentUser(): ?array
{
    if (!isset($_SESSION['user_id'])) return null;
    $statement = db()->prepare('SELECT u.*, p.name AS player_name FROM users u LEFT JOIN players p ON p.id = u.player_id WHERE u.id = ? AND u.is_active = 1');
    $statement->execute([$_SESSION['user_id']]);
    return $statement->fetch() ?: null;
}

function isMasterAdmin(): bool
{
    return (bool) ($_SESSION['legacy_admin'] ?? false) && !isset($_SESSION['user_id']);
}

function hasFullAccess(): bool
{
    return isMasterAdmin() || (currentUser() !== null && currentUser()['player_id'] === null);
}

function requirePanelAccess(): array
{
    $user = currentUser();
    if ($user !== null || isMasterAdmin()) return $user ?? ['name' => 'Administrador principal', 'player_id' => null];
    header('Location: admin.php');
    exit;
}

function gameIsAccessible(int $gameId, ?int $playerId): bool
{
    if ($playerId === null) return true;
    $statement = db()->prepare('SELECT 1 FROM games WHERE id = ? AND (player_a_id = ? OR player_b_id = ?)');
    $statement->execute([$gameId, $playerId, $playerId]);
    return (bool) $statement->fetchColumn();
}

function invitationByToken(string $token): ?array
{
    $statement = db()->prepare('SELECT i.*, u.name, u.username, u.email, u.player_id FROM user_invites i JOIN users u ON u.id = i.user_id WHERE i.token_hash = ? AND i.used_at IS NULL AND i.expires_at >= ?');
    $statement->execute([hash('sha256', $token), date('c')]);
    return $statement->fetch() ?: null;
}

function gameRows(): array
{
    return db()->query('SELECT g.*, a.name AS a, b.name AS b
        FROM games g
        JOIN players a ON a.id = g.player_a_id
        JOIN players b ON b.id = g.player_b_id
        ORDER BY g.round_number, g.game_number')->fetchAll();
}

function gameById(int $id): ?array
{
    $statement = db()->prepare('SELECT g.*, a.name AS a, b.name AS b
        FROM games g
        JOIN players a ON a.id = g.player_a_id
        JOIN players b ON b.id = g.player_b_id
        WHERE g.id = ?');
    $statement->execute([$id]);
    return $statement->fetch() ?: null;
}

function dailyRefereeLink(int $gameId, ?string $matchDate = null): array
{
    return auditedTransaction(static function () use ($gameId, $matchDate): array {
    $matchDate ??= date('Y-m-d');
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $matchDate);
    if (!$parsed || $parsed->format('Y-m-d') !== $matchDate || $matchDate < date('Y-m-d')) {
        throw new InvalidArgumentException('Informe uma data válida, de hoje em diante.');
    }
    $game = gameById($gameId);
    if (!$game || $game['score_a'] !== null || $game['score_b'] !== null) {
        throw new RuntimeException('Súmula disponível somente para jogos sem resultado.');
    }
    // Coluna legada: guarda o dia de validade do QR e da partida, não o instante de impressão.
    $today = $matchDate;
    $pdo = db();
    $existing = $pdo->prepare('SELECT * FROM referee_links WHERE game_id = ? AND generated_on = ?');
    $existing->execute([$gameId, $today]);
    $link = $existing->fetch();
    if ($link) {
        auditRecord('Súmula reemitida', 'Jogo ' . $gameId, [], ['jogador_a'=>$game['a'], 'jogador_b'=>$game['b'], 'data'=>$matchDate, 'link_id'=>$link['id'], 'gerado_por'=>$link['created_by']]);
        return $link;
    }
    $token = bin2hex(random_bytes(24));
    $insert = $pdo->prepare('INSERT INTO referee_links (game_id, token, generated_on, created_by) VALUES (?, ?, ?, ?)');
    $creator = auditActor();
    $insert->execute([$gameId, $token, $today, $creator]);
    $linkId = (int) $pdo->lastInsertId();
    auditRecord('Súmula gerada', 'Jogo ' . $gameId, [], ['jogador_a'=>$game['a'], 'jogador_b'=>$game['b'], 'data'=>$matchDate, 'link_id'=>$linkId, 'gerado_por'=>$creator]);
    return ['game_id' => $gameId, 'token' => $token, 'generated_on' => $today, 'used_at' => null];
    });
}

function validRefereeLink(string $token): ?array
{
    $statement = db()->prepare('SELECT r.id AS referee_link_id, r.game_id, r.token,
        r.generated_on, r.used_at, r.created_by, g.round_number, g.game_number, g.turn_number,
        g.score_a, g.score_b,
        a.name AS a, b.name AS b
        FROM referee_links r
        JOIN games g ON g.id = r.game_id
        JOIN players a ON a.id = g.player_a_id
        JOIN players b ON b.id = g.player_b_id
        WHERE r.token = ? AND r.generated_on = ?');
    $statement->execute([$token, date('Y-m-d')]);
    return $statement->fetch() ?: null;
}

function saveRefereeResult(string $token, int $scoreA, int $scoreB): void
{
    if ($scoreA < 0 || $scoreB < 0) {
        throw new InvalidArgumentException('Placar inválido.');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $link = validRefereeLink($token);
        if (!$link || $link['used_at'] !== null || $link['score_a'] !== null || $link['score_b'] !== null) {
            throw new RuntimeException('Link indisponível ou já utilizado.');
        }
        $used = $pdo->prepare('UPDATE referee_links SET used_at = ?
            WHERE id = ? AND token = ? AND generated_on = ? AND used_at IS NULL');
        $used->execute([gmdate('c'), $link['referee_link_id'], $token, date('Y-m-d')]);
        if ($used->rowCount() !== 1) {
            throw new RuntimeException('Link indisponível ou já utilizado.');
        }
        $before = gameById((int) $link['game_id']);
        $game = $pdo->prepare('UPDATE games SET score_a = ?, score_b = ?, played_at = ? WHERE id = ? AND score_a IS NULL AND score_b IS NULL');
        $game->execute([$scoreA, $scoreB, $link['generated_on'], $link['game_id']]);
        if ($game->rowCount() !== 1) {
            throw new RuntimeException('Jogo não encontrado.');
        }
        auditRecord('Resultado salvo', 'Jogo ' . $link['game_id'], auditGame($before), auditGame(gameById((int) $link['game_id'])) + ['link_id'=>$link['referee_link_id'], 'gerado_por'=>$link['created_by'] ?? 'Não identificado (link anterior)'], 'QR Code', 'Portador do QR (identidade não verificada)');
        $pdo->commit();
        dispatchPlayerNotifications();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}

function publicData(): array
{
    $games = gameRows();
    $stats = [];
    foreach (db()->query('SELECT id, name FROM players ORDER BY id') as $player) {
        $stats[$player['id']] = [
            'id' => 'p' . $player['id'], 'name' => $player['name'], 'played' => 0,
            'wins' => 0, 'draws' => 0, 'losses' => 0, 'points' => 0,
            'goalsFor' => 0, 'goalsAgainst' => 0, 'goalDifference' => 0,
            'position' => 0, 'history' => [],
        ];
    }

    $publicGames = [];
    $playedGames = 0;
    foreach ($games as $game) {
        $played = $game['score_a'] !== null && $game['score_b'] !== null;
        if ($played) {
            $playedGames++;
            foreach ([[$game['player_a_id'], $game['score_a'], $game['score_b']], [$game['player_b_id'], $game['score_b'], $game['score_a']]] as [$id, $goalsFor, $goalsAgainst]) {
                $stats[$id]['played']++;
                $stats[$id]['goalsFor'] += $goalsFor;
                $stats[$id]['goalsAgainst'] += $goalsAgainst;
                if ($goalsFor > $goalsAgainst) { $stats[$id]['wins']++; $stats[$id]['points'] += 3; }
                elseif ($goalsFor === $goalsAgainst) { $stats[$id]['draws']++; $stats[$id]['points']++; }
                else { $stats[$id]['losses']++; }
            }
        }
        $publicGames[] = [
            'databaseId' => (int) $game['id'],
            'id' => 'r' . $game['round_number'] . 'j' . $game['game_number'],
            'round' => (int) $game['round_number'], 'game' => (int) $game['game_number'],
            'turn' => (int) $game['turn_number'], 'a' => $game['a'], 'b' => $game['b'],
            'scoreA' => $played ? (int) $game['score_a'] : null,
            'scoreB' => $played ? (int) $game['score_b'] : null,
            'status' => $played ? 'played' : 'pending', 'playedAt' => $played ? $game['played_at'] : null,
        ];
    }

    $players = rankPlayerStats($stats);
    $dated = [];
    $undated = 0;
    foreach ($games as $game) {
        if ($game['score_a'] === null || $game['score_b'] === null) continue;
        $date = (string) ($game['played_at'] ?? '');
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) { $undated++; continue; }
        $dated[$date][] = $game;
    }
    ksort($dated);
    $timeline = [];
    foreach ($stats as $id => $player) {
        foreach (['played','wins','draws','losses','points','goalsFor','goalsAgainst','goalDifference','position'] as $field) $player[$field] = 0;
        $timeline[$id] = $player;
    }
    $histories = [];
    foreach ($dated as $date => $dailyGames) {
        foreach ($dailyGames as $game) {
            foreach ([[$game['player_a_id'], $game['score_a'], $game['score_b']], [$game['player_b_id'], $game['score_b'], $game['score_a']]] as [$id, $gf, $ga]) {
                $timeline[$id]['played']++;
                $timeline[$id]['goalsFor'] += $gf;
                $timeline[$id]['goalsAgainst'] += $ga;
                if ($gf > $ga) { $timeline[$id]['wins']++; $timeline[$id]['points'] += 3; }
                elseif ($gf === $ga) { $timeline[$id]['draws']++; $timeline[$id]['points']++; }
                else $timeline[$id]['losses']++;
            }
        }
        foreach (rankPlayerStats($timeline) as $player) {
            $histories[$player['id']][] = ['date'=>$date, 'position'=>$player['position'], 'points'=>$player['points']];
        }
    }
    foreach ($players as &$player) $player['history'] = $histories[$player['id']] ?? [];
    unset($player);

    return [
        'title' => 'I Copa Lago de Pedra', 'source' => 'SQLite', 'importedAt' => gmdate('c'),
        'players' => $players, 'games' => $publicGames, 'issues' => [],
        'totals' => ['points' => array_sum(array_column($players, 'points')), 'goalsFor' => array_sum(array_column($players, 'goalsFor')), 'goalsAgainst' => array_sum(array_column($players, 'goalsAgainst'))],
        'playedGames' => $playedGames, 'pendingGames' => count($publicGames) - $playedGames,
        'historyUndatedGames' => $undated,
        'rankingCriteria' => ['points', 'wins', 'goalDifference', 'goalsFor'],
    ];
}

function rankPlayerStats(array $stats): array
{
    $players = array_values($stats);
    foreach ($players as &$player) {
        $player['goalDifference'] = $player['goalsFor'] - $player['goalsAgainst'];
        $player['lostPoints'] = 3 * $player['played'] - $player['points'];
    }
    unset($player);
    usort($players, fn(array $a, array $b): int => [$b['points'], $b['wins'], $b['goalDifference'], $b['goalsFor'], $a['name']] <=> [$a['points'], $a['wins'], $a['goalDifference'], $a['goalsFor'], $b['name']]);
    $previous = null;
    foreach ($players as $index => &$player) {
        $key = [$player['points'], $player['wins'], $player['goalDifference'], $player['goalsFor']];
        $player['position'] = $previous !== null && $key === $previous ? $players[$index - 1]['position'] : $index + 1;
        $previous = $key;
    }
    unset($player);

    return $players;
}
