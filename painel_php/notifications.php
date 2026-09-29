<?php
declare(strict_types=1);

function organizerNotificationEmail(): string
{
    return strtolower(trim((string) (getenv('COPA_NOTIFY_EMAIL') ?: (config()['notification_email'] ?? ''))));
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

}

function queuePlayerNotification(string $eventId, string $action, string $source, array $after = [], string $target = '', array $before = []): void
{
    $accountRecipients = [];
    if (in_array($action, ['Dados de usuário alterados','Senha definida e conta ativada'], true) && preg_match('/^Usuário ([0-9]+)$/u', $target, $match)) {
        $query=db()->prepare('SELECT email FROM users WHERE id=?');
        $query->execute([(int)$match[1]]);
        $accountRecipients=$query->fetchAll(PDO::FETCH_COLUMN);
        if ($action==='Dados de usuário alterados' && isset($before['email'])) $accountRecipients[]=$before['email'];
    } elseif ($action==='Nome de botonista corrigido' && preg_match('/^Botonista ([0-9]+)$/', $target, $match)) {
        $query=db()->prepare('SELECT email FROM users WHERE player_id=?');
        $query->execute([(int)$match[1]]); $accountRecipients=$query->fetchAll(PDO::FETCH_COLUMN);
    }
    foreach ($accountRecipients as $email) {
        $email=strtolower(trim((string)$email));
        if (!filter_var($email,FILTER_VALIDATE_EMAIL)) continue;
        db()->prepare("INSERT OR IGNORE INTO email_notifications(event_id,recipient,audience) VALUES (?,?,'account')")->execute([$eventId,$email]);
        $GLOBALS['copaNotificationEvents'][]=$eventId;
    }
    $actions = ['Resultado salvo', 'Resultado removido', 'Nome de botonista corrigido', 'Súmula gerada', 'Súmula reemitida', 'Senha definida e conta ativada'];
    if (!in_array($action, $actions, true)) return;
    $user = currentUser();
    $playerInvite = $source === 'Convite' && isset($after['jogador_id']);
    if (in_array($action, ['Resultado salvo', 'Resultado removido', 'Súmula gerada', 'Súmula reemitida'], true) && preg_match('/^Jogo ([0-9]+)$/', $target, $match)) {
        $game = gameById((int) $match[1]);
        if ($game) {
            $query = db()->prepare('SELECT email FROM users WHERE player_id IN (?, ?)');
            $query->execute([$game['player_a_id'], $game['player_b_id']]);
            foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $email) {
                $email = strtolower(trim((string) $email));
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
                db()->prepare("INSERT OR IGNORE INTO email_notifications(event_id,recipient,audience) VALUES (?,?,'player')")->execute([$eventId,$email]);
                $GLOBALS['copaNotificationEvents'][] = $eventId;
            }
        }
    }
    if ($source !== 'QR Code' && !$playerInvite && !$user && !isMasterAdmin()) return;
    $organizerEmail = organizerNotificationEmail();
    if (!filter_var($organizerEmail, FILTER_VALIDATE_EMAIL)) return;
    db()->prepare("INSERT INTO email_notifications(event_id, recipient) VALUES (?, ?) ON CONFLICT(event_id,recipient) DO UPDATE SET audience=CASE WHEN audience='account' THEN 'account' ELSE 'organizer' END")->execute([$eventId, $organizerEmail]);
    $GLOBALS['copaNotificationEvents'][] = $eventId;
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

