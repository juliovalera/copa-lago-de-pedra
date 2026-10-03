<?php
declare(strict_types=1);

function initialiseComplaints(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS complaints (
        id TEXT PRIMARY KEY, protocol TEXT NOT NULL UNIQUE, name TEXT NOT NULL, email TEXT NOT NULL, phone TEXT NOT NULL,
        subject TEXT NOT NULL, description TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'unconfirmed',
        created_at INTEGER NOT NULL, confirmed_at INTEGER, ip_hash TEXT NOT NULL,
        confirm_hash TEXT NOT NULL, confirm_until INTEGER NOT NULL,
        author_hash TEXT, author_until INTEGER, defense_hash TEXT, defense_until INTEGER,
        defense_email TEXT, shared_summary TEXT, decision TEXT, revision INTEGER NOT NULL DEFAULT 0
    );
    CREATE TABLE IF NOT EXISTS complaint_events (
        id TEXT PRIMARY KEY, complaint_id TEXT NOT NULL, occurred_at INTEGER NOT NULL,
        actor TEXT NOT NULL, audience TEXT NOT NULL, message TEXT NOT NULL
    );
    CREATE TABLE IF NOT EXISTS complaint_files (
        id TEXT PRIMARY KEY, complaint_id TEXT NOT NULL, audience TEXT NOT NULL,
        name TEXT NOT NULL, mime TEXT NOT NULL, bytes BLOB NOT NULL
    );
    CREATE TABLE IF NOT EXISTS complaint_mail (
        id TEXT PRIMARY KEY, complaint_id TEXT NOT NULL, recipient TEXT NOT NULL,
        subject TEXT NOT NULL, body TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pending',
        attempts INTEGER NOT NULL DEFAULT 0, attempted_at INTEGER
    );
    CREATE INDEX IF NOT EXISTS complaints_rate_idx ON complaints(created_at,ip_hash);
    CREATE INDEX IF NOT EXISTS complaint_events_case_idx ON complaint_events(complaint_id,occurred_at);
    CREATE INDEX IF NOT EXISTS complaint_mail_case_idx ON complaint_mail(complaint_id);
    CREATE INDEX IF NOT EXISTS complaint_files_case_idx ON complaint_files(complaint_id);");
}
function complaintQuery(string $sql, array $args=[]): PDOStatement { $q=db()->prepare($sql); $q->execute($args); return $q; }
function complaintText(array $input, string $key, int $min, int $max): string {
    $value=is_string($input[$key]??null)?trim($input[$key]):'';
    if (strlen($value)<$min || strlen($value)>$max || !preg_match('//u',$value) || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$value)) throw new RuntimeException('Confira o campo '.$key.' e seu tamanho.');
    return $value;
}
function complaintEmail(string $value): string {
    $value=strtolower(trim($value));
    if (strlen($value)>254 || !filter_var($value,FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/',$value)) throw new RuntimeException('Informe um e-mail válido.');
    return $value;
}
function complaintGet(string $id): array {
    $row=complaintQuery('SELECT * FROM complaints WHERE id=?',[$id])->fetch();
    if (!$row) throw new RuntimeException('Registro indisponível.');
    return $row;
}
function complaintEvent(string $id, string $actor, string $audience, string $message): void {
    complaintQuery('INSERT INTO complaint_events VALUES (?,?,?,?,?,?)',[bin2hex(random_bytes(16)),$id,time(),$actor,$audience,$message]);
}
function complaintMail(string $id, string $email, string $subject, string $body): void {
    complaintQuery('INSERT INTO complaint_mail(id,complaint_id,recipient,subject,body) VALUES (?,?,?,?,?)',[bin2hex(random_bytes(16)),$id,complaintEmail($email),$subject,$body]);
}
function complaintLink(string $token, string $mode): string {
    return rtrim(config()['base_url'],'/').'/denuncia.php?mode='.$mode.'&token='.rawurlencode($token);
}
function complaintDispatch(string $id): void {
    if (!function_exists('smtpSend')) require_once __DIR__.'/mailer.php';
    $mails=complaintQuery("SELECT * FROM complaint_mail WHERE complaint_id=? AND status='pending'",[$id])->fetchAll();
    foreach ($mails as $mail) {
        $q=complaintQuery("UPDATE complaint_mail SET status='sending',attempts=attempts+1,attempted_at=? WHERE id=? AND status='pending'",[time(),$mail['id']]);
        if (!$q->rowCount()) continue;
        try { smtpSend($mail['recipient'],$mail['subject'],$mail['body']); $status='sent'; }
        catch (Throwable $error) { $status='failed'; }
        complaintQuery('UPDATE complaint_mail SET status=? WHERE id=?',[$status,$mail['id']]);
    }
}
function complaintAdmins(string $id, string $message): void {
    $emails=complaintQuery("SELECT email FROM users WHERE is_active=1 AND role='admin'")->fetchAll(PDO::FETCH_COLUMN);
    $emails[]=organizerNotificationEmail();
    foreach (array_unique(array_map('strtolower',$emails)) as $email) {
        if (!filter_var($email,FILTER_VALIDATE_EMAIL)) continue;
        complaintMail($id,$email,'Canal de denúncias — aviso reservado',$message."\nConsulte o painel com sua conta de administrador:\n".rtrim(config()['base_url'],'/').'/denuncias.php');
    }
}
function complaintUploads(array $upload): array {
    if (!$upload || !isset($upload['error'])) return [];
    if (!is_array($upload['error']) || count($upload['error'])>2) throw new RuntimeException('Anexe no máximo dois arquivos por envio.');
    $files=[];
    foreach ($upload['error'] as $i=>$error) {
        if ($error===UPLOAD_ERR_NO_FILE) continue;
        if ($error!==UPLOAD_ERR_OK || ($upload['size'][$i]??0)>2*1024*1024 || !is_uploaded_file($upload['tmp_name'][$i]??'')) throw new RuntimeException('Cada anexo deve ter até 2 MB.');
        $bytes=file_get_contents($upload['tmp_name'][$i]);
        $mime=str_starts_with($bytes,'%PDF-')?'application/pdf':false;
        if (!$mime) { $info=@getimagesizefromstring($bytes); $mime=$info['mime']??false; }
        if (!in_array($mime,['application/pdf','image/jpeg','image/png'],true)) throw new RuntimeException('Use apenas PDF, JPG ou PNG.');
        $name=basename(str_replace('\\','/',(string)$upload['name'][$i]));
        $files[]=['name'=>substr(preg_replace('/[\x00-\x1f\x7f]/','',$name),0,160),'mime'=>$mime,'bytes'=>$bytes];
    }
    return $files;
}
function complaintStoreFiles(string $id, string $audience, array $files): void {
    if ((int)complaintQuery('SELECT COUNT(*) FROM complaint_files WHERE complaint_id=?',[$id])->fetchColumn()+count($files)>8) throw new RuntimeException('O caso já atingiu o limite de oito anexos. Envie esclarecimentos por texto.');
    foreach ($files as $file) {
        $q=db()->prepare('INSERT INTO complaint_files VALUES (?,?,?,?,?,?)');
        foreach ([bin2hex(random_bytes(16)),$id,$audience,$file['name'],$file['mime']] as $i=>$v) $q->bindValue($i+1,$v);
        $q->bindValue(6,$file['bytes'],PDO::PARAM_LOB); $q->execute();
    }
}
function complaintCreate(array $input, array $files, string $ip): string {
    $name=complaintText($input,'nome',3,150); $email=complaintEmail(complaintText($input,'email',5,254));
    $phone=complaintText($input,'telefone',10,30); $digits=preg_replace('/\D/','',$phone);
    if (!preg_match('/^[+0-9().\s-]+$/D',$phone) || !preg_match('/^\d{10,13}$/D',$digits)) throw new RuntimeException('Informe o telefone com DDD.');
    $subject=complaintText($input,'envolvidos',3,300); $description=complaintText($input,'relato',30,10000);
    if (($input['consentimento']??'')!=='1' || !empty($input['website'])) throw new RuntimeException('Confira o formulário e aceite o uso reservado das informações.');
    $id=bin2hex(random_bytes(16)); $token=bin2hex(random_bytes(32)); $now=time();
    $ipHash=hash_hmac('sha256',$ip,(string)config()['admin_password']);
    db()->exec('BEGIN IMMEDIATE');
    try {
        if ((int)complaintQuery('SELECT COUNT(*) FROM complaints WHERE created_at>? AND (ip_hash=? OR email=?)',[$now-86400,$ipHash,$email])->fetchColumn()>=3) throw new RuntimeException('Limite de três registros em 24 horas para este contato ou conexão. Aguarde para tentar novamente.');
        $protocol='DEN-'.date('Ymd').'-'.strtoupper(substr($id,0,10));
        complaintQuery('INSERT INTO complaints(id,protocol,name,email,phone,subject,description,created_at,ip_hash,confirm_hash,confirm_until) VALUES (?,?,?,?,?,?,?,?,?,?,?)',[$id,$protocol,$name,$email,$phone,$subject,$description,$now,$ipHash,hash('sha256',$token),$now+172800]);
        complaintStoreFiles($id,'author',$files);
        complaintMail($id,$email,'Confirme seu registro no canal da Copa',"Você solicitou um registro no canal de denúncias. Confirme em até 48 horas:\n".complaintLink($token,'confirm')."\nSe não foi você, ignore esta mensagem. A denúncia não será encaminhada sem confirmação.");
        db()->exec('COMMIT');
    } catch (Throwable $e) { db()->exec('ROLLBACK'); throw $e; }
    complaintDispatch($id);
    return $id;
}
function complaintConfirm(string $token): array {
    if (!preg_match('/^[a-f0-9]{64}$/D',$token)) throw new RuntimeException('Link inválido ou expirado.');
    db()->exec('BEGIN IMMEDIATE');
    try {
        $row=complaintQuery("SELECT * FROM complaints WHERE confirm_hash=? AND status='unconfirmed' AND confirm_until>=?",[hash('sha256',$token),time()])->fetch();
        if (!$row) throw new RuntimeException('Link inválido, já utilizado ou expirado.');
        $access=bin2hex(random_bytes(32));
        complaintQuery("UPDATE complaints SET status='analysis',confirmed_at=?,confirm_hash='',author_hash=?,author_until=?,revision=revision+1 WHERE id=?",[time(),hash('sha256',$access),time()+90*86400,$row['id']]);
        complaintEvent($row['id'],'Sistema','author','E-mail confirmado. Registro encaminhado à administração.');
        complaintMail($row['id'],$row['email'],'Protocolo '.$row['protocol'],"Guarde seu link reservado de acompanhamento (90 dias). Não o compartilhe:\n".complaintLink($access,'author'));
        complaintAdmins($row['id'],'Há um novo registro confirmado: '.$row['protocol'].'. O relato ainda precisa ser apurado.');
        auditRecord('Denúncia confirmada',$row['protocol'],[],[],'Canal reservado','E-mail confirmado');
        db()->exec('COMMIT');
    } catch (Throwable $e) { db()->exec('ROLLBACK'); throw $e; }
    complaintDispatch($row['id']);
    return $row;
}
function complaintAccess(string $mode, string $token): ?array {
    if (!in_array($mode,['author','defense'],true) || !preg_match('/^[a-f0-9]{64}$/D',$token)) return null;
    return complaintQuery("SELECT * FROM complaints WHERE {$mode}_hash=? AND {$mode}_until>=? AND status<>'unconfirmed'",[hash('sha256',$token),time()])->fetch()?:null;
}
function complaintReply(string $id, string $audience, string $message, array $files, string $accessHash): void {
    $message=complaintText(['mensagem'=>$message],'mensagem',10,10000);
    db()->exec('BEGIN IMMEDIATE');
    try {
        $row=complaintGet($id);
        if (!in_array($audience,['author','defense'],true) || !in_array($row['status'],['analysis','defense','answered'],true)) throw new RuntimeException('Este caso não recebe novas mensagens.');
        if (!hash_equals((string)$row[$audience.'_hash'],$accessHash) || $row[$audience.'_until']<time()) throw new RuntimeException('O acesso expirou ou foi substituído. Use o link mais recente.');
        if ($audience==='defense' && (!$row['defense_until'] || $row['defense_until']<time())) throw new RuntimeException('O prazo de defesa terminou. Solicite novo prazo à organização.');
        if ((int)complaintQuery('SELECT COUNT(*) FROM complaint_events WHERE complaint_id=? AND occurred_at>?',[$id,time()-3600])->fetchColumn()>=20) throw new RuntimeException('Aguarde antes de enviar novas mensagens.');
        complaintStoreFiles($id,$audience,$files);
        complaintEvent($id,$audience==='author'?'Denunciante':'Pessoa convidada para defesa',$audience,$message);
        complaintQuery('UPDATE complaints SET status=?,revision=revision+1 WHERE id=?',[$audience==='defense'?'answered':$row['status'],$id]);
        complaintAdmins($id,'Nova mensagem no protocolo '.$row['protocol'].'.');
        auditRecord('Resposta no canal de denúncias',$row['protocol'],[],[],'Canal reservado',$audience==='author'?'Denunciante':'Defesa');
        db()->exec('COMMIT');
    } catch (Throwable $e) { db()->exec('ROLLBACK'); throw $e; }
    complaintDispatch($id);
}
function complaintAdminAction(string $id, array $input): void {
    if (!hasFullAccess()) throw new RuntimeException('Acesso restrito à administração.');
    $action=$input['action']??''; $actor=auditActor();
    db()->exec('BEGIN IMMEDIATE');
    try {
        $row=complaintGet($id);
        if (($row['status']==='unconfirmed' && $action!=='retry') || !isset($input['revision']) || (string)$row['revision']!==(string)$input['revision']) throw new RuntimeException('O caso foi atualizado ou ainda não foi confirmado. Recarregue e confira antes de salvar.');
        if (in_array($row['status'],['closed','archived'],true) && !in_array($action,['retry','renew'],true)) throw new RuntimeException('Este caso está encerrado.');
        if ($action==='invite') {
            $email=complaintEmail(complaintText($input,'email_defesa',5,254));
            $summary=complaintText($input,'resumo_defesa',30,10000);
            $date=complaintText($input,'prazo',10,10);
            if (!validGameDate($date)) throw new RuntimeException('Informe uma data válida para a resposta.');
            $until=(new DateTimeImmutable($date.' 23:59:59'))->getTimestamp();
            if ($until<time() || $until>time()+90*86400) throw new RuntimeException('Escolha um prazo de hoje até 90 dias.');
            $token=bin2hex(random_bytes(32));
            complaintQuery("UPDATE complaints SET status='defense',defense_email=?,shared_summary=?,defense_hash=?,defense_until=? WHERE id=?",[$email,$summary,hash('sha256',$token),$until,$id]);
            complaintMail($id,$email,'Convite reservado para esclarecimentos — '.$row['protocol'],"A organização solicita sua versão sobre um relato, sem conclusão antecipada. Responda até ".$date." pelo link reservado (não compartilhe):\n".complaintLink($token,'defense'));
            complaintEvent($id,$actor,'both','A organização abriu prazo para apresentação de defesa até '.$date.'.');
        } elseif ($action==='message') {
            $audience=$input['audience']??'';
            if (!in_array($audience,['author','defense','both'],true)) throw new RuntimeException('Escolha quem receberá a mensagem.');
            if ($audience!=='author' && !$row['defense_email']) throw new RuntimeException('Convide a pessoa citada antes de enviar mensagens a ela.');
            $message=complaintText($input,'mensagem',10,10000);
            complaintEvent($id,$actor,$audience,$message);
            foreach (array_unique(array_filter([$audience!=='defense'?$row['email']:null,$audience!=='author'?$row['defense_email']:null])) as $email) complaintMail($id,$email,'Atualização — '.$row['protocol'],$message."\nConsulte também seu link reservado de acompanhamento.");
        } elseif (in_array($action,['close','archive'],true)) {
            if ($action==='close' && (!$row['defense_until'] || ($row['status']!=='answered' && $row['defense_until']>=time()))) throw new RuntimeException('Para concluir a apuração, aguarde a defesa ou o término do prazo. É possível arquivar sem punição com justificativa.');
            $decision=complaintText($input,'justificativa',20,10000);
            complaintQuery('UPDATE complaints SET status=?,decision=? WHERE id=?',[$action==='close'?'closed':'archived',$decision,$id]);
            complaintEvent($id,$actor,'both',($action==='close'?'Apuração concluída: ':'Arquivado sem punição: ').$decision);
            foreach (array_unique(array_filter([$row['email'],$row['defense_email']])) as $email) complaintMail($id,$email,'Conclusão — '.$row['protocol'],$decision."\nNenhum placar foi alterado automaticamente pelo canal.");
        } elseif ($action==='renew') {
            $token=bin2hex(random_bytes(32));
            complaintQuery('UPDATE complaints SET author_hash=?,author_until=? WHERE id=?',[hash('sha256',$token),time()+90*86400,$id]);
            complaintMail($id,$row['email'],'Novo acesso — '.$row['protocol'],"Seu novo link reservado, válido por 90 dias, substitui o anterior:\n".complaintLink($token,'author'));
        } elseif ($action==='share') {
            $file=complaintText($input,'file',32,32);
            complaintQuery("UPDATE complaint_files SET audience='both' WHERE complaint_id=? AND id=?",[$id,$file]);
            complaintEvent($id,$actor,'both','A organização disponibilizou um anexo para ambas as partes.');
        } elseif ($action==='retry') {
            complaintQuery("UPDATE complaint_mail SET status='pending' WHERE complaint_id=? AND (status='failed' OR (status='sending' AND attempted_at<?))",[$id,time()-600]);
        } else throw new RuntimeException('Ação inválida.');
        complaintQuery('UPDATE complaints SET revision=revision+1 WHERE id=?',[$id]);
        auditRecord('Canal de denúncias: '.$action,$row['protocol'],[],[],'Canal reservado',$actor);
        db()->exec('COMMIT');
    } catch (Throwable $e) { db()->exec('ROLLBACK'); throw $e; }
    complaintDispatch($id);
}
function complaintStatus(string $status): string { return ['unconfirmed'=>'Aguardando confirmação','analysis'=>'Em análise','defense'=>'Aguardando defesa','answered'=>'Defesa apresentada','closed'=>'Apuração concluída','archived'=>'Arquivado sem punição'][$status]??$status; }

function complaintDownload(string $id, string $fileId, string $role): never {
    $file=complaintQuery('SELECT * FROM complaint_files WHERE id=? AND complaint_id=?',[$fileId,$id])->fetch();
    if (!$file || ($role!=='admin' && !in_array($file['audience'],[$role,'both'],true))) { http_response_code(404); exit('Anexo indisponível.'); }
    header('Content-Type: '.$file['mime']);
    header('Content-Disposition: attachment; filename="anexo.'.['application/pdf'=>'pdf','image/png'=>'png','image/jpeg'=>'jpg'][$file['mime']].'"');
    header('Content-Length: '.strlen($file['bytes'])); echo $file['bytes']; exit;
}
