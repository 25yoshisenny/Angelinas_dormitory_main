<?php
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

function qr_management_data_uri(string $token): string {
    $options=new QROptions([
        'outputType'=>QRCode::OUTPUT_MARKUP_SVG,
        'eccLevel'=>QRCode::ECC_M,
        'scale'=>4,
        'addQuietzone'=>true,
    ]);
    return (new QRCode($options))->render('ADQR1:'.$token);
}

function qr_management_image_response(bool $download=false): never {
    $u=user();
    if(!$u || $u['role']!=='admin' || $u['must_change']) { http_response_code(403); exit; }
    $parameter=$download?'tenant_qr_download':'tenant_qr_image';
    $id=filter_var($_GET[$parameter]??null,FILTER_VALIDATE_INT);
    if(!$id || $id<1) { http_response_code(400); exit('Invalid QR code.'); }
    $code=one("SELECT q.id,q.tenant_id,q.token FROM tenant_qr_codes q JOIN tenants t ON t.id=q.tenant_id WHERE q.id=? AND q.status='active' AND t.status='active'",[$id]);
    if(!$code) { http_response_code(404); exit; }
    $dataUri=qr_management_data_uri($code['token']);
    $prefix='data:image/svg+xml;base64,';
    if(!str_starts_with($dataUri,$prefix)) { http_response_code(500); exit('QR image could not be generated.'); }
    $svg=base64_decode(substr($dataUri,strlen($prefix)),true);
    if($svg===false || !str_contains($svg,'<svg')) { http_response_code(500); exit('QR image could not be generated.'); }
    header('Content-Type: image/svg+xml; charset=utf-8');
    if($download) header('Content-Disposition: attachment; filename="tenant-'.$code['tenant_id'].'-qr.svg"');
    header('Content-Length: '.strlen($svg));
    echo $svg;
    exit;
}

function qr_management_issue(int $tenantId,int $actorId,bool $reissue=false): array {
    $tenant=one('SELECT id,status FROM tenants WHERE id=? FOR UPDATE',[$tenantId]);
    if(!$tenant || $tenant['status']!=='active') throw new RuntimeException('QR codes can only be issued to active tenants.');
    $active=one("SELECT id FROM tenant_qr_codes WHERE tenant_id=? AND status='active' FOR UPDATE",[$tenantId]);
    if($active && !$reissue) throw new RuntimeException('This tenant already has an active QR code.');
    if(!$active && $reissue) throw new RuntimeException('This tenant has no active QR code to reissue.');

    $token=bin2hex(random_bytes(32));
    if(!str_starts_with(qr_management_data_uri($token),'data:image/svg+xml;base64,')) throw new RuntimeException('The QR code could not be generated.');
    if($active) query("UPDATE tenant_qr_codes SET status='revoked',revoked_at=UTC_TIMESTAMP() WHERE id=? AND status='active'",[$active['id']]);
    try {
        query("INSERT INTO tenant_qr_codes(tenant_id,token,status,generated_by) VALUES(?,?,'active',?)",[$tenantId,$token,$actorId]);
    } catch(PDOException $error) {
        if(($error->errorInfo[1]??0)===1062) throw new RuntimeException('An active QR code already exists for this tenant. Refresh the page and try again.');
        throw $error;
    }
    $newId=(int)db()->lastInsertId();
    audit($reissue?'Reissued tenant QR code':'Generated tenant QR code','tenant_qr_codes',$newId,$reissue?'Tenant ID '.$tenantId.'; previous record '.$active['id'].' revoked.':'Tenant ID '.$tenantId.'.');
    return ['id'=>$newId,'previous_id'=>$active?(int)$active['id']:null];
}

function qr_management_action(string $action,array $actor): bool {
    if(!in_array($action,['tenant_qr_generate','tenant_qr_generate_all','tenant_qr_reissue'],true)) return false;
    $actor=require_role(['admin']);
    if($action==='tenant_qr_generate_all') {
        $count=0;
        foreach(rows("SELECT id FROM tenants WHERE status='active' ORDER BY id FOR UPDATE") as $tenant) {
            if(one("SELECT id FROM tenant_qr_codes WHERE tenant_id=? AND status='active'",[$tenant['id']])) continue;
            qr_management_issue((int)$tenant['id'],(int)$actor['id']);
            $count++;
        }
        if($count) {
            audit('Generated missing tenant QR codes','tenant_qr_codes',null,$count.' generated; active codes unchanged.');
            $_SESSION['action_message']=$count.' QR code(s) generated. Existing active codes were unchanged.';
        } else $_SESSION['action_message']='Every active tenant already has a QR code.';
        return true;
    }
    $tenantId=filter_var(required('tenant_id'),FILTER_VALIDATE_INT);
    if(!$tenantId || $tenantId<1) throw new RuntimeException('Choose a valid tenant.');
    $reissue=$action==='tenant_qr_reissue';
    qr_management_issue((int)$tenantId,(int)$actor['id'],$reissue);
    $_SESSION['action_message']=$reissue?'Tenant QR code reissued. The previous QR code is no longer valid.':'Tenant QR code generated.';
    return true;
}

