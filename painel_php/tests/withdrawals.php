<?php
declare(strict_types=1);
// All data and configuration are temporary and fictional. No real email or database.
$tmp=sys_get_temp_dir().'/copa-withdrawal-'.bin2hex(random_bytes(8)); mkdir($tmp);
$files=[];
function check(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); }
function denied(callable $fn,string $label): void { try {$fn();} catch(Throwable $e) {return;} throw new RuntimeException('Expected denial: '.$label); }
try {
    foreach (['db.php','withdrawals.php','audit.php','digital_sheet.php','version.php','notifications.php','login_security.php','confirmations.php','documents.php','attachments.php','complaints.php'] as $file) {copy(dirname(__DIR__).'/'.$file,$tmp.'/'.$file);$files[]=$tmp.'/'.$file;}
    file_put_contents($tmp.'/config.php',"<?php return ['database'=>__DIR__.'/test.sqlite','timezone'=>'America/Sao_Paulo','notification_email'=>'organizer@example.invalid','base_url'=>'https://example.invalid','admin_password'=>'test'];");$files[]=$tmp.'/config.php';
    $mail=[];
    function smtpSend($to,$subject,$text): void { $GLOBALS['mail'][]=[$to,$subject,$text]; }
    require $tmp.'/db.php'; require $tmp.'/confirmations.php';
    initialiseDatabase(); initialiseConfirmations();
    for($i=1;$i<=4;$i++) {
        db()->prepare('INSERT INTO players VALUES (?,?)')->execute([$i,'Ficticio '.$i]);
        db()->prepare("INSERT INTO users(name,username,email,player_id,role,is_active,created_at) VALUES (?,?,?,?,'player',1,?)")->execute(['Ficticio '.$i,'f'.$i,'f'.$i.'@example.invalid',$i,gmdate('c')]);
    }
    $pairs=[[1,2,2,0],[2,1,null,null],[1,3,1,1],[3,1,null,null],[1,4,0,3],[4,1,null,null],[2,3,4,2],[3,2,null,null],[2,4,null,null],[4,2,null,null],[3,4,2,1],[4,3,null,null]];
    foreach($pairs as $n=>$g) db()->prepare('INSERT INTO games(id,round_number,game_number,turn_number,player_a_id,player_b_id,score_a,score_b,played_at) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$n+1,$n+1,1,$n%2+1,...$g,$g[2]===null?null:'2026-09-20']);
    $original=gameRows(); $start=publicData();
    $_SESSION=[]; denied(fn()=>withdrawalPreview([1]),'guest');
    $_SESSION=['user_id'=>1]; denied(fn()=>withdrawalPreview([1]),'player');
    $_SESSION=['legacy_admin'=>true];
    $link=dailyRefereeLink(2,date('Y-m-d'));
    $p=withdrawalPreview([1]); check(count($p['games'])===6 && count($p['after']['players'])===3,'preview');
    check(!$p['after']['games'] && count($p['games'])===6,'only affected game backup');
    $token=panelResultToken(gameById(2));
    $mail=[];
    $id=withdrawalApply([1],'',$p['hash'],'Decision approved');
    $data=publicData(); check(count($data['games'])===6 && count($data['excludedGames'])===6 && $data['totals']['points']===6,'math after one withdrawal');
    check($data['playedGames']===2 && $data['pendingGames']===4,'progress');
    check(count($mail)===5 && count(array_unique(array_column($mail,0)))===5,'one message per recipient');
    check(validRefereeLink($link['token'])===null,'QR blocked');
    denied(fn()=>savePanelResult(2,1,0,date('Y-m-d'),$token),'panel blocked');
    denied(fn()=>dailyRefereeLink(2),'new QR blocked');
    denied(fn()=>digitalContext(2,date('Y-m-d'),$link['token']),'digital blocked');
    denied(fn()=>confirmationCreate(1,7),'confirmation blocked');
    denied(fn()=>db()->exec('UPDATE games SET score_a=8 WHERE id=1'),'SQL protection');
    denied(fn()=>withdrawalApply([1],'',$p['hash'],'replay'),'duplicate');
    $p2=withdrawalPreview([2]); $id2=withdrawalApply([2],'',$p2['hash'],'Second withdrawal');
    check(count(publicData()['games'])===2 && publicData()['totals']['points']===3,'second withdrawal');
    $r=withdrawalPreview([],$id); $rid=withdrawalApply([],$id,$r['hash'],'Restore first only');
    check(withdrawnIds()===[2] && count(publicData()['games'])===6,'independent restoration');
    foreach(gameRows() as $n=>$game) foreach(['score_a','score_b','played_at'] as $key) check($game[$key]===$original[$n][$key],'original scores preserved');
    check(panelResultToken(gameById(2))!==$token,'old forms invalidated');
    denied(fn()=>withdrawalPreview([],$id),'already restored');
    $r=withdrawalPreview([],$id2); withdrawalApply([],$id2,$r['hash'],'Restore second');
    check(publicData()===$start,'full statistical restoration');
    $p=withdrawalPreview([3]); db()->exec("UPDATE games SET score_a=5 WHERE id=1");
    denied(fn()=>withdrawalApply([3],'',$p['hash'],'stale'),'concurrent result');
    check(withdrawnIds()===[],'stale rollback');
    $p=withdrawalPreview([3]);
    db()->exec("CREATE TRIGGER reject_withdrawal_audit BEFORE INSERT ON audit_log WHEN NEW.action='Desistência registrada' BEGIN SELECT RAISE(ABORT,'test'); END");
    $count=db()->query('SELECT COUNT(*) FROM withdrawal_operations')->fetchColumn();
    denied(fn()=>withdrawalApply([3],'',$p['hash'],'audit fail'),'atomic audit');
    check(withdrawnIds()===[] && db()->query('SELECT COUNT(*) FROM withdrawal_operations')->fetchColumn()===$count,'atomic rollback');
    db()->exec('DROP TRIGGER reject_withdrawal_audit');
    $p=withdrawalPreview([1,2]); $batch=withdrawalApply([1,2],'',$p['hash'],'Batch');
    check(count(publicData()['excludedGames'])===10,'mutual encounters excluded once');
    $op=withdrawalOperation($batch); $snap=json_decode($op['snapshot'],true);
    check(count($snap['games'])===10 && count($snap['rows'])===4,'snapshot');
    denied(fn()=>db()->prepare('UPDATE withdrawal_operations SET reason=? WHERE id=?')->execute(['tamper',$batch]),'immutable report');
    // A database-only backup must preserve the state and immutable operation history.
    $backup=$tmp.'/specific-test.sqlite'; db()->exec("VACUUM INTO '".$backup."'"); $files[]=$backup;
    $r=withdrawalPreview([],$batch); withdrawalApply([],$batch,$r['hash'],'Restore batch');
    restoreAuditedBackup($backup,'isolated safety copy');
    check(withdrawnIds()===[1,2],'full backup restoration');
    $r=withdrawalPreview([],$batch); withdrawalApply([],$batch,$r['hash'],'Restore after full backup');
    check(withdrawnIds()===[],'specific restoration after full restore');
    echo "OK: permissions, wins/draws/losses, both turns, exclusions, history/progress, backup, independent restoration, stale previews, audit atomicity, immutable snapshots, consolidated emails and write protection.\n";
} finally {
    if (isset($databaseConnection)) $databaseConnection=null;
    foreach($files as $file) if(is_file($file))unlink($file);
    foreach(['test.sqlite','test.sqlite-journal','test.sqlite-wal','test.sqlite-shm'] as $name) if(is_file($tmp.'/'.$name))unlink($tmp.'/'.$name);
    // Attachment initialisation may create an empty protected directory in this isolated test.
    foreach ([$tmp.'/sumulas',$tmp.'/test.sqlite.files'] as $dir) if(is_dir($dir)) { foreach(glob($dir.'/*')?:[] as $file) if(is_file($file))unlink($file); if(is_file($dir.'/.htaccess'))unlink($dir.'/.htaccess'); @rmdir($dir); }
    @rmdir($tmp);
}
