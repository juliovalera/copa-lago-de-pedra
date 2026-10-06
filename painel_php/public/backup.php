<?php

declare(strict_types=1);

session_start();
require dirname(__DIR__) . '/db.php';
initialiseDatabase();
require_once dirname(__DIR__).'/attachments.php';
initialiseAttachments();

function h(string|int|null $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function backupCsrf(): string { return $_SESSION['backup_csrf'] ??= bin2hex(random_bytes(32)); }
function backupRedirect(string $message): never { $_SESSION['backup_flash'] = $message; header('Location: backup.php'); exit; }
function formatBytes(int $bytes): string { return $bytes < 1024 ? $bytes . ' B' : ($bytes < 1048576 ? number_format($bytes / 1024, 1, ',', '.') . ' KB' : number_format($bytes / 1048576, 2, ',', '.') . ' MB'); }
function backupDirectory(): string
{
    $directory = config()['backup_directory'];
    if (!is_dir($directory)) mkdir($directory, 0775, true);
    return $directory;
}
function backupFiles(): array
{
    $files = glob(backupDirectory() . '/copa-lago-de-pedra-*.sqlite') ?: [];
    rsort($files, SORT_STRING);
    return array_map(static fn(string $file): array => ['name' => basename($file), 'path' => $file, 'size' => filesize($file), 'modified' => filemtime($file)], $files);
}
function selectedBackup(string $name): ?string
{
    if (!preg_match('/\Acopa-lago-de-pedra-\d{8}-\d{6}(?:-antes-da-restauracao)?\.sqlite\z/', $name)) return null;
    $file = backupDirectory() . '/' . $name;
    return is_file($file) ? $file : null;
}
function verifyBackup(string $file): void
{
    $candidate = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $tables = $candidate->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name IN ('players', 'games')")->fetchAll(PDO::FETCH_COLUMN);
    if (count($tables) !== 2 || (int) $candidate->query('SELECT COUNT(*) FROM players')->fetchColumn() !== 26 || (int) $candidate->query('SELECT COUNT(*) FROM games')->fetchColumn() !== 650) throw new RuntimeException('Estrutura incompatível.');
}

requirePanelAccess();
if (!hasFullAccess()) { http_response_code(403); exit('Acesso restrito a administradores.'); }
$databasePath = config()['database'];

if (isset($_GET['download'])) {
    $file = selectedBackup((string) $_GET['download']);
    if (!$file) { http_response_code(404); exit('Backup não encontrado.'); }
    if (isset($_GET['complete'])) {
        try { attachmentBackupDownload($file); }
        catch (Throwable $e) { http_response_code(409); exit('Backup dos anexos incompleto. Crie uma nova copia completa antes de baixar.'); }
    }
    auditRecord('Backup baixado', 'Banco de dados', [], ['arquivo'=>basename($file)]);
    header('Content-Type: application/vnd.sqlite3');
    header('Content-Disposition: attachment; filename="' . basename($file) . '"');
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: no-store');
    readfile($file);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(backupCsrf(), (string) ($_POST['csrf'] ?? ''))) { http_response_code(400); exit('Solicitação inválida.'); }
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'create') {
        $name = 'copa-lago-de-pedra-' . date('Ymd-His') . '.sqlite';
        $target = backupDirectory() . '/' . $name;
        $created=false;
        try {
            db()->exec('VACUUM INTO ' . db()->quote($target)); $created=true;
            attachmentBackupFiles($target);
        } catch (Throwable $e) {
            if ($created && is_file($target)) unlink($target);
            backupRedirect('Backup não concluído. Confira espaço, permissões e disponibilidade dos anexos antes de tentar novamente.');
        }
        auditRecord('Backup criado', 'Banco de dados', [], ['arquivo'=>$name]);
        backupRedirect('Backup criado e guardado no sistema: ' . $name);
    }
    if ($action === 'restore') {
        if (!isMasterAdmin()) { http_response_code(403); exit('Somente o administrador máximo pode restaurar backups.'); }
        $source = selectedBackup((string) ($_POST['backup_name'] ?? ''));
        if (!$source) backupRedirect('Selecione um backup disponível na lista.');
        try {
            verifyBackup($source);
            attachmentRestoreFiles($source);
            $safety = backupDirectory() . '/copa-lago-de-pedra-' . date('Ymd-His') . '-antes-da-restauracao.sqlite';
            db()->exec('VACUUM INTO ' . db()->quote($safety));
            attachmentBackupFiles($safety);
            restoreAuditedBackup($source, $safety);
            backupRedirect('Backup restaurado. Uma cópia do estado anterior foi guardada automaticamente.');
        } catch (Throwable $exception) {
            backupRedirect('Não foi possível restaurar o backup selecionado.');
        }
    }
}

