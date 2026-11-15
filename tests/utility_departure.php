<?php
// Standalone disposable database: nonzero final utilities and stale allocation previews.
if(PHP_SAPI!=='cli') exit;
$name='angelinas_test_'.bin2hex(random_bytes(6));putenv('DORMITORY_DB_NAME='.$name);
try {
    require dirname(__DIR__).'/database/setup.php';
    require dirname(__DIR__).'/app/utilities.php';
    require dirname(__DIR__).'/app/features.php';
    require dirname(__DIR__).'/app/departure.php';
    $_SESSION=['uid'=>1];
    $check=function(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);};
    query("INSERT INTO rooms(number,capacity,rent) VALUES('205',3,500000)");
    migrate_fixed_utilities($db,$name);
    $extra=one("SELECT * FROM rooms WHERE number='205'");
    $check((int)$extra['fixed_electricity']===75000 && (int)$extra['fixed_water']===19000,'Additional rooms need capacity-only estimates.');
    query('UPDATE rooms SET fixed_electricity=0,fixed_water=0 WHERE id=?',[$extra['id']]);
    migrate_fixed_utilities($db,$name);
    $check((int)one('SELECT fixed_electricity FROM rooms WHERE id=?',[$extra['id']])['fixed_electricity']===0,'Migration must preserve explicitly configured zero rates.');
    query('INSERT INTO tenants(name,email,contact,move_in) VALUES(?,?,?,?)',['Departure utility test','utility@example.test','09170000000',date('Y-m-01')]);$tenant=(int)db()->lastInsertId();
    try {create_statement($tenant,date('Y-m'),date('Y-m-d'),date('Y-m-d'),450000);throw new LogicException('Unassigned tenant was billed.');}catch(RuntimeException $e){$check(str_contains($e->getMessage(),'actively assigned'),'Unassigned tenants must be rejected.');}
    query('INSERT INTO tenant_assignments(tenant_id,bed_id,start_date) VALUES(?,?,?)',[$tenant,1,date('Y-m-01')]);
    db()->beginTransaction();query("SELECT value FROM settings WHERE `key`='seeded' FOR UPDATE");
    $_POST=['tenant_id'=>$tenant,'move_out'=>date('Y-m-d'),'rent_policy'=>'full','water'=>'9999','electricity'=>'9999'];
    departure_action('preview_departure');$q=$_SESSION['departure_preview'];
    $check($q['water']===18000 && $q['electricity']===65000,'Departure must use room allocation, not posted charges.');
    query('UPDATE rooms SET fixed_electricity=65001 WHERE id=1');
    try {departure_action('confirm_departure');throw new LogicException('Changed rates must invalidate preview.');}catch(RuntimeException $e){$check(str_contains($e->getMessage(),'account changed'),'Expected stale preview rejection.');}
    departure_action('preview_departure');departure_action('confirm_departure');db()->commit();
    $bill=one('SELECT * FROM billing WHERE tenant_id=?',[$tenant]);
    $check((int)$bill['electricity']===65001 && (int)$bill['water']===18000,'Final statement must preserve allocated centavos.');
    $check(one('SELECT id FROM tenant_assignments WHERE tenant_id=? AND end_date IS NULL',[$tenant])===null,'Departure must release the bed.');
    $check((int)query('SELECT COUNT(*) FROM billing WHERE tenant_id=?',[$tenant])->fetchColumn()===1,'Only one final statement.');
    // Existing historical utilities must remain unchanged even when different from configured rates.
    $legacy=departure_quote(1,date('Y-m-d'),'full');
    $check($legacy['electricity']===80000 && $legacy['water']===30000,'Legacy departure must retain original utilities.');
    echo "PASS: fixed departure allocation, forged input ignored, stale rates blocked, exact saved charges, bed release, historical utility preservation.\n";
} finally {
    if(isset($db)) {if($db->inTransaction())$db->rollBack();$db->exec('DROP DATABASE `'.$name.'`');}
}
