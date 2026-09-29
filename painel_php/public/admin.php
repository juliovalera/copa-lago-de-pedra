<?php

declare(strict_types=1);

session_start();
require dirname(__DIR__) . '/db.php';
initialiseDatabase();

function h(string|int|null $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function redirect(string $message, string $location = 'admin.php'): never { $_SESSION['flash'] = $message; header('Location: ' . $location); exit; }
function searchText(string $value): string
{
    return strtolower(strtr($value, [
        'Á'=>'A', 'À'=>'A', 'Ã'=>'A', 'Â'=>'A', 'Ä'=>'A', 'á'=>'a', 'à'=>'a', 'ã'=>'a', 'â'=>'a', 'ä'=>'a',
        'É'=>'E', 'È'=>'E', 'Ê'=>'E', 'Ë'=>'E', 'é'=>'e', 'è'=>'e', 'ê'=>'e', 'ë'=>'e',
        'Í'=>'I', 'Ì'=>'I', 'Î'=>'I', 'Ï'=>'I', 'í'=>'i', 'ì'=>'i', 'î'=>'i', 'ï'=>'i',
        'Ó'=>'O', 'Ò'=>'O', 'Õ'=>'O', 'Ô'=>'O', 'Ö'=>'O', 'ó'=>'o', 'ò'=>'o', 'õ'=>'o', 'ô'=>'o', 'ö'=>'o',
        'Ú'=>'U', 'Ù'=>'U', 'Û'=>'U', 'Ü'=>'U', 'ú'=>'u', 'ù'=>'u', 'û'=>'u', 'ü'=>'u', 'Ç'=>'C', 'ç'=>'c',
    ]));
}

if (isset($_POST['logout'])) { session_destroy(); header('Location: admin.php'); exit; }
$loggedUser = currentUser();
if ($loggedUser === null && !isMasterAdmin()) {
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $login = is_string($_POST['login'] ?? null) ? trim($_POST['login']) : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        try {
            $attempt = authenticateWithLimit($login, $password, 'Painel');
            $user = $attempt['user'];
            if ($user) {
                session_regenerate_id(true);
                if ($login === '') {
                    unset($_SESSION['user_id']); $_SESSION['legacy_admin'] = true;
                } else {
                    unset($_SESSION['legacy_admin']); $_SESSION['user_id'] = (int) $user['id'];
                }
                redirect('Acesso liberado.');
            }
            $error = $attempt['message'];
            if ($attempt['retry_after']) {
                http_response_code(429); header('Retry-After: ' . $attempt['retry_after']);
            }
        } catch (Throwable $exception) {
            http_response_code(503);
            $error = 'Não foi possível verificar o acesso agora. Tente novamente em instantes.';
        }
    }
    ?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#123e32"><title>Entrar · Painel Copa Lago de Pedra</title><link rel="stylesheet" href="admin.css?v=<?= filemtime(__DIR__.'/admin.css') ?>"></head><body><a class="skip" href="#login-access">Pular para o acesso</a><main class="login-shell"><section class="login" id="login-access" aria-labelledby="login-title"><p class="eyebrow">PAINEL DE CONTROLE</p><h1 id="login-title">Atualizar resultados</h1><p>Entre com seu login ou e-mail e a senha cadastrada.</p><?php if ($error): ?><p class="error" role="alert"><?= h($error) ?></p><?php endif ?><form method="post"><label for="login">Login ou e-mail<input id="login" name="login" type="text" autocomplete="username" autofocus></label><label for="password">Senha<input id="password" name="password" type="password" autocomplete="current-password" required></label><p><button class="button button-primary" type="submit">Entrar no painel</button></p></form><a class="button button-outline-dark login-back" href="index.php">← Voltar à área pública</a><?= copaHelpFooter() ?></section></main></body></html>
<?php exit; }

