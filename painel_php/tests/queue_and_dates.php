<?php
declare(strict_types=1);
require __DIR__ . '/referee.php';
$GLOBALS['testEmails'] = [];
$pdo->exec("INSERT INTO users(id,name,username,email,player_id,is_active,created_at) VALUES (1,'Demo','demo','demo@example.invalid',1,1,'2026-09-28')");
$_SESSION = ['legacy_admin'=>true];
$insertGame->execute([401,401]);
foreach (['2026-02-30','2026-04-31','2026-02-29','0000-01-01','2026-13-01','2026-00-10','2026-1-01',"2026-01-01\n", "2026-01-01\0"] as $invalid) {
    $auditBefore = (int) $pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
    try { savePanelResult(401,1,0,$invalid); throw new LogicException('Data inválida aceita.'); }
    catch (InvalidArgumentException $expected) {}
    check(gameById(401)['score_a'] === null && $auditBefore === (int) $pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn(), 'data inválida recusada sem gravar jogo ou auditoria');
}
savePanelResult(401,1,0,'2024-02-29');
check(gameById(401)['played_at'] === '2024-02-29', 'ano bissexto aceito');
check(count($GLOBALS['testEmails']) === 1, 'aviso enviado ao salvar, sem agendamento');
// Compatibility with databases already upgraded to 1.33: stale retry dates do not block manual delivery.
$pdo->exec('ALTER TABLE email_notifications ADD COLUMN next_attempt_at TEXT NULL');
$GLOBALS['testSmtpFailure'] = true;
savePanelResult(401,2,0,'2024-02-29');
check(gameById(401)['score_a']===2, 'falha SMTP preserva resultado');
$row=$pdo->query("SELECT event_id FROM email_notifications WHERE recipient='demo@example.invalid' AND status='failed' ORDER BY rowid DESC LIMIT 1")->fetch();
$pdo->prepare("UPDATE email_notifications SET status='pending', attempts=5,next_attempt_at='2099-01-01T00:00:00+00:00' WHERE event_id=?")->execute([$row['event_id']]);
$GLOBALS['testSmtpFailure'] = false;
sendPlayerNotification($row['event_id']);sendPlayerNotification($row['event_id']);
check(count($GLOBALS['testEmails'])===2, 'reenvio manual funciona com banco 1.33 e nao duplica confirmado');
savePanelResult(401,null,null,'');
check(gameById(401)['played_at']===null, 'remocao limpa data');
