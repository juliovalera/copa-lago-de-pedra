<?php
declare(strict_types=1);
session_start();
require dirname(__DIR__).'/db.php';
require dirname(__DIR__).'/confirmations.php';
initialiseDatabase(); requirePanelAccess();
if (!hasFullAccess()) { http_response_code(403); exit('Acesso exclusivo da administração.'); }
initialiseConfirmations();
header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer'); header('X-Robots-Tag: noindex, nofollow');
function h(mixed $v): string { return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
function confirmationTime(?string $v): string { return $v ? date('d/m/Y H:i',strtotime($v)) : '—'; }
$csrf=$_SESSION['confirmation_csrf']??=bin2hex(random_bytes(32)); $error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if (!is_string($_POST['csrf']??null) || !hash_equals($csrf,$_POST['csrf'])) throw new InvalidArgumentException('Solicitação inválida. Reabra a página.');
        if (($_POST['action']??'')==='create') {
            $links=confirmationCreate((int)($_POST['game']??0),(int)($_POST['days']??7));
            $_SESSION['confirmation_links']=$links; $id=$links['id'];
        } else {
            $id=is_string($_POST['id']??null)?$_POST['id']:'';
            confirmationAdminAction($id,(string)($_POST['action']??''));
        }
        header('Location: confirmacoes.php?id='.rawurlencode($id)); exit;
    } catch (InvalidArgumentException $e) { $error=$e->getMessage(); }
    catch (Throwable $e) { $error='Não foi possível salvar. Reabra a página e confira a situação antes de tentar novamente.'; }
}
$id=is_string($_GET['id']??null)?$_GET['id']:''; $row=null;
if ($id!=='') { try { $row=confirmationGet($id); } catch (InvalidArgumentException $e) { http_response_code(404); $error=$e->getMessage(); } }
$links=$_SESSION['confirmation_links']??null; unset($_SESSION['confirmation_links']);
$players=db()->query('SELECT id,name FROM players ORDER BY name')->fetchAll();
$dateQuery=db()->prepare("SELECT DISTINCT played_at FROM games WHERE score_a IS NOT NULL AND score_b IS NOT NULL AND played_at <= ? ORDER BY played_at DESC"); $dateQuery->execute([date('Y-m-d')]); $dates=$dateQuery->fetchAll(PDO::FETCH_COLUMN);
$p=(int)($_GET['player']??0); $o=(int)($_GET['opponent']??0); $date=is_string($_GET['date']??null)?$_GET['date']:'';
$games=[]; $total=0; $page=max(1,(int)($_GET['page']??1));
if (!$row) {
    $where=['g.score_a IS NOT NULL','g.score_b IS NOT NULL','g.played_at <= ?']; $args=[date('Y-m-d')];
    foreach ([$p,$o] as $v) if ($v) { $where[]='(g.player_a_id=? OR g.player_b_id=?)'; $args[]=$v; $args[]=$v; }
    if ($p && $p===$o) $where[]='0=1';
    if ($date!=='') { $where[]='g.played_at=?'; $args[]=$date; }
    $clause=implode(' AND ',$where); $q=db()->prepare('SELECT COUNT(*) FROM games g WHERE '.$clause); $q->execute($args); $total=(int)$q->fetchColumn();
    $page=min($page,max(1,(int)ceil($total/20)));
    $q=db()->prepare('SELECT g.*,a.name AS a,b.name AS b FROM games g JOIN players a ON a.id=g.player_a_id JOIN players b ON b.id=g.player_b_id WHERE '.$clause.' ORDER BY g.played_at DESC,g.id LIMIT 20 OFFSET '.(($page-1)*20)); $q->execute($args); $games=$q->fetchAll();
}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Confirmar partidas</title><link rel="stylesheet" href="admin.css"><link rel="stylesheet" href="digital.css"><link rel="stylesheet" href="confirmations.css?v=<?= copaVersion() ?>"><script src="confirmations.js?v=<?= copaVersion() ?>" defer></script></head><body><main class="admin digital-page">
<nav class="no-print"><a class="button button-outline-dark" href="admin.php">← Painel</a> <a class="button button-outline-dark" href="confirmacoes.php">Confirmar partidas</a></nav>
<h1>Confirmar partidas sem árbitro</h1><p class="no-print">Localize um jogo já registrado. Gere um link para cada jogador, envie em conversa privada e dê seu aval depois das duas assinaturas. O aval é da organização, sem indicar presença na partida.</p>
<?php if ($error): ?><p class="error" role="alert"><?= h($error) ?></p><?php endif ?>
<?php if ($row): $s=json_decode($row['snapshot'],true); ?>
<article class="admin-card"><h2><?= h($s['a']) ?> × <?= h($s['b']) ?></h2><p><strong>Placar: <?= h($s['score_a']) ?> × <?= h($s['score_b']) ?></strong> · <?= h(date('d/m/Y',strtotime($s['date']))) ?> · Rodada <?= h($s['round']) ?> · <?= $s['turn']==1?'Turno':'Returno' ?></p>
<p><strong><?= h(confirmationStatus($row)) ?></strong></p><p>Documento <?= h($row['id']) ?><br>Gerado por <?= h($row['creator_name']) ?> em <?= h(confirmationTime($row['created_at'])) ?>.<br>Links válidos até <?= h(date('d/m/Y H:i',(int)$row['expires_at'])) ?>.</p>
<?php if ($links && $links['id']===$row['id']): ?><section class="no-print"><h3>Copie e envie os links agora</h3><p>Por segurança, os links completos aparecem somente nesta tela após a geração. Envie cada um apenas ao jogador indicado. Se perder um link, cancele a coleta e gere novos links; serão necessárias duas novas assinaturas.</p><?php foreach (['a','b'] as $side): $url=rtrim(config()['base_url'],'/').'/assinar-partida.php?t='.$links[$side]; ?><label class="confirmation-label">Link de <?= h($s[$side]) ?><input id="link-<?= $side ?>" value="<?= h($url) ?>" readonly></label><button type="button" class="button button-outline-dark" data-copy="link-<?= $side ?>">Copiar link de <?= h($s[$side]) ?></button><?php endforeach ?><p id="copy-status" role="status"></p></section><?php endif ?>
<?php if ($row['divergence']): ?><p class="error">Divergência: <?= h($row['divergence']) ?></p><?php endif ?>
<div class="signature-grid"><?php foreach (['a','b'] as $side): ?><section><h3><?= h($s[$side]) ?></h3><?php if ($row['signature_'.$side]): ?><?= digitalSignatureSvg(json_decode($row['signature_'.$side],true)) ?><p>Assinou em <?= h(confirmationTime($row['signed_'.$side])) ?> pelo link individual.</p><?php else: ?><p>Aguardando assinatura.</p><?php endif ?></section><?php endforeach ?></div>
<?php if ($row['status']==='final'): ?><h3>Partida realizada sem árbitro — confirmada pelos dois jogadores</h3><p>Aval da organização: <?= h($row['approved_by']) ?> em <?= h(confirmationTime($row['approved_at'])) ?>.</p><a class="button button-primary no-print" href="sumula-confirmada.php?id=<?= h($row['id']) ?>">Abrir súmula / salvar PDF</a>
<?php elseif ($row['status']!=='cancelled'): ?><form method="post" class="no-print"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= h($id) ?>"><?php if ($row['creator_key']===confirmationKey() && $row['status']==='pending' && $row['signature_a'] && $row['signature_b'] && confirmationCurrent($row)): ?><button class="button button-primary" name="action" value="approve">Dar aval e concluir súmula</button><?php else: ?><p>O aval depende das duas assinaturas dos dados atuais e deve ser dado pelo administrador que gerou os links.</p><?php endif ?><button class="button button-outline-dark" name="action" value="cancel">Cancelar coleta e invalidar links</button></form><?php endif ?></article>
<?php else: ?>
<form method="get" class="confirmation-filters"><label>Botonista<select name="player"><option value="">Todos</option><?php foreach ($players as $v): ?><option value="<?= $v['id'] ?>"<?= $p===$v['id']?' selected':'' ?>><?= h($v['name']) ?></option><?php endforeach ?></select></label><label>Adversário<select name="opponent"><option value="">Todos</option><?php foreach ($players as $v): ?><option value="<?= $v['id'] ?>"<?= $o===$v['id']?' selected':'' ?>><?= h($v['name']) ?></option><?php endforeach ?></select></label><label>Data do jogo<select name="date"><option value="">Todas as datas</option><?php foreach ($dates as $v): ?><option value="<?= h($v) ?>"<?= $date===$v?' selected':'' ?>><?= h(date('d/m/Y',strtotime($v))) ?></option><?php endforeach ?></select></label><button class="button button-primary">Filtrar</button><a href="confirmacoes.php" class="button button-outline-dark">Limpar filtros</a></form>
<p><?= $total ?> jogo(s) · página <?= $page ?></p>
<?php foreach ($games as $g): ?><article class="admin-card"><h2><?= h($g['a']) ?> × <?= h($g['b']) ?></h2><p><?= h($g['score_a']) ?> × <?= h($g['score_b']) ?> · <?= h(date('d/m/Y',strtotime($g['played_at']))) ?> · Rodada <?= h($g['round_number']) ?></p>
<?php $q=db()->prepare('SELECT * FROM match_confirmations WHERE game_id=? ORDER BY created_at DESC,rowid DESC'); $q->execute([$g['id']]); foreach ($q as $r): ?><p><a href="confirmacoes.php?id=<?= h($r['id']) ?>">Abrir documento — <?= h(confirmationStatus($r)) ?></a></p><?php endforeach ?>
<form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="game" value="<?= h($g['id']) ?>"><label>Validade dos links<select name="days"><option value="7">7 dias</option><option value="3">3 dias</option><option value="14">14 dias</option><option value="30">30 dias</option></select></label><button class="button button-primary" name="action" value="create">Gerar links para os dois jogadores</button></form></article><?php endforeach ?>
<nav class="no-print"><?php if ($page>1): ?><a class="button button-outline-dark" href="?<?= h(http_build_query(['player'=>$p,'opponent'=>$o,'date'=>$date,'page'=>$page-1])) ?>">← Anterior</a><?php endif ?><?php if ($page*20<$total): ?><a class="button button-outline-dark" href="?<?= h(http_build_query(['player'=>$p,'opponent'=>$o,'date'=>$date,'page'=>$page+1])) ?>">Próxima →</a><?php endif ?></nav>
<?php endif ?><?= copaHelpFooter() ?></main></body></html>