function sendPlayerNotification(string $id): void
{
    // Após o commit: falhas de SMTP nunca desfazem nem invalidam o salvamento.
    if (db()->inTransaction()) return;
    $query = db()->prepare("SELECT recipient FROM email_notifications WHERE event_id=? AND status='pending'");
    $query->execute([$id]);
    foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $recipient) {
        try {
            $claim = db()->prepare("UPDATE email_notifications SET status='sending', attempts=attempts+1, attempted_at=? WHERE event_id=? AND recipient=? AND status='pending'");
            $claim->execute([gmdate('c'), $id, $recipient]);
            if ($claim->rowCount() !== 1) continue;
            $query = db()->prepare('SELECT a.*, n.recipient, n.audience FROM audit_log a JOIN email_notifications n ON n.event_id=a.event_id WHERE a.event_id=? AND n.recipient=?');
            $query->execute([$id, $recipient]); $event = $query->fetch();
            if (!function_exists('smtpSend')) require_once __DIR__ . '/mailer.php';
            smtpSend($recipient, '[Copa Lago de Pedra] ' . $event['action'], match ($event['audience']) { 'account' => accountNotificationText($event), 'player' => matchNotificationText($event), default => notificationText($event) });
            db()->prepare("UPDATE email_notifications SET status='sent', sent_at=? WHERE event_id=? AND recipient=?")->execute([gmdate('c'), $id, $recipient]);
        } catch (Throwable $exception) {
            try { db()->prepare("UPDATE email_notifications SET status='failed' WHERE event_id=? AND recipient=? AND status='sending'")->execute([$id, $recipient]); }
            catch (Throwable $ignored) { error_log('Copa: falha ao atualizar notificacao.'); }
        }
    }
}

function dispatchPlayerNotifications(): void
{
    $events = $GLOBALS['copaNotificationEvents'] ?? [];
    $GLOBALS['copaNotificationEvents'] = [];
    foreach (array_unique($events) as $id) sendPlayerNotification($id);
}

function matchNotificationText(array $event): string
{
    $before = json_decode($event['before_json'], true) ?: [];
    $after = json_decode($event['after_json'], true) ?: [];
    $score = static fn(array $d): string => isset($d['placar_a'], $d['placar_b']) ? $d['placar_a'] . ' x ' . $d['placar_b'] : 'Sem resultado';
    $date = static fn(array $d): string => !empty($d['data']) ? date('d/m/Y', strtotime($d['data'])) : 'Não informada';
    if (in_array($event['action'], ['Súmula gerada','Súmula reemitida'], true)) {
        $operation = $event['action']==='Súmula reemitida' ? 'reemitida' : 'gerada';
        return "Olá! A súmula da sua partida foi {$operation}.\n\n"
            . ($after['jogador_a'] ?? '') . ' x ' . ($after['jogador_b'] ?? '')
            . "\nData da partida: " . $date($after)
            . "\n\nEste aviso não significa que um resultado foi registrado.\n"
            . "Consulte os jogos: " . rtrim((string)(config()['base_url'] ?? ''),'/') . '/index.php#jogos';
    }
    $text = "Olá! Houve uma atualização no resultado da sua partida na Copa Lago de Pedra.\n\n";
    $text .= ($after['jogador_a'] ?? $before['jogador_a'] ?? '') . ' x ' . ($after['jogador_b'] ?? $before['jogador_b'] ?? '') . "\n";
    if (isset($before['placar_a'], $before['placar_b'])) $text .= 'Antes: ' . $score($before) . ' | Data: ' . $date($before) . "\n";
    $text .= 'Agora: ' . $score($after) . ' | Data: ' . $date($after) . "\n";
    $text .= "\nConsulte os jogos e a classificação: " . rtrim((string) (config()['base_url'] ?? ''), '/') . '/index.php#jogos';
    return $text;
}

function accountNotificationText(array $event): string
{
    $before=json_decode($event['before_json'],true) ?: [];
    $after=json_decode($event['after_json'],true) ?: [];
    $text="Olá! Houve uma atualização no seu cadastro da Copa Lago de Pedra.\n\n";
    if ($event['action']==='Senha definida e conta ativada') {
        $text.="Sua senha foi definida ou redefinida com sucesso. Por segurança, a senha não é enviada por e-mail.\n";
    } else {
        foreach (['nome'=>'Nome','email'=>'E-mail'] as $key=>$label) {
            if (isset($after[$key]) && ($before[$key] ?? null)!==$after[$key]) $text.=$label . ' anterior: ' . ($before[$key] ?? 'Não informado') . "\n" . $label . ' atualizado: ' . $after[$key] . "\n";
        }
    }
    $text.="\nData e hora: " . date('d/m/Y H:i:s',strtotime($event['occurred_at'])) . ' (' . config()['timezone'] . ").\n";
    $text.="Se você não reconhece esta alteração, entre em contato com a organização da copa.\n";
    return $text . "\nAcesse o sistema: " . rtrim((string)(config()['base_url'] ?? ''),'/') . '/admin.php';
}
