<?php
declare(strict_types=1);
session_start();
require dirname(__DIR__).'/db.php';
require dirname(__DIR__).'/complaints.php';
initialiseDatabase(); initialiseComplaints();
header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer'); header('X-Content-Type-Options: nosniff'); header('X-Robots-Tag: noindex, nofollow');
function ch(mixed $v): string { return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function cp(string $key): string { return is_string($_POST[$key]??null)?$_POST[$key]:''; }
$csrf=$_SESSION['complaint_csrf']??=bin2hex(random_bytes(32)); $error=''; $notice=$_SESSION['complaint_notice']??''; unset($_SESSION['complaint_notice']);
if (isset($_GET['token'])) {
    $token=is_string($_GET['token'])?$_GET['token']:''; $mode=$_GET['mode']??'';
    if ($mode==='confirm' && preg_match('/^[a-f0-9]{64}$/D',$token)) {
        $_SESSION['complaint_confirm']=$token; header('Location: denuncia.php?confirm=1'); exit;
    }
    $row=is_string($mode)?complaintAccess($mode,$token):null;
    if (!$row) { http_response_code(403); $error='Link inválido ou expirado. Solicite orientação à organização.'; }
    else { session_regenerate_id(true); $_SESSION['complaint_access'][$row['id']]=['role'=>$mode,'hash'=>hash('sha256',$token)]; header('Location: denuncia.php?case='.$row['id']); exit; }
}
$id=is_string($_GET['case']??null)?$_GET['case']:''; $case=null; $role='';
if ($id!=='') {
    $grant=$_SESSION['complaint_access'][$id]??null;
    try { $case=complaintGet($id); } catch (Throwable $e) {}
    $role=$grant['role']??'';
    if (!$case || !in_array($role,['author','defense'],true) || !hash_equals((string)($case[$role.'_hash']??''),(string)($grant['hash']??'')) || ($case[$role.'_until']??0)<time()) { http_response_code(403); exit('Acesso reservado. Use o link recebido por e-mail ou procure a organização se ele expirou.'); }
    if (isset($_GET['file'])) complaintDownload($id,is_string($_GET['file'])?$_GET['file']:'',$role);
}
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if (!hash_equals($csrf,cp('csrf'))) throw new RuntimeException('A página expirou. Recarregue antes de enviar.');
        if (cp('action')==='confirm') {
            $row=complaintConfirm($_SESSION['complaint_confirm']??''); unset($_SESSION['complaint_confirm']);
            $row=complaintGet($row['id']); session_regenerate_id(true);
            $_SESSION['complaint_access'][$row['id']]=['role'=>'author','hash'=>$row['author_hash']];
            $_SESSION['complaint_notice']='E-mail confirmado. Guarde seu protocolo e o link de acompanhamento enviado por e-mail.';
            header('Location: denuncia.php?case='.$row['id']); exit;
        }
        $files=complaintUploads($_FILES['anexos']??[]);
        if ($case) {
            complaintReply($id,$role,cp('mensagem'),$files,$grant['hash']);
            $_SESSION['complaint_notice']='Mensagem registrada. A organização foi avisada.';
            header('Location: denuncia.php?case='.$id); exit;
        }
        complaintCreate($_POST,$files,$_SERVER['REMOTE_ADDR']??'unknown');
        $_SESSION['complaint_notice']='Registro recebido. Confira seu e-mail e a pasta de spam: confirme pelo link em até 48 horas para encaminhar a denúncia. Se a mensagem não chegar, procure a organização. Não é preciso criar uma conta.';
        header('Location: denuncia.php?sent=1'); exit;
    } catch (PDOException $e) { $error='Não foi possível concluir agora. Tente novamente em instantes.'; }
    catch (RuntimeException $e) { $error=$e->getMessage(); }
    catch (Throwable $e) { $error='Não foi possível concluir agora. Tente novamente em instantes.'; }
}
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Canal reservado · Copa Lago de Pedra</title><link rel="stylesheet" href="admin.css"><link rel="stylesheet" href="complaints.css"></head><body><main class="complaint-shell"><a href="index.php">← Área pública</a><h1>Canal de denúncias</h1><p>Um espaço reservado para relatar situações que precisam ser apuradas. O relato não significa culpa e não muda resultados automaticamente.</p>
<?php if ($error): ?><p class="error" role="alert"><?= ch($error) ?></p><?php endif ?><?php if ($notice): ?><p class="flash" role="status"><?= ch($notice) ?></p><?php endif ?>
<?php if (isset($_GET['confirm'])): ?>
<h2>Confirme seu e-mail</h2><p>Ao confirmar, seu registro será encaminhado à administração. Se você não enviou uma denúncia, feche esta página.</p><form method="post"><input type="hidden" name="csrf" value="<?= ch($csrf) ?>"><input type="hidden" name="action" value="confirm"><button class="button button-primary">Confirmar e encaminhar</button></form>
<?php elseif ($case): ?>
<h2>Protocolo <?= ch($case['protocol']) ?></h2><p><strong><?= ch(complaintStatus($case['status'])) ?></strong></p>
<?php if ($role==='author'): ?><h3>Seu relato original</h3><p><?= ch($case['subject']) ?></p><div class="complaint-text"><?= ch($case['description']) ?></div><?php else: ?><h3>Pedido de esclarecimentos</h3><div class="complaint-text"><?= ch($case['shared_summary']) ?></div><p>Prazo para responder: <?= ch(date('d/m/Y H:i',$case['defense_until'])) ?>. Apresente sua versão e evidências. Este convite não representa uma conclusão sobre o caso.</p><?php endif ?>
<h3>Comunicações</h3><?php foreach (complaintQuery("SELECT * FROM complaint_events WHERE complaint_id=? AND audience IN (?, 'both') ORDER BY occurred_at,rowid",[$id,$role])->fetchAll() as $event): ?><article class="complaint-event"><small><?= ch(date('d/m/Y H:i',$event['occurred_at'])) ?> · <?= ch($event['actor']) ?></small><div class="complaint-text"><?= ch($event['message']) ?></div></article><?php endforeach ?>
<h3>Anexos disponíveis</h3><?php foreach (complaintQuery("SELECT id,name FROM complaint_files WHERE complaint_id=? AND audience IN (?, 'both')",[$id,$role])->fetchAll() as $file): ?><p><a href="?case=<?= ch($id) ?>&amp;file=<?= ch($file['id']) ?>">Baixar <?= ch($file['name']) ?></a></p><?php endforeach ?>
<?php if (in_array($case['status'],['analysis','defense','answered'],true)): ?><h3><?= $role==='defense'?'Apresentar defesa ou complemento':'Enviar esclarecimento' ?></h3><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?= ch($csrf) ?>"><label>Mensagem<textarea name="mensagem" minlength="10" maxlength="10000" required rows="6"></textarea></label><label>Anexos opcionais — até dois por envio, 2 MB cada (PDF, JPG ou PNG)<input name="anexos[]" type="file" accept=".pdf,.jpg,.jpeg,.png" multiple></label><button class="button button-primary">Enviar mensagem</button></form><?php endif ?>
<?php elseif (!isset($_GET['sent']) && !$error || ($_SERVER['REQUEST_METHOD']==='POST' && !$case && !isset($_GET['confirm']))): ?>
<p>Não precisa de conta. <strong>Nome, e-mail, telefone com DDD e relato são obrigatórios.</strong> O e-mail será confirmado por link; o telefone será usado apenas para contato, sem confirmação por código.</p>
<form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?= ch($csrf) ?>"><div class="complaint-trap" aria-hidden="true"><label>Deixe vazio<input name="website" tabindex="-1" autocomplete="off"></label></div>
<label>Nome completo<input name="nome" autocomplete="name" maxlength="150" required value="<?= ch(cp('nome')) ?>"></label><label>E-mail<input type="email" name="email" autocomplete="email" maxlength="254" required value="<?= ch(cp('email')) ?>"></label><label>Telefone com DDD<input type="tel" name="telefone" autocomplete="tel" maxlength="30" required placeholder="(00) 00000-0000" value="<?= ch(cp('telefone')) ?>"></label><label>Jogador ou partida envolvida<input name="envolvidos" maxlength="300" required placeholder="Informe nomes e, se souber, rodada e jogo" value="<?= ch(cp('envolvidos')) ?>"></label><label>O que aconteceu?<textarea name="relato" minlength="30" maxlength="10000" required rows="8" placeholder="Descreva os fatos, quando e onde ocorreram e o que precisa ser apurado."><?= ch(cp('relato')) ?></textarea></label><label>Evidências opcionais — até dois arquivos de 2 MB (PDF, JPG ou PNG)<input type="file" name="anexos[]" accept=".pdf,.jpg,.jpeg,.png" multiple></label><p>O conteúdo não será publicado no site. A administração terá acesso aos contatos e poderá compartilhar com a pessoa citada as informações e evidências necessárias à defesa. Não há promessa de anonimato. Não inclua dados de terceiros que não sejam necessários ao relato.</p><label class="complaint-consent"><input type="checkbox" name="consentimento" value="1" required> Li e concordo com o uso destas informações para apuração e contato.</label><button class="button button-primary">Enviar para confirmar meu e-mail</button></form>
<?php endif ?><?= copaHelpFooter() ?></main></body></html>
