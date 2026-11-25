<?php
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use PragmaRX\Google2FA\Google2FA;

const ADMIN_2FA_PENDING_TTL = 300;
const ADMIN_2FA_ATTEMPT_WINDOW = 900;
const ADMIN_2FA_MAX_ATTEMPTS = 5;

function admin_two_factor_key(): string {
    $encoded=getenv('DORMITORY_TOTP_ENCRYPTION_KEY');
    // Fallback: read from config.local.env file if env var is not set
    if($encoded===false || $encoded==='') {
        $envFile=dirname(__DIR__).'/config.local.env';
        if(is_file($envFile)) $encoded=trim((string)file_get_contents($envFile));
    }
    $key=is_string($encoded)&&$encoded!==''?base64_decode($encoded,true):false;
    if($key===false || strlen($key)!==32) throw new RuntimeException('Two-factor security is not configured. Contact the system administrator.');
    return $key;
}

function admin_two_factor_encrypt(string $secret): string {
    $iv=random_bytes(12);$tag='';
    $cipher=openssl_encrypt($secret,'aes-256-gcm',admin_two_factor_key(),OPENSSL_RAW_DATA,$iv,$tag,'angelinas-admin-totp-v1',16);
    if($cipher===false || strlen($tag)!==16) throw new RuntimeException('Could not protect the authenticator secret.');
    return base64_encode($iv.$tag.$cipher);
}

function admin_two_factor_decrypt(string $encrypted): string {
    $payload=base64_decode($encrypted,true);
    if($payload===false || strlen($payload)<29) throw new RuntimeException('Two-factor configuration could not be read. Contact the system administrator.');
    $secret=openssl_decrypt(substr($payload,28),'aes-256-gcm',admin_two_factor_key(),OPENSSL_RAW_DATA,substr($payload,0,12),substr($payload,12,16),'angelinas-admin-totp-v1');
    if($secret===false || $secret==='') throw new RuntimeException('Two-factor configuration could not be read. Check the encryption key configuration.');
    return $secret;
}

function admin_two_factor_encryption_ready(): void {
    admin_two_factor_key();
}

function admin_two_factor_record(int $userId): ?array {
    return one('SELECT user_id,secret_ciphertext,enabled_at,last_otp_counter FROM admin_two_factor WHERE user_id=?',[$userId]);
}

function admin_two_factor_verified(array $u): bool {
    if($u['role']!=='admin') return true;
    return (int)($_SESSION['admin_2fa_verified_user']??0)===(int)$u['id'] && admin_two_factor_record((int)$u['id'])!==null;
}

function admin_two_factor_pending_user(): ?array {
    $id=(int)($_SESSION['admin_2fa_pending_user']??0);
    $started=(int)($_SESSION['admin_2fa_pending_at']??0);
    if(!$id || !$started || time()-$started>ADMIN_2FA_PENDING_TTL) {
        admin_two_factor_clear_pending();
        return null;
    }
    return one("SELECT * FROM users WHERE id=? AND role='admin' AND active=1",[$id]);
}

function admin_two_factor_clear_pending(): void {
    unset($_SESSION['admin_2fa_pending_user'],$_SESSION['admin_2fa_pending_at'],$_SESSION['admin_2fa_pending_mode'],$_SESSION['admin_2fa_setup_secret'],$_SESSION['admin_2fa_setup_at']);
}

function admin_two_factor_start(array $u,string $mode): void {
    session_regenerate_id(true);
    unset($_SESSION['uid'],$_SESSION['admin_2fa_verified_user']);
    admin_two_factor_clear_pending();
    $_SESSION['admin_2fa_pending_user']=(int)$u['id'];
    $_SESSION['admin_2fa_pending_at']=time();
    $_SESSION['admin_2fa_pending_mode']=$mode;
    $_SESSION['csrf']=bin2hex(random_bytes(32));
    if($mode==='setup') admin_two_factor_encryption_ready();
    redirect($mode==='setup'?'security':'otp');
}

