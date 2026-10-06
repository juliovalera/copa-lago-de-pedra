<?php
declare(strict_types=1);
session_start();
require dirname(__DIR__).'/db.php'; require dirname(__DIR__).'/attachments.php';
initialiseDatabase(); requirePanelAccess(); initialiseAttachments();
header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer'); header('X-Content-Type-Options: nosniff'); header('X-Robots-Tag: noindex, nofollow');
function h(mixed $v): string { return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
if (isset($_GET['file'])) {
    try {
        $row=attachmentGet(is_string($_GET['file'])?$_GET['file']:'');
        $path=attachmentPath($row['id']); attachmentVerify($row,$path);
        $ext=['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png'][$row['mime']]??null;
        if (!$ext) throw new RuntimeException('Tipo de arquivo inválido.');
        header('Content-Type: '.$row['mime']);
        header('Content-Security-Policy: sandbox; default-src \'none\'; img-src \'self\' data:; style-src \'unsafe-inline\'');
        header('Content-Disposition: '.(isset($_GET['download'])?'attachment':'inline').'; filename="sumula-jogo-'.(int)$row['game_id'].'.'.$ext.'"');
        header('Content-Length: '.$row['size']);
        $handle=fopen($path,'rb'); fseek($handle,strlen(ATTACHMENT_PREFIX)); fpassthru($handle); fclose($handle); exit;
    } catch (Throwable $e) { http_response_code(404); exit('Anexo indisponível para esta conta.'); }
}
$gameId=(int)($_GET['game']??0);
try { $game=attachmentGame($gameId); } catch (InvalidArgumentException $e) { http_response_code(403); exit(h($e->getMessage())); }
$csrf=$_SESSION['attachment_csrf']??=bin2hex(random_bytes(32)); $error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if (!is_string($_POST['csrf']??null) || !hash_equals($csrf,$_POST['csrf'])) throw new InvalidArgumentException('Solicitação inválida. Reabra a página.');
        if (($_POST['action']??'')==='remove') {
            $row=attachmentGet(is_string($_POST['id']??null)?$_POST['id']:'');
            if ((int)$row['game_id']!==$gameId) throw new InvalidArgumentException('Anexo de outro jogo.');
            attachmentRemove($row['id']);
        } elseif (($_POST['action']??'')==='upload') {
            attachmentUpload($gameId,$_FILES['file']??[],is_string($_POST['replace']??null)?$_POST['replace']:'');
        } else throw new InvalidArgumentException('Escolha uma ação válida.');
        header('Location: anexos.php?game='.$gameId.'&saved=1'); exit;
    } catch (InvalidArgumentException $e) { $error=$e->getMessage(); }
    catch (Throwable $e) { $error='Não foi possível guardar a alteração. Confira o espaço e as permissões da pasta de anexos com a administração.'; }
}
$q=db()->prepare('SELECT * FROM game_attachments WHERE game_id=? AND removed_at IS NULL ORDER BY created_at,id'); $q->execute([$gameId]);
$rows=array_values(array_filter($q->fetchAll(),static fn($r)=>hasFullAccess() || in_array((int)currentUser()['player_id'],[(int)$r['player_a_id'],(int)$r['player_b_id']],true)));
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Anexos da súmula</title><link rel="stylesheet" href="admin.css"><link rel="stylesheet" href="attachments.css?v=<?= copaVersion() ?>"></head><body><main class="admin attachments-page"><a class="button button-outline-dark" href="admin.php">← Painel</a><h1>Anexos da súmula</h1><h2><?= h($game['a']) ?> × <?= h($game['b']) ?></h2><p>Rodada <?= h($game['round_number']) ?> · Jogo <?= h($game['game_number']) ?></p><p>Envie uma foto ou PDF da súmula. Até dois arquivos de 2 MB por jogo, por exemplo frente e verso. Anexar não altera o placar nem a data.</p>
<?php if ($error): ?><p class="error" role="alert"><?= h($error) ?></p><?php elseif (isset($_GET['saved'])): ?><p class="flash" role="status">Alteração dos anexos salva.</p><?php endif ?>
<section aria-label="Arquivos anexados"><?php if (!$rows): ?><p>Nenhuma cópia anexada.</p><?php endif ?><?php foreach ($rows as $row): ?><article class="admin-card"><h3><?= h($row['filename']) ?></h3><p><?= h(round($row['size']/1024)) ?> KB · Enviado em <?= h(date('d/m/Y H:i',strtotime($row['created_at']))) ?> por <?= h($row['created_by']) ?></p><div class="attachment-actions"><a class="button button-outline-dark" href="?file=<?= h($row['id']) ?>" target="_blank" rel="noopener">Visualizar</a><a class="button button-outline-dark" href="?file=<?= h($row['id']) ?>&amp;download=1">Baixar</a><?php if (hasFullAccess()): ?><form method="post" onsubmit="return confirm('Remover este anexo da consulta do jogo?');"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= h($row['id']) ?>"><button class="button button-danger" name="action" value="remove">Remover</button></form><?php endif ?></div></article><?php endforeach ?></section>
<?php if (count($rows)<2 || hasFullAccess()): ?><section class="admin-card"><h2><?= $rows?(hasFullAccess()?'Adicionar ou substituir arquivo':'Adicionar arquivo'):'Anexar súmula' ?></h2><form class="attachment-upload" method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="MAX_FILE_SIZE" value="2097152"><label>Arquivo (PDF, JPG ou PNG, até 2 MB)<input type="file" name="file" accept="application/pdf,image/jpeg,image/png" required></label><?php if (hasFullAccess() && $rows): ?><label>Destino<select name="replace"><?php if (count($rows)<2): ?><option value="">Adicionar novo anexo</option><?php endif ?><?php foreach ($rows as $r): ?><option value="<?= h($r['id']) ?>">Substituir <?= h($r['filename']) ?></option><?php endforeach ?></select></label><?php endif ?><button class="button button-primary" name="action" value="upload">Enviar arquivo</button></form></section><?php endif ?>
<p>Somente os participantes desta partida e administradores podem consultar os anexos. Para corrigir um envio, procure a administração.</p><?php if (hasFullAccess()): ?><p>Remover ou substituir retira o arquivo da consulta. As versões anteriores são conservadas na pasta protegida e nos backups, para preservar o histórico.</p><?php endif ?><?= copaHelpFooter() ?></main></body></html>
