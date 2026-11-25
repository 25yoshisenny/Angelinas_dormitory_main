<?php
function admin_section_tabs(string $page,array $u): void {
    if($u['role']!=='admin') return;
    $tabs=match($page) {
        'reports','expenses','ledger'=>['reports'=>'Analytics','expenses'=>'Expenses','ledger'=>'Account Ledger'],
        'settings','notifications'=>['settings'=>'Dormitory & Billing','notifications'=>'Email / Notifications'],
        'billing','batch','departure'=>['billing'=>'Statements','batch'=>'Billing by Room','departure'=>'Final Bill & Departure'],
        default=>[]
    };
    if(!$tabs) return;
    echo '<nav class="section-tabs no-print" aria-label="Section navigation">';
    foreach($tabs as $key=>$label) echo '<a '.($page===$key?'aria-current="page"':'').' href="?page='.$key.'">'.e($label).'</a>';
    echo '</nav>';
}
function dormitory_metrics(): array {
    $active=(int)query("SELECT COUNT(*) FROM tenants WHERE status='active'")->fetchColumn();
    $occupied=(int)query('SELECT COUNT(*) FROM tenant_assignments WHERE end_date IS NULL')->fetchColumn();
    $capacity=(int)query('SELECT COUNT(*) FROM bed_spaces')->fetchColumn();
    $available=count(available_beds());
    return [$active,$occupied,$available,$capacity?round($occupied/$capacity*100,1):0,$capacity];
}
function dashboard_financial_chart(string $id,string $title,string $description,array $series,bool $hasData): void {
    echo '<section class="panel overview-chart-card"><div class="panel-heading"><div><h2>'.e($title).'</h2><p class="muted">'.e($description).'</p></div></div>';
    if(!$hasData) {
        echo '<p class="overview-chart-empty">No recorded data for the last six months.</p></section>';
        return;
    }

    $series=array_values($series);
    $max=max(1,...array_map('intval',array_column($series,'amount')));
    $left=76;$right=620;$top=28;$bottom=214;$height=$bottom-$top;$count=count($series);$points=[];
    foreach($series as $index=>$month) {
        $x=$left+($count>1?$index*($right-$left)/($count-1):($right-$left)/2);
        $amount=(int)$month['amount'];
        $y=$bottom-($amount/$max)*$height;
        $points[]=['x'=>$x,'y'=>$y,'month'=>$month['month'],'label'=>$month['label'],'amount'=>$amount];
    }
    $pathParts=[];
    foreach($points as $index=>$point) $pathParts[]=($index===0?'M ':'L ').number_format($point['x'],2,'.','').' '.number_format($point['y'],2,'.','');
    $linePath=implode(' ',$pathParts);
    $areaPath=$linePath.' L '.number_format($points[$count-1]['x'],2,'.','').' '.$bottom.' L '.number_format($points[0]['x'],2,'.','').' '.$bottom.' Z';
    echo '<div class="overview-chart-wrap"><svg class="overview-chart" viewBox="0 0 640 280" role="img" aria-labelledby="overview-chart-title-'.$id.'"><title id="overview-chart-title-'.$id.'">'.e($title).' for the last six months</title><defs><linearGradient id="overview-chart-fill-'.$id.'" x1="0" x2="0" y1="0" y2="1"><stop offset="0%" class="overview-chart-fill-start"/><stop offset="100%" class="overview-chart-fill-end"/></linearGradient></defs>';
    for($tick=0;$tick<=4;$tick++) {
        $y=$bottom-$tick*$height/4;$value=(int)round($max*$tick/4);$labelX=$left-10;
        echo '<line class="overview-chart-gridline" x1="'.$left.'" y1="'.number_format($y,2,'.','').'" x2="'.$right.'" y2="'.number_format($y,2,'.','').'"/><text class="overview-chart-axis" x="'.$labelX.'" y="'.number_format($y+4,2,'.','').'" text-anchor="end">₱'.number_format((int)round($value/100)).'</text>';
    }
    echo '<path class="overview-chart-area" fill="url(#overview-chart-fill-'.$id.')" d="'.$areaPath.'"/><path class="overview-chart-line" d="'.$linePath.'"/>';
    foreach($points as $point) {
        $tooltip=$point['month'].': '.money($point['amount']);
        echo '<circle class="overview-chart-point" cx="'.number_format($point['x'],2,'.','').'" cy="'.number_format($point['y'],2,'.','').'" r="4.5" tabindex="0" aria-label="'.e($tooltip).'"><title>'.e($tooltip).'</title></circle><text class="overview-chart-month" x="'.number_format($point['x'],2,'.','').'" y="246" text-anchor="middle">'.e($point['label']).'</text>';
    }
    echo '</svg></div></section>';
}
function admin_dashboard(array $u): void {
    $bills=aging_rows();$outstanding=0;$overdue=[];
    foreach($bills as $b){$outstanding+=balance($b);if(status($b)==='overdue')$overdue[]=$b;}
    $collected=(int)query('SELECT COALESCE(SUM(amount),0) FROM payments WHERE voided_at IS NULL AND payment_date BETWEEN ? AND ?',[date('Y-m-01'),date('Y-m-t')])->fetchColumn();
    $pending=(int)query("SELECT COUNT(*) FROM payment_submissions WHERE status='pending'")->fetchColumn();
    $maintenance=(int)query("SELECT COUNT(*) FROM maintenance_requests WHERE status!='resolved'")->fetchColumn();
    $notices=rows('SELECT * FROM announcements WHERE active=1 AND (expires_on IS NULL OR expires_on>=?) ORDER BY id DESC LIMIT 3',[date('Y-m-d')]);
    [$active,$occupied,$available,$rate,$capacity]=dormitory_metrics();
    $firstMonth=new DateTimeImmutable('first day of this month');
    $monthStart=$firstMonth->modify('-5 months');
    $nextMonth=$firstMonth->modify('+1 month');
    $series=[];
    for($i=0;$i<6;$i++) {
        $month=$monthStart->modify('+'.$i.' months');
        $key=$month->format('Y-m');
        $series[$key]=['month'=>$month->format('F Y'),'label'=>$month->format('M').' '.$month->format('y'),'amount'=>0];
    }
    $paymentMonths=rows("SELECT DATE_FORMAT(payment_date,'%Y-%m') month,SUM(amount) amount FROM payments WHERE voided_at IS NULL AND payment_date>=? AND payment_date<? GROUP BY month ORDER BY month",[$monthStart->format('Y-m-d'),$nextMonth->format('Y-m-d')]);
    $expenseMonths=rows("SELECT DATE_FORMAT(expense_date,'%Y-%m') month,SUM(amount) amount FROM expenses WHERE voided_at IS NULL AND expense_date>=? AND expense_date<? GROUP BY month ORDER BY month",[$monthStart->format('Y-m-d'),$nextMonth->format('Y-m-d')]);
    $collectionHasData=(bool)$paymentMonths;$expenseHasData=(bool)$expenseMonths;
    foreach($paymentMonths as $month)$series[$month['month']]['amount']=(int)$month['amount'];
    $collectionSeries=$series;$expenseSeries=$series;
    foreach($expenseMonths as $month)$expenseSeries[$month['month']]['amount']=(int)$month['amount'];
    $payments=array_slice(payment_rows($u),0,5);
    echo '<div class="admin-overview"><section class="welcome-banner"><div><span class="eyebrow">'.e(date('l, F j')).'</span><h2>Welcome back, '.e(explode(' ',$u['name'])[0]).'.</h2><p>Collections, accommodation, and the work that needs your attention.</p></div><div class="welcome-art">'.icon('rooms').'<span>Angelina&#8217;s Dormitory</span></div></section>';
    echo '<div class="overview-kpis">';
    echo '<a class="overview-kpi" href="?page=payments"><span class="overview-kpi-label">Total Collections</span><strong>'.money($collected).'</strong><span class="overview-kpi-note '.($collected?'overview-positive':'').'">'.($collected?'Confirmed this month · ':'No confirmed payments · ').e(date('F Y')).'</span></a>';
    echo '<a class="overview-kpi" href="?page=billing"><span class="overview-kpi-label">Outstanding Balance</span><strong>'.money($outstanding).'</strong><span class="overview-kpi-note '.($overdue?'overview-negative':'overview-positive').'">'.($overdue?count($overdue).' overdue statements':'No overdue statements').'</span></a>';
    echo '<article class="overview-kpi"><span class="overview-kpi-label">Active Tenants</span><strong>'.number_format($active).'</strong><span class="overview-kpi-note">'.number_format($occupied).' occupied · '.number_format($available).' available · '.number_format($rate,1).'% occupancy</span><span class="overview-kpi-links"><a href="?page=tenants">Tenants</a><a href="?page=rooms">Rooms &amp; beds</a></span></article>';
    echo '</div><div class="overview-chart-grid">';
    dashboard_financial_chart('collections','Monthly Collections','Confirmed payments · last six months',$collectionSeries,$collectionHasData);
    dashboard_financial_chart('expenses','Monthly Expenses','Recorded expenses · last six months',$expenseSeries,$expenseHasData);
    echo '</div>';
    echo '<div class="overview-report-link"><a href="?page=reports">Open detailed Financial Reports</a></div>';
    echo '<section class="panel overview-transactions"><div class="panel-heading"><div><h2>Recent confirmed payments</h2><p class="muted">Latest recorded transactions. Pending submissions are excluded.</p></div></div>';
    payments_table($payments);echo '</section>';
    echo '<div class="overview-secondary"><section class="panel"><div class="panel-heading"><div><h2>Needs attention</h2><p class="muted">Follow through from statement to payment and receipt.</p></div></div><div class="admin-alerts">';
    foreach([['billing',count($overdue),'Overdue bills'],['payments',$pending,'Pending payment verification'],['requests',$maintenance,'Open maintenance requests']] as [$page,$count,$label])echo '<a href="?page='.$page.'"><span>'.e($label).'</span><strong>'.$count.'</strong><span></span></a>';
    echo '</div>';foreach(array_slice($overdue,0,4) as $b)echo '<div class="history-row"><a href="?page=statement&id='.$b['id'].'">'.e($b['name']).' &middot; '.e($b['period']).'</a><strong>'.money(balance($b)).'</strong></div>';echo '</section><section class="panel"><div class="panel-heading"><div><h2>Important notices</h2><p class="muted">Published information visible to tenants.</p></div><a href="?page=announcements">Notice Board</a></div>';
    if(!$notices)echo '<p class="empty">No published notices.</p>';foreach($notices as $notice)echo '<article class="dashboard-notice"><h3>'.e($notice['title']).'</h3><p>'.nl2br(e($notice['message'])).'</p></article>';echo '</section></div></div>';
}

function workspace_back_link(string $page,array $u): void {
    if(in_array($page,['dashboard','statement','receipt'],true))return;
    [$target,$label]=match($page){
        'batch','departure'=>['billing','Billing Statements'],
        'expenses','ledger'=>['reports','Financial Reports'],
        'notifications'=>['settings','Settings'],
        'pay'=>['billing','My bills'],
        'tenants'=>isset($_GET['tenant_id'])?['tenants','Tenants']:['dashboard','Overview'],
        'rooms'=>isset($_GET['bed_id'])?['rooms','Rooms & Beds']:['dashboard','Overview'],
        default=>['dashboard',$u['role']==='tenant'?'My Home':'Overview']
    };
    if($u['role']==='tenant'&&$page==='ledger')[$target,$label]=['billing','My bills'];
    echo '<a class="workspace-back no-print" href="?page='.e($target).'">Back to '.e($label).'</a>';
}
