<?php
declare(strict_types=1);
session_start();
require dirname(__DIR__).'/db.php';
initialiseDatabase();
$user=requirePanelAccess();
header('Cache-Control: no-store');
function h(mixed $v): string { return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
$sheets=db()->query('SELECT * FROM digital_sheets ORDER BY updated_at DESC,id DESC')->fetchAll();
$sheets=array_values(array_filter($sheets,static function ($sheet) use ($user) {
    if (hasFullAccess()) return true;
    $d=json_decode($sheet['data_json'],true);
    return in_array((int)($user['player_id']??0),[(int)$d['player_a_id'],(int)$d['player_b_id']],true);
}));
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Súmulas digitais</title><link rel="stylesheet" href="admin.css"></head><body><main class="admin"><header class="admin-header"><div><h1>Súmulas digitais</h1><p>Consulte rascunhos e fichas finalizadas dos jogos que você pode acessar.</p></div><a class="button button-light" href="admin.php">Voltar aos jogos</a></header><p>Para iniciar, abra um jogo sem resultado, escolha gerar súmula e selecione “Preencher e assinar no celular”. As fichas finalizadas preservam o documento original, mesmo após uma correção do resultado.</p><section class="admin-grid">
<?php if (!$sheets): ?><p>Nenhuma súmula digital salva ainda.</p><?php endif ?>
<?php foreach ($sheets as $sheet): $d=json_decode($sheet['data_json'],true); $url=$sheet['status']==='final'?'sumula-digital.php?document='.(int)$sheet['id']:'sumula-digital.php?'.http_build_query(['game'=>$sheet['game_id'],'date'=>$sheet['match_date']]); ?>
<article class="admin-card"><h2><?= h($d['a']) ?> × <?= h($d['b']) ?></h2><p><?= h(date('d/m/Y',strtotime($sheet['match_date']))) ?> · <?= $sheet['status']==='final'?'Finalizada':'Rascunho' ?></p><a class="button button-outline-dark" href="<?= h($url) ?>"><?= $sheet['status']==='final'?'Consultar / salvar PDF':'Abrir rascunho' ?></a></article>
<?php endforeach ?></section><?= copaHelpFooter() ?></main></body></html>
