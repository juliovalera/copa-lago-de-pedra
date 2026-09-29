<?php
declare(strict_types=1);

function initialiseAudit(): void
{
    db()->exec('CREATE TABLE IF NOT EXISTS audit_log (
        event_id TEXT PRIMARY KEY, occurred_at TEXT NOT NULL,
        actor TEXT NOT NULL, action TEXT NOT NULL, source TEXT NOT NULL,
        target TEXT NOT NULL, before_json TEXT NOT NULL, after_json TEXT NOT NULL
    )');
    db()->exec('CREATE INDEX IF NOT EXISTS audit_time_idx ON audit_log(occurred_at)');
    db()->exec("CREATE TRIGGER IF NOT EXISTS audit_no_update BEFORE UPDATE ON audit_log BEGIN SELECT RAISE(ABORT, 'Audit is append only'); END");
    db()->exec("CREATE TRIGGER IF NOT EXISTS audit_no_delete BEFORE DELETE ON audit_log BEGIN SELECT RAISE(ABORT, 'Audit is append only'); END");
}

function auditActor(): string
{
    $user = currentUser();
    if ($user) return $user['name'] . ' (@' . $user['username'] . ', ID ' . $user['id'] . ')';
    return ($_SESSION['legacy_admin'] ?? false) ? 'Administrador principal' : 'Sistema';
}

function auditRecord(string $action, string $target, array $before = [], array $after = [], string $source = 'Painel', ?string $actor = null): void
{
    // Somente campos explicitamente permitidos: nunca senhas, tokens ou configurações.
    $allowed = array_flip(['login_informado','ip','tela','bloqueado_ate','nivel','placar_a','placar_b','data','jogador_a','jogador_b','nome','login','email','jogador_id','ativo','senha_definida','link_id','gerado_por','arquivo','seguranca','status']);
    $encode = static fn(array $data): string => json_encode(array_intersect_key($data, $allowed), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $eventId = bin2hex(random_bytes(16));
    db()->prepare('INSERT INTO audit_log VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([
        $eventId, gmdate('Y-m-d\TH:i:s\Z'), $actor ?? auditActor(),
        $action, $source, $target, $encode($before), $encode($after)
    ]);
    queuePlayerNotification($eventId, $action, $source, $after, $target, $before);
}

function auditedTransaction(callable $work): mixed
{
    $pdo = db();
    $pdo->beginTransaction();
    try { $result = $work(); $pdo->commit(); dispatchPlayerNotifications(); return $result; }
    catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}

function auditGame(array $game): array
{
    return ['jogador_a'=>$game['a'], 'jogador_b'=>$game['b'], 'placar_a'=>$game['score_a'], 'placar_b'=>$game['score_b'], 'data'=>$game['played_at']];
}

function validGameDate(string $date): bool
{
    return preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $date, $parts) === 1
        && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
}

class ResultConflict extends RuntimeException {}

function panelResultToken(array $game): string
{
    return hash('sha256', json_encode([$game['id'], $game['player_a_id'], $game['player_b_id'], $game['score_a'], $game['score_b'], $game['played_at'], $game['result_revision']], JSON_THROW_ON_ERROR));
}

function savePanelResult(int $id, ?int $a, ?int $b, string $date, string $expected): void
{
    if ($a !== null && !validGameDate($date)) throw new InvalidArgumentException('Informe uma data existente no calendário.');
    auditedTransaction(static function () use ($id, $a, $b, $date, $expected): void {
        $before = gameById($id);
        if (!$before) throw new RuntimeException('Jogo não encontrado.');
        if (!hash_equals(panelResultToken($before), $expected)) {
            throw new ResultConflict('Este jogo foi atualizado depois que você abriu o formulário. Sua alteração não foi salva. Confira o placar e a data atuais antes de tentar novamente.');
        }
        db()->prepare('UPDATE games SET score_a = ?, score_b = ?, played_at = ? WHERE id = ?')->execute([$a, $b, $a === null ? null : $date, $id]);
        $after = gameById($id);
        if (auditGame($before) !== auditGame($after)) auditRecord($a === null ? 'Resultado removido' : 'Resultado salvo', 'Jogo ' . $id, auditGame($before), auditGame($after));
    });
}

