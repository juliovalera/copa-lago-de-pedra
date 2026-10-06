<?php
declare(strict_types=1);

const ATTACHMENT_PREFIX = "<?php http_response_code(404); exit; __halt_compiler();\n";

function initialiseAttachments(): void
{
    db()->exec('CREATE TABLE IF NOT EXISTS game_attachments (
        id TEXT PRIMARY KEY, game_id INTEGER NOT NULL, player_a_id INTEGER NOT NULL, player_b_id INTEGER NOT NULL,
        filename TEXT NOT NULL, mime TEXT NOT NULL, size INTEGER NOT NULL, sha256 TEXT NOT NULL,
        created_at TEXT NOT NULL, created_by TEXT NOT NULL, removed_at TEXT, removed_by TEXT
    )');
    db()->exec('CREATE INDEX IF NOT EXISTS attachments_game ON game_attachments(game_id,removed_at)');
}

function attachmentDirectory(): string
{
    $dir=config()['attachment_directory']??(dirname(config()['database']).'/sumulas');
    if (!is_dir($dir) && !mkdir($dir,0700,true)) throw new RuntimeException('Não foi possível criar a pasta dos anexos.');
    $deny="Require all denied\n";
    if (!is_file($dir.'/.htaccess') && file_put_contents($dir.'/.htaccess',$deny)!==strlen($deny)) throw new RuntimeException('Não foi possível proteger a pasta dos anexos.');
    return $dir;
}

function attachmentPath(string $id, ?string $directory=null): string
{
    if (!preg_match('/^[a-f0-9]{32}$/D',$id)) throw new InvalidArgumentException('Anexo inválido.');
    return ($directory??attachmentDirectory()).'/'.$id.'.php';
}

function attachmentGame(int $game): array
{
    $row=gameById($game); $user=currentUser();
    if (!$row || (!hasFullAccess() && (!$user || !$user['player_id'] || !in_array((int)$user['player_id'],[(int)$row['player_a_id'],(int)$row['player_b_id']],true)))) throw new InvalidArgumentException('Você não tem acesso aos anexos deste jogo.');
    return $row;
}

function attachmentGet(string $id): array
{
    $q=db()->prepare('SELECT * FROM game_attachments WHERE id=? AND removed_at IS NULL'); $q->execute([$id]);
    $row=$q->fetch();
    if (!$row) throw new InvalidArgumentException('Anexo indisponível.');
    attachmentGame((int)$row['game_id']);
    if (!hasFullAccess() && !in_array((int)currentUser()['player_id'],[(int)$row['player_a_id'],(int)$row['player_b_id']],true)) throw new InvalidArgumentException('Anexo indisponível.');
    return $row;
}