function qr_management_tenant_lookup_response(): never {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    $respond=static function(int $status,array $payload): never {
        http_response_code($status);
        echo json_encode($payload,JSON_THROW_ON_ERROR);
        exit;
    };
    if($_SERVER['REQUEST_METHOD']!=='POST') $respond(405,['error'=>'Use POST to verify a tenant QR code.']);
    $tenantUser=user();
    if(!$tenantUser || $tenantUser['role']!=='tenant' || $tenantUser['must_change'] || empty($tenantUser['tenant_id'])) {
        $respond(403,['error'=>'Sign in to your tenant account to scan your QR code.']);
    }
    $csrf=$_SERVER['HTTP_X_CSRF_TOKEN']??($_POST['csrf']??'');
    if(!is_string($csrf) || !hash_equals(token(),$csrf)) $respond(419,['error'=>'Your session expired. Reload the page and try again.']);
    $payload=$_POST['token']??'';
    if(!is_string($payload) || strlen($payload)>80) $respond(422,['error'=>'Scan a valid tenant QR code.']);
    if(!preg_match('/^ADQR1:([a-f0-9]{64})$/D',$payload,$match)) {
        $respond(404,['error'=>'This QR code is not valid for your tenant account.']);
    }

    $tenantId=(int)$tenantUser['tenant_id'];
    $code=one("SELECT q.id FROM tenant_qr_codes q JOIN tenants t ON t.id=q.tenant_id WHERE q.token=? AND q.tenant_id=? AND q.status='active' AND t.status='active'",[$match[1],$tenantId]);
    if(!$code) $respond(404,['error'=>'This QR code is not valid for your tenant account.']);

    $tenant=one('SELECT id,name FROM tenants WHERE id=?',[$tenantId]);
    $room=one('SELECT r.number room_number,bs.label bed_number FROM tenant_assignments a JOIN bed_spaces bs ON bs.id=a.bed_id JOIN rooms r ON r.id=bs.room_id WHERE a.tenant_id=? AND a.end_date IS NULL',[$tenantId]);
    $bill=one("SELECT b.*,COALESCE((SELECT SUM(amount) FROM payments WHERE billing_id=b.id AND voided_at IS NULL),0) paid FROM billing b WHERE b.tenant_id=? AND b.carried_to IS NULL ORDER BY b.period DESC,b.id DESC LIMIT 1",[$tenantId]);
    $history=rows("SELECT p.payment_date,p.amount,p.method,b.period FROM payments p JOIN billing b ON b.id=p.billing_id WHERE p.tenant_id=? AND p.voided_at IS NULL ORDER BY p.payment_date DESC,p.id DESC",[$tenantId]);
    foreach($history as &$payment) {
        $payment['amount']=(int)$payment['amount'];
        $payment['method']=$payment['method']==='GCash'?'GCash':'Cash';
    }
    unset($payment);

    $billData=null;
    if($bill) {
        $charges=one("SELECT COALESCE(SUM(CASE WHEN type='adjustment' THEN amount ELSE 0 END),0) other_charges,COALESCE(SUM(CASE WHEN type='discount' THEN amount ELSE 0 END),0) discounts FROM billing_adjustments WHERE billing_id=?",[$bill['id']]);
        $billData=[
            'period'=>$bill['period'],
            'due_date'=>$bill['due_date'],
            'rent'=>(int)$bill['rent'],
            'water'=>(int)$bill['water'],
            'electricity'=>(int)$bill['electricity'],
            'other_charges'=>(int)$charges['other_charges'],
            'previous_balance'=>(int)$bill['previous_balance'],
            'discounts'=>(int)$charges['discounts'],
            'total_due'=>(int)$bill['total_due'],
            'total_paid'=>(int)$bill['paid'],
            'remaining_balance'=>balance($bill),
            'payment_status'=>match(status($bill)) {'partial'=>'Partially Paid','paid'=>'Paid','overdue'=>'Overdue',default=>'Unpaid'},
        ];
    }
    $respond(200,[
        'tenant'=>[
            'id'=>(int)$tenant['id'],
            'name'=>$tenant['name'],
            'room'=>$room['room_number']??null,
            'bed'=>$room['bed_number']??null,
        ],
        'current_bill'=>$billData,
        'payment_history'=>$history,
    ]);
}

