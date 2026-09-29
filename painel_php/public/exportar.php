<?php
declare(strict_types=1);
require dirname(__DIR__).'/db.php';
require dirname(__DIR__).'/spreadsheet.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
    initialiseDatabase();
    // Both sheets must describe the same database snapshot.
    db()->beginTransaction();
    $data=publicData();
    $generated=new DateTimeImmutable('now',new DateTimeZone(config()['timezone']));
    db()->commit();
    $file=competitionSpreadsheet($data,$generated);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="copa-lago-de-pedra-'.$generated->format('Ymd-His').'.xlsx"');
    header('Content-Length: '.strlen($file));
    echo $file;
} catch (Throwable $error) {
    if (isset($databaseConnection) && $databaseConnection instanceof PDO && $databaseConnection->inTransaction()) $databaseConnection->rollBack();
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Não foi possível gerar a planilha agora. Volte à página e tente novamente.';
}
