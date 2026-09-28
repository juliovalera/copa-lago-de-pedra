<?php
declare(strict_types=1);

// No public HTTP endpoint: schedule this command in cron or Task Scheduler.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Acesso somente pelo terminal.'); }
require __DIR__ . '/db.php';
$lock = null;
try {
    $pdo = db();
    $pdo->exec('PRAGMA busy_timeout=5000');
    $lock = fopen(config()['database'] . '.emails.lock', 'c');
    if ($lock === false) throw new RuntimeException('Não foi possível abrir o controle da fila.');
    if (!flock($lock, LOCK_EX | LOCK_NB)) { echo "Fila já em processamento.\n"; exit(0); }
    initialiseDatabase();
    $processed = processNotificationQueue();
    echo "Avisos processados: $processed. Consulte o estado de entrega na Auditoria.\n";
} catch (Throwable $error) {
    fwrite(STDERR, "Não foi possível processar a fila. Verifique configuração e permissões.\n");
    exit(1);
} finally {
    if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
}
