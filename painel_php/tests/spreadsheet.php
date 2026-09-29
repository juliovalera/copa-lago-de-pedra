<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
require dirname(__DIR__).'/audit.php';
require dirname(__DIR__).'/spreadsheet.php';
// Binary fixture on stdout. No config, database, filesystem writes or real data.
$data=[
    'players'=>[
        ['position'=>1,'name'=>'=1+1','points'=>3,'played'=>1,'wins'=>1,'draws'=>0,'losses'=>0,'goalsFor'=>4,'goalsAgainst'=>2,'goalDifference'=>2],
        ['position'=>2,'name'=>'Álvaro & <Teste>','points'=>0,'played'=>1,'wins'=>0,'draws'=>0,'losses'=>1,'goalsFor'=>2,'goalsAgainst'=>4,'goalDifference'=>-2],
        ['position'=>3,'name'=>"@Nome\x01 fictício",'points'=>0,'played'=>0,'wins'=>0,'draws'=>0,'losses'=>0,'goalsFor'=>0,'goalsAgainst'=>0,'goalDifference'=>0],
    ],
    'games'=>[
        ['round'=>1,'game'=>1,'turn'=>1,'a'=>'=1+1','b'=>'Álvaro & <Teste>','scoreA'=>4,'scoreB'=>2,'status'=>'played','playedAt'=>'2024-02-29'],
        ['round'=>26,'game'=>1,'turn'=>2,'a'=>'Álvaro & <Teste>','b'=>'=1+1','scoreA'=>0,'scoreB'=>0,'status'=>'played','playedAt'=>null],
        ['round'=>2,'game'=>1,'turn'=>1,'a'=>'=1+1','b'=>'@Nome fictício','scoreA'=>null,'scoreB'=>null,'status'=>'pending','playedAt'=>null],
    ],
];
echo competitionSpreadsheet($data,new DateTimeImmutable('2026-09-29 18:30:00',new DateTimeZone('America/Sao_Paulo')));
