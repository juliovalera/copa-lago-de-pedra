<?php
declare(strict_types=1);
session_start();
require dirname(__DIR__) . '/db.php';
initialiseDatabase();
$user = requirePanelAccess();
$fullAccess = hasFullAccess();
$ownPlayerId = $fullAccess ? null : (int) $user['player_id'];
header('Cache-Control: no-store');
function h(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
$csrf = $_SESSION['players_csrf'] ??= bin2hex(random_bytes(32));
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals($csrf, $_POST['csrf'])) { http_response_code(400); exit('Solicitação inválida. Atualize a página e tente novamente.'); }
    $id = filter_input(INPUT_POST, 'player_id', FILTER_VALIDATE_INT);
    if (!$fullAccess && (!$id || $id !== $ownPlayerId)) { http_response_code(403); exit('Você pode corrigir apenas o seu próprio nome.'); }
    try {
        if (!$id || !is_string($_POST['name'] ?? null) || !is_string($_POST['old_name'] ?? null)) throw new InvalidArgumentException('Informe o botonista e o nome correto.');
        renamePlayer($id, $_POST['name'], $_POST['old_name']);
        $_SESSION['players_flash'] = 'Nome salvo. Os jogos, resultados e vínculos foram preservados.';
        header('Location: botonistas.php'); exit;
    } catch (InvalidArgumentException $exception) { $error = $exception->getMessage(); }
    catch (Throwable $exception) { $error = 'Não foi possível salvar o nome. Nenhuma alteração foi confirmada. Tente novamente.'; }
}
$flash = $_SESSION['players_flash'] ?? ''; unset($_SESSION['players_flash']);
$query = db()->prepare('SELECT id, name FROM players' . ($fullAccess ? '' : ' WHERE id = ?') . ' ORDER BY name');
$query->execute($fullAccess ? [] : [$ownPlayerId]);
$players = $query->fetchAll();
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Botonistas · Copa Lago de Pedra</title><link rel="stylesheet" href="admin.css?v=<?= filemtime(__DIR__.'/admin.css') ?>"></head><body>
<a class="skip" href="#players">Pular para os botonistas</a><main class="admin"><header class="admin-header"><div><p class="eyebrow">PAINEL DE CONTROLE</p><h1><?= $fullAccess ? 'Corrigir nomes dos botonistas' : 'Corrigir meu nome' ?></h1><p>A correção aparece na classificação, nos jogos e nas novas súmulas, mantendo todos os resultados.</p></div><nav class="header-actions" aria-label="Administração"><a class="button button-light" href="admin.php">Jogos</a><?php if ($fullAccess): ?><?php if (isMasterAdmin()): ?><a class="button button-outline" href="usuarios.php">Usuários</a><?php endif ?><a class="button button-outline" href="auditoria.php">Auditoria</a><?php endif ?></nav></header>
<p>Edite o nome e clique em “Salvar nome”. A alteração ficará registrada na auditoria. O nome e o login da conta de acesso são cadastros separados.</p>
<?php if ($flash): ?><p class="flash" role="status"><?= h($flash) ?></p><?php endif ?><?php if ($error): ?><p class="error" role="alert"><?= h($error) ?></p><?php endif ?>
<section id="players" class="admin-grid" aria-label="Botonistas cadastrados"><?php foreach ($players as $player): ?><article class="admin-card"><h2><?= h($player['name']) ?></h2><form class="user-form" method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="player_id" value="<?= h($player['id']) ?>"><input type="hidden" name="old_name" value="<?= h($player['name']) ?>"><label for="player-<?= h($player['id']) ?>">Nome do botonista<input id="player-<?= h($player['id']) ?>" name="name" value="<?= h($player['name']) ?>" required maxlength="100" autocomplete="off"></label><button class="button button-primary" type="submit" aria-label="Salvar nome de <?= h($player['name']) ?>">Salvar nome</button></form></article><?php endforeach ?></section><?= copaHelpFooter() ?></main></body></html>
