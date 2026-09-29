<?php
declare(strict_types=1);
require __DIR__ . '/referee.php';
$pdo->exec("INSERT INTO users(id,name,username,email,player_id,is_active,created_at) VALUES (1,'Nome Antigo','demo','old@example.invalid',1,1,'2026-09-28'),(2,'Outra Conta','other','other@example.invalid',2,1,'2026-09-28')");
$_SESSION=['legacy_admin'=>true];
$GLOBALS['testEmails']=[];
updateUserDetails(1,'Nome Novo','old@example.invalid','Nome Antigo','old@example.invalid');
check(count($GLOBALS['testEmails'])===1 && str_contains($GLOBALS['testEmails'][0]['text'],'Nome anterior: Nome Antigo'), 'nome da conta avisa o titular');
updateUserDetails(1,'Nome Novo','old@example.invalid','Nome Novo','old@example.invalid');
check(count($GLOBALS['testEmails'])===1,'sem mudanca nao envia aviso');
$pdo->exec("INSERT INTO user_invites(user_id,token_hash,expires_at,created_at) VALUES (1,'fictitious','2099-01-01','2026-09-28')");
updateUserDetails(1,'Nome Novo','new@example.invalid','Nome Novo','old@example.invalid');
$recipients=array_column(array_slice($GLOBALS['testEmails'],1),'recipient');sort($recipients);
check($recipients===['new@example.invalid','old@example.invalid'],'troca de email avisa antigo e novo');
check($pdo->query("SELECT used_at FROM user_invites WHERE token_hash='fictitious'")->fetchColumn()!==null,'troca de email cancela convite antigo');
foreach ([['Outra Coisa','other@example.invalid','Nome Novo','new@example.invalid'],['Outra Coisa','invalid','Nome Novo','new@example.invalid'],['Outra Coisa','next@example.invalid','Nome Antigo','old@example.invalid']] as $args) {
    try { updateUserDetails(1,...$args); throw new LogicException('Edicao deveria falhar'); } catch (InvalidArgumentException $expected) {}
}
check(count($GLOBALS['testEmails'])===3,'email duplicado, invalido e edicao desatualizada nao enviam aviso');
$_SESSION=['user_id'=>1];
try { updateUserDetails(2,'Intruso','bad@example.invalid','Outra Conta','other@example.invalid'); throw new LogicException('Permissao incorreta'); } catch (RuntimeException $expected) {}
check($pdo->query('SELECT name FROM users WHERE id=2')->fetchColumn()==='Outra Conta','botonista nao edita contas');
renamePlayer(1,'Nome de mesa','Teste A');
check(count(array_filter($GLOBALS['testEmails'],fn($e)=>$e['recipient']==='new@example.invalid'))===2,'nome de botonista avisa conta vinculada');
$_SESSION=['legacy_admin'=>true];
$GLOBALS['testSmtpFailure']=true;
updateUserDetails(1,'Nome Salvo','new@example.invalid','Nome Novo','new@example.invalid');
check($pdo->query('SELECT name FROM users WHERE id=1')->fetchColumn()==='Nome Salvo','falha SMTP preserva nome salvo');
check((int)$pdo->query("SELECT COUNT(*) FROM email_notifications WHERE audience='account' AND status='failed'")->fetchColumn()===1,'falha registrada para reenvio');
$GLOBALS['testSmtpFailure']=false;
$prior=count($GLOBALS['testEmails']);
$pdo->exec("CREATE TRIGGER block_account_audit BEFORE INSERT ON audit_log BEGIN SELECT RAISE(ABORT,'test'); END");
try { updateUserDetails(1,'Nao Salvar','new@example.invalid','Nome Salvo','new@example.invalid'); } catch (PDOException $expected) {}
check($pdo->query('SELECT name FROM users WHERE id=1')->fetchColumn()==='Nome Salvo' && count($GLOBALS['testEmails'])===$prior,'rollback nao altera conta nem envia aviso');
$pdo->exec('DROP TRIGGER block_account_audit');
auditedTransaction(static function (): void {
    auditRecord('Senha definida e conta ativada','Usuário 2',[],['senha_definida'=>true], 'Convite','Conta de teste');
});
$emails=array_values(array_filter($GLOBALS['testEmails'],fn($e)=>$e['recipient']==='other@example.invalid'));
$email=end($emails);
check($email['recipient']==='other@example.invalid' && str_contains($email['text'],'senha foi definida'), 'senha notifica inclusive conta sem emissor autenticado');
check(!str_contains($email['text'],'token_hash') && !str_contains($email['text'],'password_hash'),'aviso nao inclui segredos');
