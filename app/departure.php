<?php
// The departure preview and confirmation use the same server-side calculation.
function departure_quote(int $tenant, string $date, string $policy): array {
    $a=one("SELECT a.*,t.name,t.move_in,r.rent FROM tenant_assignments a JOIN tenants t ON t.id=a.tenant_id JOIN bed_spaces bs ON bs.id=a.bed_id JOIN rooms r ON r.id=bs.room_id WHERE a.tenant_id=? AND a.end_date IS NULL AND t.status='active'",[$tenant]);
    if(!$a || $date<$a['start_date'] || $date>date('Y-m-d')) throw new RuntimeException('Choose a departure date within the current assignment and no later than today.');
    if(!in_array($policy,['daily','full'],true)) throw new RuntimeException('Choose a rent policy.');
    if(one("SELECT id FROM payment_submissions WHERE tenant_id=? AND status='pending'",[$tenant])) throw new RuntimeException('Review pending payment submissions before preparing a final bill.');
    $period=substr($date,0,7);
    // Final billing must not silently skip rent from an earlier unbilled month.
    $billed=array_column(rows('SELECT period FROM billing WHERE tenant_id=?',[$tenant]),'period');
    for($month=new DateTimeImmutable(substr($a['move_in'],0,7).'-01');$month->format('Y-m')<$period;$month=$month->modify('+1 month')) {
        if(!in_array($month->format('Y-m'),$billed,true)) throw new RuntimeException('Create the missing monthly statement for '.$month->format('Y-m').' before calculating departure.');
    }
    if(one('SELECT id FROM billing WHERE tenant_id=? AND period>?',[$tenant,$period])) throw new RuntimeException('A later billing period exists. Correct the departure date or review those statements first.');
    $existing=one('SELECT id FROM billing WHERE tenant_id=? AND period=?',[$tenant,$period]);
    $b=$existing?bill((int)$existing['id']):null;
    if($b && $b['carried_to']) throw new RuntimeException('This statement has already been carried forward.');
    $share=$b?null:utility_share($tenant,$period);
    $water=(int)($b['water']??$share['water']);$electricity=(int)($b['electricity']??$share['electricity']);
    $start=max($period.'-01',$a['move_in']);
    $days=(int)(new DateTimeImmutable($start))->diff(new DateTimeImmutable($date))->days+1;
    $monthDays=(int)(new DateTimeImmutable($date))->format('t');
    $rate=(int)($b['rent']??$a['rent']);
    $rent=$policy==='daily'?intdiv($rate*$days+intdiv($monthDays,2),$monthDays):$rate;
    $previous=$b?(int)$b['previous_balance']:(int)query('SELECT COALESCE(SUM(GREATEST(0,b.total_due-COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.billing_id=b.id AND p.voided_at IS NULL),0))),0) FROM billing b WHERE b.tenant_id=? AND b.carried_to IS NULL',[$tenant])->fetchColumn();
    $adjustments=$b?(int)$b['total_due']-(int)$b['rent']-(int)$b['water']-(int)$b['electricity']-$previous:0;
    $paid=(int)($b['paid']??0);$total=$rent+$water+$electricity+$previous+$adjustments;
    if($total<$paid || $total<0) throw new RuntimeException('Final charges would be less than confirmed payments or discounts. Resolve the credit with the office before departure; no payment is silently discarded.');
    return ['tenant'=>$tenant,'name'=>$a['name'],'assignment'=>(int)$a['id'],'date'=>$date,'period'=>$period,'policy'=>$policy,'start'=>$start,'days'=>$days,'month_days'=>$monthDays,'rate'=>$rate,'rent'=>$rent,'water'=>$water,'electricity'=>$electricity,'previous'=>$previous,'adjustments'=>$adjustments,'paid'=>$paid,'total'=>$total,'balance'=>$total-$paid,'bill_id'=>$b?(int)$b['id']:null,'original'=>$b,'utility_basis'=>$share];
}
function departure_action(string $action): bool {
    if(!in_array($action,['preview_departure','confirm_departure'],true)) return false;
    $u=require_role(['admin']);
    if($action==='preview_departure') {
        unset($_SESSION['departure_preview']);
        $q=departure_quote((int)required('tenant_id'),date_input('move_out'),required('rent_policy'));
        $_SESSION['departure_preview']=$q;
        $_SESSION['action_message']='Review the final charges below. Nothing has been billed or moved out yet.';
        return true;
    }
    $old=$_SESSION['departure_preview']??null;
    if(!$old) throw new RuntimeException('Prepare a departure preview first.');
    $q=departure_quote($old['tenant'],$old['date'],$old['policy']);
    if($q!==$old) { unset($_SESSION['departure_preview']); throw new RuntimeException('The tenant account changed. Prepare a new preview before confirming.'); }
    $reason='Final bill for departure '.$q['date'].'; '.$q['policy'].' rent policy; '.$q['days'].'/'.$q['month_days'].' days.';
    if($q['bill_id']) {
        $id=$q['bill_id'];
        query('UPDATE billing SET rent=?,water=?,electricity=?,total_due=? WHERE id=?',[$q['rent'],$q['water'],$q['electricity'],$q['total'],$id]);
        query("UPDATE utility_charges SET amount=? WHERE billing_id=? AND type='water'",[$q['water'],$id]);
        query("UPDATE utility_charges SET amount=? WHERE billing_id=? AND type='electricity'",[$q['electricity'],$id]);
    } else {
        $id=create_statement($q['tenant'],$q['period'],$q['date'],$q['date'],$q['rent']);
    }
    query("UPDATE tenants SET status='moved_out',move_out=? WHERE id=?",[$q['date'],$q['tenant']]);
    query('UPDATE tenant_assignments SET end_date=? WHERE id=?',[$q['date'],$q['assignment']]);
    audit('Finalized departure and released bed','billing',$id,json_encode(['reason'=>$reason,'calculation'=>$q],JSON_UNESCAPED_UNICODE));
    notify($q['tenant'],$id,'billing','Your final departure statement',$reason.' Outstanding: '.money($q['balance']).'. Your deposit is settled separately.');
    unset($_SESSION['departure_preview']);
    $_SESSION['action_message']='Departure completed. Final statement #'.$id.' is available in Billing. The bed is now available; deposits remain separate.';
    return true;
}
function departure_view(): void {
    echo '<div class="notice">Prepare charges · Review final balance · Confirm departure. Confirmation saves the statement and releases the bed together.</div>';
    $q=$_SESSION['departure_preview']??null;
    if($q) {
        section_start('Review departure: '.$q['name'],'No changes are saved until you confirm. Existing payments and adjustments are retained.');
        echo '<div class="table-wrap"><table><tbody>';
        $lines=['Departure date'=>$q['date'],'Rent calculation'=>$q['policy']==='daily'?money($q['rate']).' × '.$q['days'].' / '.$q['month_days'].' days':money($q['rate']).' (full month)','Final rent'=>money($q['rent']),'Final water'=>money($q['water']),'Final electricity'=>money($q['electricity']),'Previous balance'=>money($q['previous']),'Existing adjustments / discounts'=>money($q['adjustments']),'Statement total'=>money($q['total']),'Confirmed payments'=>money($q['paid']),'Remaining to collect'=>money($q['balance'])];
        foreach($lines as $label=>$value) echo '<tr><th scope="row">'.e($label).'</th><td>'.e($value).'</td></tr>';
        echo '</tbody></table></div><p class="muted">'.($q['bill_id']?'Updates statement #'.$q['bill_id'].' without creating duplicate monthly charges. Its due date stays unchanged.':'Creates a new final statement due on the departure date.').' Deposits are not automatically applied to rent. Unpaid balances remain collectible after move-out.</p>';
        form_start('confirm_departure','departure');submit('Confirm final bill & move-out','Complete this departure? The final bill will be saved and the tenant’s bed will be released.');echo '</section>';
    }
    section_start('Prepare final bill','Enter the total utilities for the departure month, including amounts already billed. These replace that month’s utility amounts.');
    $opts=[];foreach(assigned() as $a)$opts[$a['tenant_id']]=$a['name'].' · Room '.$a['number'];
    if(!$opts){echo '<p class="empty">No currently assigned tenants.</p></section>';return;}
    form_start('preview_departure','departure');
    select_field('Departing tenant','tenant_id',$opts,$_GET['tenant_id']??$q['tenant']??'');
    field('Departure date (inclusive)','move_out','date',$q['date']??date('Y-m-d'),true,'max="'.date('Y-m-d').'"');
    select_field('Approved rent policy','rent_policy',['full'=>'Full monthly rent','daily'=>'Prorate by calendar days occupied'],$q['policy']??'full');
    echo '<p class="form-wide muted">'.e(utility_policy_text()).'</p>';
    echo '<p class="form-wide muted">Daily proration includes move-in and departure dates, divided by the number of calendar days in the month. It uses the existing statement rent, or the current room rate if no statement exists. Confirm the agreed rent policy with the tenant; utilities are calculated automatically.</p>';
    submit('Calculate & review');echo '</section>';
}
