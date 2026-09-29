<?php
declare(strict_types=1);
session_start();
require dirname(__DIR__).'/db.php';
initialiseDatabase();
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
function h(mixed $v): string { return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
$token=is_string($_GET['t']??null)?$_GET['t']:'';
$gameId=(int)($_GET['game']??0); $date=is_string($_GET['date']??null)?$_GET['date']:'';
$sheet=null; $error=''; $readOnly=false;
$receipt=(int)($_GET['receipt']??0);
$document=(int)($_GET['document']??0);
try {
    if ($receipt || $document) {
        $q=db()->prepare("SELECT * FROM digital_sheets WHERE id=? AND status='final'"); $q->execute([$receipt?:$document]); $sheet=$q->fetch();
        $snapshot=$sheet?json_decode($sheet['data_json'],true):[];
        $user=currentUser();
        $allowed=$receipt ? !empty($_SESSION['digital_receipts'][$receipt]) : (hasFullAccess() || ($user && in_array((int)$user['player_id'],[(int)($snapshot['player_a_id']??0),(int)($snapshot['player_b_id']??0)],true)));
        if (!$sheet || !$allowed) throw new RuntimeException('Comprovante indisponível nesta sessão. Consulte o painel com uma conta autorizada.');
        $gameId=(int)$sheet['game_id']; $date=$sheet['match_date']; $readOnly=true;
    } else {
        if ($token!=='') {
            $link=validRefereeLink($token);
            if (!$link || $link['used_at']!==null) throw new RuntimeException('QR indisponível ou já utilizado. Consulte a organização.');
            $gameId=(int)$link['game_id']; $date=$link['generated_on'];
        }
        digitalContext($gameId,$date,$token);
        $sheet=digitalSheet($gameId,$date);
        $readOnly=($sheet['status']??'')==='final';
    }
} catch (RuntimeException $e) { http_response_code(403); exit(h($e->getMessage())); }
$game=gameById($gameId);
$csrf=$_SESSION['digital_csrf']??=bin2hex(random_bytes(32));
$values=$sheet?json_decode($sheet['data_json'],true):[];
$signatures=$readOnly?json_decode($sheet['signatures_json'],true):[];
$message=$_SESSION['digital_flash']??''; unset($_SESSION['digital_flash']);
$blocked=!$readOnly && (!$game || $game['score_a']!==null || $game['score_b']!==null || $date<date('Y-m-d'));
$action='sumula-digital.php?'.($token!==''?'t='.rawurlencode($token):http_build_query(['game'=>$gameId,'date'=>$date]));
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if (!is_string($_POST['csrf']??null) || !hash_equals($csrf,$_POST['csrf'])) throw new InvalidArgumentException('Solicitação inválida. Reabra a súmula.');
        if ($readOnly || $blocked) throw new ResultConflict('Esta súmula não pode mais ser alterada.');
        $mode=is_string($_POST['mode']??null)?$_POST['mode']:'';
        if (!in_array($mode,['draft','final'],true)) throw new InvalidArgumentException('Escolha salvar rascunho ou finalizar.');
        $saved=saveDigitalSheet($gameId,$date,$token,(int)($_POST['revision']??-1),is_string($_POST['result_token']??null)?$_POST['result_token']:'',$_POST,$mode==='final');
        if ($mode==='final') {
            $_SESSION['digital_receipts'][(int)$saved['id']]=true;
            header('Location: sumula-digital.php?receipt='.(int)$saved['id']); exit;
        }
        $_SESSION['digital_flash']='Rascunho salvo. Colete as assinaturas somente depois de conferir todos os dados.';
        header('Location: '.$action); exit;
    } catch (ResultConflict $e) {
        http_response_code(409); $error=$e->getMessage(); $blocked=true;
    } catch (InvalidArgumentException $e) {
        http_response_code(422); $error=$e->getMessage();
        foreach (['venue','time','table','referee','notes','first_a','first_b','second_a','second_b'] as $key) if (is_string($_POST[$key]??null)) $values[$key]=$_POST[$key];
        $error.=' As assinaturas precisam ser coletadas novamente após a conferência.';
    } catch (Throwable $e) { http_response_code(503); $error='Não foi possível salvar. Reabra a súmula e confira o rascunho antes de tentar novamente.'; $blocked=true; }
}
$a=$values['a']??$game['a']??''; $b=$values['b']??$game['b']??'';
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Súmula digital · Copa Lago de Pedra</title><link rel="stylesheet" href="admin.css"><link rel="stylesheet" href="digital.css?v=<?= filemtime(__DIR__.'/digital.css') ?>"><script src="digital.js?v=<?= filemtime(__DIR__.'/digital.js') ?>" defer></script></head>
<body><main class="admin digital-page"><header><p class="eyebrow">I COPA LAGO DE PEDRA</p><h1>Súmula digital<?= $readOnly?' — finalizada':'' ?></h1><h2><?= h($a) ?> × <?= h($b) ?></h2><p>Rodada <?= h($values['round']??$game['round_number']??'') ?> · <?= h(date('d/m/Y',strtotime($date))) ?></p></header>
<nav class="no-print header-actions"><a class="button button-outline-dark" href="sumulas-digitais.php">Minhas súmulas no painel</a><a class="button button-outline-dark" href="index.php">Área pública</a></nav>
<?php if ($message): ?><p class="flash" role="status"><?= h($message) ?></p><?php endif ?>
<?php if ($error): ?><p class="error" role="alert"><?= h($error) ?></p><?php endif ?>
<?php if ($readOnly): ?>
<section class="admin-card"><dl class="digital-info"><?php foreach (['venue'=>'Local','time'=>'Horário','table'=>'Mesa','referee'=>'Árbitro'] as $key=>$label): ?><div><dt><?= h($label) ?></dt><dd><?= h($values[$key]?:'Não informado') ?></dd></div><?php endforeach ?></dl>
<table class="digital-scores"><thead><tr><th>Registro</th><th><?= h($a) ?></th><th><?= h($b) ?></th></tr></thead><tbody><tr><th>1º tempo</th><td><?= h($values['first_a']) ?></td><td><?= h($values['first_b']) ?></td></tr><tr><th>2º tempo</th><td><?= h($values['second_a']) ?></td><td><?= h($values['second_b']) ?></td></tr><tr><th>Placar final</th><td><?= (int)$values['first_a']+(int)$values['second_a'] ?></td><td><?= (int)$values['first_b']+(int)$values['second_b'] ?></td></tr></tbody></table>
<h3>Ocorrências e observações</h3><p class="digital-notes"><?= h($values['notes']?:'Sem observações.') ?></p>
<div class="signature-grid"><?php foreach (['a'=>$a,'b'=>$b,'referee'=>'Árbitro: '.$values['referee']] as $who=>$name): ?><section><h3><?= h($name) ?></h3><?= digitalSignatureSvg($signatures[$who]) ?></section><?php endforeach ?></div>
<p>Finalizada em <?= h(date('d/m/Y H:i:s',strtotime($sheet['finalized_at']))) ?> (<?= h(config()['timezone']) ?>). Responsável pelo envio: <?= h($sheet['updated_by']) ?>.</p><p>Documento nº <?= (int)$sheet['id'] ?>. Esta ficha preserva o registro original. Correções posteriores no painel não alteram as assinaturas.</p>
<?php if ($game && ($game['score_a']!=(int)$values['first_a']+(int)$values['second_a'] || $game['score_b']!=(int)$values['first_b']+(int)$values['second_b'] || $game['played_at']!==$date)): ?><p class="error">O resultado atual do jogo difere desta ficha original. Consulte a organização e a auditoria.</p><?php endif ?>
</section><p class="no-print"><button class="button button-primary" type="button" id="digital-print">Imprimir / salvar em PDF</button></p><p class="no-print">Na janela de impressão do dispositivo, escolha “Salvar como PDF”, quando disponível.</p>
<?php elseif ($blocked): ?><p class="error">O jogo já foi registrado, a data passou ou houve alteração em outro acesso. Nenhum dado foi sobrescrito.</p><a class="button button-outline-dark" href="<?= h($action) ?>">Reabrir e conferir a súmula</a>
<?php else: ?>
<p>Preencha a ficha e confira com os jogadores. Você pode salvar um rascunho antes de coletar as assinaturas. Para salvar ou finalizar, mantenha conexão com a internet.</p>
<noscript><p class="error">Ative o JavaScript para desenhar as assinaturas. A opção de impressão com QR continua disponível.</p></noscript>
<form method="post" action="<?= h($action) ?>" id="digital-form" class="admin-card">
<input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="revision" value="<?= (int)($sheet['revision']??0) ?>"><input type="hidden" name="result_token" value="<?= h(panelResultToken($game)) ?>">
<div class="digital-info user-form">
<?php foreach (['venue'=>['Local','text',160],'time'=>['Horário','time',5],'table'=>['Mesa','text',40],'referee'=>['Nome do árbitro','text',100]] as $key=>[$label,$type,$max]): ?><label><?= h($label) ?><input name="<?= h($key) ?>" type="<?= h($type) ?>" maxlength="<?= $max ?>" value="<?= h($values[$key]??'') ?>"></label><?php endforeach ?>
</div><h3>Gols em cada tempo</h3><div class="digital-info user-form"><?php foreach (['first_a'=>'1º tempo — '.$a,'first_b'=>'1º tempo — '.$b,'second_a'=>'2º tempo — '.$a,'second_b'=>'2º tempo — '.$b] as $key=>$label): ?><label><?= h($label) ?><input name="<?= h($key) ?>" type="number" min="0" max="999" inputmode="numeric" value="<?= h($values[$key]??'') ?>"></label><?php endforeach ?></div>
<p class="digital-total" aria-live="polite">Placar final: <output id="total-a">—</output> × <output id="total-b">—</output></p>
<label class="digital-notes-label">Ocorrências / advertências / observações<textarea name="notes" maxlength="2000" rows="4"><?= h($values['notes']??'') ?></textarea></label>
<p><button class="button button-outline-dark" type="submit" name="mode" value="draft">Salvar rascunho</button></p>
<h3>Assinaturas</h3><p>Confira os dados antes de assinar. Alterar um campo limpa as assinaturas. Elas só ficam guardadas ao finalizar; salvar um rascunho não guarda assinaturas.</p>
<p id="signature-status" role="status"></p><div class="signature-grid">
<?php foreach (['a'=>$a,'b'=>$b,'referee'=>'Árbitro'] as $who=>$name): ?><fieldset><legend>Assinatura — <?= h($name) ?></legend><canvas width="1000" height="500" data-signature="<?= h($who) ?>" aria-label="Área para <?= h($name) ?> assinar com o dedo ou caneta. Para assinatura no papel, use a súmula impressa."></canvas><input type="hidden" name="signature_<?= h($who) ?>" value="[]"><button type="button" class="button button-outline-dark" data-clear="<?= h($who) ?>">Limpar assinatura</button></fieldset><?php endforeach ?>
</div><label class="digital-consent"><input type="checkbox" name="consent" value="1"> Conferimos a ficha. Os dois jogadores e o árbitro assinaram e concordam com o registro.</label>
<p>A assinatura desenhada registra a concordância, mas não verifica automaticamente a identidade de quem assinou. A ficha assinada fica disponível para a organização e para as contas vinculadas aos jogadores.</p>
<button class="button button-primary" type="submit" name="mode" value="final" id="digital-final" data-available="<?= $date===date('Y-m-d')?'yes':'no' ?>" disabled>Finalizar súmula e registrar resultado</button><p>Finalização disponível somente na data do jogo. Depois de finalizar, a ficha assinada não pode ser editada.</p>
</form><?php endif ?><?= copaHelpFooter() ?></main></body></html>