function admin_two_factor_setup_secret(int $userId): string {
    admin_two_factor_encryption_ready();
    $created=(int)($_SESSION['admin_2fa_setup_at']??0);
    if($created && time()-$created<=ADMIN_2FA_PENDING_TTL && isset($_SESSION['admin_2fa_setup_secret'])) {
        $secret=admin_two_factor_decrypt((string)$_SESSION['admin_2fa_setup_secret']);
        return $secret;
    }
    $_SESSION['admin_2fa_setup_secret']=admin_two_factor_encrypt((new Google2FA())->generateSecretKey());
    $_SESSION['admin_2fa_setup_at']=time();
    return admin_two_factor_decrypt($_SESSION['admin_2fa_setup_secret']);
}

function admin_two_factor_uri(string $secret,array $u): string {
    $holder=(string)($u['email']?:$u['username']);
    return (new Google2FA())->getQRCodeUrl("Angelina's Dormitory",$holder,$secret);
}

function admin_two_factor_qr(string $uri): string {
    $options=new QROptions(['outputType'=>QROutputInterface::MARKUP_SVG,'outputBase64'=>false,'scale'=>5]);
    $svg=(new QRCode($options))->render($uri);
    return 'data:image/svg+xml;base64,'.base64_encode($svg);
}

function admin_two_factor_rate_count(int $userId): int {
    return (int)query('SELECT COUNT(*) FROM admin_two_factor_attempts WHERE user_id=? AND attempted_at>=?',[ $userId,time()-ADMIN_2FA_ATTEMPT_WINDOW ])->fetchColumn();
}

function admin_two_factor_attempt_identity(int $userId): string {
    return hash('sha256','admin-2fa|'.$userId.'|'.($_SERVER['REMOTE_ADDR']??'unknown'));
}

function admin_two_factor_record_failure(int $userId): void {
    query('INSERT INTO admin_two_factor_attempts(user_id,identity,attempted_at) VALUES(?,?,?)',[$userId,admin_two_factor_attempt_identity($userId),time()]);
}

function admin_two_factor_clear_attempts(int $userId): void {
    query('DELETE FROM admin_two_factor_attempts WHERE user_id=?',[$userId]);
}

function admin_two_factor_make_recovery_codes(int $userId): array {
    $codes=[];
    for($i=0;$i<10;$i++) {
        $code=strtoupper(bin2hex(random_bytes(12)));
        $codes[]=implode('-',str_split($code,4));
        query('INSERT INTO admin_two_factor_recovery(user_id,code_hash) VALUES(?,?)',[$userId,hash('sha256',strtolower($code))]);
    }
    return $codes;
}

function admin_two_factor_normalize_recovery(string $code): ?string {
    $normalized=strtolower(str_replace(['-',' '],'',trim($code)));
    return preg_match('/^[a-f0-9]{24}$/D',$normalized)?$normalized:null;
}

