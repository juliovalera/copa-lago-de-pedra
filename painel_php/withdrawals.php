<?php
declare(strict_types=1);

function initialiseWithdrawals(): void
{
    db()->exec('CREATE TABLE IF NOT EXISTS withdrawal_operations (
        id TEXT PRIMARY KEY, kind TEXT NOT NULL, restores_id TEXT,
        created_at TEXT NOT NULL, actor TEXT NOT NULL, reason TEXT NOT NULL,
        snapshot TEXT NOT NULL
    )');
    db()->exec('CREATE TABLE IF NOT EXISTS withdrawn_players (
        player_id INTEGER PRIMARY KEY REFERENCES players(id),
        operation_id TEXT NOT NULL REFERENCES withdrawal_operations(id)
    )');
    foreach (['UPDATE','DELETE'] as $verb) db()->exec("CREATE TRIGGER IF NOT EXISTS withdrawal_immutable_{$verb} BEFORE $verb ON withdrawal_operations BEGIN SELECT RAISE(ABORT,'Withdrawal history is immutable'); END");
    db()->exec("CREATE TRIGGER IF NOT EXISTS withdrawn_game_protected BEFORE UPDATE OF score_a,score_b,played_at,player_a_id,player_b_id ON games
        WHEN EXISTS(SELECT 1 FROM withdrawn_players WHERE player_id IN (OLD.player_a_id,OLD.player_b_id,NEW.player_a_id,NEW.player_b_id))
        BEGIN SELECT RAISE(ABORT,'Jogo excluido por desistencia; restaure pela administracao.'); END");
}

function withdrawnIds(): array
{
    return array_map('intval',db()->query('SELECT player_id FROM withdrawn_players ORDER BY player_id')->fetchAll(PDO::FETCH_COLUMN));
}

function gameExcluded(array $game, ?array $ids = null): bool
{
    $ids ??= withdrawnIds();
    return in_array((int)$game['player_a_id'],$ids,true) || in_array((int)$game['player_b_id'],$ids,true);
}

function requireActiveGame(array $game): void
{
    if (gameExcluded($game)) throw new InvalidArgumentException('Jogo anulado ou cancelado por desistência. Consulte a administração.');
}

function withdrawalOperation(string $id): array
{
    $q=db()->prepare('SELECT * FROM withdrawal_operations WHERE id=?'); $q->execute([$id]);
    return $q->fetch() ?: throw new InvalidArgumentException('Operação não encontrada.');
}

function withdrawalStateHash(): string
{
    return hash('sha256',json_encode([gameRows(),db()->query('SELECT * FROM players ORDER BY id')->fetchAll(),db()->query('SELECT * FROM withdrawn_players ORDER BY player_id')->fetchAll(),db()->query('SELECT id FROM withdrawal_operations ORDER BY id')->fetchAll()],JSON_THROW_ON_ERROR));
}

function withdrawalPreview(array $ids, string $restore = ''): array
{
    if (!hasFullAccess()) throw new InvalidArgumentException('Acesso exclusivo da administração.');
    $current=withdrawnIds();
    if ($restore !== '') {
        $op=withdrawalOperation($restore);
        if ($op['kind']!=='withdraw') throw new InvalidArgumentException('Escolha uma desistência para restaurar.');
        $q=db()->prepare('SELECT player_id FROM withdrawn_players WHERE operation_id=?'); $q->execute([$restore]);
        $ids=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
        if (!$ids) throw new InvalidArgumentException('Esta desistência já foi restaurada.');
        $snapshot=json_decode($op['snapshot'],true,512,JSON_THROW_ON_ERROR);
        foreach ($snapshot['games'] as $saved) {
            $now=gameById((int)$saved['id']);
            foreach (['player_a_id','player_b_id','score_a','score_b','played_at'] as $field) {
                if (!$now || $now[$field] !== $saved[$field]) throw new InvalidArgumentException('Um jogo do backup foi alterado. A restauração foi bloqueada para revisão administrativa.');
            }
        }
        $next=array_values(array_diff($current,$ids));
    } else {
        $ids=array_values(array_unique(array_map('intval',$ids))); sort($ids);
        $all=array_map('intval',db()->query('SELECT id FROM players')->fetchAll(PDO::FETCH_COLUMN));
        if (!$ids || array_diff($ids,$all) || array_intersect($ids,$current)) throw new InvalidArgumentException('Selecione participantes que ainda não desistiram.');
        $next=array_values(array_unique(array_merge($current,$ids))); sort($next);
    }
    $games=array_values(array_filter(gameRows(),fn($g)=>in_array((int)$g['player_a_id'],$ids,true)||in_array((int)$g['player_b_id'],$ids,true)));
    $before=publicData($current); $after=publicData($next);
    $direct=[];
    foreach ($games as $game) {
        if (gameExcluded($game,$current)!==gameExcluded($game,$next)) {
            $direct[]='p'.$game['player_a_id']; $direct[]='p'.$game['player_b_id'];
        }
    }
    $rows=[]; $b=array_column($before['players'],null,'id'); $a=array_column($after['players'],null,'id');
    foreach (db()->query('SELECT id,name FROM players ORDER BY name') as $p) {
        $key='p'.$p['id']; $old=$b[$key]??null; $new=$a[$key]??null;
        if ($old===null && $new===null && !in_array((int)$p['id'],$ids,true)) continue;
        $changed=$old!==$new;
        if (!$changed && !in_array($key,$direct,true)) continue;
        $rows[]=['id'=>(int)$p['id'],'name'=>$p['name'],'direct'=>in_array($key,$direct,true),'before'=>$old,'after'=>$new];
    }
    usort($rows,fn($a,$b)=>($b['direct']<=>$a['direct']) ?: (abs(($b['after']['points']??0)-($b['before']['points']??0)) <=> abs(($a['after']['points']??0)-($a['before']['points']??0))) ?: strcmp($a['name'],$b['name']));
    foreach (['before','after'] as $side) { ${$side}['excludedCount']=count(${$side}['excludedGames']); ${$side}['games']=[]; ${$side}['excludedGames']=[]; }
    return ['ids'=>$ids,'restore'=>$restore,'hash'=>withdrawalStateHash(),'games'=>$games,'before'=>$before,'after'=>$after,'rows'=>$rows,'activeBefore'=>$current,'activeAfter'=>$next];
}

function withdrawalApply(array $ids, string $restore, string $expected, string $reason): string
{
    if (!hasFullAccess()) throw new InvalidArgumentException('Acesso exclusivo da administração.');
    $reason=trim($reason);
    if ($reason==='' || strlen($reason)>3000 || !preg_match('//u',$reason)) throw new InvalidArgumentException('Informe uma justificativa de até 3000 bytes.');
    $pdo=db(); $pdo->exec('BEGIN IMMEDIATE');
    try {
        if (!hash_equals(withdrawalStateHash(),$expected)) throw new InvalidArgumentException('Os dados mudaram desde a prévia. Confira uma nova prévia antes de confirmar.');
        $snapshot=withdrawalPreview($ids,$restore);
        $id=bin2hex(random_bytes(16)); $now=gmdate('c');
        $pdo->prepare('INSERT INTO withdrawal_operations VALUES (?,?,?,?,?,?,?)')->execute([$id,$restore===''?'withdraw':'restore',$restore?:null,$now,auditActor(),$reason,json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
        foreach ($snapshot['ids'] as $player) {
            if ($restore==='') $pdo->prepare('INSERT INTO withdrawn_players VALUES (?,?)')->execute([$player,$id]);
            else $pdo->prepare('DELETE FROM withdrawn_players WHERE player_id=? AND operation_id=?')->execute([$player,$restore]);
        }
        // Invalidate old result forms and pending signature links, even after restoration.
        foreach ($snapshot['games'] as $game) $pdo->prepare('UPDATE games SET result_revision=result_revision+1 WHERE id=?')->execute([$game['id']]);
        auditRecord($restore===''?'Desistência registrada':'Desistência restaurada','Desistência '.$id,[],['justificativa'=>$reason,'status'=>count($snapshot['games']).' jogos preservados; classificação recalculada','arquivo'=>'Backup específico '.$id,'gerado_por'=>auditActor()]);
        $q=$pdo->prepare('SELECT event_id FROM audit_log WHERE target=?'); $q->execute(['Desistência '.$id]); $event=$q->fetchColumn();
        $recipients=[organizerNotificationEmail()];
        foreach ($snapshot['rows'] as $row) {
            $q=$pdo->prepare('SELECT email FROM users WHERE player_id=? AND is_active=1'); $q->execute([$row['id']]);
            $recipients=array_merge($recipients,$q->fetchAll(PDO::FETCH_COLUMN));
        }
        foreach (array_unique(array_map(fn($e)=>strtolower(trim($e)),$recipients)) as $email) {
            if (filter_var($email,FILTER_VALIDATE_EMAIL)) $pdo->prepare("INSERT OR IGNORE INTO email_notifications(event_id,recipient,audience) VALUES (?,?,'withdrawal')")->execute([$event,$email]);
        }
        $pdo->exec('COMMIT');
    } catch (Throwable $e) { $pdo->exec('ROLLBACK'); throw $e; }
    sendPlayerNotification((string)$event);
    return $id;
}

function withdrawalReport(array $operation, array $snapshot): array
{
    require_once __DIR__.'/documents.php';
    $time=(new DateTimeImmutable($operation['created_at']))->setTimezone(new DateTimeZone(config()['timezone']));
    $report=competitionReport($snapshot['after'],$time,'ranking');
    $report['generated']='Operação em '.$time->format('d/m/Y H:i:s').' ('.config()['timezone'].')';
    $report['title']='Desistências — antes e depois';
    $report['filename']='desistencia-'.$operation['id'];
    $names=[];
    foreach (array_merge($snapshot['before']['players'],$snapshot['before']['withdrawnPlayers']??[]) as $p) if (in_array((int)substr($p['id'],1),$snapshot['ids'],true)) $names[]=$p['name'];
    $report['note']='Operação '.$operation['id'].' • Cópia histórica; alterações posteriores não modificam este relatório.';
    $note=($operation['kind']==='restore'?'Restauração':'Desistência').': '.implode(', ',$names).'. Responsável: '.$operation['actor'].'. Motivo: '.$operation['reason'];
    $sections=[];
    foreach ([true,false] as $direct) {
        $rows=[];
        foreach ($snapshot['rows'] as $row) {
            if ($row['direct']!==$direct) continue;
            foreach (['before'=>'Antes','after'=>'Depois'] as $key=>$label) {
                $p=$row[$key];
                $rows[]=[$row['name'],$label,...array_map(fn($f)=>$p===null?'—':(string)$p[$f],['position','points','played','wins','draws','losses','goalsFor','goalsAgainst','goalDifference'])];
            }
            $rows[]=[$row['name'],'Diferença',...array_map(fn($f)=>$row['before']===null||$row['after']===null?'—':sprintf('%+d',$row['after'][$f]-$row['before'][$f]),['position','points','played','wins','draws','losses','goalsFor','goalsAgainst','goalDifference'])];
        }
        if ($rows) $sections[]=['title'=>$direct?'Participantes diretamente afetados':'Demais mudanças na classificação','note'=>$note.' Posição: diferença negativa indica subida. — indica fora da classificação. PG pontos; J jogos; V vitórias; E empates; D derrotas; GM gols marcados; GS sofridos; SG saldo.','headers'=>['Botonista','Momento','Pos.','PG','J','V','E','D','GM','GS','SG'],'widths'=>[27,13,7,7,6,6,6,6,7,7,8],'left'=>[0,1],'rows'=>$rows];
    }
    $rows=[];
    foreach ($snapshot['games'] as $g) {
        $played=$g['score_a']!==null && $g['score_b']!==null;
        $excluded=gameExcluded($g,$snapshot['activeAfter']);
        $rows[]=[(string)$g['id'],(string)$g['round_number'],$g['turn_number']==1?'Turno':'Returno',$g['a'],$g['b'],$played?$g['score_a'].' × '.$g['score_b']:'Sem resultado',(string)($g['played_at']??'—'),$excluded?($played?'Anulado':'Cancelado'):'Volta a contar'];
    }
    $sections[]=['title'=>'Jogos e registros originais preservados','note'=>$note.' Nenhum placar ou anexo foi apagado. Jogos contra outros desistentes continuam excluídos na restauração.','headers'=>['ID','Rod.','Etapa','Jogador A','Jogador B','Original','Data original','Após operação'],'widths'=>[5,5,9,22,22,12,11,14],'left'=>[3,4],'rows'=>$rows];
    $report['sections']=$sections;
    return $report;
}
