<?php
declare(strict_types=1);
require dirname(__DIR__).'/db.php';
require dirname(__DIR__).'/spreadsheet.php';
require dirname(__DIR__).'/documents.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$format=$_GET['format']??'xlsx';
$content=$_GET['content']??'all';
if (!in_array($format,['xlsx','docx','pdf'],true) || !in_array($content,['all','ranking','games'],true)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Escolha um formato e um conteúdo válidos.');
}
try {
    initialiseDatabase();
    // Both sheets must describe the same database snapshot.
    db()->beginTransaction();
    $data=publicData();
    $generated=new DateTimeImmutable('now',new DateTimeZone(config()['timezone']));
    db()->commit();
    if ($format==='pdf') {
        // The bundled browser library renders this public snapshot, without an external service.
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(competitionReport($data,$generated,$content),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        exit;
    }
    $file=$format==='docx'?competitionWord(competitionReport($data,$generated,$content)):competitionSpreadsheet($data,$generated,$content);
    header('Content-Type: application/vnd.openxmlformats-officedocument.'.($format==='docx'?'wordprocessingml.document':'spreadsheetml.sheet'));
    header('Content-Disposition: attachment; filename="copa-lago-de-pedra-'.$content.'-'.$generated->format('Ymd-His').'.'.$format.'"');
    header('Content-Length: '.strlen($file));
    echo $file;
} catch (Throwable $error) {
    if (isset($databaseConnection) && $databaseConnection instanceof PDO && $databaseConnection->inTransaction()) $databaseConnection->rollBack();
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Não foi possível gerar o arquivo agora. Volte à página e tente novamente.';
}