function attachmentUpload(int $gameId, array $file, string $replace=''): void
{
    attachmentGame($gameId);
    if ($replace!=='' && !hasFullAccess()) throw new InvalidArgumentException('Somente administradores podem substituir anexos.');
    if (($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_string($file['tmp_name']??null) || !is_uploaded_file($file['tmp_name'])) throw new InvalidArgumentException('Selecione um arquivo de até 2 MB. O limite da hospedagem também se aplica.');
    if (filesize($file['tmp_name'])>2*1024*1024) throw new InvalidArgumentException('O arquivo deve ter até 2 MB.');
    $bytes=file_get_contents($file['tmp_name']);
    if ($bytes===false || strlen($bytes)<8 || strlen($bytes)>2*1024*1024) throw new InvalidArgumentException('O arquivo deve ter até 2 MB.');
    $name=is_string($file['name']??null)?basename(str_replace('\\','/',$file['name'])):'';
    if ($name==='' || strlen($name)>240 || preg_match('//u',$name)!==1 || preg_match('/[\x00-\x1F\x7F]/',$name)) throw new InvalidArgumentException('Nome de arquivo inválido.');
    $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
    $mime='';
    $valid=$ext==='pdf' && preg_match('/^%PDF-[12]\.[0-9]/',$bytes) && str_contains(substr($bytes,-2048),'%%EOF');
    if ($valid) $mime='application/pdf';
    if (in_array($ext,['jpg','jpeg','png'],true)) {
        $image=@getimagesizefromstring($bytes);
        $mime=$image['mime']??'';
        $valid=$image && $image[0]<=16000 && $image[1]<=16000 && (($ext==='png' && $mime==='image/png' && $image[2]===IMAGETYPE_PNG) || ($ext!=='png' && $mime==='image/jpeg' && $image[2]===IMAGETYPE_JPEG));
    }
    if (!$valid) throw new InvalidArgumentException('Envie um PDF, JPG ou PNG válido.');
    $id=bin2hex(random_bytes(16)); $path=attachmentPath($id); $written=false;
    try {
        auditedTransaction(static function () use ($gameId,$replace,$bytes,$mime,$name,$id,$path,&$written): void {
            $game=attachmentGame($gameId);
            if ($replace!=='') {
                if (!hasFullAccess()) throw new InvalidArgumentException('Acesso restrito.');
                $old=attachmentGet($replace);
                if ((int)$old['game_id']!==$gameId) throw new InvalidArgumentException('Anexo de outro jogo.');
                db()->prepare('UPDATE game_attachments SET removed_at=?,removed_by=? WHERE id=?')->execute([gmdate('c'),auditActor(),$replace]);
            }
            $q=db()->prepare('SELECT COUNT(*) FROM game_attachments WHERE game_id=? AND removed_at IS NULL'); $q->execute([$gameId]);
            if ((int)$q->fetchColumn()>=2) throw new InvalidArgumentException('Este jogo já tem dois anexos. Peça à administração para substituir um deles.');
            $handle=fopen($path,'x+b'); if (!$handle) throw new RuntimeException('Não foi possível guardar o arquivo.');
            $written=true;
            try { $payload=ATTACHMENT_PREFIX.$bytes; if (fwrite($handle,$payload)!==strlen($payload) || !fflush($handle)) throw new RuntimeException('Falha ao guardar o arquivo.'); } finally { fclose($handle); }
            @chmod($path,0600);
            db()->prepare('INSERT INTO game_attachments(id,game_id,player_a_id,player_b_id,filename,mime,size,sha256,created_at,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$id,$gameId,$game['player_a_id'],$game['player_b_id'],$name,$mime,strlen($bytes),hash('sha256',ATTACHMENT_PREFIX.$bytes),gmdate('c'),auditActor()]);
            auditRecord($replace===''?'Súmula anexada':'Anexo de súmula substituído','Jogo '.$gameId,$replace!==''?['arquivo'=>$old['filename']]:[],['arquivo'=>$name]);
        });
    } catch (Throwable $e) {
        // Only remove the new, random file if its metadata did not commit.
        $q=db()->prepare('SELECT COUNT(*) FROM game_attachments WHERE id=?'); $q->execute([$id]);
        if ($written && !$q->fetchColumn() && is_file($path)) unlink($path);
        throw $e;
    }
}

function attachmentRemove(string $id): void
{
    if (!hasFullAccess()) throw new InvalidArgumentException('Somente administradores podem remover anexos.');
    auditedTransaction(static function () use ($id): void {
        $row=attachmentGet($id);
        db()->prepare('UPDATE game_attachments SET removed_at=?,removed_by=? WHERE id=?')->execute([gmdate('c'),auditActor(),$id]);
        auditRecord('Anexo de súmula removido','Jogo '.$row['game_id'],['arquivo'=>$row['filename']]);
    });
}

function attachmentVerify(array $row, string $path): void
{
    if (!is_file($path) || is_link($path) || filesize($path)!==(int)$row['size']+strlen(ATTACHMENT_PREFIX) || !hash_equals($row['sha256'],hash_file('sha256',$path))) throw new RuntimeException('Arquivo de súmula ausente ou danificado.');
}

function attachmentBackupRows(string $database): array
{
    $pdo=new PDO('sqlite:'.$database,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    if (!$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='game_attachments'")->fetchColumn()) return [];
    return $pdo->query('SELECT * FROM game_attachments')->fetchAll();
}

function attachmentBackupFiles(string $database): void
{
    $rows=attachmentBackupRows($database); if (!$rows) return;
    $dir=$database.'.files';
    if (!is_dir($dir) && !mkdir($dir,0700)) throw new RuntimeException('Falha ao criar backup dos anexos.');
    if (file_put_contents($dir.'/.htaccess',"Require all denied\n")===false) throw new RuntimeException('Falha ao proteger backup dos anexos.');
    foreach ($rows as $row) {
        $source=attachmentPath($row['id']); attachmentVerify($row,$source);
        $target=attachmentPath($row['id'],$dir);
        if (!copy($source,$target)) throw new RuntimeException('Falha ao copiar anexo para o backup.');
        attachmentVerify($row,$target); @chmod($target,0600);
    }
}

function attachmentRestoreFiles(string $database): void
{
    foreach (attachmentBackupRows($database) as $row) {
        $target=attachmentPath($row['id']);
        if (is_file($target)) { attachmentVerify($row,$target); continue; }
        $source=attachmentPath($row['id'],$database.'.files'); attachmentVerify($row,$source);
        // Immutable IDs: restore only missing files, never overwrite existing ones.
        $in=fopen($source,'rb'); $out=fopen($target,'x+b');
        if (!$in || !$out) { if ($in) fclose($in); if ($out) fclose($out); throw new RuntimeException('Falha ao recuperar anexo.'); }
        try { stream_copy_to_stream($in,$out); } finally { fclose($in); fclose($out); }
        attachmentVerify($row,$target); @chmod($target,0600);
    }
}

// Streaming ZIP STORE: no ZipArchive dependency and no archive-sized memory allocation.
function attachmentBackupDownload(string $database): never
{
    $name=basename($database); $files=[$name=>$database];
    foreach (attachmentBackupRows($database) as $row) {
        $path=attachmentPath($row['id'],$database.'.files'); attachmentVerify($row,$path);
        $files[$name.'.files/'.$row['id'].'.php']=$path;
    }
    if (is_file($database.'.files/.htaccess')) $files[$name.'.files/.htaccess']=$database.'.files/.htaccess';
    $prepared=[]; $total=0;
    foreach ($files as $entry=>$path) {
        $size=filesize($path); $total+=$size+strlen($entry)+100;
        if ($total>4000000000) throw new RuntimeException('Backup muito grande para download único. Copie pela hospedagem.');
        $prepared[]=[$entry,$path,$size,hexdec(hash_file('crc32b',$path))];
    }
    auditRecord('Backup completo baixado','Banco e anexos',[],['arquivo'=>$name]);
    header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="'.substr($name,0,-7).'-completo.zip"'); header('Cache-Control: no-store');
    $offset=0; $directory='';
    foreach ($prepared as [$entry,$path,$size,$crc]) {
        $length=strlen($entry);
        $header=pack('VvvvvvVVVvv',0x04034b50,20,0,0,0,33,$crc,$size,$size,$length,0).$entry;
        echo $header; readfile($path);
        $directory.=pack('VvvvvvvVVVvvvvvVV',0x02014b50,20,20,0,0,0,33,$crc,$size,$size,$length,0,0,0,0,0,$offset).$entry;
        $offset+=strlen($header)+$size;
    }
    echo $directory.pack('VvvvvVVv',0x06054b50,0,0,count($prepared),count($prepared),strlen($directory),$offset,0); exit;
}
