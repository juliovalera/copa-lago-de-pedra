<?php
declare(strict_types=1);
require __DIR__ . '/referee.php';
$_SESSION=['legacy_admin'=>true];
$insertGame->execute([601,601]);
$token=static fn(): string => panelResultToken(gameById(601));
$old=$token();
savePanelResult(601,2,1,'2026-09-20',$old, 'Correcao conferida no teste');
$reject=static function (string $expected, ?int $a=9, ?int $b=9, string $date='2026-09-21'): void {
    $before=gameById(601);
    $count=(int)db()->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
    $emails=count($GLOBALS['testEmails'] ?? []);
    $failed=false;
    try { savePanelResult(601,$a,$b,$date,$expected, 'Correcao conferida no teste'); } catch (ResultConflict $error) { $failed=true; }
    check($failed && gameById(601)===$before, 'Stale submission preserves current result');
    check($count===(int)db()->query('SELECT COUNT(*) FROM audit_log')->fetchColumn() && $emails===count($GLOBALS['testEmails'] ?? []), 'Conflict creates no result audit or e-mail');
};
$reject($old); $reject(''); $reject('invalid');
$current=$token();
savePanelResult(601,2,1,'2026-09-20',$current, 'Correcao conferida no teste');
check($token()===$current,'Saving unchanged result does not invalidate other forms');
savePanelResult(601,2,1,'2026-09-21',$current, 'Correcao conferida no teste');
$reject($current,null,null,'');
$current=$token();
savePanelResult(601,null,null,'',$current, 'Correcao conferida no teste');
$reject($current);
check($token()!==$old,'Returning to empty state still invalidates old forms');
$reject($old);
$beforeQr=$token();
$link=dailyRefereeLink(601);
saveRefereeResult($link['token'],0,0);
$reject($beforeQr);
$current=$token();
savePanelResult(601,3,2,date('Y-m-d'),$current, 'Correcao conferida no teste');
check(gameById(601)['score_a']===3,'Freshly reviewed result can be corrected');
$current=$token();
$pdo->exec("CREATE TRIGGER fail_result_audit BEFORE INSERT ON audit_log BEGIN SELECT RAISE(ABORT,'test'); END");
try { savePanelResult(601,4,2,date('Y-m-d'),$current, 'Correcao conferida no teste'); } catch (PDOException $expected) {}
check($token()===$current && gameById(601)['score_a']===3,'Rollback preserves result and revision');