clearstatcache(true, $databasePath);
$size = filesize($databasePath);
$modified = filemtime($databasePath);
$backups = backupFiles();
$flash = $_SESSION['backup_flash'] ?? '';
unset($_SESSION['backup_flash']);
?>
<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#123e32"><title>Backup · Copa Lago de Pedra</title><link rel="stylesheet" href="admin.css?v=<?= filemtime(__DIR__.'/admin.css') ?>"></head>
<body><a class="skip" href="#backups">Pular para backups</a><main class="admin"><header class="admin-header"><div><p class="eyebrow">PAINEL DE CONTROLE</p><h1>Backup e restauração</h1><p>Crie, consulte e restaure cópias históricas sem sair do sistema.</p></div><div class="header-actions"><a class="button button-light" href="admin.php">Voltar aos jogos</a><a class="button button-outline" href="botonistas.php">Botonistas</a><a class="button button-outline" href="auditoria.php">Auditoria</a><a class="button button-outline" href="index.php">Ver site público</a></div></header>
<?php if ($flash): ?><p class="flash" role="status" aria-live="polite"><?= h($flash) ?></p><?php endif ?>
<section class="overview" aria-label="Resumo do banco"><article class="metric"><small>BANCO ATUAL</small><strong><?= h(formatBytes($size)) ?></strong></article><article class="metric"><small>ÚLTIMA ALTERAÇÃO</small><strong class="metric-date"><?= h(date('d/m/Y', $modified)) ?></strong><small><?= h(date('H:i', $modified)) ?></small></article><article class="metric"><small>BACKUPS GUARDADOS</small><strong><?= h(count($backups)) ?></strong></article></section>
<section class="toolbar backup-create"><div><h2>Novo backup</h2><p>Cria uma cópia do banco e dos arquivos de súmulas. Use <strong>Baixar completo (ZIP)</strong> para guardar ambos. O banco sozinho não contém os anexos.</p></div><form method="post"><input type="hidden" name="csrf" value="<?= h(backupCsrf()) ?>"><input type="hidden" name="action" value="create"><button class="button button-primary" type="submit">+ Criar backup agora</button></form></section>
<p>Para restaurar um ZIP em outra instalação, extraia o arquivo .sqlite e a pasta .sqlite.files juntos na pasta de backups configurada na hospedagem. Depois use Restaurar pelo acesso principal. Não envie o ZIP como atualização de código.</p><section id="backups" class="backup-list" aria-labelledby="backups-title"><h2 id="backups-title">Backups disponíveis</h2><?php if (!$backups): ?><div class="empty">Ainda não há backups guardados. Clique em “Criar backup agora” para criar a primeira cópia.</div><?php else: ?><div class="backup-table" role="region" aria-label="Lista de backups"><table><thead><tr><th>Arquivo</th><th>Data e hora</th><th>Tamanho</th><th>Download</th><th>Restauração</th></tr></thead><tbody><?php foreach ($backups as $backup): ?><tr><td><?= h($backup['name']) ?></td><td><?= h(date('d/m/Y H:i:s', $backup['modified'])) ?></td><td><?= h(formatBytes($backup['size'])) ?></td><td><a class="button button-small" href="backup.php?download=<?= rawurlencode($backup['name']) ?>">Somente banco</a> <a class="button button-small" href="backup.php?download=<?= rawurlencode($backup['name']) ?>&amp;complete=1">Baixar completo (ZIP)</a></td><td><?php if (isMasterAdmin()): ?><form method="post" onsubmit="return confirm('Restaurar este backup? O banco atual será substituído e salvo automaticamente como cópia de segurança.');"><input type="hidden" name="csrf" value="<?= h(backupCsrf()) ?>"><input type="hidden" name="action" value="restore"><input type="hidden" name="backup_name" value="<?= h($backup['name']) ?>"><button class="button button-danger button-small" type="submit">Restaurar</button></form><?php else: ?>Somente acesso principal<?php endif ?></td></tr><?php endforeach ?></tbody></table></div><?php endif ?></section><?= copaHelpFooter() ?></main></body></html>