function admin_two_factor_check_code(int $userId,string $code,bool $allowRecovery=true): bool {
    $db=db();
    $db->beginTransaction();
    try {
        query('SELECT id FROM users WHERE id=? FOR UPDATE',[$userId]);
        if(admin_two_factor_rate_count($userId)>=ADMIN_2FA_MAX_ATTEMPTS) {
            $db->commit();
            throw new RuntimeException('Too many verification attempts. Wait 15 minutes before trying again.');
        }
        $record=one('SELECT secret_ciphertext,last_otp_counter FROM admin_two_factor WHERE user_id=? FOR UPDATE',[$userId]);
        if($record && preg_match('/^\d{6}$/D',$code)) {
            $secret=admin_two_factor_decrypt($record['secret_ciphertext']);
            $counter=(new Google2FA())->verifyKeyNewer($secret,$code,(int)$record['last_otp_counter']);
            if(is_int($counter)) {
                query('UPDATE admin_two_factor SET last_otp_counter=? WHERE user_id=?',[$counter,$userId]);
                admin_two_factor_clear_attempts($userId);
                $db->commit();
                return true;
            }
        }
        if($allowRecovery && ($normalized=admin_two_factor_normalize_recovery($code))!==null) {
            foreach(rows('SELECT id,code_hash FROM admin_two_factor_recovery WHERE user_id=? AND used_at IS NULL FOR UPDATE',[$userId]) as $recovery) {
                if(hash_equals($recovery['code_hash'],hash('sha256',$normalized))) {
                    query('UPDATE admin_two_factor_recovery SET used_at=UTC_TIMESTAMP() WHERE id=? AND used_at IS NULL',[$recovery['id']]);
                    admin_two_factor_clear_attempts($userId);
                    $db->commit();
                    return true;
                }
            }
        }
        admin_two_factor_record_failure($userId);
        $db->commit();
        return false;
    } catch(Throwable $error) {
        if($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function admin_two_factor_complete_login(array $u): void {
    session_regenerate_id(true);
    admin_two_factor_clear_pending();
    $_SESSION['uid']=(int)$u['id'];
    $_SESSION['admin_2fa_verified_user']=(int)$u['id'];
    $_SESSION['csrf']=bin2hex(random_bytes(32));
    audit('Logged in with two-factor authentication');
}

function admin_two_factor_otp_page(): void {
    $u=admin_two_factor_pending_user();
    if(!$u || ($_SESSION['admin_2fa_pending_mode']??'')!=='verify') redirect('login');
    echo '<div class="auth-page"><a class="site-brand" href="?page=home"><span class="site-mark">A</span><span>ANGELINA\'S<small>DORMITORY</small></span></a><main class="auth-card"><div class="login-card"><span class="eyebrow">ADMIN SECURITY</span><h2>Verify your sign-in</h2><p class="muted">Enter the current 6-digit code from Google Authenticator, or use one recovery code.</p>';
    show_flash();
    echo '<form method="post">'.csrf().'<input type="hidden" name="action" value="verify_admin_otp"><input type="hidden" name="return_page" value="otp"><label>Verification code<input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}|[A-Fa-f0-9-]{24,35}" required maxlength="35" autofocus></label><button class="primary full">Verify and continue</button></form><p class="login-note">This verification expires after five minutes. If it expires, sign in again.</p></div></main><p class="auth-footer">Angelina\'s Dormitory &middot; Admin verification</p></div>';
}

function admin_two_factor_enrollment_page(): void {
    $u=admin_two_factor_pending_user();
    if(!$u || ($_SESSION['admin_2fa_pending_mode']??'')!=='setup') redirect('login');
    $secret=admin_two_factor_setup_secret((int)$u['id']);
    $uri=admin_two_factor_uri($secret,$u);
    $qr=admin_two_factor_qr($uri);
    echo '<div class="auth-page"><a class="site-brand" href="?page=home"><span class="site-mark">A</span><span>ANGELINA\'S<small>DORMITORY</small></span></a><main class="auth-card"><div class="login-card"><span class="eyebrow">ADMIN SECURITY SETUP</span><h2>Connect Google Authenticator</h2><p class="muted">Scan this QR code or enter the setup key in Google Authenticator, then enter the current 6-digit code to finish signing in.</p>';
    show_flash();
    echo '<div class="two-factor-qr"><img src="'.$qr.'" alt="Google Authenticator setup QR code"></div><label>Manual setup key<code class="two-factor-secret">'.e($secret).'</code></label><form method="post">'.csrf().'<input type="hidden" name="action" value="confirm_admin_2fa_setup"><input type="hidden" name="return_page" value="security"><label>6-digit verification code<input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" required maxlength="6" autofocus></label><button class="primary full">Confirm and continue</button></form><p class="login-note">Keep this page private. The setup key is shown only during enrollment.</p></div></main><p class="auth-footer">Angelina\'s Dormitory &middot; Admin security setup</p></div>';
}

function admin_two_factor_handle_action(string $action): bool {
    if(!in_array($action,['verify_admin_otp','confirm_admin_2fa_setup','disable_admin_2fa','regenerate_admin_recovery'],true)) return false;
    if($action==='verify_admin_otp') {
        $u=admin_two_factor_pending_user();
        if(!$u || ($_SESSION['admin_2fa_pending_mode']??'')!=='verify') throw new RuntimeException('Your verification session expired. Sign in again.');
        if(!admin_two_factor_check_code((int)$u['id'],trim((string)($_POST['code']??'')))) throw new RuntimeException('That verification or recovery code is incorrect.');
        admin_two_factor_complete_login($u);
        redirect($u['must_change']?'account':'dashboard');
    }
    if($action==='confirm_admin_2fa_setup') {
        $u=admin_two_factor_pending_user();
        $preAuth=$u && ($_SESSION['admin_2fa_pending_mode']??'')==='setup';
        if(!$preAuth) {
            $u=require_role(['admin']);
            if(!admin_two_factor_verified($u)) throw new RuntimeException('Sign in again before setting up two-factor authentication.');
        }
        if(!$u) throw new RuntimeException('Your setup session expired. Sign in again.');
        if(admin_two_factor_record((int)$u['id'])) throw new RuntimeException('Two-factor authentication is already enabled.');
        if(admin_two_factor_rate_count((int)$u['id'])>=ADMIN_2FA_MAX_ATTEMPTS) throw new RuntimeException('Too many verification attempts. Wait 15 minutes before trying again.');
        $created=(int)($_SESSION['admin_2fa_setup_at']??0);
        if(!$created || time()-$created>ADMIN_2FA_PENDING_TTL || !isset($_SESSION['admin_2fa_setup_secret'])) throw new RuntimeException('Your setup session expired. Sign in again.');
        $secret=admin_two_factor_decrypt((string)$_SESSION['admin_2fa_setup_secret']);
        $code=trim((string)($_POST['code']??''));
        $counter=preg_match('/^\d{6}$/D',$code)?(new Google2FA())->verifyKeyNewer($secret,$code,0):false;
        if(!is_int($counter)) { admin_two_factor_record_failure((int)$u['id']); throw new RuntimeException('That verification code is incorrect. Check your phone and try again.'); }
        $db=db();$db->beginTransaction();
        try {
            query('INSERT INTO admin_two_factor(user_id,secret_ciphertext,enabled_at,last_otp_counter) VALUES(?,?,UTC_TIMESTAMP(),?)',[(int)$u['id'],admin_two_factor_encrypt($secret),$counter]);
            $codes=admin_two_factor_make_recovery_codes((int)$u['id']);
            admin_two_factor_clear_attempts((int)$u['id']);
            $db->commit();
        } catch(Throwable $error) { if($db->inTransaction()) $db->rollBack(); throw $error; }
        if($preAuth) admin_two_factor_complete_login($u);
        $_SESSION['admin_2fa_recovery_once']=['user_id'=>(int)$u['id'],'created_at'=>time(),'codes'=>$codes];
        audit('Enabled admin two-factor authentication');
        redirect($u['must_change']?'account':'security');
    }
    $u=require_role(['admin']);
    if(!admin_two_factor_verified($u)) throw new RuntimeException('Sign in again before changing two-factor settings.');
    if(!admin_two_factor_record((int)$u['id'])) throw new RuntimeException('Set up Google Authenticator before using this action.');
    if(!admin_two_factor_check_code((int)$u['id'],trim((string)($_POST['code']??'')))) throw new RuntimeException('That verification or recovery code is incorrect.');
    if($action==='regenerate_admin_recovery') {
        $db=db();$db->beginTransaction();
        try {
            query('DELETE FROM admin_two_factor_recovery WHERE user_id=?',[(int)$u['id']]);
            $codes=admin_two_factor_make_recovery_codes((int)$u['id']);
            $db->commit();
        } catch(Throwable $error) { if($db->inTransaction()) $db->rollBack(); throw $error; }
        $_SESSION['admin_2fa_recovery_once']=['user_id'=>(int)$u['id'],'created_at'=>time(),'codes'=>$codes];
        flash('New recovery codes generated. Save them now; previous codes no longer work.');
        redirect('security');
    }
    query('DELETE FROM admin_two_factor WHERE user_id=?',[(int)$u['id']]);
    query('DELETE FROM admin_two_factor_recovery WHERE user_id=?',[(int)$u['id']]);
    unset($_SESSION['uid'],$_SESSION['admin_2fa_verified_user'],$_SESSION['admin_2fa_recovery_once']);
    session_regenerate_id(true);
    $_SESSION['csrf']=bin2hex(random_bytes(32));
    flash('Two-factor authentication was disabled. Sign in again to set it up.');
    redirect('login');
}

function admin_two_factor_settings_view(array $u): void {
    if($u['role']!=='admin' || !admin_two_factor_verified($u)) throw new RuntimeException('Sign in again to manage administrator security.');
    $record=admin_two_factor_record((int)$u['id']);
    section_start('Admin security settings','Google Authenticator two-factor verification protects every Admin session.');
    if(!$record) {
        $secret=admin_two_factor_setup_secret((int)$u['id']);
        $qr=admin_two_factor_qr(admin_two_factor_uri($secret,$u));
        echo '<p>Scan the QR code or enter the manual setup key in Google Authenticator. Confirm with a current 6-digit code to enable protection.</p><div class="two-factor-qr"><img src="'.$qr.'" alt="Google Authenticator setup QR code"></div><label>Manual setup key<code class="two-factor-secret">'.e($secret).'</code></label><form method="post" class="form-grid">'.csrf().'<input type="hidden" name="action" value="confirm_admin_2fa_setup"><input type="hidden" name="return_page" value="security">';field('6-digit verification code','code','text','',true,'inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code"');submit('Enable Google Authenticator');echo '</form></section>';
        return;
    }
    echo '<p class="notice success">Google Authenticator is enabled for this account.</p>';
    $once=$_SESSION['admin_2fa_recovery_once']??null;
    if(is_array($once) && (int)($once['user_id']??0)===(int)$u['id'] && time()-(int)($once['created_at']??0)<=600) {
        echo '<div class="notice"><strong>Save these one-time recovery codes now.</strong><p>Each code works once. They will not be displayed again.</p><code class="recovery-codes">'.e(implode("\n",$once['codes']??[])).'</code></div>';
        unset($_SESSION['admin_2fa_recovery_once']);
    } else unset($_SESSION['admin_2fa_recovery_once']);
    $remaining=(int)query('SELECT COUNT(*) FROM admin_two_factor_recovery WHERE user_id=? AND used_at IS NULL',[(int)$u['id']])->fetchColumn();
    echo '<p>'.e((string)$remaining).' recovery codes remain.</p><form method="post" class="form-grid">'.csrf().'<input type="hidden" name="action" value="regenerate_admin_recovery"><input type="hidden" name="return_page" value="security">';field('Current authenticator or recovery code','code','text','',true,'autocomplete="one-time-code" maxlength="35"');submit('Generate new recovery codes','This invalidates every existing recovery code. Continue?');echo '</form><form method="post" class="form-grid">'.csrf().'<input type="hidden" name="action" value="disable_admin_2fa"><input type="hidden" name="return_page" value="security">';field('Current authenticator or recovery code','code','text','',true,'autocomplete="one-time-code" maxlength="35"');submit('Disable Google Authenticator','Disable two-factor authentication? You will be signed out and must set it up again at next Admin login.');echo '</form></section>';
}
