<?php
declare(strict_types=1);

function initialiseDigitalSheets(): void
{
    // Documents are retained independently of game deletion/restoration.
    db()->exec("CREATE TABLE IF NOT EXISTS digital_sheets (
        id INTEGER PRIMARY KEY, game_id INTEGER NOT NULL, match_date TEXT NOT NULL,
        revision INTEGER NOT NULL DEFAULT 1, status TEXT NOT NULL DEFAULT 'draft',
        data_json TEXT NOT NULL, signatures_json TEXT NOT NULL DEFAULT '{}',
        updated_by TEXT NOT NULL, updated_at TEXT NOT NULL, finalized_at TEXT NULL,
        UNIQUE(game_id,match_date))");
    db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS digital_final_game ON digital_sheets(game_id) WHERE status='final'");
    db()->exec("CREATE TRIGGER IF NOT EXISTS digital_final_update BEFORE UPDATE ON digital_sheets WHEN OLD.status='final' BEGIN SELECT RAISE(ABORT,'Final document is immutable'); END");
    db()->exec("CREATE TRIGGER IF NOT EXISTS digital_final_delete BEFORE DELETE ON digital_sheets WHEN OLD.status='final' BEGIN SELECT RAISE(ABORT,'Final document is immutable'); END");
}

function digitalSheet(int $game, string $date): ?array
{
    $q=db()->prepare('SELECT * FROM digital_sheets WHERE game_id=? AND match_date=?');
    $q->execute([$game,$date]); return $q->fetch() ?: null;
}

function digitalContext(int $gameId, string $date, string $token): array
{
    $q=db()->prepare('SELECT * FROM referee_links WHERE game_id=? AND generated_on=?');
    $q->execute([$gameId,$date]); $link=$q->fetch();
    $user=currentUser();
    $authorized=isMasterAdmin() || ($user && gameIsAccessible($gameId,userGameRestriction($user)));
    if (!$link || (!$authorized && ($date!==date('Y-m-d') || $token==='' || !hash_equals($link['token'],$token) || $link['used_at']!==null))) {
        throw new RuntimeException('Acesso indisponível. Entre com uma conta autorizada ou use o QR na data do jogo.');
    }
    return $link;
}

function digitalSignature(mixed $raw): array
{
    if (!is_string($raw) || strlen($raw)>100000) throw new InvalidArgumentException('Assinatura inválida. Limpe o campo e assine novamente.');
    try { $strokes=json_decode($raw,true,32,JSON_THROW_ON_ERROR); }
    catch (Throwable $e) { throw new InvalidArgumentException('Assinatura inválida.'); }
    if (!is_array($strokes) || !array_is_list($strokes) || count($strokes)>100) throw new InvalidArgumentException('Assinatura inválida.');
    $count=0; $length=0;
    foreach ($strokes as $stroke) {
        if (!is_array($stroke) || !array_is_list($stroke) || count($stroke)<2) throw new InvalidArgumentException('Assine com um traço completo.');
        $last=null;
        foreach ($stroke as $point) {
            if (!is_array($point) || !array_is_list($point) || count($point)!==2 || !is_numeric($point[0]) || !is_numeric($point[1]) || !is_finite((float)$point[0]) || !is_finite((float)$point[1]) || $point[0]<0 || $point[0]>1000 || $point[1]<0 || $point[1]>500 || ++$count>4000) throw new InvalidArgumentException('Assinatura inválida.');
            if ($last) $length+=hypot((float)$point[0]-$last[0],(float)$point[1]-$last[1]);
            $last=$point;
        }
    }
    if ($count<8 || $length<30) throw new InvalidArgumentException('Colete as três assinaturas completas antes de finalizar.');
    return $strokes;
}

function digitalSignatureSvg(array $strokes): string
{
    $svg='<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 500" role="img" aria-label="Assinatura registrada">';
    foreach ($strokes as $stroke) {
        $points=implode(' ',array_map(static fn($p)=>sprintf('%.2F,%.2F',(float)$p[0],(float)$p[1]),$stroke));
        $svg.='<polyline points="'.$points.'" fill="none" stroke="#123e32" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>';
    }
    return $svg.'</svg>';
}