function changeUserPrivilege(int $id, string $role, ?int $playerId, string $expectedPlayer, string $expectedRole): void
{
    if (!isMasterAdmin()) throw new RuntimeException('Somente o administrador máximo pode alterar privilégios.');
    if (!in_array($role, ['admin', 'player'], true) || ($role === 'player' && !$playerId)) {
        throw new InvalidArgumentException('Selecione o nível e vincule um botonista quando necessário.');
    }
    auditedTransaction(static function () use ($id, $role, $playerId, $expectedPlayer, $expectedRole): void {
        $query = db()->prepare('SELECT id, name, player_id, role FROM users WHERE id = ?');
        $query->execute([$id]); $before = $query->fetch();
        if (!$before) throw new InvalidArgumentException('Usuário não encontrado.');
        if ((string) ($before['player_id'] ?? '') !== $expectedPlayer || $before['role'] !== $expectedRole) throw new InvalidArgumentException('O acesso foi alterado por outra pessoa. Confira a situação atual e tente novamente.');
        if ($playerId !== null) {
            $query = db()->prepare('SELECT id FROM players WHERE id = ?'); $query->execute([$playerId]);
            if (!$query->fetchColumn()) throw new InvalidArgumentException('Botonista não encontrado.');
        }
        if ($before['player_id'] === $playerId && $before['role'] === $role) return;
        db()->prepare('UPDATE users SET player_id = ?, role = ? WHERE id = ?')->execute([$playerId, $role, $id]);
        auditRecord('Privilégios alterados', 'Usuário ' . $id,
            ['nome'=>$before['name'], 'jogador_id'=>$before['player_id'], 'nivel'=>$before['role'] === 'admin' ? 'Administrador' : 'Botonista'],
            ['nome'=>$before['name'], 'jogador_id'=>$playerId, 'nivel'=>$role === 'admin' ? 'Administrador' : 'Botonista']);
    });
}

