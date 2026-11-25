<?php
function tenant_credentials(bool $temporary=true): array {
    $username=required('username');
    if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{2,49}$/',$username)) throw new RuntimeException('Username must be 3–50 characters using letters, numbers, dots, underscores, or hyphens.');
    if(one('SELECT id FROM users WHERE username=?',[$username])) throw new RuntimeException('That username is already taken. Choose another username.');
    $password=required('temporary_password');
    if(strlen($password)<10 || strlen($password)>72) throw new RuntimeException(($temporary?'Temporary password':'Password').' must be between 10 and 72 characters.');
    if($password!==($_POST['confirm_password']??'')) throw new RuntimeException('The passwords do not match.');
    return [$username,$password];
}
function account_email(string $email, ?int $exceptUser=null): string {
    $email=trim($email);
    if(strlen($email)>254 || !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid email address.');
    if(one('SELECT id FROM users WHERE email=? AND id<>?',[$email,$exceptUser??0])) throw new RuntimeException('That email address is already assigned to another account.');
    return $email;
}
function handle_action(): void {
    if ($_SERVER['REQUEST_METHOD']!=='POST') return;
    $action=(string)($_POST['action']??'');
    $return=(string)($_POST['return_page']??'dashboard');
    if($action==='verify_admin_otp') $return='otp';
    if(in_array($action,['confirm_admin_2fa_setup','disable_admin_2fa','regenerate_admin_recovery'],true)) $return='security';
    if($return==='verification') $return='payments';
    if($action==='register') $return='register';
    if (!hash_equals(token(),(string)($_POST['csrf']??''))) { http_response_code(419); exit('Your session expired. Reload this page and try again.'); }
    try {
        if(admin_two_factor_handle_action($action)) return;
        if($action==='public_contact') {
            $return='home';$identity=hash('sha256','public-contact|'.($_SERVER['REMOTE_ADDR']??'local'));
            query('DELETE FROM login_attempts WHERE attempted_at < ?',[time()-900]);
            if((int)query('SELECT COUNT(*) FROM login_attempts WHERE identity=?',[$identity])->fetchColumn()>=5)throw new RuntimeException('Too many messages. Please try again in 15 minutes.');
            $name=required('name');$email=required('email');$message=required('message');
            if(strlen($name)>200||strlen($email)>254||!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Please enter your name and a valid email address.');
            if(!empty($_POST['website']))throw new RuntimeException('Unable to submit this message.');
            query('INSERT INTO login_attempts(identity,attempted_at) VALUES(?,?)',[$identity,time()]);
            audit('New website inquiry','public_contact',null,json_encode(['name'=>$name,'email'=>$email,'message'=>$message],JSON_UNESCAPED_UNICODE));
            flash('Your message has been received by the dormitory office.');header('Location: index.php?page=home#contact');exit;
        }
        if($action==='register') {
            if(user()) throw new RuntimeException('Sign out before registering a new account.');
            $_SESSION['signup_values']=array_intersect_key($_POST,array_flip(['name','email','contact','username']));
            $identity=hash('sha256','registration|'.($_SERVER['REMOTE_ADDR']??'local'));
            query('DELETE FROM login_attempts WHERE attempted_at < ?',[time()-900]);
            if((int)query('SELECT COUNT(*) FROM login_attempts WHERE identity=?',[$identity])->fetchColumn()>=8) throw new RuntimeException('Too many registration attempts. Try again in 15 minutes.');
            query('INSERT INTO login_attempts(identity,attempted_at) VALUES(?,?)',[$identity,time()]);
            db()->beginTransaction(); query("SELECT value FROM settings WHERE `key`='seeded' FOR UPDATE");
            [$username,$password]=tenant_credentials(false); $name=required('name'); $email=required('email'); $contact=required('contact');
            if(strlen($name)>200 || strlen($email)>254 || strlen($contact)>80) throw new RuntimeException('Your name, email, or contact number is too long.');
            if(!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid email address.');
            if(one('SELECT id FROM tenants WHERE email=?',[$email])) throw new RuntimeException('This email already has a tenant record. Sign in with your existing account or contact the dormitory for help.');
            $email=account_email($email);
            query('INSERT INTO tenants(name,email,contact,move_in) VALUES(?,?,?,?)',[$name,$email,$contact,date('Y-m-d')]); $id=(int)db()->lastInsertId();
            query("INSERT INTO users(username,password_hash,role,name,tenant_id,email,must_change) VALUES(?,?,'tenant',?,?,?,0)",[$username,password_hash($password,PASSWORD_DEFAULT),$name,$id,$email]);
            audit('Tenant self-registered','tenants',$id);
            db()->commit(); unset($_SESSION['signup_values']); $_SESSION['registered_username']=$username;
            flash('Your tenant account is ready. Sign in using your username and password. The dormitory will assign your room and bed.'); redirect('login');
        }
        if ($action==='login') {
            $identityValue=trim((string)($_POST['username']??''));
            $identity=hash('sha256',strtolower($identityValue).'|'.($_SERVER['REMOTE_ADDR']??'local'));
            query('DELETE FROM login_attempts WHERE attempted_at < ?',[time()-900]);
            if ((int)query('SELECT COUNT(*) FROM login_attempts WHERE identity=?',[$identity])->fetchColumn()>=8) throw new RuntimeException('Too many attempts. Try again in 15 minutes.');
            $u=one('SELECT * FROM users WHERE username=? AND active=1',[$identityValue]);
            if(!$u && filter_var($identityValue,FILTER_VALIDATE_EMAIL)) $u=one("SELECT * FROM users WHERE role='admin' AND email=? AND active=1",[$identityValue]);
            if (!$u || !password_verify($_POST['password']??'',$u['password_hash'])) {
                query('INSERT INTO login_attempts(identity,attempted_at) VALUES(?,?)',[$identity,time()]);
                throw new RuntimeException('The username or password is incorrect.');
            }
            query('DELETE FROM login_attempts WHERE identity=?',[$identity]);
            if($u['role']==='admin') {
                admin_two_factor_encryption_ready();
                admin_two_factor_start($u,admin_two_factor_record((int)$u['id'])?'verify':'setup');
            }
            session_regenerate_id(true); $_SESSION['uid']=$u['id']; $_SESSION['csrf']=bin2hex(random_bytes(32));
            audit('Logged in');
            redirect($u['must_change']?'account':'dashboard');
        }
        $sessionUser=user();
        if($sessionUser && $sessionUser['role']==='admin' && !admin_two_factor_verified($sessionUser)) {
            $_SESSION=[]; session_regenerate_id(true); $_SESSION['csrf']=bin2hex(random_bytes(32));
            flash('Please sign in again and complete two-factor verification.','error');
            redirect('login');
        }
        $u=require_role(['admin','staff','tenant']);
        if ($action==='logout') { audit('Logged out'); $_SESSION=[]; session_regenerate_id(true); redirect('login'); }
        if ($u['must_change'] && $action!=='password') throw new RuntimeException('Set your own password before continuing.');
        db()->beginTransaction();
        // Serialize financial and assignment changes with an InnoDB row lock.
        query("SELECT value FROM settings WHERE `key`='seeded' FOR UPDATE");
        switch ($action) {
        case 'password':
            if(!password_verify($_POST['current_password']??'',$u['password_hash'])) throw new RuntimeException('Current password is incorrect.');
            $pw=required('new_password');
            if(strlen($pw)<10 || strlen($pw)>72 || $pw!==($_POST['confirm_password']??'') || password_verify($pw,$u['password_hash'])) throw new RuntimeException('Use a different password of at least 10 and at most 72 bytes and confirm it correctly.');
            query('UPDATE users SET password_hash=?,must_change=0 WHERE id=?',[password_hash($pw,PASSWORD_DEFAULT),$u['id']]); audit('Changed own password'); break;
        case 'room':
            require_role(['admin']); $capacity=(int)required('capacity');
            if($capacity<1||$capacity>30) throw new RuntimeException('Room capacity must be between 1 and 30.');
            $hasElectricity=array_key_exists('fixed_electricity',$_POST);$hasWater=array_key_exists('fixed_water',$_POST);
            if($hasElectricity!==$hasWater) throw new RuntimeException('Enter both legacy utility values or neither.');
            if($hasElectricity) query('INSERT INTO rooms(number,capacity,rent,fixed_electricity,fixed_water) VALUES(?,?,?,?,?)',[required('number'),$capacity,cents('rent'),cents('fixed_electricity'),cents('fixed_water')]);
            else query('INSERT INTO rooms(number,capacity,rent) VALUES(?,?,?)',[required('number'),$capacity,cents('rent')]);
            $id=(int)db()->lastInsertId();
            for($i=1;$i<=$capacity;$i++) query('INSERT INTO bed_spaces(room_id,label) VALUES(?,?)',[$id,'Bed '.$i]); audit('Created room','rooms',$id); break;
        case 'room_update':
            require_role(['admin']); $id=(int)required('room_id'); $st=required('status');$room=one('SELECT number,capacity FROM rooms WHERE id=?',[$id]);
            if(!$room) throw new RuntimeException('Room not found.');
            $number=trim((string)($_POST['number']??$room['number']));
            if(strlen($number)>50) throw new RuntimeException('Room name must be 50 characters or fewer.');
            if(!in_array($st,['active','maintenance'],true)) throw new RuntimeException('Invalid room status.');
            if($st==='maintenance' && one('SELECT a.id FROM tenant_assignments a JOIN bed_spaces b ON b.id=a.bed_id WHERE b.room_id=? AND a.end_date IS NULL',[$id])) throw new RuntimeException('Transfer active tenants before marking this room for maintenance.');
            if(one('SELECT id FROM rooms WHERE number=? AND id<>?',[$number,$id])) throw new RuntimeException('That room name is already in use.');
            $capacity=filter_var($_POST['capacity']??$room['capacity'],FILTER_VALIDATE_INT);
            if($capacity===false||$capacity<1||$capacity>30) throw new RuntimeException('Bed capacity must be between 1 and 30.');
            $bedCount=(int)query('SELECT COUNT(*) FROM bed_spaces WHERE room_id=?',[$id])->fetchColumn();
            $occupied=(int)query('SELECT COUNT(DISTINCT b.id) FROM bed_spaces b JOIN tenant_assignments a ON a.bed_id=b.id WHERE b.room_id=? AND a.end_date IS NULL',[$id])->fetchColumn();
            if($capacity<$occupied) throw new RuntimeException('Bed capacity cannot be lower than the number of occupied beds.');
            $bedsToRemove=[];
            if($capacity<$bedCount) {
                $removeCount=$bedCount-$capacity;
                foreach(rows('SELECT b.id FROM bed_spaces b WHERE b.room_id=? AND NOT EXISTS(SELECT 1 FROM tenant_assignments a WHERE a.bed_id=b.id) ORDER BY b.id DESC',[$id]) as $candidate) {
                    $bedsToRemove[]=(int)$candidate['id'];
                    if(count($bedsToRemove)===$removeCount) break;
                }
                if(count($bedsToRemove)<$removeCount) throw new RuntimeException('Cannot remove beds with assignment history. Reduce capacity only by unused beds.');
            }
            $hasElectricity=array_key_exists('fixed_electricity',$_POST);$hasWater=array_key_exists('fixed_water',$_POST);
            if($hasElectricity!==$hasWater) throw new RuntimeException('Enter both legacy utility values or neither.');
            $old=one('SELECT number,capacity,rent,status'.($hasElectricity?',fixed_electricity,fixed_water':'').' FROM rooms WHERE id=?',[$id]);
            if($bedsToRemove) foreach($bedsToRemove as $bedId) query('DELETE FROM bed_spaces WHERE id=? AND room_id=?',[$bedId,$id]);
            if($capacity>$bedCount) {
                $nextLabel=1;
                foreach(rows("SELECT label FROM bed_spaces WHERE room_id=?",[$id]) as $existingBed) if(preg_match('/^Bed ([1-9][0-9]*)$/',$existingBed['label'],$match)) $nextLabel=max($nextLabel,(int)$match[1]+1);
                for($i=0;$i<$capacity-$bedCount;$i++) query('INSERT INTO bed_spaces(room_id,label) VALUES(?,?)',[$id,'Bed '.($nextLabel+$i)]);
            }
            if($hasElectricity) {
                query('UPDATE rooms SET number=?,capacity=?,rent=?,status=?,fixed_electricity=?,fixed_water=? WHERE id=?',[$number,$capacity,cents('rent'),$st,cents('fixed_electricity'),cents('fixed_water'),$id]);
                $after=['number'=>$number,'capacity'=>$capacity,'rent'=>cents('rent'),'status'=>$st,'fixed_electricity'=>cents('fixed_electricity'),'fixed_water'=>cents('fixed_water')];
            } else {
                query('UPDATE rooms SET number=?,capacity=?,rent=?,status=? WHERE id=?',[$number,$capacity,cents('rent'),$st,$id]);
                $after=['number'=>$number,'capacity'=>$capacity,'rent'=>cents('rent'),'status'=>$st];
            }
            audit('Updated room settings','rooms',$id,json_encode(['before'=>$old,'after'=>$after])); break;
        case 'room_delete':
            require_role(['admin']); $id=(int)required('room_id');
            $room=one('SELECT number FROM rooms WHERE id=? FOR UPDATE',[$id]);
            if(!$room) throw new RuntimeException('Room not found.');
            if(one('SELECT a.id FROM tenant_assignments a JOIN bed_spaces b ON b.id=a.bed_id WHERE b.room_id=?',[$id])) throw new RuntimeException('This room cannot be removed because it has tenant assignment history.');
            if(one('SELECT id FROM occupancy_scans WHERE room_id=?',[$id]) || one('SELECT id FROM maintenance_requests WHERE room_id=?',[$id])) throw new RuntimeException('This room cannot be removed because it has linked occupancy or maintenance records.');
            query('DELETE FROM bed_spaces WHERE room_id=?',[$id]);
            query('DELETE FROM rooms WHERE id=?',[$id]);
            audit('Removed unused room','rooms',$id,'Room '.$room['number'].' had no tenant assignment history or linked records.'); break;
        case 'tenant':
            require_role(['admin']); $email=account_email(required('email'));
            [$username,$password]=tenant_credentials();
            $room=(int)required('room_id');
            $bed=(int)required('bed_id');
            if(!one('SELECT id FROM rooms WHERE id=? AND status=\'active\' FOR UPDATE',[$room])) throw new RuntimeException('Selected room is no longer active.');
            if(!one("SELECT b.id FROM bed_spaces b WHERE b.id=? AND b.room_id=? AND NOT EXISTS(SELECT 1 FROM tenant_assignments a WHERE a.bed_id=b.id AND a.end_date IS NULL)",[$bed,$room])) throw new RuntimeException('This bed is no longer available in the selected room.');
            $date=date_input('move_in'); if($date>date('Y-m-d')) throw new RuntimeException('Move-in cannot be in the future.');
            query('INSERT INTO tenants(name,email,contact,move_in) VALUES(?,?,?,?)',[required('name'),$email,required('contact'),$date]); $id=(int)db()->lastInsertId();
            try { query('INSERT INTO tenant_assignments(tenant_id,bed_id,start_date) VALUES(?,?,?)',[$id,$bed,$date]); }
            catch(PDOException $error) { if(($error->errorInfo[1]??0)===1062) throw new RuntimeException('This bed was just assigned to another tenant. Choose an available bed.'); throw $error; }
            query('INSERT INTO users(username,password_hash,role,name,tenant_id,email) VALUES(?,?,?,?,?,?)',[$username,password_hash($password,PASSWORD_DEFAULT),'tenant',required('name'),$id,$email]);
            audit('Registered tenant, created individual account, and assigned bed','tenants',$id);
            $_SESSION['action_message']='Tenant registered and account created. Username: '.$username.'. Share the temporary password privately; the tenant must change it on first login.'; break;
        case 'tenant_account':
            require_role(['admin']); $id=(int)required('tenant_id');
            $tenant=one("SELECT * FROM tenants WHERE id=? AND status='active'",[$id]);
            if(!$tenant) throw new RuntimeException('Choose an active tenant to create an account.');
            if(one('SELECT id FROM users WHERE tenant_id=?',[$id])) throw new RuntimeException('This tenant already has an account. Use password reset or enable access instead.');
            [$username,$password]=tenant_credentials();
            $email=account_email($tenant['email']);
            query("INSERT INTO users(username,password_hash,role,name,tenant_id,email) VALUES(?,?,'tenant',?,?,?)",[$username,password_hash($password,PASSWORD_DEFAULT),$tenant['name'],$id,$email]);
            audit('Created account for existing tenant','users',(int)db()->lastInsertId(),'Linked tenant #'.$id);
            $_SESSION['action_message']='Tenant account created. Username: '.$username.'. The tenant must change the temporary password on first login.'; break;
        case 'tenant_update':
            require_role(['admin']); $id=(int)required('tenant_id'); $email=required('email');
            $account=one('SELECT id FROM users WHERE tenant_id=?',[$id]);
            $email=account_email($email,$account?(int)$account['id']:null);
            query('UPDATE tenants SET name=?,email=?,contact=? WHERE id=?',[required('name'),$email,required('contact'),$id]);
            query('UPDATE users SET name=?,email=? WHERE tenant_id=?',[required('name'),$email,$id]); audit('Updated tenant details','tenants',$id); break;
        case 'transfer':
            require_role(['admin']); $id=(int)required('tenant_id'); $a=one('SELECT * FROM tenant_assignments WHERE tenant_id=? AND end_date IS NULL',[$id]);
            if(!$a) throw new RuntimeException('No active assignment.');
            $date=date_input('transfer_date'); if($date<$a['start_date']||$date>date('Y-m-d')) throw new RuntimeException('Transfer date must be within the active stay.');
            $bed=(int)required('bed_id');
            if(!one("SELECT b.id FROM bed_spaces b JOIN rooms r ON r.id=b.room_id WHERE b.id=? AND r.status='active' AND NOT EXISTS(SELECT 1 FROM tenant_assignments a WHERE a.bed_id=b.id AND a.end_date IS NULL)",[$bed])) throw new RuntimeException('Selected bed is unavailable.');
            query('UPDATE tenant_assignments SET end_date=? WHERE id=?',[$date,$a['id']]);
            query('INSERT INTO tenant_assignments(tenant_id,bed_id,start_date) VALUES(?,?,?)',[$id,$bed,$date]); audit('Transferred tenant','tenants',$id); break;
        case 'assign_tenant':
            require_role(['admin']); $id=(int)required('tenant_id'); $bed=(int)required('bed_id'); $date=date_input('move_in');
            if(!one("SELECT id FROM tenants WHERE id=? AND status='active'",[$id]) || one('SELECT id FROM tenant_assignments WHERE tenant_id=? AND end_date IS NULL',[$id])) throw new RuntimeException('Choose an active tenant without a bed assignment.');
            if($date>date('Y-m-d')) throw new RuntimeException('Move-in cannot be in the future.');
            $room=(int)($_POST['room_id']??0);
            $availableBed=one("SELECT b.id,b.room_id FROM bed_spaces b JOIN rooms r ON r.id=b.room_id WHERE b.id=? AND r.status='active' AND NOT EXISTS(SELECT 1 FROM tenant_assignments a WHERE a.bed_id=b.id AND a.end_date IS NULL)",[$bed]);
            if(!$availableBed) throw new RuntimeException('This bed is no longer available.');
            if($room && (int)$availableBed['room_id']!==$room) throw new RuntimeException('Choose an available bed from the selected room.');
            query('INSERT INTO tenant_assignments(tenant_id,bed_id,start_date) VALUES(?,?,?)',[$id,$bed,$date]);
            query('UPDATE tenants SET move_in=? WHERE id=?',[$date,$id]); audit('Assigned room to registered tenant','tenants',$id); break;
        case 'moveout':
            require_role(['admin']); $id=(int)required('tenant_id'); $a=one('SELECT * FROM tenant_assignments WHERE tenant_id=? AND end_date IS NULL',[$id]);
            $date=date_input('move_out'); if(!$a || $date<$a['start_date']||$date>date('Y-m-d')) throw new RuntimeException('Move-out date must be within the active stay.');
            query("UPDATE tenants SET status='moved_out',move_out=? WHERE id=?",[$date,$id]);
            query('UPDATE tenant_assignments SET end_date=? WHERE id=?',[$date,$a['id']]); audit('Recorded move-out; history retained','tenants',$id); break;
        case 'bill':
            require_role(['admin']);
            $statementId=create_statement((int)required('tenant_id'),period_input(),date_input('cutoff'),date_input('due_date'),cents('rent'),cents('discount'),cents('adjustment',true),trim($_POST['discount_reason']??''),trim($_POST['adjustment_reason']??'')); $return='statement&id='.$statementId; break;
        case 'adjust_bill':
            require_role(['admin']); $id=(int)required('billing_id'); $b=bill($id);
            if(!$b || $b['carried_to']) throw new RuntimeException('Carried statements cannot be adjusted; adjust the current statement.');
            $type=required('type'); if(!in_array($type,['discount','adjustment'],true)) throw new RuntimeException('Invalid adjustment type.');
            $amount=cents('amount',$type==='adjustment'); if(!$amount) throw new RuntimeException('Adjustment cannot be zero.');
            $delta=$type==='discount'?-$amount:$amount;
            if($b['total_due']+$delta<$b['paid']) throw new RuntimeException('The revised total cannot be less than payments already received.');
            query('INSERT INTO billing_adjustments(billing_id,type,amount,reason,created_by) VALUES(?,?,?,?,?)',[$id,$type,$amount,required('reason'),$u['id']]);
            query('UPDATE billing SET total_due=total_due+? WHERE id=?',[$delta,$id]);
            notify((int)$b['tenant_id'],$id,'billing','Billing statement updated','Your revised total is '.money($b['total_due']+$delta).'. Reason: '.required('reason'));
            audit('Adjusted billing statement','billing',$id,required('reason')); break;
        case 'payment':
            require_role(['admin','staff']); $key=required('idempotency_key'); if(one('SELECT id FROM payments WHERE idempotency_key=?',[$key])) break;
            $id=(int)required('billing_id'); $b=bill($id); $amt=cents('amount');
            if(!$b||$b['carried_to']||$amt<=0||$amt>balance($b)) throw new RuntimeException('Payment must be positive and no greater than the current bill balance.');
            $method=required('method'); if(!in_array($method,['GCash','Personal Payment'],true)) throw new RuntimeException('Invalid payment method.'); if($u['role']==='staff'&&$method!=='Personal Payment') throw new RuntimeException('Staff may only record cash payments.');
            $ref=trim($_POST['reference']??''); if($method==='GCash' && $ref==='') throw new RuntimeException('GCash reference is required.');
            $date=date_input('payment_date'); if($date>date('Y-m-d')) throw new RuntimeException('Payment date cannot be in the future.');
            if($method==='GCash' && one("SELECT id FROM payments WHERE method='GCash' AND reference=?",[$ref])) throw new RuntimeException('This GCash reference has already been recorded.');
            if($method==='GCash' && one("SELECT id FROM payment_submissions WHERE reference=? AND status='pending'",[$ref])) throw new RuntimeException('This reference is pending verification. Confirm the existing submission in Payments instead of recording it twice.');
            query('INSERT INTO payments(billing_id,tenant_id,amount,method,payer,payment_date,reference,received_by,idempotency_key,balance_after) VALUES(?,?,?,?,?,?,?,?,?,?)',[$id,$b['tenant_id'],$amt,$method,required('payer'),$date,$ref,$u['id'],$key,balance($b)-$amt]); $pid=(int)db()->lastInsertId();
            notify((int)$b['tenant_id'],$id,'receipt','Payment receipt AD-'.str_pad((string)$pid,6,'0',STR_PAD_LEFT),'Payment received: '.money($amt).' via '.$method.'. Remaining balance: '.money(balance($b)-$amt).'. Reference: '.$ref.'.');
            audit('Recorded payment and generated receipt','payments',$pid); $return='receipt&id='.$pid; break;
        case 'deposit':
            require_role(['admin']); $amt=cents('amount'); if($amt<=0) throw new RuntimeException('Deposit must be positive.');
            $date=date_input('received_date'); if($date>date('Y-m-d')) throw new RuntimeException('Deposit date cannot be in the future.');
            query('INSERT INTO deposits(tenant_id,amount,received_date,recorded_by) VALUES(?,?,?,?)',[(int)required('tenant_id'),$amt,$date,$u['id']]); audit('Recorded security deposit','deposits',(int)db()->lastInsertId()); break;
        case 'refund':
            require_role(['admin']); $id=(int)required('deposit_id'); $d=one('SELECT d.*,t.status FROM deposits d JOIN tenants t ON t.id=d.tenant_id WHERE d.id=?',[$id]); $amt=cents('refund_amount');
            if(!$d || $d['refunded_date'] || $d['status']!=='moved_out'||$amt>$d['amount']) throw new RuntimeException('Refund requires a moved-out tenant and cannot exceed an unsettled deposit.');
            $date=date_input('refunded_date'); $t=one('SELECT move_out FROM tenants WHERE id=?',[$d['tenant_id']]);
            if($date<$t['move_out']||$date>date('Y-m-d')) throw new RuntimeException('Refund date must fall between move-out and today.');
            query('UPDATE deposits SET refund_amount=?,deduction_reason=?,refunded_date=? WHERE id=?',[$amt,required('deduction_reason'),$date,$id]); audit('Settled security deposit','deposits',$id,required('deduction_reason')); break;
        case 'staff':
            require_role(['admin']); $pw=required('temporary_password'); if(strlen($pw)<10 || strlen($pw)>72) throw new RuntimeException('Password must have at least 10 and at most 72 bytes.');
            $username=required('username'); if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{2,49}$/',$username) || one('SELECT id FROM users WHERE username=?',[$username])) throw new RuntimeException('Enter a valid, unused username.');
            $email=account_email(required('email')); $role=(string)($_POST['role']??'staff');
            if(!in_array($role,['admin','staff'],true)) throw new RuntimeException('Choose a Landlady or Staff account role.');
            query('INSERT INTO users(username,password_hash,role,name,email) VALUES(?,?,?,?,?)',[$username,password_hash($pw,PASSWORD_DEFAULT),$role,required('name'),$email]); audit('Created '.$role.' account','users',(int)db()->lastInsertId()); break;
        case 'account_email':
            require_role(['admin']); $id=(int)required('user_id'); $account=one('SELECT id,role,tenant_id FROM users WHERE id=?',[$id]);
            if(!$account) throw new RuntimeException('Account not found.');
            $email=account_email(required('email'),$id);
            query('UPDATE users SET email=? WHERE id=?',[$email,$id]);
            if($account['role']==='tenant') query('UPDATE tenants SET email=? WHERE id=?',[$email,$account['tenant_id']]);
            audit('Updated account email','users',$id); break;
        case 'account_username':
            require_role(['admin']); $id=(int)required('user_id');
            if(!one('SELECT id FROM users WHERE id=?',[$id])) throw new RuntimeException('Account not found.');
            $username=trim(required('username'));
            if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{2,49}$/',$username)) throw new RuntimeException('Username must be 3–50 characters using letters, numbers, dots, underscores, or hyphens.');
            if(one('SELECT id FROM users WHERE username=? AND id<>?',[$username,$id])) throw new RuntimeException('That username is already taken. Choose another username.');
            query('UPDATE users SET username=? WHERE id=?',[$username,$id]);
            audit('Updated account username','users',$id); break;
        case 'toggle_user':
            require_role(['admin']); $id=(int)required('user_id'); if($id===$u['id']) throw new RuntimeException('You cannot disable your own account.');
            query('UPDATE users SET active=1-active WHERE id=?',[$id]); audit('Changed account access','users',$id); break;
        case 'reset_password':
            require_role(['admin']); $id=(int)required('user_id'); if($id===$u['id']) throw new RuntimeException('Use your account page to change your own password.');
            $pw=required('temporary_password'); if(strlen($pw)<10 || strlen($pw)>72) throw new RuntimeException('Password must have at least 10 and at most 72 bytes.');
            query('UPDATE users SET password_hash=?,must_change=1 WHERE id=?',[password_hash($pw,PASSWORD_DEFAULT),$id]); audit('Reset account password','users',$id); break;
        case 'reminders':
            require_role(['admin']);
            queue_due_reminders();
            audit('Prepared due-date reminders'); break;
        case 'send_mail':
            require_role(['admin']); db()->commit(); require_once __DIR__.'/mailer.php'; $counts=send_notifications(); flash($counts.' Review delivery status below.'); redirect('notifications');
        default: if(!feature_action($action,$u)) throw new RuntimeException('Unknown action.');
        }
        if($action==='submit_payment') $return='payments';
        db()->commit(); flash($_SESSION['action_message']??'Saved successfully.'); unset($_SESSION['action_message']);
        if(preg_match('/^(receipt|statement)&id=(\d+)$/',$return,$target)) { header('Location: index.php?page='.$target[1].'&id='.(int)$target[2]); exit; }
        redirect($return);
    } catch(Throwable $ex) {
        if(db()->inTransaction()) db()->rollBack();
        if(isset($GLOBALS['new_payment_proof']) && is_file($GLOBALS['new_payment_proof'])) unlink($GLOBALS['new_payment_proof']);
        $msg=$ex instanceof PDOException ? (($ex->errorInfo[1]??0)===1062?'That account, room, billing period, reference, or assignment already exists.':'Database operation failed. No changes were saved.') : $ex->getMessage();
        flash($msg,'error'); redirect($action==='login'?'login':$return);
    }
}