function saveDigitalSheet(int $gameId, string $date, string $token, int $revision, string $expectedGame, array $input, bool $final): array
{
    return auditedTransaction(static function () use ($gameId,$date,$token,$revision,$expectedGame,$input,$final): array {
        $link=digitalContext($gameId,$date,$token);
        $game=gameById($gameId); $sheet=digitalSheet($gameId,$date);
        if (!$game || $link['used_at']!==null || $game['score_a']!==null || $game['score_b']!==null || ($sheet['status']??'')==='final') throw new ResultConflict('O jogo já foi registrado ou a súmula já foi finalizada. Procure a organização para corrigir o resultado.');
        if (!hash_equals(panelResultToken($game),$expectedGame) || (int)($sheet['revision']??0)!==$revision) throw new ResultConflict('O jogo ou o rascunho mudou em outro acesso. Reabra a súmula e confira os dados antes de continuar.');
        if ($date<date('Y-m-d') || ($final && $date!==date('Y-m-d'))) throw new InvalidArgumentException('A finalização só é permitida na data do jogo. O rascunho pode ser preparado antes.');
        $data=['a'=>$game['a'],'b'=>$game['b'],'round'=>$game['round_number'],'turn'=>$game['turn_number'],'player_a_id'=>$game['player_a_id'],'player_b_id'=>$game['player_b_id']];
        foreach (['venue'=>160,'time'=>5,'table'=>40,'referee'=>100,'notes'=>2000] as $key=>$max) {
            $value=is_string($input[$key]??null) ? trim($input[$key]) : '';
            if (strlen($value)>$max*4 || preg_match('//u',$value)!==1 || preg_match_all('/./us',$value)>$max) throw new InvalidArgumentException('Um campo ultrapassou o tamanho permitido.');
            $data[$key]=$value;
        }
        if ($data['time']!=='' && !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$data['time'])) throw new InvalidArgumentException('Informe um horário válido.');
        if ($final && ($data['referee']==='' || $data['time']==='')) throw new InvalidArgumentException('Informe o nome do árbitro e o horário.');
        foreach (['first_a','first_b','second_a','second_b'] as $key) {
            $v=$input[$key]??'';
            if ($v==='' && !$final) { $data[$key]=null; continue; }
            $n=filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>999]]);
            if ($n===false) throw new InvalidArgumentException('Informe os gols de cada tempo, de 0 a 999.');
            $data[$key]=$n;
        }
        $signatures=[];
        if ($final) {
            if (($input['consent']??'')!=='1') throw new InvalidArgumentException('Confirme a conferência dos dados e das assinaturas.');
            foreach (['a','b','referee'] as $who) $signatures[$who]=digitalSignature($input['signature_'.$who]??null);
        }
        $actor=currentUser() || isMasterAdmin() ? auditActor() : 'Portador do QR (identidade não verificada)';
        $now=gmdate('c'); $status=$final?'final':'draft';
        db()->prepare("INSERT INTO digital_sheets(game_id,match_date,revision,status,data_json,signatures_json,updated_by,updated_at,finalized_at) VALUES (?,?,1,?,?,?,?,?,?) ON CONFLICT(game_id,match_date) DO UPDATE SET revision=revision+1,status=excluded.status,data_json=excluded.data_json,signatures_json=excluded.signatures_json,updated_by=excluded.updated_by,updated_at=excluded.updated_at,finalized_at=excluded.finalized_at")
            ->execute([$gameId,$date,$status,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),json_encode($signatures,JSON_THROW_ON_ERROR),$actor,$now,$final?$now:null]);
        if ($final) {
            $q=db()->prepare('UPDATE games SET score_a=?,score_b=?,played_at=? WHERE id=? AND score_a IS NULL AND score_b IS NULL');
            $q->execute([$data['first_a']+$data['second_a'],$data['first_b']+$data['second_b'],$date,$gameId]);
            if ($q->rowCount()!==1) throw new ResultConflict('O resultado já foi registrado.');
            db()->prepare('UPDATE referee_links SET used_at=? WHERE game_id=? AND used_at IS NULL')->execute([$now,$gameId]);
            auditRecord('Resultado salvo','Jogo '.$gameId,auditGame($game),auditGame(gameById($gameId)), 'Súmula digital',$actor);
        }
        auditRecord($final?'Súmula digital finalizada':'Rascunho de súmula salvo','Jogo '.$gameId,[],['data'=>$date,'status'=>$status], 'Súmula digital',$actor);
        return digitalSheet($gameId,$date);
    });
}