function updateUserDetails(int $id, string $name, string $email, string $expectedName, string $expectedEmail): void
{
    if (!isMasterAdmin()) throw new RuntimeException('Somente o administrador máximo pode editar os dados da conta.');
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    $email = strtolower(trim($email));
    $length = preg_match_all('/./u', $name);
    if ($name === '' || $length === false || $length > 100 || preg_match('/\p{C}/u', $name) || strlen($email)>160 || !filter_var($email,FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Informe um nome com até 100 caracteres e um e-mail válido.');
    }
    auditedTransaction(static function () use ($id,$name,$email,$expectedName,$expectedEmail): void {
        $query = db()->prepare('SELECT name,email FROM users WHERE id=?');
        $query->execute([$id]); $before=$query->fetch();
        if (!$before) throw new InvalidArgumentException('Usuário não encontrado.');
        if ($before['name']!==$expectedName || $before['email']!==$expectedEmail) throw new InvalidArgumentException('Os dados foram alterados. Recarregue a página e confira antes de salvar.');
        if ($before['name']===$name && $before['email']===$email) return;
        $query=db()->prepare('SELECT 1 FROM users WHERE email=? COLLATE NOCASE AND id<>?');
        $query->execute([$email,$id]);
        if ($query->fetchColumn()) throw new InvalidArgumentException('Este e-mail já está em uso por outra conta.');
        db()->prepare('UPDATE users SET name=?,email=? WHERE id=?')->execute([$name,$email,$id]);
        if ($before['email']!==$email) db()->prepare('UPDATE user_invites SET used_at=? WHERE user_id=? AND used_at IS NULL')->execute([date('c'),$id]);
        auditRecord('Dados de usuário alterados', 'Usuário ' . $id, ['nome'=>$before['name'],'email'=>$before['email']], ['nome'=>$name,'email'=>$email]);
    });
}

function renamePlayer(int $id, string $name, string $expectedName): void
{
    $user = currentUser();
    if (!hasFullAccess() && (!$user || $user['player_id'] === null || (int) $user['player_id'] !== $id)) {
        throw new RuntimeException('Você pode corrigir apenas o seu próprio nome.');
    }
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    $length = preg_match_all('/./u', $name);
    if ($name === '' || $length === false || $length > 100 || preg_match('/\p{C}/u', $name)) {
        throw new InvalidArgumentException('Informe um nome válido, com até 100 caracteres.');
    }
    auditedTransaction(static function () use ($id, $name, $expectedName): void {
        $query = db()->prepare('SELECT name FROM players WHERE id = ?');
        $query->execute([$id]); $before = $query->fetchColumn();
        if ($before === false) throw new InvalidArgumentException('Botonista não encontrado.');
        if ($before !== $expectedName) throw new InvalidArgumentException('Este nome foi alterado por outra pessoa. Confira o cadastro atualizado e tente novamente.');
        if ($before === $name) return;
        foreach (db()->query('SELECT id, name FROM players')->fetchAll() as $player) {
            if ((int) $player['id'] !== $id && preg_match('/^' . preg_quote($name, '/') . '$/iu', trim(preg_replace('/\s+/u', ' ', $player['name'])))) {
                throw new InvalidArgumentException('Já existe outro botonista com esse nome.');
            }
        }
        db()->prepare('UPDATE players SET name = ? WHERE id = ?')->execute([$name, $id]);
        auditRecord('Nome de botonista corrigido', 'Botonista ' . $id, ['nome'=>$before], ['nome'=>$name]);
    });
}

function restoreAuditedBackup(string $source, string $safety): void
{
    if (!isMasterAdmin()) throw new RuntimeException('Somente o administrador máximo pode restaurar backups.');
    $pdo = db();
    $actor = auditActor();
    $pdo->prepare('ATTACH DATABASE ? AS restoration')->execute([$source]);
    try {
        auditedTransaction(static function () use ($pdo, $source, $safety, $actor): void {
            $tables = $pdo->query("SELECT name FROM restoration.sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
            foreach (['user_invites','referee_links','users','games','players'] as $table) $pdo->exec('DELETE FROM main.' . $table);
            foreach (['players','games','users','referee_links','user_invites'] as $table) {
                if (!in_array($table, $tables, true)) continue;
                $columns = array_column($pdo->query('PRAGMA restoration.table_info(' . $table . ')')->fetchAll(), 'name');
                $current = array_column($pdo->query('PRAGMA main.table_info(' . $table . ')')->fetchAll(), 'name');
                $columns = array_intersect($current, $columns);
                $list = implode(',', array_map(static fn($name) => '"' . $name . '"', $columns));
                $pdo->exec('INSERT INTO main.' . $table . ' (' . $list . ') SELECT ' . $list . ' FROM restoration.' . $table);
                if ($table === 'users' && !in_array('role', $columns, true)) {
                    $pdo->exec("UPDATE main.users SET role = CASE WHEN player_id IS NULL THEN 'admin' ELSE 'player' END");
                }
            }
            if (in_array('digital_sheets', $tables, true)) {
                $pdo->exec("INSERT INTO main.digital_sheets(game_id,match_date,revision,status,data_json,signatures_json,updated_by,updated_at,finalized_at)
                    SELECT game_id,match_date,revision,status,data_json,signatures_json,updated_by,updated_at,finalized_at FROM restoration.digital_sheets AS source
                    WHERE NOT EXISTS (SELECT 1 FROM main.digital_sheets AS current WHERE current.game_id=source.game_id AND current.status='final')
                    ON CONFLICT(game_id,match_date) DO UPDATE SET status=excluded.status,data_json=excluded.data_json,signatures_json=excluded.signatures_json,updated_by=excluded.updated_by,updated_at=excluded.updated_at,finalized_at=excluded.finalized_at,revision=excluded.revision
                    WHERE digital_sheets.status='draft' AND excluded.status='final'");
            }
            if (in_array('audit_log', $tables, true)) $pdo->exec('INSERT OR IGNORE INTO main.audit_log SELECT * FROM restoration.audit_log');
            auditRecord('Backup restaurado', 'Banco de dados', [], ['arquivo'=>basename($source), 'seguranca'=>basename($safety)], 'Painel', $actor);
        });
    } finally { $pdo->exec('DETACH DATABASE restoration'); }
}
