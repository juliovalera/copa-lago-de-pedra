<?php
declare(strict_types=1);

function organizerNotificationEmail(): string
{
    return trim((string) (getenv('COPA_NOTIFY_EMAIL') ?: (config()['notification_email'] ?? '')));
}

function initialiseNotifications(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS email_notifications (
        event_id TEXT PRIMARY KEY REFERENCES audit_log(event_id),
        recipient TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pending',
        attempts INTEGER NOT NULL DEFAULT 0, attempted_at TEXT NULL, sent_at TEXT NULL
    )");
    $columns = array_column(db()->query('PRAGMA table_info(email_notifications)')->fetchAll(), 'name');
    if (!in_array('audience', $columns, true)) {
        db()->exec('BEGIN IMMEDIATE');
        try {
            $columns = array_column(db()->query('PRAGMA table_info(email_notifications)')->fetchAll(), 'name');
            if (!in_array('audience', $columns, true)) {
                db()->exec("CREATE TABLE email_notifications_new (
                    event_id TEXT NOT NULL REFERENCES audit_log(event_id), recipient TEXT NOT NULL,
                    status TEXT NOT NULL DEFAULT 'pending', attempts INTEGER NOT NULL DEFAULT 0,
                    attempted_at TEXT NULL, sent_at TEXT NULL, audience TEXT NOT NULL DEFAULT 'organizer',
                    PRIMARY KEY(event_id, recipient))");
                db()->exec('INSERT INTO email_notifications_new(event_id,recipient,status,attempts,attempted_at,sent_at) SELECT event_id,recipient,status,attempts,attempted_at,sent_at FROM email_notifications');
                db()->exec('DROP TABLE email_notifications');
                db()->exec('ALTER TABLE email_notifications_new RENAME TO email_notifications');
            }
            db()->exec('COMMIT');
        } catch (Throwable $e) { db()->exec('ROLLBACK'); throw $e; }
    }

    $columns = array_column(db()->query('PRAGMA table_info(email_notifications)')->fetchAll(), 'name');
    if (!in_array('next_attempt_at', $columns, true)) {
        db()->exec('BEGIN IMMEDIATE');
        try {
            $columns = array_column(db()->query('PRAGMA table_info(email_notifications)')->fetchAll(), 'name');
            if (!in_array('next_attempt_at', $columns, true)) db()->exec('ALTER TABLE email_notifications ADD COLUMN next_attempt_at TEXT NULL');
            db()->exec('COMMIT');
        } catch (Throwable $e) { db()->exec('ROLLBACK'); throw $e; }
    }

}

function queuePlayerNotification(string $eventId, string $action, string $source, array $after = [], string $target = ''): void
{
    $actions = ['Resultado salvo', 'Resultado removido', 'Nome de botonista corrigido', 'Súmula gerada', 'Súmula reemitida', 'Senha definida e conta ativada'];
    if (!in_array($action, $actions, true)) return;
    $user = currentUser();
    $playerInvite = $source === 'Convite' && isset($after['jogador_id']);
    if (in_array($action, ['Resultado salvo', 'Resultado removido'], true) && preg_match('/^Jogo ([0-9]+)$/', $target, $match)) {
        $game = gameById((int) $match[1]);
        if ($game) {
            $query = db()->prepare('SELECT email FROM users WHERE player_id IN (?, ?)');
            $query->execute([$game['player_a_id'], $game['player_b_id']]);
            foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $email) {
                $email = strtolower(trim((string) $email));
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
                db()->prepare("INSERT OR IGNORE INTO email_notifications(event_id,recipient,audience) VALUES (?,?,'player')")->execute([$eventId,$email]);
            }
        }
    }
    if ($source !== 'QR Code' && !$playerInvite && (!$user || $user['player_id'] === null)) return;
    $organizerEmail = organizerNotificationEmail();
    if (!filter_var($organizerEmail, FILTER_VALIDATE_EMAIL)) return;
    db()->prepare("INSERT INTO email_notifications(event_id, recipient) VALUES (?, ?) ON CONFLICT(event_id,recipient) DO UPDATE SET audience='organizer'")->execute([$eventId, $organizerEmail]);
}

function notificationText(array $event): string
{
    $labels = ['jogador_a'=>'Jogador A', 'jogador_b'=>'Jogador B', 'placar_a'=>'Gols A', 'placar_b'=>'Gols B', 'data'=>'Data do jogo', 'nome'=>'Nome', 'link_id'=>'Identificador da súmula', 'gerado_por'=>'Súmula gerada por'];
    $text = "Copa Lago de Pedra — atualização registrada\n\nAção: {$event['action']}\nCadastro: {$event['target']}\nResponsável: {$event['actor']}\nOrigem: {$event['source']}\nData e hora: " . date('d/m/Y H:i:s', strtotime($event['occurred_at'])) . ' (' . config()['timezone'] . ")\n";
    foreach (['Antes'=>'before_json', 'Depois'=>'after_json'] as $heading=>$column) {
        $text .= "\n$heading:\n";
        $data = json_decode($event[$column], true) ?: [];
        foreach ($labels as $key=>$label) {
            if (!array_key_exists($key, $data)) continue;
            $value = $data[$key];
            if ($key === 'data' && $value) $value = date('d/m/Y', strtotime($value));
            $text .= $label . ': ' . ($value ?? 'Não informado') . "\n";
        }
        if (!$data) $text .= "Não se aplica.\n";
    }
    if ($event['source'] === 'QR Code') $text .= "\nO envio foi feito pelo portador do QR; sua identidade não foi verificada.\n";
    return $text . "\nConsulte o painel e a auditoria para mais detalhes.\nReferência: " . $event['event_id'];
}

