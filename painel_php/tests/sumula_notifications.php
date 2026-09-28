<?php
declare(strict_types=1);
require __DIR__ . '/referee.php';
$pdo->exec("INSERT INTO users(id,name,username,email,player_id,is_active,created_at) VALUES (1,'A','a','a@example.invalid',1,1,'2026-09-28'),(2,'B','b','b@example.invalid',2,1,'2026-09-28')");
$insertGame->execute([501,501]);
$_SESSION=['legacy_admin'=>true];
$GLOBALS['testEmails']=[];
$date=date('Y-m-d',strtotime('+1 day'));
$link=dailyRefereeLink(501,$date);
check(count($GLOBALS['testEmails'])===2,'sumula gerada por administrador avisa os dois jogadores');
foreach ($GLOBALS['testEmails'] as $email) {
    check(str_contains($email['text'],'Teste A x Teste B') && str_contains($email['text'],date('d/m/Y',strtotime($date))), 'aviso contem confronto e data escolhida');
    check(!str_contains($email['text'],$link['token']) && !str_contains($email['text'],'arbitro.php?t='), 'aviso nao divulga token de registro');
}
$same=dailyRefereeLink(501,$date);
check($same['token']===$link['token'] && count($GLOBALS['testEmails'])===4,'reemissao mantém QR e avisa jogadores');
$_SESSION=['user_id'=>1];
dailyRefereeLink(501,$date);
check(count($GLOBALS['testEmails'])===7,'geracao pelo botonista preserva copia ao organizador');
$GLOBALS['testFailRecipient']='b@example.invalid';
$prior=count($GLOBALS['testEmails']);
dailyRefereeLink(501,$date);
check(count($GLOBALS['testEmails'])===$prior+2,'falha de um destinatario nao impede os outros');
$failed=$pdo->query("SELECT event_id FROM email_notifications WHERE recipient='b@example.invalid' AND status='failed' ORDER BY rowid DESC LIMIT 1")->fetchColumn();
check((bool)$failed,'falha de aviso da sumula fica registrada');
$GLOBALS['testFailRecipient']='';
$pdo->prepare("UPDATE email_notifications SET status='pending' WHERE event_id=? AND status='failed'")->execute([$failed]);
sendPlayerNotification($failed);sendPlayerNotification($failed);
check(count($GLOBALS['testEmails'])===$prior+3,'reenvio só entrega o aviso que faltou');
$pdo->exec("UPDATE users SET email='' WHERE id=2");
$prior=count($GLOBALS['testEmails']);
dailyRefereeLink(501,$date);
check(count($GLOBALS['testEmails'])===$prior+2,'conta sem email e ignorada');
check(gameById(501)['score_a']===null && gameById(501)['played_at']===null,'geracao nao registra resultado ou data no jogo');
