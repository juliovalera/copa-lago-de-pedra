<?php
declare(strict_types=1);
session_start();
require dirname(__DIR__).'/db.php';
require dirname(__DIR__).'/export_ui.php';
initialiseDatabase(); requirePanelAccess();
if (!hasFullAccess()) { http_response_code(403); exit('Acesso exclusivo da administração.'); }
header('Cache-Control: no-store'); header('X-Robots-Tag: noindex, nofollow'); header('Referrer-Policy: no-referrer');
function h(mixed $v): string { return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
$csrf=$_SESSION['withdrawal_csrf']??=bin2hex(random_bytes(32)); $error=''; $preview=null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if (!is_string($_POST['csrf']??null)||!hash_equals($csrf,$_POST['csrf'])) { http_response_code(400); throw new InvalidArgumentException('Solicitação inválida. Reabra a página.'); }
        if (($_POST['action']??'')==='preview') {
            $ids=is_array($_POST['players']??null)?array_filter($_POST['players'],'is_scalar'):[];
            $restore=is_string($_POST['restore']??null)?$_POST['restore']:'';
            $reason=is_string($_POST['reason']??null)?trim($_POST['reason']):'';
            if ($reason==='' || strlen($reason)>3000) throw new InvalidArgumentException('Informe uma justificativa de até 3000 bytes.');
            db()->beginTransaction();
            try { $preview=withdrawalPreview($ids,$restore); db()->commit(); }
            catch (Throwable $e) { if(db()->inTransaction())db()->rollBack(); throw $e; }
            $_SESSION['withdrawal_preview']=['snapshot'=>$preview,'reason'=>$reason,'nonce'=>bin2hex(random_bytes(24)),'time'=>time()];
        } elseif (($_POST['action']??'')==='confirm') {
            $saved=$_SESSION['withdrawal_preview']??null;
            if (!$saved || time()-$saved['time']>1800 || !is_string($_POST['nonce']??null) || !hash_equals($saved['nonce'],$_POST['nonce'])) throw new InvalidArgumentException('Prévia expirada ou já confirmada. Gere uma nova prévia.');
            $p=$saved['snapshot'];
            $id=withdrawalApply($p['ids'],$p['restore'],$p['hash'],$saved['reason']);
            unset($_SESSION['withdrawal_preview']);
            header('Location: desistencias.php?id='.$id); exit;
        } else throw new InvalidArgumentException('Ação inválida.');
    } catch (InvalidArgumentException $e) { $error=$e->getMessage(); }
    catch (Throwable $e) { $error='Não foi possível concluir. Confira os dados e tente novamente. Nenhuma alteração parcial foi aplicada.'; }
}
$id=is_string($_GET['id']??null)?$_GET['id']:''; $op=null; $snapshot=null;
if ($id==='preview' && isset($_SESSION['withdrawal_preview'])) {
    $saved=$_SESSION['withdrawal_preview'];
    $snapshot=$saved['snapshot'];
    $op=['id'=>'previa','kind'=>$snapshot['restore']?'restore':'withdraw','reason'=>$saved['reason'],'actor'=>auditActor(),'created_at'=>gmdate('c')];
} elseif ($id!=='') {
    try { $op=withdrawalOperation($id); $snapshot=json_decode($op['snapshot'],true,512,JSON_THROW_ON_ERROR); }
    catch (Throwable $e) { http_response_code(404); exit('Relatório indisponível.'); }
}
if (isset($_GET['format'])) {
    if (!$op || !$snapshot) { http_response_code(404); exit('Escolha uma operação.'); }
    if ($_GET['format']==='pdf') { header('Content-Type: application/json; charset=utf-8'); echo json_encode(withdrawalReport($op,$snapshot),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); exit; }
    if ($_GET['format']==='backup' && $id!=='preview') {
        header('Content-Type: application/json; charset=utf-8'); header('Content-Disposition: attachment; filename="backup-desistencia-'.$op['id'].'.json"');
        echo json_encode(['format'=>'copa-withdrawal-backup-v1','operation'=>$op,'snapshot_sha256'=>hash('sha256',$op['snapshot'])],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); exit;
    }
    http_response_code(400); exit('Formato inválido.');
}
if ($preview) {
    $snapshot=$preview; $id='preview';
    $op=['id'=>'prévia','kind'=>$preview['restore']?'restore':'withdraw','reason'=>$_SESSION['withdrawal_preview']['reason'],'actor'=>auditActor(),'created_at'=>gmdate('c')];
}
$players=db()->query('SELECT p.*,w.operation_id FROM players p LEFT JOIN withdrawn_players w ON w.player_id=p.id ORDER BY p.name')->fetchAll();
$operations=db()->query('SELECT o.id,o.kind,o.created_at,o.actor,o.reason,(SELECT id FROM withdrawal_operations r WHERE r.restores_id=o.id ORDER BY rowid DESC LIMIT 1) AS restored_by,EXISTS(SELECT 1 FROM withdrawn_players w WHERE w.operation_id=o.id) AS is_active FROM withdrawal_operations o ORDER BY o.created_at DESC,o.rowid DESC')->fetchAll();
$metrics=['position'=>'Posição','points'=>'Pontos','played'=>'Jogos','wins'=>'Vitórias','draws'=>'Empates','losses'=>'Derrotas','goalsFor'=>'Gols marcados','goalsAgainst'=>'Gols sofridos','goalDifference'=>'Saldo'];
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Desistências · Copa Lago de Pedra</title><link rel="stylesheet" href="admin.css"><link rel="stylesheet" href="withdrawals.css"><?= exportAssets() ?></head><body><main class="admin"><header class="admin-header"><div><p class="eyebrow">ADMINISTRAÇÃO</p><h1>Desistências</h1><p>Anulação no turno e returno, com registros originais preservados.</p></div><nav class="header-actions"><a class="button button-light" href="admin.php">Jogos</a><a class="button button-outline" href="desistencias.php">Nova operação</a></nav></header>
<?php if($error): ?><p class="error" role="alert"><?= h($error) ?></p><?php endif ?>
<?php if($snapshot && $op): ?>
<section class="admin-card"><h2><?= $id==='preview'?'Confira antes de confirmar':'Relatório da operação' ?></h2><p><strong><?= $op['kind']==='restore'?'Restauração de desistência':'Registro de desistência' ?></strong> · <?= h(date('d/m/Y H:i',strtotime($op['created_at']))) ?></p><p>Responsável: <?= h($op['actor']) ?><br>Motivo: <?= h($op['reason']) ?></p><p><strong>Participantes selecionados:</strong> <?php foreach(array_merge($snapshot['before']['players'],$snapshot['before']['withdrawnPlayers']) as $p) if(in_array((int)substr($p['id'],1),$snapshot['ids'],true)): ?><span><?= h($p['name']) ?>; </span><?php endif ?></p><p><?= count($snapshot['games']) ?> jogos no backup específico. Nenhum placar, súmula ou anexo é apagado. Na restauração, jogos contra outros desistentes continuam fora dos cálculos.</p>
<form class="export-form" action="desistencias.php" method="get"><input type="hidden" name="id" value="<?= h($id) ?>"><label>Formato<select name="format"><option value="pdf">PDF com logos — antes e depois</option></select></label><p class="export-status" role="status"></p><a class="export-ready" hidden>Salvar arquivo preparado</a><button class="button button-primary" type="submit">Baixar relatório PDF</button></form>
<?php if($id!=='preview'): ?><p><a class="button button-outline-dark" href="desistencias.php?id=<?= h($id) ?>&amp;format=backup">Baixar backup específico (JSON)</a></p><p>O backup inclui os jogos originais e a comparação desta operação. Para desfazer, use a restauração abaixo; não importe este arquivo sobre o banco.</p><?php endif ?>
</section><section class="admin-grid" aria-label="Comparação dos participantes">
<?php foreach($snapshot['rows'] as $row): ?><article class="admin-card"><h2><?= h($row['name']) ?></h2><p><?= $row['direct']?'Diretamente afetado pelos confrontos':'Mudança decorrente da classificação' ?></p><?php if($row['after']===null): ?><p><strong>Desistente — fora da classificação.</strong></p><?php endif ?><table class="withdrawal-comparison"><thead><tr><th>Número</th><th>Antes</th><th>Depois</th><th>Dif.</th></tr></thead><tbody><?php foreach($metrics as $key=>$label): ?><tr><th scope="row"><?= h($label) ?></th><td><?= h($row['before'][$key]??'—') ?></td><td><?= h($row['after'][$key]??'—') ?></td><td><?= $row['before']===null||$row['after']===null?'—':h(sprintf('%+d',$row['after'][$key]-$row['before'][$key])) ?></td></tr><?php endforeach ?></tbody></table><small>Na posição, diferença negativa significa subida.</small></article><?php endforeach ?></section>
<section class="admin-card"><details><summary>Ver jogos preservados no backup</summary><ul class="withdrawal-games"><?php foreach($snapshot['games'] as $g): $played=$g['score_a']!==null&&$g['score_b']!==null; ?><li><strong>#<?= h($g['id']) ?> · <?= h($g['a']) ?> × <?= h($g['b']) ?></strong><br>Rodada <?= h($g['round_number']) ?> · <?= $g['turn_number']==1?'Turno':'Returno' ?> · Original: <?= $played?h($g['score_a'].' × '.$g['score_b']):'Sem resultado' ?> · <?= h($g['played_at']??'Sem data') ?><br>Após a operação: <?= gameExcluded($g,$snapshot['activeAfter'])?($played?'Anulado por desistência':'Cancelado por desistência'):'Volta a contar' ?></li><?php endforeach ?></ul></details></section>
<?php if($preview): ?><section class="admin-card"><h2>Confirmar decisão</h2><p>O backup específico e o relatório serão guardados junto com a operação. Será enviado um aviso consolidado aos participantes afetados com e-mail cadastrado.</p><form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="confirm"><input type="hidden" name="nonce" value="<?= h($_SESSION['withdrawal_preview']['nonce']) ?>"><button class="button button-primary" type="submit">Confirmar <?= $preview['restore']?'restauração':'desistência e recalcular' ?></button> <a class="button button-outline-dark" href="desistencias.php">Cancelar</a></form></section><?php endif ?>
<?php else: ?><section class="admin-card"><h2>Registrar nova desistência</h2><p>Escolha os participantes. Todos os confrontos deles sairão dos cálculos, inclusive os jogos já realizados. Primeiro será exibida uma prévia; marcar os nomes não altera resultados.</p><form class="user-form" method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="preview"><fieldset class="withdrawal-choices"><legend>Participantes</legend><?php foreach($players as $p): ?><label><input type="checkbox" name="players[]" value="<?= h($p['id']) ?>"<?= $p['operation_id']?' disabled':'' ?>><?= h($p['name']) ?><?= $p['operation_id']?' — desistente':'' ?></label><?php endforeach ?></fieldset><label>Justificativa<textarea name="reason" rows="3" maxlength="1000" required></textarea></label><button class="button button-primary" type="submit">Ver prévia dos impactos</button></form></section><?php endif ?>
<section class="admin-card"><h2>Histórico e restauração</h2><?php if(!$operations): ?><p>Nenhuma desistência registrada.</p><?php endif ?><?php foreach($operations as $operation): ?><article class="withdrawal-operation"><h3><?= $operation['kind']==='restore'?'Restauração':'Desistência' ?> · <?= h(date('d/m/Y H:i',strtotime($operation['created_at']))) ?></h3><p><?= h($operation['actor']) ?> · <?= h($operation['reason']) ?></p><a class="button button-outline-dark" href="desistencias.php?id=<?= h($operation['id']) ?>">Ver relatório e backup</a><?php if($operation['kind']==='withdraw' && $operation['is_active']): ?><details><summary>Restaurar esta desistência</summary><p>Somente esta operação será desfeita. Outras desistências continuarão vigentes. Você verá uma prévia antes de confirmar.</p><form class="user-form" method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="preview"><input type="hidden" name="restore" value="<?= h($operation['id']) ?>"><label>Motivo da restauração<textarea name="reason" rows="2" maxlength="1000" required></textarea></label><button class="button button-outline-dark" type="submit">Ver prévia da restauração</button></form></details><?php elseif($operation['restored_by']): ?><p>Restaurada. <a href="desistencias.php?id=<?= h($operation['restored_by']) ?>">Ver restauração</a></p><?php endif ?></article><?php endforeach ?></section><?= copaHelpFooter() ?></main></body></html>
