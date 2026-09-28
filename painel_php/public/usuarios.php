<?php

declare(strict_types=1);

session_start();
require dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/mailer.php';
initialiseDatabase();

function h(string|int|null $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function csrf(): string { return $_SESSION['users_csrf'] ??= bin2hex(random_bytes(32)); }
function redirect(string $message): never { $_SESSION['users_flash'] = $message; header('Location: usuarios.php'); exit; }
function sendInvitation(array $user): void
{
    $token = bin2hex(random_bytes(32));
    $expiresAt = date('c', strtotime('+72 hours'));
    $pdo = db();
    auditedTransaction(static function () use ($pdo, $user, $token, $expiresAt): void {
    $pdo->prepare('UPDATE user_invites SET used_at = ? WHERE user_id = ? AND used_at IS NULL')->execute([date('c'), $user['id']]);
    $pdo->prepare('INSERT INTO user_invites (user_id, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?)')->execute([$user['id'], hash('sha256', $token), $expiresAt, date('c')]);
    $pdo->prepare('UPDATE users SET invited_at = ? WHERE id = ?')->execute([date('c'), $user['id']]);
    auditRecord('Convite emitido', 'Usuário ' . $user['id'], [], ['nome'=>$user['name']]);
    });
    $link = rtrim(config()['base_url'], '/') . '/invite.php?t=' . $token;
    try {
    smtpSend($user['email'], 'Defina sua senha — Copa Lago de Pedra', "Olá, {$user['name']}.\n\nSua conta no painel da Copa Lago de Pedra foi criada. Para definir sua senha e ativar o acesso, abra o link abaixo:\n\n{$link}\n\nEste link é pessoal, pode ser usado uma única vez e vence em 72 horas.\n\nSe você não solicitou este acesso, ignore esta mensagem.");
    } catch (Throwable $error) {
        auditRecord('Falha no envio de convite', 'Usuário ' . $user['id']);
        throw $error;
    }
    auditRecord('Convite enviado', 'Usuário ' . $user['id']);
}

requirePanelAccess();
if (!isMasterAdmin()) { http_response_code(403); exit('Acesso restrito a administradores.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(csrf(), (string) ($_POST['csrf'] ?? ''))) { http_response_code(400); exit('Solicitação inválida.'); }
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'privilege') {
        try {
            $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
            $role = is_string($_POST['role'] ?? null) ? $_POST['role'] : '';
            $player = filter_input(INPUT_POST, 'player_id', FILTER_VALIDATE_INT) ?: null;
            $expected = is_string($_POST['previous_player'] ?? null) ? $_POST['previous_player'] : 'invalid';
            changeUserPrivilege($userId ?: 0, $role, $player, $expected);
            redirect('Privilégios atualizados. A mudança vale a partir da próxima solicitação do usuário.');
        } catch (InvalidArgumentException $exception) { redirect($exception->getMessage()); }
        catch (Throwable $exception) { redirect('Não foi possível alterar os privilégios. Nenhuma alteração foi confirmada.'); }
    }
    if ($action === 'create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $playerId = filter_input(INPUT_POST, 'player_id', FILTER_VALIDATE_INT) ?: null;
        $playerExists = true;
        if ($playerId !== null) {
            $playerStatement = db()->prepare('SELECT 1 FROM players WHERE id = ?');
            $playerStatement->execute([$playerId]);
            $playerExists = (bool) $playerStatement->fetchColumn();
        }
        if ($name === '' || !preg_match('/^[a-zA-Z0-9._-]{3,40}$/', $username) || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$playerExists) {
            redirect('Preencha nome, e-mail válido e login com 3 a 40 caracteres (letras, números, ponto, hífen ou sublinhado).');
        }
        try {
            $user = auditedTransaction(static function () use ($name, $username, $email, $playerId): array {
            $statement = db()->prepare('INSERT INTO users (name, username, email, player_id, created_at) VALUES (?, ?, ?, ?, ?)');
            $statement->execute([$name, $username, $email, $playerId, date('c')]);
            $user = ['id' => (int) db()->lastInsertId(), 'name' => $name, 'email' => $email];
            auditRecord('Usuário cadastrado', 'Usuário ' . $user['id'], [], ['nome'=>$name, 'login'=>$username, 'email'=>$email, 'jogador_id'=>$playerId, 'ativo'=>0]);
            return $user;
            });
            try {
                sendInvitation($user);
                redirect('Usuário cadastrado. O convite para definição de senha foi enviado por e-mail.');
            } catch (Throwable $exception) {
                redirect('Usuário cadastrado, mas o convite não foi enviado. Configure o SMTP e use “Reenviar convite”.');
            }
        } catch (PDOException $exception) {
            redirect('Não foi possível cadastrar: o login ou e-mail já está em uso.');
        }
    }
    $id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    $statement = db()->prepare('SELECT id, name, email FROM users WHERE id = ?');
    $statement->execute([$id]);
    $user = $statement->fetch();
    if (!$user) redirect('Usuário não encontrado.');
    if ($action === 'resend') {
        try { sendInvitation($user); redirect('Novo convite enviado por e-mail.'); }
        catch (Throwable $exception) { redirect('Não foi possível enviar. Confira a configuração SMTP e tente novamente.'); }
    }
    if ($action === 'toggle') {
        $actor = auditActor();
        try {
        auditedTransaction(static function () use ($id, $actor): void {
            $query = db()->prepare('SELECT name, is_active FROM users WHERE id = ?');
            $query->execute([$id]); $before = $query->fetch();
            db()->prepare('UPDATE users SET is_active = CASE is_active WHEN 1 THEN 0 ELSE 1 END WHERE id = ?')->execute([$id]);
            if ((int) $before['is_active'] === 1) {
                // Invalida também formulários de convite já abertos, na mesma transação.
                db()->prepare('UPDATE user_invites SET used_at = ? WHERE user_id = ? AND used_at IS NULL')->execute([date('c'), $id]);
            }
            auditRecord('Acesso alterado', 'Usuário ' . $id, ['nome'=>$before['name'], 'ativo'=>(int) $before['is_active']], ['ativo'=>1 - (int) $before['is_active']], 'Painel', $actor);
        });
        } catch (Throwable $exception) {
            redirect('Não foi possível atualizar o acesso. Nenhuma alteração foi confirmada. Tente novamente.');
        }
        redirect('Situação do usuário atualizada.');
    }
}

$flash = $_SESSION['users_flash'] ?? '';
unset($_SESSION['users_flash']);
$players = db()->query('SELECT id, name FROM players ORDER BY name')->fetchAll();
$users = db()->query('SELECT u.*, p.name AS player_name FROM users u LEFT JOIN players p ON p.id = u.player_id ORDER BY u.created_at DESC, u.name')->fetchAll();
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#123e32"><title>Usuários · Copa Lago de Pedra</title><link rel="stylesheet" href="admin.css?v=<?= filemtime(__DIR__.'/admin.css') ?>"></head><body><a class="skip" href="#users">Pular para usuários</a><main class="admin"><header class="admin-header"><div><p class="eyebrow">PAINEL DE CONTROLE</p><h1>Usuários e acessos</h1><p>Convide administradores ou vincule uma conta a um botonista para limitar os jogos disponíveis.</p></div><div class="header-actions"><a class="button button-light" href="admin.php">Voltar aos jogos</a><a class="button button-outline" href="backup.php">Backup</a><a class="button button-outline" href="botonistas.php">Botonistas</a><a class="button button-outline" href="auditoria.php">Auditoria</a></div></header><?php if ($flash): ?><p class="flash" role="status"><?= h($flash) ?></p><?php endif ?>
<section class="user-layout"><section class="toolbar"><h2>Novo usuário</h2><p>O e-mail é obrigatório. A senha será definida pela própria pessoa no link de convite, válido por 72 horas.</p><form class="user-form" method="post"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="create"><label>Nome completo<input name="name" required maxlength="100" autocomplete="name"></label><label>Login<input name="username" required minlength="3" maxlength="40" pattern="[A-Za-z0-9._-]+" autocomplete="username"><small>Use letras, números, ponto, hífen ou sublinhado.</small></label><label>E-mail<input name="email" type="email" required maxlength="160" autocomplete="email"></label><label>Vincular a jogador (opcional)<select name="player_id"><option value="">Administrador — acesso a todos os jogos</option><?php foreach ($players as $player): ?><option value="<?= h($player['id']) ?>"><?= h($player['name']) ?></option><?php endforeach ?></select><small>Com vínculo, a pessoa só vê e salva jogos daquele botonista.</small></label><div><button class="button button-primary" type="submit">Cadastrar e enviar convite</button></div></form></section>
<section id="users" class="backup-list"><h2>Usuários cadastrados</h2><?php if (!$users): ?><div class="empty">Nenhum usuário foi cadastrado ainda.</div><?php else: ?><div class="backup-table" role="region" aria-label="Usuários cadastrados"><table><thead><tr><th>Nome / login</th><th>E-mail</th><th>Acesso</th><th>Situação</th><th>Ações</th></tr></thead><tbody><?php foreach ($users as $user): ?><tr><td><strong><?= h($user['name']) ?></strong><br><small><?= h($user['username']) ?></small></td><td><?= h($user['email']) ?></td><td><?= $user['player_name'] ? 'Somente ' . h($user['player_name']) : 'Todos os jogos' ?></td><td><?= $user['is_active'] ? 'Ativo' : 'Aguardando senha' ?></td><td><details><summary>Alterar privilégios</summary><form class="user-form" method="post"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="privilege"><input type="hidden" name="user_id" value="<?= h($user['id']) ?>"><input type="hidden" name="previous_player" value="<?= h($user['player_id'] ?? '') ?>"><label>Nível de acesso<select name="role"><option value="admin" <?= $user['player_id'] === null ? 'selected' : '' ?>>Administrador</option><option value="player" <?= $user['player_id'] !== null ? 'selected' : '' ?>>Botonista</option></select></label><label>Botonista vinculado<select name="player_id"><option value="">Selecione para o nível Botonista</option><?php foreach ($players as $player): ?><option value="<?= h($player['id']) ?>" <?= (int) $user['player_id'] === (int) $player['id'] ? 'selected' : '' ?>><?= h($player['name']) ?></option><?php endforeach ?></select><small>Obrigatório para Botonista. Administrador tem acesso a todos os jogos.</small></label><button class="button button-primary" type="submit">Salvar privilégios</button></form></details><div class="table-actions"><form method="post"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="resend"><input type="hidden" name="user_id" value="<?= h($user['id']) ?>"><button class="button button-small" type="submit">Reenviar convite</button></form><?php if ($user['is_active']): ?><form method="post"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="user_id" value="<?= h($user['id']) ?>"><button class="button button-danger button-small" type="submit">Desativar</button></form><?php endif ?></div></td></tr><?php endforeach ?></tbody></table></div><?php endif ?></section></section><?= copaHelpFooter() ?></main></body></html>
