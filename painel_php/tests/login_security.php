<?php
declare(strict_types=1);
require dirname(__DIR__) . '/db.php';
$databaseConnection = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$databaseConnection->exec('PRAGMA foreign_keys=ON');
initialiseDatabase();
$pdo=db();
$password='senha-apenas-do-teste';
$insert=$pdo->prepare('INSERT INTO users(name,username,email,password_hash,is_active,created_at) VALUES (?,?,?,?,?,?)');
foreach ([['Ana','ana','ana@example.invalid',1],['Beto','beto','beto@example.invalid',1],['Inativo','inativo','inativo@example.invalid',0]] as [$name,$login,$email,$active]) $insert->execute([$name,$login,$email,password_hash($password,PASSWORD_DEFAULT),$active,date('c')]);
function check(bool $ok,string $label): void { if (!$ok) throw new RuntimeException($label); echo "OK: $label\n"; }
$_SERVER['REMOTE_ADDR']='192.0.2.10';
for ($i=1;$i<=5;$i++) {
    $result=authenticateWithLimit($i%2 ? 'Ana' : 'ana@example.invalid','errada',$i%2 ? 'Painel' : 'Súmula');
    check(!$result['user'] && $result['retry_after']===($i===5 ? 900 : 0), 'Tentativa ' . $i . ' respeita o limite compartilhado');
}
$deadline=$pdo->query('SELECT blocked_until FROM login_limits')->fetchColumn();
check(!authenticateWithLimit('ana',$password,'Painel')['user'], 'senha correta não ignora bloqueio ativo');
$_SERVER['HTTP_X_FORWARDED_FOR']='192.0.2.99';
check(!authenticateWithLimit('ana',$password,'Súmula')['user'],'cabeçalho de IP fornecido pelo visitante não contorna o bloqueio');
check($pdo->query('SELECT blocked_until FROM login_limits')->fetchColumn()===$deadline,'tentativa bloqueada não prolonga o prazo');
check(authenticateWithLimit('beto',$password,'Painel')['user']!==null,'outra conta no mesmo IP permanece livre');
$_SERVER['REMOTE_ADDR']='192.0.2.11';
check(authenticateWithLimit('ana',$password,'Painel')['user']!==null,'a conta permanece acessível por outra origem');
$_SERVER['REMOTE_ADDR']='192.0.2.10';
$pdo->exec('UPDATE login_limits SET blocked_until=' . (time()-1));
check(authenticateWithLimit('ana',$password,'Painel')['user']!==null,'prazo encerrado libera automaticamente');
check((int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='Bloqueio encerrado'")->fetchColumn()===1,'fim do bloqueio registrado na próxima tentativa');
authenticateWithLimit('ana','errada','Painel');
$pdo->prepare('UPDATE login_limits SET failures_json=?')->execute([json_encode(array_fill(0,4,time()-901))]);
check(authenticateWithLimit('ana','errada','Painel')['retry_after']===0,'erros fora da janela não contam');
check(authenticateWithLimit('ana',$password,'Painel')['user']!==null && (int)$pdo->query('SELECT COUNT(*) FROM login_limits')->fetchColumn()===0,'sucesso limpa o contador daquele acesso');
$unknown=authenticateWithLimit('nao-existe','errada','Painel');
$disabled=authenticateWithLimit('inativo',$password,'Painel');
check($unknown['message']===$disabled['message'] && !$disabled['user'],'conta inexistente e desativada recebem mensagem genérica');
$pdo->exec("INSERT INTO users(name,username,email,is_active,created_at) VALUES ('Sem senha','sem-senha','sem-senha@example.invalid',1,'2026-09-27')");
check(!authenticateWithLimit('sem-senha','password','Painel')['user'],'conta sem senha definida não usa o hash fictício como credencial');
$rows=$pdo->query('SELECT * FROM audit_log')->fetchAll();
check(!str_contains(json_encode($rows),$password) && !str_contains(json_encode($rows),'errada'),'senhas não entram na auditoria');
check(str_contains(json_encode($rows),'192.0.2.10'),'origem registrada');
$pdo->exec("CREATE TRIGGER fail_login_log BEFORE INSERT ON audit_log BEGIN SELECT RAISE(ABORT,'teste'); END");
$failed=false;
try { authenticateWithLimit('beto',$password,'Painel'); } catch (PDOException $e) { $failed=true; }
check($failed,'falha de auditoria não libera autenticação sem registro');
