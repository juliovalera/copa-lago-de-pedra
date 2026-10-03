<?php
declare(strict_types=1);
session_start();
require dirname(__DIR__) . '/db.php';
initialiseDatabase();
requirePanelAccess();
if (!hasFullAccess()) { http_response_code(403); exit('Acesso restrito a administradores.'); }
header('Cache-Control: no-store');
function h(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function textParam(string $name): string { return is_string($_GET[$name] ?? null) ? trim($_GET[$name]) : ''; }
$notificationCsrf = $_SESSION['notification_csrf'] ??= bin2hex(random_bytes(32));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isMasterAdmin()) { http_response_code(403); exit('Acesso restrito ao administrador máximo.'); }
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals($notificationCsrf, $_POST['csrf'])) { http_response_code(400); exit('Solicitação inválida.'); }
    $id = is_string($_POST['notification'] ?? null) ? $_POST['notification'] : '';
    $retry = db()->prepare("UPDATE email_notifications SET status='pending' WHERE event_id=? AND (status IN ('pending','failed') OR (status='sending' AND attempted_at < ?))");
    $retry->execute([$id, gmdate('c', time()-600)]);
    if ($retry->rowCount() > 0) sendPlayerNotification($id);
    header('Location: auditoria.php'); exit;
}
function details(string $json): string
{
    $labels = ['justificativa'=>'Justificativa da correção','login_informado'=>'Login informado','ip'=>'Origem (IP)','tela'=>'Tela de entrada','bloqueado_ate'=>'Bloqueado até (UTC)','nivel'=>'Nível de acesso','placar_a'=>'Gols do jogador A','placar_b'=>'Gols do jogador B','data'=>'Data do jogo','jogador_a'=>'Jogador A','jogador_b'=>'Jogador B','nome'=>'Nome','login'=>'Login','email'=>'E-mail','jogador_id'=>'Vínculo com jogador','ativo'=>'Acesso ativo','senha_definida'=>'Senha definida','link_id'=>'Identificador da súmula','gerado_por'=>'Súmula gerada por','arquivo'=>'Arquivo','seguranca'=>'Cópia de segurança','status'=>'Situação'];
    $data = json_decode($json, true) ?: [];
    if (!$data) return '<p>Não se aplica.</p>';
    $html = '<dl>';
    foreach ($data as $key=>$value) {
        if (!isset($labels[$key])) continue;
        if ($key === 'ativo' || $key === 'senha_definida') $value = $value ? 'Sim' : 'Não';
        if ($key === 'data' && $value) $value = date('d/m/Y', strtotime($value));
        $html .= '<dt>' . h($labels[$key]) . '</dt><dd>' . h($value ?? 'Não informado') . '</dd>';
    }
    return $html . '</dl>';
}
$start = textParam('start'); $end = textParam('end'); $actor = textParam('actor'); $action = textParam('action');
$where = isMasterAdmin() ? [] : ["source <> 'Autenticação'"]; $params = []; $error = '';
foreach (['start'=>$start,'end'=>$end] as $key=>$value) {
    if ($value === '') continue;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) { $error = 'Informe datas válidas.'; continue; }
    if ($key === 'end') $date = $date->modify('+1 day');
    $where[] = 'occurred_at ' . ($key === 'start' ? '>=' : '<') . ' ?';
    $params[] = $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
}
if ($start && $end && $start > $end) $error = 'A data inicial deve ser anterior ou igual à final.';
if ($actor !== '') { $where[] = 'instr(lower(actor), lower(?)) > 0'; $params[] = $actor; }
if ($action !== '') { $where[] = 'action = ?'; $params[] = $action; }
if ($error) $where[] = '0 = 1';
$sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$query = db()->prepare('SELECT COUNT(*) FROM audit_log' . $sql); $query->execute($params); $total = (int) $query->fetchColumn();
$pages = max(1, (int) ceil($total / 30));
$page = min($pages, max(1, (int) textParam('page')));
$query = db()->prepare('SELECT a.*, n.status AS notification_status, n.attempted_at FROM audit_log a LEFT JOIN (SELECT event_id, MIN(attempted_at) AS attempted_at, CASE WHEN SUM(status="failed")>0 THEN "failed" WHEN SUM(status="pending")>0 THEN "pending" WHEN SUM(status="sending")>0 THEN "sending" ELSE "sent" END AS status FROM email_notifications GROUP BY event_id) n ON n.event_id=a.event_id' . $sql . ' ORDER BY occurred_at DESC, a.rowid DESC LIMIT 30 OFFSET ' . (($page - 1) * 30));
$query->execute($params); $events = $query->fetchAll();
$notices = [];
if ($events) {
    $ids = array_column($events, 'event_id');
    $query = db()->prepare('SELECT event_id, recipient, status, attempts, audience FROM email_notifications WHERE event_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY recipient');
    $query->execute($ids);
    foreach ($query->fetchAll() as $notice) $notices[$notice['event_id']][] = $notice;
}
function recipientDetails(array $notices): string
{
    if (!$notices) return '<p><small>Nenhum destinatário de aviso registrado para esta ação.</small></p>';
    $html = '<details><summary>Ver destinatários dos avisos</summary><p>Aceito pelo servidor não confirma entrega na caixa de entrada.</p><ul>';
    $statuses = ['pending'=>'Aguardando envio', 'sending'=>'Envio sem confirmação', 'sent'=>'Aceito pelo servidor de e-mail', 'failed'=>'Falha no envio'];
    $audiences = ['player'=>'Partida do botonista', 'organizer'=>'Organização', 'account'=>'Cadastro da conta'];
    foreach ($notices as $notice) {
        $html .= '<li><strong class="notification-recipient">' . h($notice['recipient']) . '</strong> — ' . h($statuses[$notice['status']] ?? 'Sem confirmação') . '<br><small>' . h($audiences[$notice['audience']] ?? 'Aviso') . ' · Tentativas: ' . (int) $notice['attempts'] . '</small></li>';
    }
    return $html . '</ul></details>';
}
$actions = db()->query('SELECT DISTINCT action FROM audit_log' . (isMasterAdmin() ? '' : " WHERE source <> 'Autenticação'") . ' ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
function pageUrl(int $page): string { global $start,$end,$actor,$action; return 'auditoria.php?' . http_build_query(compact('page','start','end','actor','action')); }
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Auditoria · Copa Lago de Pedra</title><link rel="stylesheet" href="admin.css?v=<?= filemtime(__DIR__.'/admin.css') ?>"></head>
<body><a class="skip" href="#events">Pular para os registros</a><main class="admin"><header class="admin-header"><div><p class="eyebrow">PAINEL DE CONTROLE</p><h1>Auditoria</h1><p>Histórico de ações e alterações, disponível somente para administradores.</p></div><nav class="header-actions" aria-label="Administração"><a class="button button-light" href="admin.php">Jogos</a><?php if (isMasterAdmin()): ?><a class="button button-outline" href="usuarios.php">Usuários</a><?php endif ?><a class="button button-outline" href="backup.php">Backup</a><a class="button button-outline" href="botonistas.php">Botonistas</a><a class="button button-outline" href="auditoria.php" aria-current="page">Auditoria</a></nav></header>
<section class="toolbar audit-intro"><p>Os registros começam após a instalação desta atualização. Datas e horários seguem <?= h(config()['timezone']) ?>. No QR Code, identificamos a súmula e quem a gerou; a identidade de quem enviou o placar não é verificada. Este histórico é somente para consulta.</p>
<form class="filters audit-filters" method="get"><label>De<input type="date" name="start" value="<?= h($start) ?>"></label><label>Até<input type="date" name="end" value="<?= h($end) ?>"></label><label>Responsável<input name="actor" value="<?= h($actor) ?>" placeholder="Nome ou login"></label><label>Ação<select name="action"><option value="">Todas as ações</option><?php foreach ($actions as $item): ?><option <?= $action === $item ? 'selected' : '' ?> value="<?= h($item) ?>"><?= h($item) ?></option><?php endforeach ?></select></label><button class="button button-primary" type="submit">Filtrar</button><a class="button button-outline-dark" href="auditoria.php">Limpar filtros</a></form></section>
<?php if ($error): ?><p class="error" role="alert"><?= h($error) ?></p><?php endif ?>
<p role="status"><?= $total ?> registro(s) · página <?= $page ?> de <?= $pages ?></p>
<section id="events" class="audit-events" aria-label="Registros de auditoria">
<?php if (!$events): ?><p class="empty">Nenhum registro encontrado para esta consulta.</p><?php endif ?>
<?php foreach ($events as $event): ?><article class="admin-card"><div class="game-meta"><time datetime="<?= h($event['occurred_at']) ?>"><?= h(date('d/m/Y H:i:s', strtotime($event['occurred_at']))) ?></time><strong><?= h($event['source']) ?></strong></div><h2><?= h($event['action']) ?></h2><p><strong><?= h($event['target']) ?></strong><br>Responsável: <?= h($event['actor']) ?></p><?php if ($event['notification_status']): ?><p><strong>Avisos por e-mail:</strong> <?= h(['pending'=>'Aguardando envio', 'sending'=>'Envio em andamento ou sem confirmação', 'sent'=>'Aceito pelo servidor de e-mail', 'failed'=>'Um ou mais avisos falharam; os dados foram salvos normalmente'][$event['notification_status']] ?? 'Sem confirmação') ?></p><?php if (isMasterAdmin() && (in_array($event['notification_status'], ['pending','failed'], true) || ($event['notification_status'] === 'sending' && strtotime($event['attempted_at']) < time()-600))): ?><form method="post"><input type="hidden" name="csrf" value="<?= h($notificationCsrf) ?>"><input type="hidden" name="notification" value="<?= h($event['event_id']) ?>"><button class="button button-outline-dark" type="submit">Tentar enviar aviso novamente</button><p><small>Uma tentativa sem confirmação pode ter sido entregue. Confirme com os destinatários antes de reenviar. Avisos já confirmados não são reenviados.</small></p></form><?php endif ?><?php endif ?><?= recipientDetails($notices[$event['event_id']] ?? []) ?><details><summary>Ver detalhes da alteração</summary><div class="audit-comparison"><section><h3>Antes</h3><?= details($event['before_json']) ?></section><section><h3>Depois / dados da ação</h3><?= details($event['after_json']) ?></section></div></details></article><?php endforeach ?>
</section><nav class="pagination" aria-label="Páginas de auditoria"><?php if ($page > 1): ?><a class="button button-outline-dark" href="<?= h(pageUrl($page-1)) ?>">Anterior</a><?php endif ?><?php if ($page < $pages): ?><a class="button button-outline-dark" href="<?= h(pageUrl($page+1)) ?>">Próxima</a><?php endif ?></nav><?= copaHelpFooter() ?></main></body></html>