function qr_management_view(): void {
    require_role(['admin']);
    $searchValue=$_GET['q']??'';
    if(!is_string($searchValue)) throw new RuntimeException('Enter a valid tenant search.');
    $search=trim($searchValue);
    if(strlen($search)>100) throw new RuntimeException('Search text must be 100 characters or fewer.');
    $roomValue=$_GET['room_id']??'';
    if(!is_string($roomValue) && !is_int($roomValue)) throw new RuntimeException('Choose a valid room.');
    $roomId=0;
    if((string)$roomValue!=='') {
        $roomId=filter_var($roomValue,FILTER_VALIDATE_INT);
        if(!$roomId || $roomId<1 || !one('SELECT id FROM rooms WHERE id=?',[$roomId])) throw new RuntimeException('Choose a valid room.');
    }

    $activeTenants=(int)query("SELECT COUNT(*) FROM tenants WHERE status='active'")->fetchColumn();
    $withCodes=(int)query("SELECT COUNT(*) FROM tenants t WHERE t.status='active' AND EXISTS(SELECT 1 FROM tenant_qr_codes q WHERE q.tenant_id=t.id AND q.status='active')")->fetchColumn();
    $rooms=rows('SELECT id,number FROM rooms ORDER BY number');
    $where="WHERE t.status='active'";
    $params=[];
    if($roomId) { $where.=' AND r.id=?'; $params[]=$roomId; }
    if($search!=='') {
        $where.=" AND (CAST(t.id AS CHAR) LIKE ? OR t.name LIKE ? OR COALESCE(r.number,'') LIKE ?)";
        $like='%'.$search.'%'; array_push($params,$like,$like,$like);
    }
    $entries=rows('SELECT t.id tenant_id,t.name,a.bed_id,r.id room_id,r.number room_number,q.id qr_id,q.status qr_status,q.created_at generated_at FROM tenants t LEFT JOIN tenant_assignments a ON a.tenant_id=t.id AND a.end_date IS NULL LEFT JOIN bed_spaces b ON b.id=a.bed_id LEFT JOIN rooms r ON r.id=b.room_id LEFT JOIN tenant_qr_codes q ON q.tenant_id=t.id '.$where.' ORDER BY t.name,q.id DESC',$params);
    $latest=[];$hasActive=[];
    foreach($entries as $entry) {
        $tenantId=(int)$entry['tenant_id'];
        $qrId=(int)($entry['qr_id']??0);
        if($qrId && (!isset($latest[$tenantId]) || $qrId>$latest[$tenantId])) $latest[$tenantId]=$qrId;
        if($entry['qr_status']==='active') $hasActive[$tenantId]=true;
    }
    $printCards=rows("SELECT q.id qr_id,q.tenant_id,t.name,r.id room_id,r.number room_number FROM tenant_qr_codes q JOIN tenants t ON t.id=q.tenant_id LEFT JOIN tenant_assignments a ON a.tenant_id=t.id AND a.end_date IS NULL LEFT JOIN bed_spaces b ON b.id=a.bed_id LEFT JOIN rooms r ON r.id=b.room_id WHERE q.status='active' AND t.status='active' ORDER BY r.number,t.name");

    echo '<div class="qr-management">';
    echo '<div class="qr-stat-grid"><article class="qr-stat"><span>Total Active Tenants</span><strong>'.number_format($activeTenants).'</strong></article><article class="qr-stat"><span>Tenants with QR Codes</span><strong>'.number_format($withCodes).'</strong></article><article class="qr-stat"><span>Tenants without QR Codes</span><strong>'.number_format(max(0,$activeTenants-$withCodes)).'</strong></article></div>';
    section_start('QR Code Management','Generate secure tenant ID codes. QR contents are random tokens and contain no billing or personal details.');
    echo '<div class="qr-toolbar no-print"><div class="qr-toolbar-actions">';
    form_start('tenant_qr_generate_all','qr_management','inline-form'); submit('Generate All QR');
    echo '<button type="button" class="button" data-qr-print-all'.(!$printCards?' disabled':'').'>Print All QR</button>';
    echo '</div><p id="qr-management-status" class="muted" role="status" aria-live="polite"></p>';
    echo '<form method="get" class="qr-filter"><input type="hidden" name="page" value="qr_management"><label>Search tenant<input name="q" value="'.e($search).'" placeholder="Tenant ID or name"></label><label>Filter / print by room<select name="room_id"><option value="">All rooms</option>';
    foreach($rooms as $room) echo '<option value="'.e($room['id']).'" '.((int)$room['id']===$roomId?'selected':'').'>Room '.e($room['number']).'</option>';
    echo '</select></label><button class="primary">Apply filters</button><button type="button" class="button" data-qr-print-room>Print per Room</button></form></div>';
    echo '<div class="table-wrap"><table class="qr-table"><thead><tr><th>Tenant ID</th><th>Tenant Name</th><th>Room Number</th><th>QR Status</th><th>Date Generated</th><th>Actions</th></tr></thead><tbody>';
    if(!$entries) empty_row(6,'No active tenants match the selected search or room.');
    foreach($entries as $entry) {
        $tenantId=(int)$entry['tenant_id'];$qrId=(int)($entry['qr_id']??0);$status=$entry['qr_status']??'not_generated';
        echo '<tr data-tenant-id="'.$tenantId.'" data-room-id="'.e($entry['room_id']??'').'"><td>'.$tenantId.'</td><td>'.e($entry['name']).'</td><td>'.e($entry['room_number']??'Unassigned').'</td><td>'.badge($status).'</td><td>'.e($entry['generated_at']??'—').'</td><td><div class="qr-row-actions">';
        if($status==='active') {
            echo '<button type="button" class="button qr-small-button" data-qr-view data-qr-id="'.$qrId.'" data-tenant-id="'.$tenantId.'" data-tenant-name="'.e($entry['name']).'" data-room="'.e($entry['room_number']??'Unassigned').'">View QR</button>';
            echo '<button type="button" class="button qr-small-button" data-qr-download="'.$qrId.'" data-tenant-id="'.$tenantId.'">Download QR</button>';
            echo '<button type="button" class="button qr-small-button" data-qr-print="'.$qrId.'">Print QR</button>';
            form_start('tenant_qr_reissue','qr_management','inline-form'); echo '<input type="hidden" name="tenant_id" value="'.$tenantId.'">'; submit('Reissue QR',"Are you sure you want to reissue this tenant's QR code? The previous QR code will no longer work.");
        } elseif(empty($hasActive[$tenantId]) && (!$qrId || $latest[$tenantId]===$qrId)) {
            form_start('tenant_qr_generate','qr_management','inline-form'); echo '<input type="hidden" name="tenant_id" value="'.$tenantId.'">'; submit('Generate QR');
        } else echo '<span class="muted">—</span>';
        echo '</div></td></tr>';
    }
    echo '</tbody></table></div></section>';
    echo '<div id="qr-print-area" aria-hidden="true">';
    foreach($printCards as $card) echo '<article class="qr-print-card" data-qr-id="'.e($card['qr_id']).'" data-room-id="'.e($card['room_id']??'').'"><header><strong>Angelina’s Dormitory</strong><span>Tenant ID Card</span></header><div class="qr-print-card-content"><div><strong>'.e($card['name']).'</strong><span>Tenant ID: '.e($card['tenant_id']).'</span><span>Assigned Room: '.e($card['room_number']??'Unassigned').'</span></div><img src="index.php?tenant_qr_image='.e($card['qr_id']).'" alt="QR code for Tenant ID '.e($card['tenant_id']).'"></div></article>';
    echo '</div><dialog id="tenant-qr-dialog" class="qr-dialog" aria-labelledby="tenant-qr-dialog-title"><div class="qr-dialog-heading"><h2 id="tenant-qr-dialog-title">Tenant QR Code</h2><button type="button" class="button" data-qr-close>Close</button></div><div class="qr-dialog-content"><img data-qr-dialog-image alt="Tenant QR code"><div><strong data-qr-dialog-name></strong><span>Tenant ID: <b data-qr-dialog-tenant></b></span><span>Room: <b data-qr-dialog-room></b></span></div></div><div class="qr-dialog-actions"><button type="button" class="button" data-qr-dialog-download>Download QR</button><button type="button" class="primary" data-qr-dialog-print>Print QR</button></div></dialog></div>';
}
