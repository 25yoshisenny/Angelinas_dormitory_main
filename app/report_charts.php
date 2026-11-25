<?php
function report_bar_chart(string $title,array $values,string $description,bool $currency=true,string $tone='balance'): void {
    section_start($title,$description);
    $maximum=max(1,...array_values($values));
    echo '<div class="report-chart report-bar-chart report-bar-chart--'.e($tone).'" role="img" aria-label="'.e($title).'">';
    foreach($values as $label=>$value) {
        $width=round(max(0,$value)/$maximum*100,2);
        echo '<div class="chart-row"><span>'.e($label).'</span><div class="chart-track"><div style="width:'.$width.'%"></div></div><strong>'.($currency?money($value):(int)$value).'</strong></div>';
    }
    if(!array_sum($values)) echo '<p class="empty">No data for this chart yet.</p>';
    echo '</div></section>';
}
function collection_charts(string $from,string $to,int $cash,int $gcash): void {
    $monthly=[];
    foreach(rows("SELECT DATE_FORMAT(payment_date,'%Y-%m') month,SUM(amount) total FROM payments WHERE voided_at IS NULL AND payment_date BETWEEN ? AND ? GROUP BY month ORDER BY month",[$from,$to]) as $r) $monthly[$r['month']]=(int)$r['total'];
    echo '<div class="report-chart-grid">';
    report_line_chart($monthly?:['No collections'=>0]);
    report_donut_chart('Payment methods',['GCash'=>$gcash,'Personal payment'=>$cash],'Confirmed collections within the selected reporting dates.',true);
    echo '</div>';
    $statuses=['Paid'=>0,'Partial'=>0,'Unpaid'=>0,'Overdue'=>0];
    foreach(aging_rows() as $b) { $label=ucfirst(status($b)); if(isset($statuses[$label])) $statuses[$label]++; }
    report_donut_chart('Current statement status',$statuses,'Live current statements, independent of the collection date filter.',false);
}
function report_line_chart(array $values): void {
    section_start('Monthly collections','Confirmed collections in the selected period. Hover over a point for the exact amount.');
    $amounts=array_values($values);$max=max(1,...$amounts);$count=count($amounts);$points=[];$labels=array_keys($values);
    foreach($amounts as $i=>$value) $points[]=[40+($count===1?240:$i*480/($count-1)),180-$value/$max*145];
    $linePoints=implode(' ',array_map(fn($p)=>implode(',',$p),$points));
    $areaPoints=$points?implode(' ',array_map(fn($p)=>implode(',',$p),array_merge([[$points[0][0],180]],$points,[[$points[$count-1][0],180]]))):'';
    echo '<div class="report-chart line-chart"><svg viewBox="0 0 560 230" role="img" aria-label="Monthly collections trend"><title>Monthly collections trend</title><defs><linearGradient id="report-collection-area" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#35d6a0" stop-opacity=".24"/><stop offset="100%" stop-color="#35d6a0" stop-opacity="0"/></linearGradient><filter id="report-collection-glow" x="-30%" y="-40%" width="160%" height="180%"><feGaussianBlur stdDeviation="3" result="blur"/><feMerge><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge></filter></defs><g class="report-chart-gridlines" aria-hidden="true"><path d="M40 35H530M40 80H530M40 125H530M40 180H530M40 25V180" fill="none"/></g><polygon class="report-chart-area" points="'.$areaPoints.'" fill="url(#report-collection-area)"/><polyline class="report-chart-line-glow" fill="none" stroke="#35d6a0" stroke-width="8" stroke-linecap="round" stroke-linejoin="round" points="'.$linePoints.'" pathLength="1" filter="url(#report-collection-glow)"/><polyline class="report-chart-line" fill="none" stroke="#35d6a0" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" points="'.$linePoints.'" pathLength="1"/>';
    foreach($points as $i=>[$x,$y]) {
        $tooltipX=max(4,min(386,$x-85));$tooltipY=max(4,$y-52);$tooltipText=$labels[$i].': '.money($amounts[$i]);
        echo '<g class="report-data-point" tabindex="0" role="img" aria-label="'.e($tooltipText).'"><circle class="report-point-hit" cx="'.$x.'" cy="'.$y.'" r="12"/><circle class="report-point-halo" cx="'.$x.'" cy="'.$y.'" r="7"/><circle class="report-point-core" cx="'.$x.'" cy="'.$y.'" r="4.5"/><g class="report-chart-tooltip" transform="translate('.$tooltipX.' '.$tooltipY.')" aria-hidden="true"><rect width="170" height="42" rx="8"/><text class="report-tooltip-date" x="10" y="16">'.e($labels[$i]).'</text><text class="report-tooltip-amount" x="10" y="33">'.e(money($amounts[$i])).'</text></g></g>';
        if($count<=8||$i===0||$i===$count-1) echo '<text class="report-chart-label" x="'.$x.'" y="205" text-anchor="middle">'.e($labels[$i]).'</text>';
    }
    echo '</svg><details><summary>View collection totals</summary>'; foreach($values as $label=>$amount) echo '<p>'.e($label).' <strong>'.money($amount).'</strong></p>'; echo '</details></div></section>';
}
function report_donut_chart(string $title,array $values,string $description,bool $currency): void {
    section_start($title,$description);$total=array_sum($values);$offset=0;$colors=$title==='Payment methods'?['#35d6a0','#78a9ff']:['#35d6a0','#e4bc62','#8790ff','#ff796e'];
    echo '<div class="report-chart donut-chart"><svg viewBox="0 0 180 180" role="img" aria-label="'.e($title).' distribution"><circle cx="90" cy="90" r="65" fill="none" stroke="#303638" stroke-width="22"/>';
    $index=0;foreach($values as $label=>$value) {$color=$colors[$index++%count($colors)];$fraction=$total?$value/$total:0;$length=$fraction*408.407;$tooltip=$label.': '.($currency?money($value):$value);echo '<circle class="report-donut-segment" cx="90" cy="90" r="65" fill="none" stroke="'.$color.'" stroke-width="22" stroke-dasharray="'.$length.' '.(408.407-$length).'" stroke-dashoffset="'.(-$offset).'" transform="rotate(-90 90 90)" style="--report-chart-color:'.$color.'" tabindex="0" role="img" aria-label="'.e($tooltip).'"><title>'.e($tooltip).'</title></circle>';$offset+=$length;}
    echo '<text x="90" y="85" text-anchor="middle" fill="#9ba6a2" font-size="12">'.($currency?'COLLECTED':'STATEMENTS').'</text><text x="90" y="106" text-anchor="middle" fill="#f3f5f4" font-size="'.($currency?'13':'24').'" font-weight="700">'.($currency?money($total):$total).'</text></svg><div class="chart-legend">';
    $index=0;foreach($values as $label=>$value) echo '<div><i style="background:'.$colors[$index++%count($colors)].'"></i><span>'.e($label).'</span><strong>'.($currency?money($value):$value).'</strong></div>';echo '</div></div></section>';
}
