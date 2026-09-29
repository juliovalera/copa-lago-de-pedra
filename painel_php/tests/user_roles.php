<?php
declare(strict_types=1);
require dirname(__DIR__) . '/db.php';
putenv('COPA_NOTIFY_EMAIL=ORGANIZER@example.invalid');
function smtpSend(string $recipient, string $subject, string $text): void {
    if (db()->inTransaction()) throw new LogicException('SMTP before commit');
    $GLOBALS['emails'][] = compact('recipient','subject','text');
}
function check(bool $ok, string $message): void {
    if (!$ok) throw new LogicException($message);
    echo "OK: $message\n";
}
function mustFail(callable $call): void {
    $failed = false;
    try { $call(); } catch (RuntimeException | InvalidArgumentException $e) { $failed = true; }
    check($failed, 'Unauthorized, invalid or stale change rejected');
}
$databaseConnection = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo = db();
$pdo->exec('PRAGMA foreign_keys=ON');
// Old installation: no role column. Preserve records, credentials and permissions.
$pdo->exec('CREATE TABLE players(id INTEGER PRIMARY KEY,name TEXT NOT NULL UNIQUE)');
$pdo->exec("INSERT INTO players VALUES (1,'Demo A'),(2,'Demo B'),(3,'Demo C')");
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,name TEXT,username TEXT,email TEXT,password_hash TEXT,player_id INTEGER REFERENCES players(id) ON DELETE SET NULL,is_active INTEGER,created_at TEXT)');
$pdo->exec("INSERT INTO users VALUES (1,'Admin','admin','organizer@example.invalid','unchanged-hash',NULL,1,'2026-09-28'),(2,'Player','player','player@example.invalid','unchanged-hash',2,1,'2026-09-28')");
$old = $pdo->query('SELECT * FROM users ORDER BY id')->fetchAll();
$backup = sys_get_temp_dir() . '/copa-role-test-' . bin2hex(random_bytes(8)) . '.sqlite';
$pdo->exec('VACUUM INTO ' . $pdo->quote($backup));
initialiseDatabase();
$new = $pdo->query('SELECT * FROM users ORDER BY id')->fetchAll();
check($new[0]['role']==='admin' && $new[1]['role']==='player', 'Migration preserves old privileges');
foreach ($new as &$row) unset($row['role']); unset($row);
check($new === $old, 'Migration preserves every original user field');
$pdo->exec("INSERT INTO games(id,round_number,game_number,turn_number,player_a_id,player_b_id) VALUES (1,1,1,1,1,2),(2,1,2,1,2,3)");
$_SESSION=['legacy_admin'=>true];
changeUserPrivilege(1,'admin',1,'','admin');
initialiseDatabase(); initialiseDatabase();
check($pdo->query('SELECT player_id FROM users WHERE id=1')->fetchColumn()===1, 'Migration is idempotent and preserves explicit link');
$_SESSION=['user_id'=>1];
check(hasFullAccess() && userGameRestriction(currentUser())===null && !isMasterAdmin(), 'Linked administrator keeps full access but not master authority');
check(gameIsAccessible(2,userGameRestriction(currentUser())), 'Administrator may access games without own participation');
mustFail(fn()=>changeUserPrivilege(2,'admin',2,'2','player'));
$GLOBALS['emails']=[];
$link=dailyRefereeLink(1,date('Y-m-d',strtotime('+1 day')));
$recipients=array_column($GLOBALS['emails'],'recipient');sort($recipients);
check($recipients===['organizer@example.invalid','player@example.invalid'], 'Administrator as player and organizer receives one notice, opponent receives own notice');
check(!str_contains(json_encode($GLOBALS['emails']),$link['token']), 'Notifications contain no QR token');
$GLOBALS['emails']=[];
dailyRefereeLink(2,date('Y-m-d',strtotime('+1 day')));
check(count($GLOBALS['emails'])===2, 'Administrative action outside own games still notifies organizer');
$_SESSION=['legacy_admin'=>true];
mustFail(fn()=>changeUserPrivilege(1,'player',1,'1','player'));
mustFail(fn()=>changeUserPrivilege(1,'player',null,'1','admin'));
mustFail(fn()=>changeUserPrivilege(1,'admin',999,'1','admin'));
$pdo->exec("CREATE TRIGGER reject_audit BEFORE INSERT ON audit_log BEGIN SELECT RAISE(ABORT,'test'); END");
mustFail(fn()=>changeUserPrivilege(1,'player',1,'1','admin'));
check($pdo->query('SELECT role FROM users WHERE id=1')->fetchColumn()==='admin', 'Audit failure rolls back role and link');
$pdo->exec('DROP TRIGGER reject_audit');
changeUserPrivilege(1,'player',1,'1','admin');
$_SESSION=['user_id'=>1];
check(!hasFullAccess() && !gameIsAccessible(2,userGameRestriction(currentUser())), 'Demotion applies immediately to existing session');
$pdo->exec('UPDATE users SET player_id=NULL WHERE id=1');
check(!hasFullAccess() && !gameIsAccessible(1,userGameRestriction(currentUser())), 'Lost link never grants administrative access');
$_SESSION=['legacy_admin'=>true];
changeUserPrivilege(1,'admin',null,'','player');
$_SESSION=['user_id'=>1];
putenv('COPA_NOTIFY_EMAIL=');
// Only check unlinked admin does not become a player recipient; config may define organizer separately.
check(hasFullAccess() && currentUser()['player_id']===null, 'Administrator without player link remains supported');

$_SESSION=['legacy_admin'=>true];
try {
    restoreAuditedBackup($backup, 'isolated-test');
    check($pdo->query('SELECT role FROM users WHERE id=1')->fetchColumn()==='admin' && $pdo->query('SELECT role FROM users WHERE id=2')->fetchColumn()==='player', 'Restoring a pre-1.38 backup preserves original permissions');
} finally { unlink($backup); }
