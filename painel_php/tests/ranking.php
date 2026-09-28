<?php
declare(strict_types=1);
require dirname(__DIR__) . '/db.php';
$databaseConnection = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$databaseConnection->exec('PRAGMA foreign_keys=ON');
initialiseDatabase();
$pdo = db();
$pdo->exec("INSERT INTO players(id,name) VALUES (1,'A'),(2,'B'),(3,'Adversário A'),(4,'Adversário B')");
$cases = [
    ['Pontos têm prioridade', [[9,9]], [[1,0]], 'B'],
    ['Vitórias superam saldo e gols em empate de pontos', [[1,0],[0,9]], [[3,3],[3,3],[3,3]], 'A'],
    ['Saldo desempata pontos e vitórias iguais', [[1,0]], [[4,0]], 'B'],
    ['Gols marcados desempataram pontos, vitórias e saldo iguais', [[3,2]], [[2,1]], 'A'],
    ['Quatro critérios iguais compartilham posição', [[2,1]], [[2,1]], 'tie'],
];
foreach ($cases as [$label,$a,$b,$expected]) {
    $pdo->exec('DELETE FROM games');
    $insert = $pdo->prepare('INSERT INTO games(round_number,game_number,turn_number,player_a_id,player_b_id,score_a,score_b) VALUES (1,?,1,?,?,?,?)');
    $number=0;
    foreach ([1=>$a,2=>$b] as $player=>$matches) foreach ($matches as [$gf,$ga]) $insert->execute([++$number,$player,$player+2,$gf,$ga]);
    $data = publicData();
    $players = array_column($data['players'],null,'name');
    $pa = $players['A']['position']; $pb = $players['B']['position'];
    $ok = $expected === 'tie' ? $pa === $pb : ($expected === 'A' ? $pa < $pb : $pb < $pa);
    if (!$ok) throw new RuntimeException($label);
    if ($data['rankingCriteria'] !== ['points','wins','goalDifference','goalsFor']) throw new RuntimeException('Metadados divergentes');
    echo "OK: $label\n";
}
