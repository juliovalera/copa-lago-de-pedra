<?php
declare(strict_types=1);

function initialiseConfirmations(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS match_confirmations (
        id TEXT PRIMARY KEY, game_id INTEGER NOT NULL, result_token TEXT NOT NULL,
        snapshot TEXT NOT NULL, creator_key TEXT NOT NULL, creator_name TEXT NOT NULL,
        created_at TEXT NOT NULL, expires_at INTEGER NOT NULL, status TEXT NOT NULL DEFAULT 'pending',
        hash_a TEXT NOT NULL, hash_b TEXT NOT NULL, signature_a TEXT, signature_b TEXT,
        signed_a TEXT, signed_b TEXT, divergence TEXT, approved_at TEXT, approved_by TEXT
    )");
    db()->exec('CREATE INDEX IF NOT EXISTS confirmation_game ON match_confirmations(game_id,created_at)');
    db()->exec("CREATE TRIGGER IF NOT EXISTS confirmation_final_update BEFORE UPDATE ON match_confirmations WHEN OLD.status='final' BEGIN SELECT RAISE(ABORT,'Final confirmation is immutable'); END");
    db()->exec("CREATE TRIGGER IF NOT EXISTS confirmation_final_delete BEFORE DELETE ON match_confirmations WHEN OLD.status='final' BEGIN SELECT RAISE(ABORT,'Final confirmation is immutable'); END");
}

function confirmationKey(): string
{
    if (!hasFullAccess()) throw new InvalidArgumentException('Acesso exclusivo da administração.');
    return isMasterAdmin() ? 'master' : 'user:'.currentUser()['id'];
}

function confirmationGet(string $id): array
{
    $q=db()->prepare('SELECT * FROM match_confirmations WHERE id=?'); $q->execute([$id]);
    return $q->fetch() ?: throw new InvalidArgumentException('Confirmação indisponível.');
}

function confirmationCurrent(array $row): bool
{
    $game=gameById((int)$row['game_id']);
    return $game && hash_equals($row['result_token'],panelResultToken($game));
}

function confirmationStatus(array $row): string
{
    if (!confirmationCurrent($row)) return 'Dados alterados — documento anterior; nova confirmação necessária';
    if ($row['status']==='final') return 'Súmula concluída';
    if ($row['status']==='cancelled') return 'Cancelada';
    if ($row['status']==='divergent') return 'Divergência informada — análise da administração';
    if ($row['signature_a'] && $row['signature_b']) return 'Aguardando aval do administrador';
    if ((int)$row['expires_at']<time()) return 'Links expirados';
    return $row['signature_a'] || $row['signature_b'] ? 'Aguardando uma assinatura' : 'Aguardando as duas assinaturas';
}

