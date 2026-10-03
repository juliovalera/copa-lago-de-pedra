<?php
declare(strict_types=1);
require __DIR__.'/referee.php';
$_SESSION=['legacy_admin'=>true];
foreach ([701,702,703,704] as $id) $insertGame->execute([$id,$id]);
$today=date('Y-m-d');
$link=dailyRefereeLink(701);
$points=[];for($i=0;$i<12;$i++) $points[]=[$i*20+10,50+($i%2)*30];
$signature=json_encode([$points]);
$fields=['venue'=>'Clube de teste','time'=>'10:00','table'=>'1','referee'=>'Árbitro fictício','notes'=>'Conferido','first_a'=>'1','first_b'=>'0','second_a'=>'2','second_b'=>'1','consent'=>'1','signature_a'=>$signature,'signature_b'=>$signature,'signature_referee'=>$signature];
$expected=panelResultToken(gameById(701));
$draft=saveDigitalSheet(701,$today,'',0,$expected,$fields,false);
check($draft['status']==='draft' && json_decode($draft['signatures_json'],true)===[] && gameById(701)['score_a']===null,'Draft saves fields without signatures or result');
function refusedDigital(callable $work): void { $failed=false;try{$work();}catch(RuntimeException|InvalidArgumentException $e){$failed=true;}check($failed,'Invalid or stale digital submission rejected'); }
refusedDigital(fn()=>saveDigitalSheet(701,$today,'',0,$expected,$fields,false));
foreach (['[]','[[["<script>",0],[2,3]]]','[[[1001,2],[2,3]]]'] as $invalid) {
    refusedDigital(fn()=>saveDigitalSheet(701,$today,'',1,$expected,array_replace($fields,['signature_a'=>$invalid]),true));
}
refusedDigital(fn()=>saveDigitalSheet(701,$today,'',1,$expected,array_replace($fields,['consent'=>'0']),true));
check(digitalSheet(701,$today)['revision']===1 && gameById(701)['score_a']===null,'Invalid signatures do not change draft or game');
$_SESSION=[];
refusedDigital(fn()=>saveDigitalSheet(701,$today,'bad',1,$expected,$fields,true));
$before=count($GLOBALS['testEmails']??[]);
$final=saveDigitalSheet(701,$today,$link['token'],1,$expected,$fields,true);
check($final['status']==='final' && gameById(701)['score_a']===3 && gameById(701)['score_b']===1,'Bearer QR finalizes signatures and summed result atomically');
check(count($GLOBALS['testEmails'])===$before+1,'Final result sends organizer notice after commit');
check(validRefereeLink($link['token'])['used_at']!==null,'Finalized digital sheet consumes QR');
check(rejected($link['token']),'Paper QR cannot overwrite digital result');
$_SESSION=['legacy_admin'=>true];
refusedDigital(fn()=>saveDigitalSheet(701,$today,'',2,panelResultToken(gameById(701)),$fields,true));
foreach (['UPDATE digital_sheets SET status=\'draft\' WHERE game_id=701','DELETE FROM digital_sheets WHERE game_id=701'] as $sql) {
    $failed=false;try{$pdo->exec($sql);}catch(PDOException $e){$failed=true;}check($failed,'Finalized document is immutable');
}
savePanelResult(701,4,1,$today,panelResultToken(gameById(701)), 'Correcao conferida no teste');
check(digitalSheet(701,$today)===$final,'Administrative correction preserves original signed document');
$future=date('Y-m-d',strtotime('+1 day'));
dailyRefereeLink(702,$future);
$futureToken=panelResultToken(gameById(702));
saveDigitalSheet(702,$future,'',0,$futureToken,$fields,false);
refusedDigital(fn()=>saveDigitalSheet(702,$future,'',1,$futureToken,$fields,true));
$link3=dailyRefereeLink(703);$old=panelResultToken(gameById(703));
saveRefereeResult($link3['token'],0,0);
refusedDigital(fn()=>saveDigitalSheet(703,$today,'',0,$old,$fields,true));
dailyRefereeLink(704);$old=panelResultToken(gameById(704));
$pdo->exec("CREATE TRIGGER digital_test_fail BEFORE INSERT ON audit_log BEGIN SELECT RAISE(ABORT,'test'); END");
refusedDigital(fn()=>saveDigitalSheet(704,$today,'',0,$old,$fields,true));
check(gameById(704)['score_a']===null && digitalSheet(704,$today)===null,'Failure rolls back signatures, game, QR and audit together');
$pdo->exec('DROP TRIGGER digital_test_fail');
$pdo->exec("INSERT INTO players(id,name) VALUES (3,'Third player'); INSERT INTO users(id,name,username,email,player_id,is_active,created_at) VALUES (1,'Third','third','third@example.invalid',3,1,'2026-09-29')");
$_SESSION=['user_id'=>1];
refusedDigital(fn()=>saveDigitalSheet(704,$today,'',0,$old,$fields,true));
check(!str_contains(json_encode($pdo->query('SELECT * FROM audit_log')->fetchAll()),$signature),'Audit does not store signature strokes');

// Restore documents from a backup, including a finalized copy over an older draft.
$_SESSION=['legacy_admin'=>true];
$backup=sys_get_temp_dir().'/copa-digital-'.bin2hex(random_bytes(8)).'.sqlite';
$pdo->exec('VACUUM INTO '.$pdo->quote($backup));
try {
    $databaseConnection=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    initialiseDatabase();
    db()->prepare("INSERT INTO digital_sheets(game_id,match_date,data_json,updated_by,updated_at) VALUES (701,?,'{}','test','test')")->execute([$today]);
    restoreAuditedBackup($backup,'isolated-test');
    check(digitalSheet(701,$today)['signatures_json']===$final['signatures_json'] && digitalSheet(701,$today)['status']==='final','Restoration imports original signatures over older draft');
    $restored=digitalSheet(701,$today);
    restoreAuditedBackup($backup,'isolated-test');
    check(digitalSheet(701,$today)===$restored,'Repeated restoration preserves finalized document');
} finally { unlink($backup); }
