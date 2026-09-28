<?php
declare(strict_types=1);
require __DIR__ . '/ranking.php';
function verifyHistory(bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); echo "OK: $label\n"; }
$pdo->exec('DELETE FROM games');
$insert = $pdo->prepare('INSERT INTO games(id,round_number,game_number,turn_number,player_a_id,player_b_id,score_a,score_b,played_at) VALUES (?,1,?,1,?,?,?, ?,?)');
$insert->execute([1,1,1,2,1,0,'2026-09-22']);
$insert->execute([2,2,2,3,4,0,'2026-09-20']);
$insert->execute([3,3,1,4,2,0,'2026-09-20']);
$d=publicData(); $p=array_column($d['players'],null,'id');
verifyHistory(array_column($p['p1']['history'],'date')===['2026-09-20','2026-09-22'], 'dates sorted, same day grouped');
verifyHistory(array_column($p['p1']['history'],'position')===[2,1], 'daily cumulative ranking');
verifyHistory(array_column($p['p1']['history'],'points')===[3,6], 'cumulative points');
foreach($p as $player) verifyHistory(end($player['history'])['position']===$player['position'], 'latest position matches current ranking');
$pdo->exec("UPDATE games SET played_at='2026-09-19' WHERE id=1");
$p=array_column(publicData()['players'],null,'id');
verifyHistory($p['p1']['history'][0]['date']==='2026-09-19', 'date correction recalculates timeline');
$pdo->exec('UPDATE games SET score_a=0,score_b=5 WHERE id=1');
$p=array_column(publicData()['players'],null,'id');
verifyHistory($p['p1']['history'][0]['points']===0, 'score correction recalculates points');
$pdo->exec("UPDATE games SET played_at='2026-02-30' WHERE id=1");
$pdo->exec('UPDATE games SET played_at=NULL WHERE id=2');
$d=publicData();
verifyHistory($d['historyUndatedGames']===2 && $d['playedGames']===3, 'invalid dates excluded only from history');
verifyHistory(count($d['players'][0]['history'])===1, 'single date supported');
$pdo->exec('UPDATE games SET score_a=NULL,score_b=NULL WHERE id=3');
$d=publicData();
verifyHistory($d['players'][0]['history']===[], 'no dated results yields empty history');