function confirmationCreate(int $gameId, int $days): array
{
    $creator=confirmationKey();
    if ($days<1 || $days>30) throw new InvalidArgumentException('Escolha uma validade entre 1 e 30 dias.');
    return auditedTransaction(static function () use ($gameId,$days,$creator): array {
        $game=gameById($gameId);
        if (!$game || $game['score_a']===null || $game['score_b']===null || !validGameDate((string)$game['played_at']) || $game['played_at']>date('Y-m-d')) throw new InvalidArgumentException('Escolha um jogo com resultado e data já ocorrida.');
        $q=db()->prepare("SELECT * FROM match_confirmations WHERE game_id=? AND status IN ('pending','final')"); $q->execute([$gameId]);
        foreach ($q as $row) {
            if (confirmationCurrent($row) && ($row['status']==='final' || (int)$row['expires_at']>=time() || ($row['signature_a'] && $row['signature_b']))) throw new InvalidArgumentException('Já existe uma confirmação vigente. Abra o documento; para substituir links pendentes, cancele a coleta anterior.');
        }
        $id=bin2hex(random_bytes(16)); $a=bin2hex(random_bytes(32)); $b=bin2hex(random_bytes(32));
        $snapshot=['a'=>$game['a'],'b'=>$game['b'],'player_a_id'=>$game['player_a_id'],'player_b_id'=>$game['player_b_id'],'score_a'=>$game['score_a'],'score_b'=>$game['score_b'],'date'=>$game['played_at'],'round'=>$game['round_number'],'turn'=>$game['turn_number']];
        db()->prepare('INSERT INTO match_confirmations(id,game_id,result_token,snapshot,creator_key,creator_name,created_at,expires_at,hash_a,hash_b) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$id,$gameId,panelResultToken($game),json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$creator,auditActor(),gmdate('c'),time()+$days*86400,hash('sha256',$a),hash('sha256',$b)]);
        auditRecord('Confirmação de partida criada','Jogo '.$gameId,[],['status'=>'Aguardando assinaturas','gerado_por'=>auditActor()]);
        return ['id'=>$id,'a'=>$a,'b'=>$b];
    });
}

function confirmationGrant(string $token): array
{
    if (!preg_match('/^[a-f0-9]{64}$/D',$token)) throw new InvalidArgumentException('Link inválido ou indisponível.');
    $hash=hash('sha256',$token);
    $q=db()->prepare('SELECT * FROM match_confirmations WHERE hash_a=? OR hash_b=?'); $q->execute([$hash,$hash]);
    $row=$q->fetch();
    if (!$row) throw new InvalidArgumentException('Link inválido ou indisponível.');
    $side=hash_equals($row['hash_a'],$hash)?'a':'b';
    confirmationCheckGrant($row,$side,$hash);
    return ['id'=>$row['id'],'side'=>$side,'hash'=>$hash];
}

function confirmationCheckGrant(array $row, string $side, string $hash): void
{
    if (!in_array($side,['a','b'],true) || !hash_equals($row['hash_'.$side],$hash) || $row['status']!=='pending' || (int)$row['expires_at']<time() || $row['signature_'.$side]!==null || !confirmationCurrent($row)) throw new InvalidArgumentException('Este link expirou, já foi utilizado ou os dados mudaram. Procure a administração.');
}

function confirmationSign(array $grant, string $signature, bool $consent, string $divergence=''): void
{
    auditedTransaction(static function () use ($grant,$signature,$consent,$divergence): void {
        $row=confirmationGet($grant['id']); $side=$grant['side'];
        confirmationCheckGrant($row,$side,$grant['hash']);
        $snapshot=json_decode($row['snapshot'],true,512,JSON_THROW_ON_ERROR);
        $actor='Portador do link de '.$snapshot[$side].' (identidade não verificada)';
        if ($divergence!=='') {
            $text=trim($divergence);
            if ($text==='' || strlen($text)>4000 || preg_match('//u',$text)!==1) throw new InvalidArgumentException('Descreva a divergência em até 1000 caracteres.');
            db()->prepare("UPDATE match_confirmations SET status='divergent',divergence=? WHERE id=?")->execute([$snapshot[$side].': '.$text,$row['id']]);
            auditRecord('Divergência na confirmação','Jogo '.$row['game_id'],[],['status'=>'Aguardando análise'],'Link individual',$actor);
            return;
        }
        if (!$consent) throw new InvalidArgumentException('Confirme que conferiu os dados da partida.');
        try { $strokes=digitalSignature($signature); } catch (InvalidArgumentException $e) { throw new InvalidArgumentException('Assine com um traço completo antes de salvar.'); }
        db()->prepare("UPDATE match_confirmations SET signature_$side=?,signed_$side=? WHERE id=?")->execute([json_encode($strokes,JSON_THROW_ON_ERROR),gmdate('c'),$row['id']]);
        auditRecord('Partida assinada','Jogo '.$row['game_id'],[],['status'=>'Assinatura recebida'],'Link individual',$actor);
    });
}

function confirmationAdminAction(string $id, string $action): void
{
    $key=confirmationKey();
    auditedTransaction(static function () use ($id,$action,$key): void {
        $row=confirmationGet($id);
        if ($row['status']==='final') throw new InvalidArgumentException('O documento concluído é preservado no histórico.');
        if ($action==='cancel') {
            if ($row['status']==='cancelled') throw new InvalidArgumentException('Coleta já cancelada.');
            db()->prepare("UPDATE match_confirmations SET status='cancelled' WHERE id=?")->execute([$id]);
            auditRecord('Coleta de assinaturas cancelada','Jogo '.$row['game_id']); return;
        }
        if ($action!=='approve' || $row['creator_key']!==$key) throw new InvalidArgumentException('Somente o administrador que gerou os links pode dar o aval.');
        if ($row['status']!=='pending' || !$row['signature_a'] || !$row['signature_b'] || !confirmationCurrent($row)) throw new InvalidArgumentException('São necessárias duas assinaturas dos dados atuais, sem divergência.');
        db()->prepare("UPDATE match_confirmations SET status='final',approved_at=?,approved_by=? WHERE id=?")->execute([gmdate('c'),auditActor(),$id]);
        auditRecord('Confirmação de partida concluída','Jogo '.$row['game_id'],[],['status'=>'Duas assinaturas e aval da organização','gerado_por'=>auditActor()]);
    });
}
