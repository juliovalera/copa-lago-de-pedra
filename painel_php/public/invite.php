<?php

declare(strict_types=1);

session_start();
require dirname(__DIR__) . '/db.php';
initialiseDatabase();

function h(string|int|null $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function page(string $title, string $body): never { echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#123e32"><title>' . h($title) . '</title><link rel="stylesheet" href="admin.css"></head><body><main class="login-shell"><section class="login">' . $body . '</section></main></body></html>'; exit; }
$token = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
$invite = $token === '' ? null : invitationByToken($token);
if (!$invite) page('Convite indisponível', '<p class="eyebrow">PAINEL DE CONTROLE</p><h1>Convite indisponível</h1><p>Este link foi cancelado, já foi usado, expirou ou não existe. Peça ao administrador para enviar um novo convite.</p>');
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string) ($_POST['password'] ?? '');
    if (strlen($password) < 8) $error = 'A senha deve ter pelo menos 8 caracteres.';
    elseif (!hash_equals($password, (string) ($_POST['confirmation'] ?? ''))) $error = 'As duas senhas não são iguais.';
    else {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $used = $pdo->prepare('UPDATE user_invites SET used_at = ? WHERE id = ? AND used_at IS NULL');
            $used->execute([date('c'), $invite['id']]);
            if ($used->rowCount() !== 1) throw new RuntimeException('Convite já utilizado.');
            $pdo->prepare('UPDATE users SET password_hash = ?, is_active = 1 WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $invite['user_id']]);
            auditRecord('Senha definida e conta ativada', 'Usuário ' . $invite['user_id'], [], ['ativo'=>1, 'senha_definida'=>true, 'jogador_id'=>$invite['player_id']], 'Convite', $invite['name'] . ' (conta ID ' . $invite['user_id'] . ')');
            $pdo->commit();
            dispatchPlayerNotifications();
            session_regenerate_id(true);
            unset($_SESSION['legacy_admin']);
            $_SESSION['user_id'] = (int) $invite['user_id'];
            header('Location: admin.php');
            exit;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Não foi possível ativar a conta. Solicite um novo convite.';
        }
    }
}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#123e32"><title>Ativar conta · Copa Lago de Pedra</title><link rel="stylesheet" href="admin.css"></head><body><main class="login-shell"><section class="login"><p class="eyebrow">PAINEL DE CONTROLE</p><h1>Defina sua senha</h1><p>Olá, <?= h($invite['name']) ?>. Seu login será <strong><?= h($invite['username']) ?></strong>.</p><?php if ($error): ?><p class="error" role="alert"><?= h($error) ?></p><?php endif ?><form method="post"><input type="hidden" name="t" value="<?= h($token) ?>"><label>Nova senha<input name="password" type="password" required minlength="8" autocomplete="new-password" autofocus></label><label>Repita a senha<input name="confirmation" type="password" required minlength="8" autocomplete="new-password"></label><p><button class="button button-primary" type="submit">Ativar conta</button></p></form></section></main></body></html>