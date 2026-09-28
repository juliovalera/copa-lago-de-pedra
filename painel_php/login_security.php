<?php
declare(strict_types=1);

function initialiseLoginSecurity(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS login_limits (
        bucket TEXT PRIMARY KEY, failures_json TEXT NOT NULL DEFAULT '[]',
        blocked_until INTEGER NOT NULL DEFAULT 0, touched_at INTEGER NOT NULL
    )");
}

function authenticateWithLimit(string $login, string $password, string $screen): array
{
    $login = trim($login);
    // REMOTE_ADDR vem do servidor. Não confiar em cabeçalhos fornecidos pelo visitante.
    $address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $packed = @inet_pton($address);
    $address = $packed === false ? 'unknown' : inet_ntop($packed);
    $invalid = strlen($login) > 320 || !preg_match('//u', $login);
    $displayLogin = $invalid ? '[login inválido ou muito longo]' : ($login === '' ? '[acesso principal]' : $login);
    $pdo = db();
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $query = $pdo->prepare('SELECT u.*, p.name AS player_name FROM users u LEFT JOIN players p ON p.id=u.player_id WHERE u.username=? OR u.email=? LIMIT 1');
        $query->execute([$invalid ? '' : $login, $invalid ? '' : $login]);
        $user = $query->fetch() ?: null;
        // Login e e-mail da mesma conta compartilham o limite, inclusive entre as duas telas.
        $identity = $login === '' ? 'principal' : ($user ? 'user:' . $user['id'] : 'login:' . ($invalid ? '[invalid]' : strtolower($login)));
        $bucket = hash('sha256', $identity . "\0" . $address);
        $now = time();
        $query = $pdo->prepare('SELECT * FROM login_limits WHERE bucket=?'); $query->execute([$bucket]);
        $limit = $query->fetch() ?: ['failures_json'=>'[]','blocked_until'=>0];
        $details = ['login_informado'=>$displayLogin, 'ip'=>$address, 'tela'=>$screen];
        $record = static function (string $action, array $extra = [], ?string $actor = null) use ($details): void {
            auditRecord($action, 'Entrada no sistema', [], $details + $extra, 'Autenticação', $actor ?? 'Visitante (identidade não verificada)');
        };
        $remaining = max(0, (int) $limit['blocked_until'] - $now);
        if ($remaining > 0) {
            $record('Tentativa durante bloqueio', ['bloqueado_ate'=>gmdate('c', (int) $limit['blocked_until'])]);
            $pdo->exec('COMMIT');
            return ['user'=>null,'retry_after'=>$remaining,'message'=>'Muitas tentativas de acesso. Aguarde ' . (int) ceil($remaining/60) . ' minuto(s) e tente novamente.'];
        }
        if ((int) $limit['blocked_until'] > 0) $record('Bloqueio encerrado');
        $failures = array_values(array_filter(json_decode($limit['failures_json'], true) ?: [], static fn($stamp) => $stamp > $now-900));
        if ((int) $limit['blocked_until'] > 0) $failures = [];
        $valid = false;
        if (!$invalid && $login === '') {
            $valid = $password !== '' && hash_equals(config()['admin_password'], $password);
            $user = $valid ? ['name'=>'Administrador principal','player_id'=>null] : null;
        } else {
            // Hash fictício evita pular completamente a verificação quando a conta não existe.
            $hash = $user['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
            $safePassword = strlen($password) <= 4096 && !str_contains($password, "\0");
            $verified = password_verify($safePassword ? $password : '', $hash) && $safePassword;
            $valid = !$invalid && $user && is_string($user['password_hash']) && $user['password_hash'] !== '' && (int) $user['is_active'] === 1 && $verified;
        }
        if ($valid) {
            $pdo->prepare('DELETE FROM login_limits WHERE bucket=?')->execute([$bucket]);
            $actor = $login === '' ? 'Administrador principal' : $user['name'] . ' (@' . $user['username'] . ', ID ' . $user['id'] . ')';
            $record('Autenticação aceita', [], $actor);
            $pdo->exec('COMMIT');
            return ['user'=>$user,'retry_after'=>0,'message'=>'Acesso autorizado.'];
        }
        $failures[] = $now;
        $blockedUntil = count($failures) >= 5 ? $now+900 : 0;
        $pdo->prepare('INSERT INTO login_limits(bucket,failures_json,blocked_until,touched_at) VALUES (?,?,?,?) ON CONFLICT(bucket) DO UPDATE SET failures_json=excluded.failures_json,blocked_until=excluded.blocked_until,touched_at=excluded.touched_at')->execute([$bucket,json_encode($failures),$blockedUntil,$now]);
        $record('Autenticação recusada');
        if ($blockedUntil) $record('Bloqueio temporário iniciado', ['bloqueado_ate'=>gmdate('c',$blockedUntil)]);
        $pdo->exec('COMMIT');
        return ['user'=>null,'retry_after'=>$blockedUntil ? 900 : 0,'message'=>$blockedUntil ? 'Muitas tentativas de acesso. Aguarde 15 minutos e tente novamente.' : 'Login, e-mail ou senha inválidos.'];
    } catch (Throwable $error) {
        $pdo->exec('ROLLBACK');
        throw $error;
    }
}
