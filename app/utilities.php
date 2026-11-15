<?php
/** Immutable monthly allocation, stored in the existing settings table. */
function utility_period(string $period): string {
    if(!preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/',$period)) throw new RuntimeException('Choose a billing month between 2000 and 2099.');
    return $period;
}
function utility_snapshot(string $period, bool $save=false): array {
    utility_period($period);
    $key='utility_snapshot_'.$period;
    $stored=one('SELECT value FROM settings WHERE `key`=?',[$key]);
    if($stored) return json_decode($stored['value'],true,512,JSON_THROW_ON_ERROR);
    if($save && !db()->inTransaction()) throw new RuntimeException('Utility snapshots require the billing transaction.');
    $asOf=min(date('Y-m-d'),(new DateTimeImmutable($period.'-01'))->format('Y-m-t'));
    $rooms=[];$people=[];
    foreach(rows('SELECT id,number,fixed_electricity,fixed_water FROM rooms ORDER BY id') as $r) {
        if($r['fixed_electricity']===null || $r['fixed_water']===null) throw new RuntimeException('Run database/setup.php to initialize room utility rates.');
        $rooms[$r['id']]=['number'=>$r['number'],'electricity'=>(int)$r['fixed_electricity'],'water'=>(int)$r['fixed_water'],'occupants'=>[],'legacy'=>false];
    }
    // At a transfer boundary the latest assignment wins; one tenant belongs to one room.
    foreach(rows('SELECT a.id,a.tenant_id,b.room_id FROM tenant_assignments a JOIN bed_spaces b ON b.id=a.bed_id WHERE a.start_date<=? AND (a.end_date IS NULL OR a.end_date>=?) ORDER BY a.start_date,a.id',[$asOf,$asOf]) as $a) {
        $people[$a['tenant_id']]=['room'=>(int)$a['room_id'],'assignment'=>(int)$a['id']];
    }
    // Migration periods keep historical charges. Do not charge that room's pool a second time.
    $legacy=[];
    foreach(rows('SELECT x.tenant_id,x.electricity,x.water,x.assignment_id,b.room_id FROM billing x JOIN tenant_assignments a ON a.id=x.assignment_id JOIN bed_spaces b ON b.id=a.bed_id WHERE x.period=?',[$period]) as $b) {
        $people[$b['tenant_id']]=['room'=>(int)$b['room_id'],'assignment'=>(int)$b['assignment_id']];
        $legacy[$b['tenant_id']]=['electricity'=>(int)$b['electricity'],'water'=>(int)$b['water']];
        $rooms[$b['room_id']]['legacy']=true;
    }
    foreach($people as $tenant=>$p) $rooms[$p['room']]['occupants'][]=(int)$tenant;
    $shares=[];
    foreach($rooms as $room=>$r) {
        sort($r['occupants'],SORT_NUMERIC);$rooms[$room]=$r;$count=count($r['occupants']);
        foreach($r['occupants'] as $index=>$tenant) {
            $shares[$tenant]=['room'=>(int)$room,'assignment'=>$people[$tenant]['assignment'],'occupants'=>$count,'electricity'=>0,'water'=>0,'legacy'=>$r['legacy']];
            foreach(['electricity','water'] as $type) $shares[$tenant][$type]=$r['legacy']?($legacy[$tenant][$type]??0):intdiv($r[$type],$count)+($index<$r[$type]%$count?1:0);
        }
    }
    $snapshot=['version'=>1,'period'=>$period,'as_of'=>$asOf,'rooms'=>$rooms,'shares'=>$shares];
    if($save) {
        $json=json_encode($snapshot,JSON_THROW_ON_ERROR);
        if(strlen($json)>60000) throw new RuntimeException('The utility snapshot exceeds storage capacity. No bills were saved.');
        query('INSERT INTO settings(`key`,value) VALUES(?,?)',[$key,$json]);
        audit('Locked monthly room utility allocation','settings',null,$period.'; occupancy as of '.$asOf.'; equal shares with remainder in tenant ID order.');
    }
    return $snapshot;
}
function utility_share(int $tenant,string $period,bool $save=false): array {
    return utility_share_from_snapshot($tenant,utility_snapshot($period,$save));
}
function utility_share_from_snapshot(int $tenant,array $s): array {
    $share=$s['shares'][$tenant]??['room'=>null,'assignment'=>null,'occupants'=>0,'electricity'=>0,'water'=>0,'legacy'=>false];
    $share['note']=$share['legacy']?'Historical room billing retained; no additional utility pool is charged this month.':($share['occupants']?'Fixed room utilities divided among '.$share['occupants'].' snapshot occupants.':'Not in this month’s occupancy snapshot; utility charging starts next month.');
    $share['as_of']=$s['as_of'];
    return $share;
}
function utility_policy_text(): string {
    return 'Fixed room totals are split equally among all billable occupants, not just selected residents. The first statement freezes the month’s rates and occupancy. Remainder centavos go to ascending tenant IDs. Mid-month utilities are not prorated: transfers/departures retain the frozen share; later arrivals start utilities next month. Historical room bills remain unchanged.';
}
function utility_preview_response(): never {
    require_role(['admin']);
    header('Content-Type: application/json');
    try {
        $period=utility_period((string)($_GET['period']??''));$s=utility_snapshot($period);$result=[];
        foreach(assigned() as $a) {
            $id=(int)$a['tenant_id'];$share=utility_share_from_snapshot($id,$s);
            $last=one('SELECT MAX(period) period FROM billing WHERE tenant_id=?',[$id]);
            $result[$id]=$share+['skip'=>($last['period']!==null && $last['period']>=$period),'rent'=>(int)$a['rent']];
        }
        echo json_encode(['period'=>$period,'shares'=>$result,'as_of'=>$s['as_of']]);
    } catch(RuntimeException $e) { http_response_code(422);echo json_encode(['error'=>$e->getMessage()]); }
    exit;
}
function utility_statement_note(array $bill): void {
    $stored=one('SELECT value FROM settings WHERE `key`=?',['utility_snapshot_'.$bill['period']]);
    if(!$stored) return;
    $s=json_decode($stored['value'],true,512,JSON_THROW_ON_ERROR);$share=$s['shares'][$bill['tenant_id']]??null;
    $text=$share && !$share['legacy']?'Fixed utility allocation: Room '.$s['rooms'][$share['room']]['number'].', '.$share['occupants'].' billable occupant(s), snapshot '.$s['as_of'].'. Room electricity '.money($s['rooms'][$share['room']]['electricity']).'; room water '.money($s['rooms'][$share['room']]['water']).'.':($share?'Historical utility charges retained.':'Joined after the monthly snapshot; utilities begin next month.');
    echo '<p class="document-note">'.e($text).' Charges on issued statements are preserved; corrections appear as audited adjustments.</p>';
}
