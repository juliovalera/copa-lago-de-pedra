<?php
declare(strict_types=1);
require __DIR__ . '/referee.php';
processNotificationQueue(100);
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
check(count($GLOBALS['testEmails']) === 0, 'salvar apenas enfileira, sem chamar SMTP');
check((int) $pdo->query("SELECT COUNT(*) FROM email_notifications WHERE status='pending'")->fetchColumn() === 1, 'aviso persistido após commit');
$GLOBALS['testSmtpFailure'] = true;
processNotificationQueue();
$row = $pdo->query("SELECT * FROM email_notifications WHERE recipient='demo@example.invalid'")->fetch();
check($row['status']==='failed' && $row['attempts']===1 && strtotime($row['next_attempt_at'])>time(), 'falha agenda tentativa futura');
processNotificationQueue();
check((int) $pdo->query("SELECT attempts FROM email_notifications WHERE recipient='demo@example.invalid'")->fetchColumn()===1, 'não tenta de novo antes do prazo');
for ($attempt=2; $attempt<=5; $attempt++) {
    $pdo->exec("UPDATE email_notifications SET next_attempt_at='2000-01-01T00:00:00+00:00' WHERE recipient='demo@example.invalid'");
    processNotificationQueue();
}
processNotificationQueue();
check((int) $pdo->query("SELECT attempts FROM email_notifications WHERE recipient='demo@example.invalid'")->fetchColumn()===5, 'para após cinco tentativas');
$GLOBALS['testSmtpFailure'] = false;
$pdo->exec("UPDATE email_notifications SET status='pending',attempts=0,next_attempt_at=NULL WHERE recipient='demo@example.invalid'");
processNotificationQueue(); processNotificationQueue();
check(count($GLOBALS['testEmails'])===1, 'reagendamento entrega uma vez sem repetir confirmado');
savePanelResult(401,2,0,'2024-02-29');
$pdo->exec("UPDATE email_notifications SET status='sending', attempts=1, attempted_at='2000-01-01T00:00:00+00:00' WHERE status='pending'");
processNotificationQueue();
check(count($GLOBALS['testEmails'])===2, 'envio interrompido antigo é recuperado');
savePanelResult(401,null,null,'');
check(gameById(401)['played_at']===null, 'remoção limpa data sem exigir preenchimento');