// Workers run only through CLI. Web requests only write the transactional queue.
function sendPlayerNotification(string $id, ?string $onlyRecipient = null): void
{
    if (PHP_SAPI !== 'cli' || db()->inTransaction()) return;
    $now = gmdate('c');
    $query = db()->prepare("SELECT recipient FROM email_notifications WHERE event_id=? AND status IN ('pending','failed') AND attempts<5 AND (next_attempt_at IS NULL OR next_attempt_at<=?)" . ($onlyRecipient !== null ? ' AND recipient=?' : ''));
    $query->execute($onlyRecipient !== null ? [$id,$now,$onlyRecipient] : [$id,$now]);
    foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $recipient) {
        try {
            $claim = db()->prepare("UPDATE email_notifications SET status='sending', attempts=attempts+1, attempted_at=? WHERE event_id=? AND recipient=? AND status IN ('pending','failed') AND attempts<5 AND (next_attempt_at IS NULL OR next_attempt_at<=?)");
            $claim->execute([$now, $id, $recipient, $now]);
            if ($claim->rowCount() !== 1) continue;
            $query = db()->prepare('SELECT a.*, n.recipient, n.audience, n.attempts FROM audit_log a JOIN email_notifications n ON n.event_id=a.event_id WHERE a.event_id=? AND n.recipient=?');
            $query->execute([$id, $recipient]); $event = $query->fetch();
            if (!function_exists('smtpSend')) require_once __DIR__ . '/mailer.php';
            smtpSend($recipient, '[Copa Lago de Pedra] ' . $event['action'], $event['audience'] === 'player' ? matchNotificationText($event) : notificationText($event));
            db()->prepare("UPDATE email_notifications SET status='sent', sent_at=?, next_attempt_at=NULL WHERE event_id=? AND recipient=?")->execute([gmdate('c'), $id, $recipient]);
        } catch (Throwable $exception) {
            try {
                $query = db()->prepare('SELECT attempts FROM email_notifications WHERE event_id=? AND recipient=?');
                $query->execute([$id,$recipient]); $attempts = (int) $query->fetchColumn();
                $delays = [1=>60, 2=>300, 3=>900, 4=>3600];
                $next = $attempts < 5 ? gmdate('c', time() + ($delays[$attempts] ?? 60)) : null;
                db()->prepare("UPDATE email_notifications SET status='failed', next_attempt_at=? WHERE event_id=? AND recipient=? AND status='sending'")->execute([$next,$id,$recipient]);
            } catch (Throwable $ignored) { error_log('Copa: falha ao atualizar notificacao.'); }
        }
    }
}

function processNotificationQueue(int $limit = 20): int
{
    if (PHP_SAPI !== 'cli' || db()->inTransaction()) return 0;
    // Only called under the CLI worker lock; a prior interrupted send may have reached SMTP.
    db()->prepare("UPDATE email_notifications SET status='failed', next_attempt_at=? WHERE status='sending' AND attempted_at<?")->execute([gmdate('c'),gmdate('c',time()-1800)]);
    $query = db()->prepare("SELECT event_id,recipient FROM email_notifications WHERE status IN ('pending','failed') AND attempts<5 AND (next_attempt_at IS NULL OR next_attempt_at<=?) ORDER BY COALESCE(next_attempt_at,''), rowid LIMIT " . max(1,min(100,$limit)));
    $query->execute([gmdate('c')]); $batch = $query->fetchAll();
    foreach ($batch as $item) sendPlayerNotification($item['event_id'], $item['recipient']);
    return count($batch);
}

function matchNotificationText(array $event): string
{
    $before = json_decode($event['before_json'], true) ?: [];
    $after = json_decode($event['after_json'], true) ?: [];
    $score = static fn(array $d): string => isset($d['placar_a'], $d['placar_b']) ? $d['placar_a'] . ' x ' . $d['placar_b'] : 'Sem resultado';
    $date = static fn(array $d): string => !empty($d['data']) ? date('d/m/Y', strtotime($d['data'])) : 'Não informada';
    $text = "Olá! Houve uma atualização no resultado da sua partida na Copa Lago de Pedra.\n\n";
    $text .= ($after['jogador_a'] ?? $before['jogador_a'] ?? '') . ' x ' . ($after['jogador_b'] ?? $before['jogador_b'] ?? '') . "\n";
    if (isset($before['placar_a'], $before['placar_b'])) $text .= 'Antes: ' . $score($before) . ' | Data: ' . $date($before) . "\n";
    $text .= 'Agora: ' . $score($after) . ' | Data: ' . $date($after) . "\n";
    $text .= "\nConsulte os jogos e a classificação: " . rtrim((string) (config()['base_url'] ?? ''), '/') . '/index.php#jogos';
    return $text;
}
