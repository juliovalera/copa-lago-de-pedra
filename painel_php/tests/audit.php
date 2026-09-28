<?php
declare(strict_types=1);
require __DIR__ . '/referee.php'; // Executa os cenários de QR em banco exclusivo em memória.
$_SESSION = ['legacy_admin'=>true];
$count = static fn(): int => (int) db()->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
$last = static fn(): array => db()->query('SELECT * FROM audit_log ORDER BY rowid DESC LIMIT 1')->fetch();
$initial = $count();
$qrEvent = $pdo->query("SELECT * FROM audit_log WHERE source='QR Code' ORDER BY rowid DESC LIMIT 1")->fetch();
check(str_contains($qrEvent['actor'], 'Portador do QR') && isset(json_decode($qrEvent['after_json'], true)['link_id']), 'envio por QR identifica link sem atribuir identidade não verificada');
$insertGame->execute([200, 200]);
$qr = dailyRefereeLink(200);
$pdo->exec("CREATE TRIGGER fail_qr_audit BEFORE INSERT ON audit_log BEGIN SELECT RAISE(ABORT, 'falha'); END");
check(rejected($qr['token']) && gameById(200)['score_a'] === null && validRefereeLink($qr['token'])['used_at'] === null, 'falha de auditoria reverte placar e consumo do QR');
$pdo->exec('DROP TRIGGER fail_qr_audit');
$initial = $count();
savePanelResult(88, 4, 3, '2026-09-20');
$event = $last();
check($count() === $initial+1 && $event['actor'] === 'Administrador principal', 'alteração administrativa identifica responsável');
check(json_decode($event['before_json'], true)['placar_a'] === 2 && json_decode($event['after_json'], true)['placar_a'] === 4, 'placar anterior e novo registrados');
savePanelResult(88, 4, 3, '2026-09-20');
check($count() === $initial+1, 'salvamento sem alteração não duplica histórico');
savePanelResult(88, null, null, '');
check($last()['action'] === 'Resultado removido' && gameById(88)['played_at'] === null, 'remoção de resultado auditada');
$pdo->exec("CREATE TRIGGER fail_audit BEFORE INSERT ON audit_log BEGIN SELECT RAISE(ABORT, 'falha de auditoria'); END");
$failed = false;
try { savePanelResult(88, 1, 1, '2026-09-20'); } catch (PDOException $e) { $failed = true; }
check($failed && gameById(88)['score_a'] === null, 'falha de auditoria reverte resultado administrativo');
$failed = false;
try { dailyRefereeLink(88, date('Y-m-d', strtotime('+2 day'))); } catch (PDOException $e) { $failed = true; }
check($failed && !$pdo->query("SELECT 1 FROM referee_links WHERE game_id=88 AND generated_on='" . date('Y-m-d', strtotime('+2 day')) . "'")->fetchColumn(), 'falha de auditoria reverte geração de QR');
$pdo->exec('DROP TRIGGER fail_audit');
$future = dailyRefereeLink(88, date('Y-m-d', strtotime('+2 day')));
check($last()['action'] === 'Súmula gerada' && json_decode($last()['after_json'], true)['gerado_por'] === 'Administrador principal', 'súmula identifica quem a gerou');
auditRecord('Teste', 'Teste', ['password'=>'SEGREDO'], ['token'=>'SEGREDO', 'nome'=>'Permitido']);
check(!str_contains($last()['before_json'] . $last()['after_json'], 'SEGREDO'), 'campos secretos excluídos do log');
foreach (['UPDATE audit_log SET actor=\'outro\'', 'DELETE FROM audit_log'] as $sql) {
    $failed = false; try { $pdo->exec($sql); } catch (PDOException $e) { $failed = true; }
    check($failed, 'histórico protegido contra edição e exclusão');
}
$backup = tempnam(sys_get_temp_dir(), 'copa-audit-test-');
unlink($backup); // Arquivo temporário criado exclusivamente por este teste.
try {
    $pdo->exec('VACUUM INTO ' . $pdo->quote($backup));
    savePanelResult(88, 5, 4, '2026-09-22');
    $beforeRestore = $count();
    restoreAuditedBackup($backup, 'seguranca-teste.sqlite');
    check(gameById(88)['score_a'] === null, 'restauração recupera resultado do backup');
    check($count() === $beforeRestore+1 && $last()['action'] === 'Backup restaurado', 'restauração preserva auditoria posterior ao backup e registra ação');
    // Simula backup antigo que ainda não tinha auditoria nem autoria da súmula.
    $old = new PDO('sqlite:' . $backup);
    $old->exec('DROP TABLE audit_log');
    $old->exec('ALTER TABLE referee_links DROP COLUMN created_by');
    $old = null;
    restoreAuditedBackup($backup, 'seguranca-teste.sqlite');
    check($count() === $beforeRestore+2, 'backup antigo restaura sem apagar histórico');
    $pdo->exec("CREATE TRIGGER fail_restore BEFORE INSERT ON audit_log BEGIN SELECT RAISE(ABORT, 'falha'); END");
    $pdo->exec('UPDATE games SET score_a=7, score_b=0 WHERE id=88');
    $failed = false; try { restoreAuditedBackup($backup, 'teste'); } catch (PDOException $e) { $failed = true; }
    check($failed && gameById(88)['score_a'] === 7, 'falha na auditoria da restauração reverte toda a restauração');
    $pdo->exec('DROP TRIGGER fail_restore');
} finally { if (is_file($backup)) unlink($backup); }
initialiseDatabase();
check($count() >= $initial, 'migração repetida preserva o histórico');