$currentUser = requirePanelAccess();
$limitedPlayerId = hasFullAccess() ? null : (int) $currentUser['player_id'];
$accessLabel = isMasterAdmin() ? 'Administrador máximo' : ($limitedPlayerId === null ? 'Administrador' : 'Jogos de ' . $currentUser['player_name']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(csrf(), (string) ($_POST['csrf'] ?? ''))) { http_response_code(400); exit('Solicitação inválida.'); }
    $id = filter_input(INPUT_POST, 'game_id', FILTER_VALIDATE_INT);
    $date = (string) ($_POST['played_at'] ?? '');
    $a = $_POST['score_a'] === '' ? null : filter_var($_POST['score_a'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    $b = $_POST['score_b'] === '' ? null : filter_var($_POST['score_b'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if (!$id || !gameIsAccessible($id, $limitedPlayerId) || $a === false || $b === false || (($a === null) !== ($b === null)) || ($a !== null && !validGameDate($date))) {
        redirect('Informe os dois gols e uma data válida, ou deixe os dois gols vazios para remover o resultado.');
    }
    try {
        savePanelResult($id, $a, $b, $date, is_string($_POST['result_token'] ?? null) ? $_POST['result_token'] : '');
    } catch (ResultConflict $exception) {
        redirect($exception->getMessage(), 'admin.php?round=' . (int) gameById($id)['round_number']);
    } catch (PDOException $exception) {
        redirect('Não foi possível salvar agora. Recarregue a página, confira o resultado atual e tente novamente.');
    }
    redirect('Resultado salvo. A classificação pública foi recalculada automaticamente.');
}

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
$summary = publicData();
$round = filter_input(INPUT_GET, 'round', FILTER_VALIDATE_INT) ?: 0;
$status = in_array($_GET['status'] ?? '', ['played', 'pending'], true) ? $_GET['status'] : '';
$search = trim((string) ($_GET['q'] ?? ''));
$games = array_values(array_filter(gameRows(), static function (array $game) use ($round, $status, $search, $limitedPlayerId): bool {
    $played = $game['score_a'] !== null && $game['score_b'] !== null;
    if ($round && (int) $game['round_number'] !== $round) return false;
    if ($status === 'played' && !$played) return false;
    if ($status === 'pending' && $played) return false;
    if ($limitedPlayerId !== null && (int) $game['player_a_id'] !== $limitedPlayerId && (int) $game['player_b_id'] !== $limitedPlayerId) return false;
    $query = searchText($search);
    return $query === '' || str_contains(searchText($game['a']), $query) || str_contains(searchText($game['b']), $query);
}));
$totalGames = count($games);
$gamesPerPage = 24;
$pageCount = max(1, (int) ceil($totalGames / $gamesPerPage));
$page = min($pageCount, max(1, filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1));
$games = array_slice($games, ($page - 1) * $gamesPerPage, $gamesPerPage);
function pageUrl(int $page, int $round, string $status, string $search): string
{
    $parameters = ['page' => $page];
    if ($round) $parameters['round'] = $round;
    if ($status !== '') $parameters['status'] = $status;
    if ($search !== '') $parameters['q'] = $search;
    return 'admin.php?' . http_build_query($parameters);
}
?>
<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#123e32"><title>Painel · Copa Lago de Pedra</title><link rel="stylesheet" href="admin.css?v=<?= filemtime(__DIR__.'/admin.css') ?>"></head>
<body><a class="skip" href="#jogos">Pular para os jogos</a><main class="admin"><header class="admin-header"><div><p class="eyebrow">PAINEL DE CONTROLE</p><h1>Resultados da I Copa Lago de Pedra</h1><p><?= $limitedPlayerId === null ? 'Cadastre placares e datas. A classificação pública é atualizada no momento do salvamento.' : 'Você está visualizando apenas os jogos de ' . h($currentUser['player_name']) . '.' ?></p></div><div class="header-actions"><div class="signed-user"><span>Conectado como</span><strong><?= h($currentUser['name']) ?></strong><small><?= h($accessLabel) ?></small></div><a class="button button-light" href="index.php">Ver site público</a><a class="button button-outline" href="sumulas-digitais.php">Súmulas digitais</a><?php if (hasFullAccess()): ?><?php if (isMasterAdmin()): ?><a class="button button-outline" href="usuarios.php">Usuários</a><?php endif ?><a class="button button-outline" href="backup.php">Backup</a><a class="button button-outline" href="botonistas.php">Botonistas</a><a class="button button-outline" href="auditoria.php">Auditoria</a><?php else: ?><a class="button button-outline" href="botonistas.php">Meu nome</a><?php endif ?><form method="post"><button class="button button-outline" name="logout" type="submit">Sair</button></form></div></header>
<?php if ($flash): ?><p class="flash" role="status" aria-live="polite"><?= h($flash) ?></p><?php endif ?>
<section class="overview" aria-label="Resumo dos jogos"><article class="metric"><small>COM RESULTADO</small><strong><?= h($summary['playedGames']) ?></strong></article><article class="metric"><small>AGUARDANDO PLACAR</small><strong><?= h($summary['pendingGames']) ?></strong></article><article class="metric"><small>JOGOS NO CALENDÁRIO</small><strong><?= h(count($summary['games'])) ?></strong></article></section>
<section class="toolbar" id="jogos" aria-labelledby="games-title"><h2 id="games-title">Gerenciar jogos</h2><p>Preencha ambos os gols e a data. Para excluir um resultado, apague os dois gols e salve.</p><form class="filters" method="get"><label for="q">Buscar botonista<input id="q" name="q" type="search" value="<?= h($search) ?>" placeholder="Digite um nome"></label><label for="round">Rodada<select id="round" name="round"><option value="">Todas</option><?php for ($i = 1; $i <= 50; $i++): ?><option value="<?= $i ?>"<?= $round === $i ? ' selected' : '' ?>>Rodada <?= $i ?></option><?php endfor ?></select></label><label for="status">Situação<select id="status" name="status"><option value="">Todos</option><option value="pending"<?= $status === 'pending' ? ' selected' : '' ?>>Sem resultado</option><option value="played"<?= $status === 'played' ? ' selected' : '' ?>>Com resultado</option></select></label><div class="filter-submit"><button class="button button-primary" type="submit">Filtrar jogos</button></div></form></section>
<p class="games-count" role="status"><?= h($totalGames) ?> jogo(s) encontrado(s) · exibindo <?= h(count($games)) ?> por página.</p><section class="admin-grid" aria-label="Jogos filtrados"><?php if (!$games): ?><div class="empty">Nenhum jogo encontrado com os filtros informados.</div><?php endif ?><?php foreach ($games as $game): $played = $game['score_a'] !== null && $game['score_b'] !== null; ?><article class="admin-card" id="game-<?= h($game['id']) ?>"><div class="game-meta"><span>Rodada <?= h($game['round_number']) ?> · Jogo <?= h($game['game_number']) ?></span><span class="status<?= $played ? ' played' : '' ?>"><?= $played ? 'Com resultado' : 'Sem resultado' ?></span></div><div class="match"><?= h($game['a']) ?> <span aria-hidden="true">×</span> <?= h($game['b']) ?></div><form class="game-form" method="post"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="game_id" value="<?= h($game['id']) ?>"><input type="hidden" name="result_token" value="<?= h(panelResultToken($game)) ?>"><label for="a-<?= h($game['id']) ?>">Gols <?= h($game['a']) ?><input id="a-<?= h($game['id']) ?>" name="score_a" type="number" min="0" inputmode="numeric" value="<?= h($game['score_a']) ?>"></label><label for="b-<?= h($game['id']) ?>">Gols <?= h($game['b']) ?><input id="b-<?= h($game['id']) ?>" name="score_b" type="number" min="0" inputmode="numeric" value="<?= h($game['score_b']) ?>"></label><label for="date-<?= h($game['id']) ?>">Data do jogo<input id="date-<?= h($game['id']) ?>" name="played_at" type="date" value="<?= h($game['played_at']) ?>"></label><div class="save"><button class="button button-primary" type="submit">Salvar</button><?php if (!$played): ?><a class="button button-qr" href="sumula.php?game=<?= h($game['id']) ?>" target="_blank" aria-label="Escolher súmula impressa ou digital"><span aria-hidden="true">▦</span><span class="sr-only">Gerar </span>Súmula</a><?php endif ?></div></form></article><?php endforeach ?></section><?php if ($totalGames > $gamesPerPage): ?><nav class="pagination" aria-label="Páginas de jogos"><a class="button button-outline-dark<?= $page === 1 ? ' disabled' : '' ?>"<?= $page === 1 ? ' aria-disabled="true"' : ' href="' . h(pageUrl($page - 1, $round, $status, $search)) . '"' ?>>← Anterior</a><span>Página <?= h($page) ?> de <?= h($pageCount) ?></span><a class="button button-outline-dark<?= $page === $pageCount ? ' disabled' : '' ?>"<?= $page === $pageCount ? ' aria-disabled="true"' : ' href="' . h(pageUrl($page + 1, $round, $status, $search)) . '"' ?>>Próxima →</a></nav><?php endif ?><?= copaHelpFooter() ?></main></body></html>
