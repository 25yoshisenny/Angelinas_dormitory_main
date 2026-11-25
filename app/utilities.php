<?php
/** Monthly occupancy snapshot and room utility totals, stored in the existing settings table. */
function utility_period(string $period): string {
    if(!preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/',$period)) throw new RuntimeException('Choose a billing month between 2000 and 2099.');
    return $period;
}
function utility_snapshot(string $period, bool $save=false, ?int $overrideRoom=null, ?array $utilityTotals=null): array {
    utility_period($period);
    $key='utility_snapshot_'.$period;
    $stored=one('SELECT value FROM settings WHERE `key`=?',[$key]);
    if($save && !db()->inTransaction()) throw new RuntimeException('Utility snapshots require the billing transaction.');
    if($stored) {
        $snapshot=json_decode($stored['value'],true,512,JSON_THROW_ON_ERROR);
        $changed=false;
    } else {
        $asOf=min(date('Y-m-d'),(new DateTimeImmutable($period.'-01'))->format('Y-m-t'));
        $rooms=[];$people=[];
        foreach(rows('SELECT id,number,fixed_electricity,fixed_water FROM rooms ORDER BY id') as $r) {
            $rooms[$r['id']]=['number'=>$r['number'],'electricity'=>$r['fixed_electricity']===null?null:(int)$r['fixed_electricity'],'water'=>$r['fixed_water']===null?null:(int)$r['fixed_water'],'occupants'=>[],'legacy'=>false];
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
            if($count && !$r['legacy'] && ($r['electricity']===null || $r['water']===null) && $overrideRoom===null) throw new RuntimeException('Room '.$r['number'].' has no monthly utility totals. Enter this month’s utilities amount before creating statements.');
            foreach($r['occupants'] as $index=>$tenant) {
                $shares[$tenant]=['room'=>(int)$room,'assignment'=>$people[$tenant]['assignment'],'occupants'=>$count,'electricity'=>0,'water'=>0,'legacy'=>$r['legacy']];
                foreach(['electricity','water'] as $type) {
                    $total=(int)($r[$type]??0);
                    $shares[$tenant][$type]=$r['legacy']?($legacy[$tenant][$type]??0):intdiv($total,$count)+($index<$total%$count?1:0);
                }
            }
        }
        $snapshot=['version'=>1,'period'=>$period,'as_of'=>$asOf,'rooms'=>$rooms,'shares'=>$shares];
        $changed=true;
    }

    if($overrideRoom!==null && $utilityTotals!==null) {
        if(!$utilityTotals || !isset($utilityTotals['total'])) throw new RuntimeException('Enter the room utilities total.');
        $roomKey=(string)$overrideRoom;
        if(!isset($snapshot['rooms'][$roomKey])) {
            if((int)$utilityTotals['total']!==0) throw new RuntimeException('This room was added after the month’s utility snapshot; utilities start next month.');
            return $snapshot;
        }
        $room=$snapshot['rooms'][$roomKey];
        $currentTotal=(int)($room['electricity']??0)+(int)($room['water']??0);
        if($room['legacy']) {
            if($currentTotal!==(int)$utilityTotals['total']) throw new RuntimeException('Historical room utilities for this month cannot be changed.');
        } else {
            $hasBills=one('SELECT b.id FROM billing b JOIN tenant_assignments a ON a.id=b.assignment_id JOIN bed_spaces bs ON bs.id=a.bed_id WHERE b.period=? AND bs.room_id=? LIMIT 1',[$period,$overrideRoom]);
            $same=$currentTotal===(int)$utilityTotals['total'];
            if($hasBills && !$same) throw new RuntimeException('This room already has a statement for this month, so its utility totals are locked.');
            if(!$hasBills && (!$same || (int)($room['electricity']??-1)!==0 || (int)($room['water']??-1)!==(int)$utilityTotals['total'])) {
                $snapshot['rooms'][$roomKey]['electricity']=0;
                $snapshot['rooms'][$roomKey]['water']=(int)$utilityTotals['total'];
                $occupants=$snapshot['rooms'][$roomKey]['occupants'];
                $count=count($occupants);
                foreach($occupants as $index=>$tenant) {
                    $snapshot['shares'][$tenant]['electricity']=0;
                    $total=(int)$utilityTotals['total'];
                    $snapshot['shares'][$tenant]['water']=$count?intdiv($total,$count)+($index<$total%$count?1:0):0;
                }
                $changed=true;
            }
        }
    }

    if($save && $changed) {
        $json=json_encode($snapshot,JSON_THROW_ON_ERROR);
        if(strlen($json)>60000) throw new RuntimeException('The utility snapshot exceeds storage capacity. No bills were saved.');
        if($stored) query('UPDATE settings SET value=? WHERE `key`=?',[$json,$key]);
        else query('INSERT INTO settings(`key`,value) VALUES(?,?)',[$key,$json]);
        $roomDetails=$overrideRoom===null?'':' Room '.$snapshot['rooms'][(string)$overrideRoom]['number'].' utilities total: '.money((int)$snapshot['rooms'][(string)$overrideRoom]['electricity']+(int)$snapshot['rooms'][(string)$overrideRoom]['water']).'.';
        audit('Locked monthly room utility allocation','settings',null,$period.'; occupancy as of '.$snapshot['as_of'].'; room totals with equal shares and remainder in tenant ID order.'.$roomDetails);
    }
    return $snapshot;
}
function utility_share(int $tenant,string $period,bool $save=false): array {
    return utility_share_from_snapshot($tenant,utility_snapshot($period,$save));
}
function utility_share_from_snapshot(int $tenant,array $s): array {
    $share=$s['shares'][$tenant]??['room'=>null,'assignment'=>null,'occupants'=>0,'electricity'=>0,'water'=>0,'legacy'=>false];
    $share['note']=$share['legacy']?'Historical room billing retained; no additional utility pool is charged this month.':($share['occupants']?'Room utilities divided among '.$share['occupants'].' snapshot occupants.':'Not in this month’s occupancy snapshot; utility charging starts next month.');
    $share['as_of']=$s['as_of'];
    return $share;
}
function utility_policy_text(): string {
    return 'Enter one monthly utilities total (electricity plus water) for each room. The room total is split equally among its billable occupants, not just selected tenants; remainder centavos go to ascending tenant IDs. A room’s utility total locks when its first statement is created. The occupancy snapshot is frozen for the month. Mid-month utilities are not prorated: transfers/departures retain their frozen share; later arrivals start utilities next month. Historical room bills remain unchanged.';
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
    $room=$share?($s['rooms'][$share['room']]??null):null;
    $text=$share && !$share['legacy'] && $room?'Room utilities total: '.money((int)$room['electricity']+(int)$room['water']).', split among '.$share['occupants'].' billable occupant(s); snapshot '.$s['as_of'].'.':($share?'Historical utility charges retained.':'Joined after the monthly snapshot; utilities begin next month.');
    echo '<p class="document-note">'.e($text).' Charges on issued statements are preserved; corrections appear as audited adjustments.</p>';
}
