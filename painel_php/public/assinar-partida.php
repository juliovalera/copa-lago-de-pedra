<?php
declare(strict_types=1);
session_start();
require dirname(__DIR__).'/db.php'; require dirname(__DIR__).'/confirmations.php';
initialiseDatabase(); initialiseConfirmations();
header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer'); header('X-Robots-Tag: noindex, nofollow'); header('X-Content-Type-Options: nosniff');
function h(mixed $v): string { return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
$error=''; $row=null; $grant=null;
try {
    if (isset($_GET['t'])) {
        $grant=confirmationGrant(is_string($_GET['t'])?$_GET['t']:''); session_regenerate_id(true);
        $_SESSION['confirmation_grants'][$grant['id']]=$grant;
        header('Location: assinar-partida.php?id='.$grant['id']); exit;
    }
    $id=is_string($_GET['id']??null)?$_GET['id']:'';
    $grant=$_SESSION['confirmation_grants'][$id]??null;
    if (!$grant) throw new InvalidArgumentException('Abra o link individual enviado pela organização.');
    $row=confirmationGet($id); confirmationCheckGrant($row,$grant['side'],$grant['hash']);
} catch (InvalidArgumentException $e) { $error=$e->getMessage(); $row=null; http_response_code(403); }
$csrf=$_SESSION['confirmation_sign_csrf']??=bin2hex(random_bytes(32));
if ($row && $_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if (!is_string($_POST['csrf']??null) || !hash_equals($csrf,$_POST['csrf'])) throw new InvalidArgumentException('Solicitação inválida. Reabra o link.');
        $action=$_POST['action']??'';
        if (!in_array($action,['sign','disagree'],true)) throw new InvalidArgumentException('Escolha uma ação válida.');
        $divergence=$action==='disagree' && is_string($_POST['divergence']??null)?trim($_POST['divergence']):'';
        if ($action==='disagree' && $divergence==='') throw new InvalidArgumentException('Descreva o que precisa ser corrigido.');
        confirmationSign($grant,is_string($_POST['signature']??null)?$_POST['signature']:'',($_POST['consent']??'')==='1',$divergence);
        unset($_SESSION['confirmation_grants'][$id]); $_SESSION['confirmation_done']=true;
        header('Location: assinar-partida.php?done=1'); exit;
    } catch (InvalidArgumentException $e) { $error=$e->getMessage(); }
    catch (Throwable $e) { $error='Não foi possível salvar. Reabra o link e confira a situação antes de tentar novamente.'; }
}
$done=isset($_GET['done']) && !empty($_SESSION['confirmation_done']);
if ($done) { http_response_code(200); $error=''; unset($_SESSION['confirmation_done']); }
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Confirmar minha partida</title><link rel="stylesheet" href="admin.css"><link rel="stylesheet" href="digital.css"><link rel="stylesheet" href="confirmations.css?v=<?= copaVersion() ?>"><script src="confirmations.js?v=<?= copaVersion() ?>" defer></script></head><body><main class="admin digital-page"><h1>Confirmar minha partida</h1>
<?php if ($done): ?><p role="status">Recebido. A organização poderá acompanhar sua confirmação ou divergência. A súmula só será concluída com as duas assinaturas e o aval do administrador.</p><?php endif ?>
<?php if ($error): ?><p class="error" role="alert"><?= h($error) ?></p><?php endif ?>
<?php if ($row): $s=json_decode($row['snapshot'],true); ?><article class="admin-card"><h2>Assinatura de <?= h($s[$grant['side']]) ?></h2><p>Este link é pessoal. Não o encaminhe para outras pessoas.</p><h3><?= h($s['a']) ?> × <?= h($s['b']) ?></h3><p class="digital-total">Placar: <?= h($s['score_a']) ?> × <?= h($s['score_b']) ?></p><p>Data: <?= h(date('d/m/Y',strtotime($s['date']))) ?> · Rodada <?= h($s['round']) ?></p><p>Partida realizada sem árbitro. Confira os dados antes de assinar.</p>
<form method="post" id="confirmation-sign"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="signature" value="[]"><div class="signature-grid"><fieldset><legend>Sua assinatura</legend><canvas width="1000" height="500" aria-label="Área para assinar com o dedo ou mouse"></canvas><button type="button" class="button button-outline-dark" data-clear-signature>Limpar assinatura</button></fieldset></div><p id="signature-status" role="status">Assine com o dedo ou mouse. Se não conseguir, peça ajuda à organização.</p><label class="digital-consent"><input type="checkbox" name="consent" value="1" required>Conferi o placar e a data e confirmo minha participação nesta partida.</label><button class="button button-primary" name="action" value="sign">Confirmar e salvar assinatura</button></form>
<details><summary>Informar divergência</summary><form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><label class="confirmation-label">O que precisa ser corrigido?<textarea name="divergence" rows="4" maxlength="1000" required></textarea></label><button class="button button-outline-dark" name="action" value="disagree">Enviar divergência à administração</button></form><p>Isso interrompe a coleta. A administração verá a mensagem no painel; fale com ela também se precisar de retorno rápido.</p></details></article><?php endif ?><?= copaHelpFooter() ?></main></body></html>
