"""End-to-end checks against a separate disposable database, never live tenant data."""
from pathlib import Path
from urllib.request import build_opener, HTTPCookieProcessor, Request
from urllib.parse import urlencode
from urllib.error import HTTPError
import http.cookiejar
import os
import re
import json
import uuid
import datetime
import base64
import hashlib
import hmac
import struct
import subprocess
import tempfile
import time

ROOT = Path(__file__).resolve().parents[1]
BASE = 'http://localhost:8081/'
PHP = r'C:\xampp\php\php.exe'
ADMIN_TOTP_SECRETS = {}

def totp_code(secret, counter_offset=0):
    key = base64.b32decode(secret.upper() + '=' * ((8-len(secret) % 8) % 8))
    counter = int(time.time() // 30) + counter_offset
    digest = hmac.new(key, struct.pack('>Q', counter), hashlib.sha1).digest()
    offset = digest[-1] & 15
    value = int.from_bytes(digest[offset:offset+4], 'big') & 0x7fffffff
    return f'{value % 1000000:06d}'

class Client:
    def __init__(self):
        self.cookie_jar = http.cookiejar.CookieJar()
        self.opener = build_opener(HTTPCookieProcessor(self.cookie_jar))
    def session_id(self):
        return next((cookie.value for cookie in self.cookie_jar if cookie.name=='PHPSESSID'),None)
    def get(self, path=''):
        try:
            with self.opener.open(BASE + path) as r:
                return r.status, r.read().decode(errors='replace')
        except HTTPError as e:
            return e.code, e.read().decode()
    def post(self, action, page='dashboard', **data):
        status, html = self.get('index.php?page=' + page)
        token = re.search(r'name="csrf" value="([^"]+)"', html)
        assert token, (page, status, html[:300])
        payload = {'csrf': token[1], 'action': action, 'return_page': page, **data}
        try:
            with self.opener.open(Request(BASE+'index.php', urlencode(payload,doseq=True).encode())) as r:
                html = r.read().decode()
                assert 'Warning:' not in html and 'Fatal error' not in html, html[:400]
                return html
        except HTTPError as e:
            return e.read().decode()
    def login(self, role, password, username=None):
        global ADMIN_TOTP_SECRETS
        usernames = {'admin':'admin','staff':'staff','tenant':'tenant'}
        login_name=username or usernames[role]
        secret_key='admin' if login_name in ('admin','admin@angelinasdormitory.test') else login_name
        html = self.post('login', 'login', username=login_name, password=password)
        if role != 'admin':
            return html
        if 'Connect Google Authenticator' in html:
            match = re.search(r'class="two-factor-secret">([^<]+)</code>', html)
            assert match, html[:400]
            ADMIN_TOTP_SECRETS[secret_key] = match[1]
            return self.post('confirm_admin_2fa_setup','security',code=totp_code(ADMIN_TOTP_SECRETS[secret_key]))
        if 'Verify your sign-in' in html:
            assert secret_key in ADMIN_TOTP_SECRETS, html[:400]
            return self.post('verify_admin_otp','otp',code=totp_code(ADMIN_TOTP_SECRETS[secret_key]))
        return html
    def upload(self, action, page, filename, content, upload_field="proof", **data):
        code, html = self.get('index.php?page='+page)
        token = re.search(r'name="csrf" value="([^"]+)"', html)[1]
        boundary = 'TestBoundary'+uuid.uuid4().hex
        payload = {'csrf':token,'action':action,'return_page':page,**data}
        body = b''
        for name,value in payload.items():
            body += ('--'+boundary+'\r\nContent-Disposition: form-data; name="'+name+'"\r\n\r\n'+str(value)+'\r\n').encode()
        body += ('--'+boundary+'\r\nContent-Disposition: form-data; name="'+upload_field+'"; filename="'+filename+'"\r\nContent-Type: application/octet-stream\r\n\r\n').encode()+content+b'\r\n'
        body += ('--'+boundary+'--\r\n').encode()
        with self.opener.open(Request(BASE+'index.php',body,{'Content-Type':'multipart/form-data; boundary='+boundary})) as r:
            return r.read().decode()

def scan_tenant_qr(client, payload):
    _, html = client.get('index.php?page=dashboard')
    csrf = re.search(r'name="csrf" value="([^"]+)"', html)
    assert csrf, html[:300]
    request = Request(BASE+'index.php?tenant_qr_lookup=1', urlencode({'csrf':csrf[1],'token':payload}).encode(), {'Content-Type':'application/x-www-form-urlencoded'})
    try:
        with client.opener.open(request) as response:
            return response.status, json.loads(response.read().decode())
    except HTTPError as error:
        return error.code, json.loads(error.read().decode())

class MysqlTestDatabase:
    def __init__(self, env):
        self.env = env
        self.result = []
    def execute(self, sql, params=()):
        result = subprocess.run([PHP, 'tests/db.php'], input=json.dumps({'sql':sql, 'params':list(params)}), cwd=ROOT,env=self.env,capture_output=True,text=True,check=True)
        def native(v):
            return int(v) if isinstance(v,str) and re.fullmatch(r'-?\d+',v) else v
        self.result = [tuple(native(v) for v in row) for row in json.loads(result.stdout)]
        return self
    def fetchone(self): return self.result[0] if self.result else None
    def fetchall(self): return self.result
    def commit(self): pass
    def close(self): pass

with tempfile.TemporaryDirectory(prefix='dormitory-test-',dir=ROOT/'storage') as temp:
    dbname = 'angelinas_test_' + uuid.uuid4().hex[:12]
    env = {**os.environ, 'DORMITORY_DB_NAME':dbname, 'DORMITORY_TOTP_ENCRYPTION_KEY':base64.b64encode(os.urandom(32)).decode()}
    subprocess.run([PHP,'database/setup.php'],cwd=ROOT,env=env,check=True,capture_output=True,text=True)
    log = open(Path(temp)/'server.log','w+')
    server = subprocess.Popen([PHP,'-d','upload_tmp_dir='+temp,'-S','localhost:8081','router.php'],cwd=ROOT,env=env,stdout=log,stderr=log,creationflags=subprocess.CREATE_NO_WINDOW)
    con = MysqlTestDatabase(env)
    try:
        admin = Client()
        for attempt in range(30):
            try:
                code, html = admin.get()
                if code == 200: break
            except OSError: pass
            time.sleep(.1)
        assert 'Manage Your Dormitory' in html
        con.execute("UPDATE users SET email=NULL WHERE role IN ('admin','staff')")
        subprocess.run([PHP,'database/setup.php'],cwd=ROOT,env=env,check=True,capture_output=True,text=True)
        assert con.execute('SELECT role,email FROM users WHERE username=?',['admin']).fetchone()==('admin','admin@angelinasdormitory.test')
        assert con.execute('SELECT role,email FROM users WHERE username=?',['staff']).fetchone()==('staff','staff@angelinasdormitory.test')
        login_page=admin.get('index.php?page=login')[1]
        assert 'name="username"' in login_page and 'name="role"' not in login_page
        assert 'Username or Admin email' in login_page
        assert 'incorrect' in admin.login('admin','wrong-password')
        setup_page=admin.post('login','login',username='admin',password='Admin@12345')
        assert 'Connect Google Authenticator' in setup_page and 'data:image/svg+xml;base64,' in setup_page
        setup_secret=re.search(r'class="two-factor-secret">([^<]+)</code>',setup_page)
        assert setup_secret, setup_page[:400]
        ADMIN_TOTP_SECRETS['admin']=setup_secret[1]
        blocked=admin.get('index.php?page=dashboard')[1]
        assert 'Sign in to your account' in blocked and 'welcome-banner' not in blocked
        assert 'incorrect' in admin.post('confirm_admin_2fa_setup','security',code='bad-code')
        enrollment=admin.post('confirm_admin_2fa_setup','security',code=totp_code(ADMIN_TOTP_SECRETS['admin']))
        assert 'Set a new personal password' in enrollment
        encrypted_secret=con.execute('SELECT secret_ciphertext FROM admin_two_factor WHERE user_id=(SELECT id FROM users WHERE username=?)',['admin']).fetchone()[0]
        assert encrypted_secret and ADMIN_TOTP_SECRETS['admin'] not in encrypted_secret
        assert 'at least 10' in admin.post('password','account', current_password='Admin@12345',new_password='short',confirm_password='short')
        admin.post('password','account',current_password='Admin@12345',new_password='AdminTesting@2026',confirm_password='AdminTesting@2026')
        security=admin.get('index.php?page=security')[1]
        recovery_match=re.search(r'class="recovery-codes">([^<]+)</code>',security)
        assert recovery_match and 'Generate new recovery codes' in security
        recovery_codes=recovery_match[1].strip().splitlines()
        assert len(recovery_codes)==10
        recovery_code=recovery_codes[0]
        assert 'recovery-codes' not in admin.get('index.php?page=security')[1]
        pending_admin=Client()
        pending_admin.get('index.php?page=login')
        before_password_session=pending_admin.session_id()
        challenge_page=pending_admin.post('login','login',username='admin',password='AdminTesting@2026')
        assert 'Verify your sign-in' in challenge_page
        assert pending_admin.session_id()!=before_password_session
        blocked=pending_admin.get('index.php?page=dashboard')[1]
        assert 'Sign in to your account' in blocked and 'welcome-banner' not in blocked
        for protected in ['tenants','rooms','billing','payments','deposits','qr_management','occupancy','notifications','audit','accounts','security','batch','reports','expenses','requests','announcements','settings','ledger']:
            blocked=pending_admin.get('index.php?page='+protected)[1]
            assert 'Sign in to your account' in blocked and 'welcome-banner' not in blocked, protected
        for protected in ['proof=1','payment_qr=1','export=1','utility_preview=1','batch_preview=1&room_id=1&period=2027-01','activity_poll=1']:
            status,blocked=pending_admin.get('index.php?'+protected)
            assert status in (200,403) and 'welcome-banner' not in blocked, protected
        wrong_secret=base64.b32encode(os.urandom(20)).decode().rstrip('=')
        wrong_otp=totp_code(wrong_secret)
        while wrong_otp in [totp_code(ADMIN_TOTP_SECRETS['admin']),totp_code(ADMIN_TOTP_SECRETS['admin'],1)]:
            wrong_secret=base64.b32encode(os.urandom(20)).decode().rstrip('=')
            wrong_otp=totp_code(wrong_secret)
        assert 'incorrect' in pending_admin.post('verify_admin_otp','otp',code=wrong_otp)
        assert 'incorrect' in pending_admin.post('verify_admin_otp','otp',code=totp_code(ADMIN_TOTP_SECRETS['admin'],-2))
        login_otp=totp_code(ADMIN_TOTP_SECRETS['admin'],1)
        before_otp_session=pending_admin.session_id()
        assert 'welcome-banner' in pending_admin.post('verify_admin_otp','otp',code=login_otp)
        assert pending_admin.session_id()!=before_otp_session
        replay_admin=Client()
        assert 'Verify your sign-in' in replay_admin.post('login','login',username='admin',password='AdminTesting@2026')
        assert 'incorrect' in replay_admin.post('verify_admin_otp','otp',code=login_otp)
        assert 'welcome-banner' in replay_admin.post('verify_admin_otp','otp',code=recovery_code)
        assert con.execute('SELECT used_at FROM admin_two_factor_recovery WHERE user_id=(SELECT id FROM users WHERE username=?) AND code_hash=?',['admin',hashlib.sha256(recovery_code.replace('-','').lower().encode()).hexdigest()]).fetchone()[0] is not None
        recovery_replay=Client()
        assert 'Verify your sign-in' in recovery_replay.post('login','login',username='admin',password='AdminTesting@2026')
        assert 'incorrect' in recovery_replay.post('verify_admin_otp','otp',code=recovery_code)
        email_admin=Client()
        assert 'Verify your sign-in' in email_admin.post('login','login',username='admin@angelinasdormitory.test',password='AdminTesting@2026')
        assert 'welcome-banner' in email_admin.post('verify_admin_otp','otp',code=recovery_codes[1])
        logout_admin=Client()
        assert 'Verify your sign-in' in logout_admin.post('login','login',username='admin',password='AdminTesting@2026')
        assert 'welcome-banner' in logout_admin.post('verify_admin_otp','otp',code=recovery_codes[4])
        logout_admin.post('logout','dashboard')
        assert 'Sign in to your account' in logout_admin.get('index.php?page=dashboard')[1]
        locked_admin=Client()
        assert 'Verify your sign-in' in locked_admin.post('login','login',username='admin',password='AdminTesting@2026')
        admin_id=con.execute('SELECT id FROM users WHERE username=?',['admin']).fetchone()[0]
        con.execute('INSERT INTO admin_two_factor_attempts(user_id,identity,attempted_at) VALUES(?,?,?),(?,?,?),(?,?,?),(?,?,?),(?,?,?)',[
            admin_id,'a'*64,int(time.time()),admin_id,'b'*64,int(time.time()),admin_id,'c'*64,int(time.time()),admin_id,'d'*64,int(time.time()),admin_id,'e'*64,int(time.time())])
        assert 'Too many verification attempts' in locked_admin.post('verify_admin_otp','otp',code=totp_code(ADMIN_TOTP_SECRETS['admin']))
        con.execute('DELETE FROM admin_two_factor_attempts WHERE user_id=?',[admin_id])
        staff_login=Client()
        staff_page=staff_login.login('staff','Staff@12345')
        assert 'Set a new personal password' in staff_page and 'Verify your sign-in' not in staff_page
        assert 'Saved successfully' in admin.post('staff','accounts',name='Managed Staff',email='managed.staff@example.com',username='managed.staff',role='staff',temporary_password='ManagedStaff@2026')
        managed_staff_id=con.execute('SELECT id FROM users WHERE username=?',['managed.staff']).fetchone()[0]
        assert 'Saved successfully' in admin.post('account_username','accounts',user_id=managed_staff_id,username='managed.staff.updated')
        assert con.execute('SELECT username FROM users WHERE id=?',[managed_staff_id]).fetchone()==('managed.staff.updated',)
        assert 'already taken' in admin.post('account_username','accounts',user_id=managed_staff_id,username='admin')
        assert '3–50 characters' in admin.post('account_username','accounts',user_id=managed_staff_id,username='bad username')
        assert 'Update username' in admin.get('index.php?page=accounts')[1]
        managed=Client()
        assert 'Set a new personal password' in managed.login('staff','ManagedStaff@2026','managed.staff.updated')
        for page in ['dashboard','tenants','rooms','billing','payments','deposits','qr_management','notifications','audit','accounts','account','statement&id=1','batch','reports','expenses','requests','announcements','settings','ledger']:
            code, html = admin.get('index.php?page='+page)
            assert code == 200 and 'Warning:' not in html and 'Fatal error' not in html, (page,code,html[:200])
        code, occupancy_page = admin.get('index.php?page=occupancy')
        assert code == 403 and 'Room occupancy camera' not in occupancy_page
        rooms_page = admin.get('index.php?page=rooms')[1]
        assert all(label not in rooms_page for label in ['Occupancy Checks','Room occupancy camera','start-camera'])
        assert 'Unknown action.' in admin.post('scan','rooms',room_id=2,detected_count=4,source='manual',notes='Verify count')
        assert con.execute('SELECT COUNT(*) FROM occupancy_scans').fetchone()==(0,)
        html=admin.get('index.php?page=dashboard')[1]
        assert all(label in html for label in ['Collected this month','Outstanding balance','Overdue statements','Pending payments','Active tenants','Occupied beds','Available beds','Occupancy rate'])
        for page,selected in [('batch','billing'),('expenses','reports'),('ledger','reports'),('notifications','settings')]:
            body=admin.get('index.php?page='+page)[1]
            assert re.search(r'class="selected" href="\?page='+selected+r'"',body),page
        directory=admin.get('index.php?page=tenants')[1]
        assert all(label in directory for label in ['Current balance','Payment status','Move-in date','Billing History'])
        staff_id=con.execute('SELECT id FROM users WHERE username=?',['staff']).fetchone()[0]
        con.execute('UPDATE users SET email=NULL WHERE id=?',[staff_id])
        legacy_staff=Client()
        assert 'Set a new personal password' in legacy_staff.login('staff','Staff@12345',username='staff')
        assert 'Saved successfully' in admin.post('account_email','accounts',user_id=staff_id,email='payment.staff@example.com')
        assert con.execute('SELECT email FROM users WHERE id=?',[staff_id]).fetchone()==('payment.staff@example.com',)
        assert 'Saved successfully' in admin.post('staff','accounts',name='Managed Admin',email='managed.admin@example.com',username='managed.admin',role='admin',temporary_password='ManagedAdmin@2026')
        assert con.execute('SELECT role,email FROM users WHERE username=?',['managed.admin']).fetchone()==('admin','managed.admin@example.com')
        managed_admin=Client()
        assert 'Set a new personal password' in managed_admin.login('admin','ManagedAdmin@2026','managed.admin')
        managed_admin.post('password','account',current_password='ManagedAdmin@2026',new_password='ManagedAdminChanged@2026',confirm_password='ManagedAdminChanged@2026')
        assert managed_admin.get('index.php?page=accounts')[0]==200
        assert con.execute('SELECT role,email FROM users WHERE username=?',['managed.staff.updated']).fetchone()==('staff','managed.staff@example.com')
        managed.post('password','account',current_password='ManagedStaff@2026',new_password='ManagedStaffChanged@2026',confirm_password='ManagedStaffChanged@2026')
        assert managed.get('index.php?page=dashboard')[0]==200
        assert managed.get('index.php?page=accounts')[0]==403
        visitor=Client()
        assert 'Manage Your Dormitory' in visitor.get()[1]
        assert visitor.get('index.php?page=privacy')[0]==200
        assert visitor.get('index.php?page=terms')[0]==200
        contact=visitor.post('public_contact','home',name='Website Visitor',email='visitor@example.com',message='A question about room availability.',website='')
        assert 'received by the dormitory office' in contact
        assert 'Website Visitor' in admin.get('index.php?page=support')[1]
        assert 'New website inquiry' in admin.get('index.php?page=inbox')[1]
        before=con.execute("SELECT COUNT(*) FROM audit_logs WHERE record_type='public_contact'").fetchone()[0]
        visitor.post('public_contact','home',name='Invalid',email='invalid',message='Hello')
        assert con.execute("SELECT COUNT(*) FROM audit_logs WHERE record_type='public_contact'").fetchone()[0]==before

        today = time.strftime('%Y-%m-%d')
        html = admin.post('payment','payments',billing_id=1,payer='Parent Santos',amount='7000',method='GCash',payment_date=today,reference='TOO-MUCH',idempotency_key='overpayment')
        assert 'no greater' in html
        assert con.execute('SELECT COUNT(*) FROM payments').fetchone()[0] == 0
        html = admin.post('payment','payments',billing_id=1,payer='Parent Santos',amount='5000',method='GCash',payment_date=today,reference='GC-123',idempotency_key='first-payment')
        assert 'Payment confirmation' in html and '₱5,000.00' in html
        admin.post('payment','payments',billing_id=1,payer='Parent Santos',amount='5000',method='GCash',payment_date=today,reference='GC-123',idempotency_key='first-payment')
        assert con.execute('SELECT COUNT(*) FROM payments').fetchone()[0] == 1
        con.execute("UPDATE billing SET due_date='2020-01-01' WHERE id=1"); con.commit()
        assert 'Overdue' in admin.get('index.php?page=statement&id=1')[1]
        admin.post('reminders','notifications')
        admin.post('reminders','notifications')
        assert con.execute("SELECT COUNT(*) FROM notifications WHERE type='overdue'").fetchone()[0] == 1
        html = admin.post('payment','payments',billing_id=1,payer='Parent Santos',amount='100',method='GCash',payment_date=today,reference='GC-123',idempotency_key='duplicate-reference')
        assert 'already been recorded' in html
        current = con.execute('SELECT period FROM billing WHERE id=1').fetchone()[0]
        y,m = map(int,current.split('-')); nxt = f'{y+1 if m==12 else y:04d}-{1 if m==12 else m+1:02d}'
        admin.post('bill','billing',tenant_id=1,period=nxt,cutoff=nxt+'-15',due_date=nxt+'-28',rent='5000',electricity='800',water='300',discount='200',discount_reason='Scholarship',adjustment='500',adjustment_reason='Additional dorm charge')
        latest = con.execute('SELECT id,previous_balance,total_due FROM billing ORDER BY id DESC LIMIT 1').fetchone()
        assert latest == (2,110000,747000), latest
        assert con.execute('SELECT carried_to FROM billing WHERE id=1').fetchone()[0] == 2
        html = admin.post('payment','payments',billing_id=1,payer='Parent',amount='100',method='Personal Payment',payment_date=today,reference='',idempotency_key='old-bill')
        assert 'no greater' in html
        admin.post('payment','payments',billing_id=2,payer='Parent',amount='3000',method='Personal Payment',payment_date=today,reference='',idempotency_key='second-payment')
        admin.post('payment','payments',billing_id=2,payer='Parent',amount='2000',method='Personal Payment',payment_date=today,reference='',idempotency_key='third-payment')
        assert con.execute('SELECT SUM(amount) FROM payments WHERE billing_id=2').fetchone()[0] == 500000
        admin.post('adjust_bill','billing',billing_id=2,type='discount',amount='300',reason='Courtesy discount')
        assert con.execute('SELECT total_due FROM billing WHERE id=2').fetchone()[0] == 717000
        admin.post('payment','payments',billing_id=2,payer='Parent',amount='2170',method='Personal Payment',payment_date=today,reference='',idempotency_key='final-payment')
        assert 'Paid' in admin.get('index.php?page=statement&id=2')[1]
        html=admin.post('adjust_bill','billing',billing_id=2,type='discount',amount='1',reason='Invalid below paid')
        assert 'less than payments' in html
        staff=Client(); staff.login('staff','Staff@12345','staff'); staff.post('password','account',current_password='Staff@12345',new_password='StaffTesting@2026',confirm_password='StaffTesting@2026')
        assert staff.get('index.php?page=tenants')[0]==403
        assert staff.get('index.php?page=batch')[0]==200
        assert 'Monthly Billing Run' in staff.get('index.php?page=dashboard')[1]
        room_page=staff.get('index.php?page=rooms')
        assert room_page[0]==200 and 'Room 101' in room_page[1] and 'Create room' not in room_page[1] and 'Save room settings' not in room_page[1]
        assert 'do not have access' in staff.post('room','payments',number='BLOCKED',capacity=4,rent=1000)
        tenant=Client(); tenant.login('tenant','Tenant@12345'); tenant.post('password','account',current_password='Tenant@12345',new_password='TenantTesting@2026',confirm_password='TenantTesting@2026')
        assert tenant.get('index.php?page=accounts')[0]==403
        assert tenant.get('index.php?page=receipt&id=1')[0]==200
        paid_home=tenant.get('index.php?page=dashboard')[1]
        assert '<h2>\u20b17,170.00</h2>' in paid_home
        assert 'Your account is settled' in paid_home
        assert 'View Bill Details' in paid_home and 'Pay Now' not in paid_home
        assert 'id="room-details"' in tenant.get('index.php?page=profile')[1]

        assert 'do not have access' in tenant.post('payment','payments',billing_id=2,amount=1)
        tenant.post('request','requests',title='Leaking tap',description='Water drips from the room sink.',priority='urgent')
        assert con.execute('SELECT tenant_id,room_id,status FROM maintenance_requests WHERE id=1').fetchone()==(1,2,'open')
        admin.post('request_update','requests',request_id=1,status='resolved',admin_note='Tap replaced.')
        assert 'Tap replaced.' in tenant.get('index.php?page=requests')[1]
        admin.post('announcement','announcements',title='Water schedule',message='Water service resumes at 5 PM.',expires_on='')
        assert 'Water schedule' in tenant.get('index.php?page=announcements')[1]
        assert 'Water schedule' in tenant.get('index.php?page=dashboard')[1]
        assert 'Water schedule' in staff.get('index.php?page=announcements')[1]
        admin.post('expense','expenses',category='Maintenance',description='=SUM(1,2)',amount=500,expense_date=today,reference='INV-1')
        assert con.execute('SELECT amount FROM expenses').fetchone()==(50000,)
        assert '₱500.00' in admin.get('index.php?page=reports')[1]
        assert "'=SUM(1,2)" in admin.get('index.php?export=expenses')[1]
        assert tenant.get('index.php?export=collections')[0]==403
        admin.post('moveout','tenants',tenant_id=1,move_out=today)
        assert con.execute('SELECT status FROM tenants WHERE id=1').fetchone()[0]=='moved_out'
        assert con.execute('SELECT COUNT(*) FROM tenant_assignments WHERE end_date IS NULL').fetchone()[0]==0
        admin.post('refund','deposits',deposit_id=1,refund_amount=4500,refunded_date=today,deduction_reason='500 cleaning deduction')
        assert con.execute('SELECT refund_amount FROM deposits WHERE id=1').fetchone()[0]==450000
        registration_room=con.execute('SELECT room_id FROM bed_spaces WHERE id=?',[5]).fetchone()[0]
        registration_page=admin.get('index.php?page=tenants')[1]
        assert 'Available Room' in registration_page and 'Available Bed' in registration_page
        available=json.loads(admin.get('index.php?tenant_beds=1&room_id='+str(registration_room))[1])
        assert 5 in {int(bed['id']) for bed in available['beds']}
        new_registration=dict(name='Another Tenant',email='other@example.com',contact='09170000001',move_in=today,room_id=registration_room,bed_id=5,username='another',temporary_password='OtherTesting@2026',confirm_password='OtherTesting@2026')
        assert 'do not match' in admin.post('tenant','accounts',**{**new_registration,'confirm_password':'Different@2026'})
        assert con.execute('SELECT COUNT(*) FROM tenants').fetchone()[0]==1
        assert 'already taken' in admin.post('tenant','accounts',**{**new_registration,'username':'admin'})
        assert '3–50 characters' in admin.post('tenant','accounts',**{**new_registration,'username':'bad username'})
        assert 'do not have access' in staff.post('tenant','payments',**new_registration)
        assert 'do not have access' in tenant.post('tenant_account','billing',tenant_id=1,username='blocked',temporary_password='BlockedTesting@2026',confirm_password='BlockedTesting@2026')
        other_room=con.execute('SELECT id FROM rooms WHERE id<>? AND status=? LIMIT 1',[registration_room,'active']).fetchone()[0]
        assert 'selected room' in admin.post('tenant','accounts',**{**new_registration,'room_id':other_room,'username':'wrong.room','email':'wrong.room@example.com'})
        assert 'Tenant registered and account created' in admin.post('tenant','accounts',**new_registration)
        assert con.execute('SELECT COUNT(*) FROM tenants').fetchone()[0]==2
        batch_page=admin.get('index.php?page=batch')[1]
        assert 'Select Room to Bill' in batch_page and 'Discount per tenant' in batch_page and 'Room utilities total (electricity + water' in batch_page
        assert 'Create a monthly statement' not in admin.get('index.php?page=billing')[1]
        batch_preview=json.loads(admin.get('index.php?batch_preview=1&room_id='+str(registration_room)+'&period='+nxt)[1])
        assert {tenant['tenant_id'] for tenant in batch_preview['tenants']}=={2}
        assert batch_preview['room']['id']==registration_room
        batch_utilities=f"{batch_preview['room']['utilities']/100:.2f}"
        refreshed=json.loads(admin.get('index.php?tenant_beds=1&room_id='+str(registration_room))[1])
        assert 5 not in {int(bed['id']) for bed in refreshed['beds']}
        assert 'no longer available' in admin.post('tenant','accounts',**{**new_registration,'username':'duplicate.bed','email':'duplicate.bed@example.com'})
        assert con.execute('SELECT COUNT(*) FROM tenants').fetchone()[0]==2
        other=Client(); other.post('login','login',username='another',password='OtherTesting@2026'); other.post('password','account',current_password='OtherTesting@2026',new_password='OtherChanged@2026',confirm_password='OtherChanged@2026')
        assert other.get('index.php?page=statement&id=1')[0]==404
        assert other.get('index.php?page=receipt&id=1')[0]==404
        assert 'Maria Santos' not in other.get('index.php?page=billing')[1]
        assert 'Leaking tap' not in other.get('index.php?page=requests')[1]
        assert 'Leaking tap' not in other.post('request_update','requests',request_id=1,status='open',admin_note='Unauthorized')
        assert con.execute('SELECT COUNT(*) FROM payments').fetchone()[0]==4
        admin.post('bill','billing',tenant_id=2,period=current,cutoff=current+'-15',due_date=current+'-28',rent=5000,electricity=0,water=0,discount=0,adjustment=0)
        staff.post('payment','payments',billing_id=3,payer='New Tenant',amount=1000,method='Personal Payment',payment_date=today,reference='',idempotency_key='staff-received')
        assert con.execute('SELECT amount,received_by,tenant_id FROM payments WHERE billing_id=3').fetchone()==(100000,2,2)
        assert 'Another Tenant' in other.get('index.php?page=receipt&id=5')[1]
        assert 'Balance when payment was received' in other.get('index.php?page=receipt&id=5')[1]
        assert 'do not have access' in staff.post('void_payment','payments',payment_id=5,reason='Staff cannot void')
        admin.post('void_payment','payments',payment_id=5,reason='Incorrect amount entered')
        assert con.execute('SELECT void_reason FROM payments WHERE id=5').fetchone()[0]=='Incorrect amount entered'
        assert 'Voided' in other.get('index.php?page=receipt&id=5')[1]
        assert '₱5,000.00' in other.get('index.php?page=ledger&tenant_id=1')[1]
        assert 'AD-5' not in admin.get('index.php?export=collections')[1]
        staff.post('payment','payments',billing_id=3,payer='New Tenant',amount=1000,method='Personal Payment',payment_date=today,reference='',idempotency_key='corrected-staff-payment')
        assert 'AD-6' in admin.get('index.php?export=collections')[1]
        admin.post('settings','settings',business_name='Test Residence',business_contact='09170000000',business_address='Test street',cutoff_day=15,due_day=31)
        assert 'Test Residence' in other.get('index.php?page=receipt&id=6')[1]
        html=admin.post('batch_billing','batch',room_id=registration_room,period=nxt,cutoff=nxt+'-15',due_date=nxt+'-28',**{'selected[]':2,'room_utilities':'invalid','discount':'0'})
        assert 'valid amount for room utilities' in html
        assert con.execute('SELECT COUNT(*) FROM billing WHERE tenant_id=2 AND period=?',[nxt]).fetchone()[0]==0
        html=admin.post('batch_billing','batch',room_id=registration_room,period=nxt,cutoff=nxt+'-15',due_date=nxt+'-28',**{'selected[]':2,'room_utilities':batch_utilities,'discount':'100','discount_reason':'Room promotion'})
        assert '1 statements created' in html
        newbill=con.execute('SELECT previous_balance,total_due,electricity,water FROM billing WHERE tenant_id=2 AND period=?',[nxt]).fetchone()
        tenant_preview=next(tenant for tenant in batch_preview['tenants'] if tenant['tenant_id']==2)
        assert newbill==(400000,900000+tenant_preview['electricity']+tenant_preview['water']-10000,tenant_preview['electricity'],tenant_preview['water']),newbill
        assert con.execute("SELECT amount,reason FROM billing_adjustments WHERE billing_id=(SELECT id FROM billing WHERE tenant_id=2 AND period=?) AND type='discount'",[nxt]).fetchone()==(10000,'Room promotion')
        html=admin.post('batch_billing','batch',room_id=registration_room,period=nxt,cutoff=nxt+'-15',due_date=nxt+'-28',**{'selected[]':2,'room_utilities':batch_utilities,'discount':'100','discount_reason':'Room promotion'})
        assert '0 statements created. 1 skipped' in html
        assert 'carried forward' in admin.post('void_payment','payments',payment_id=6,reason='Cannot change a carried bill')
        assert 'Test Residence' in other.get('index.php?page=ledger')[1]
        admin.post('send_mail','notifications')
        assert con.execute("SELECT COUNT(*) FROM notifications WHERE status='sent'").fetchone()[0]==0
        for path in ['storage/dormitory.sqlite','app/bootstrap.php','database/schema.sql','config.example.php','tests/integration.py','%2e%2e/config.local.php']:
            assert admin.get(path)[0]==403, path
        req=Request(BASE+'index.php',urlencode({'action':'room','number':'CSRF','capacity':4,'rent':1000}).encode())
        try: admin.opener.open(req); raise AssertionError('Missing CSRF was accepted')
        except HTTPError as ex: assert ex.code==419
        assert con.execute("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=?",[dbname]).fetchone()[0]>15
        try:
            con.execute("INSERT INTO tenant_assignments(tenant_id,bed_id,start_date) VALUES(2,5,?)",[today])
            raise AssertionError('Duplicate active assignment was accepted')
        except subprocess.CalledProcessError: pass
        try:
            con.execute("INSERT INTO bed_spaces(room_id,label) VALUES(999999,'invalid')")
            raise AssertionError('Foreign key violation was accepted')
        except subprocess.CalledProcessError: pass
        assert con.execute('SELECT COUNT(*) FROM audit_logs').fetchone()[0]>15
        assert 'already has an account' in admin.post('tenant_account','accounts',tenant_id=2,username='duplicated',temporary_password='DuplicateTesting@2026',confirm_password='DuplicateTesting@2026')
        con.execute('INSERT INTO tenants(name,email,contact,move_in) VALUES(?,?,?,?)',['Existing Resident','existing@example.com','09171111111',today])
        existing_id=con.execute('SELECT id FROM tenants WHERE email=?',['existing@example.com']).fetchone()[0]
        assert 'Existing Resident' in admin.get('index.php?page=tenants')[1]
        assert 'Create login for an existing tenant' not in admin.get('index.php?page=accounts')[1]
        assert 'Tenant account created' in admin.post('tenant_account','tenants',tenant_id=existing_id,username='existing.resident',temporary_password='ExistingTesting@2026',confirm_password='ExistingTesting@2026')
        assert con.execute('SELECT tenant_id,must_change FROM users WHERE username=?',['existing.resident']).fetchone()==(existing_id,1)
        existing=Client()
        assert 'Set a new personal password' in existing.post('login','login',username='existing.resident',password='ExistingTesting@2026')
        assert con.execute('SELECT COUNT(*) FROM tenants').fetchone()[0]==3
        assert con.execute('SELECT COUNT(*) FROM users WHERE tenant_id=?',[existing_id]).fetchone()[0]==1
        signup=Client()
        assert 'Get Started' in signup.get()[1]
        assert signup.get('index.php?page=register')[0]==200
        details=dict(name='Self Registered',email='signup@example.com',contact='09172222222',username='self.registered',temporary_password='PersonalTesting@2026',confirm_password='PersonalTesting@2026')
        before=con.execute('SELECT COUNT(*) FROM tenants').fetchone()[0]
        assert 'do not match' in signup.post('register','register',**{**details,'confirm_password':'Different@2026'})
        assert con.execute('SELECT COUNT(*) FROM tenants').fetchone()[0]==before
        assert 'already taken' in signup.post('register','register',**{**details,'username':'admin'})
        assert 'already has a tenant record' in signup.post('register','register',**{**details,'email':'maria@example.com'})
        assert 'Your tenant account is ready' in signup.post('register','register',**details,role='admin',tenant_id=1,active=0)
        new_id=con.execute('SELECT tenant_id FROM users WHERE username=?',['self.registered']).fetchone()[0]
        assert con.execute('SELECT role,must_change,active FROM users WHERE username=?',['self.registered']).fetchone()==('tenant',0,1)
        assert con.execute('SELECT COUNT(*) FROM tenant_assignments WHERE tenant_id=?',[new_id]).fetchone()[0]==0
        assert 'Not assigned yet' in signup.post('login','login',username='self.registered',password='PersonalTesting@2026')
        assert signup.get('index.php?page=accounts')[0]==403
        assert 'Your room must be assigned' in signup.get('index.php?page=requests')[1]
        assert 'value="request"' not in signup.get('index.php?page=requests')[1]
        assert signup.get('index.php?page=statement&id=1')[0]==404
        bed_screen=admin.get('index.php?page=rooms&bed_id=1')[1]
        assert 'SELECTED BED' in bed_screen and 'Assign a tenant' in bed_screen and 'data-assignment-room' in bed_screen and 'data-assignment-bed' in bed_screen and 'value="1" selected' in bed_screen
        assert 'Assign room' in admin.get('index.php?page=tenants')[1]
        bed_room=con.execute('SELECT room_id FROM bed_spaces WHERE id=1').fetchone()[0]
        wrong_room=con.execute('SELECT id FROM rooms WHERE id<>? AND status=? LIMIT 1',[bed_room,'active']).fetchone()[0]
        assert 'selected room' in admin.post('assign_tenant','rooms',tenant_id=new_id,bed_id=1,room_id=wrong_room,move_in=today)
        admin.post('assign_tenant','rooms',tenant_id=new_id,bed_id=1,room_id=bed_room,move_in=today)
        assert 'Occupied by' in admin.get('index.php?page=rooms&bed_id=1')[1]
        admin.post('assign_tenant','rooms',tenant_id=2,bed_id=1,move_in=today)
        assert con.execute('SELECT COUNT(*) FROM tenant_assignments WHERE bed_id=1 AND end_date IS NULL').fetchone()[0]==1
        assert con.execute('SELECT bed_id FROM tenant_assignments WHERE tenant_id=?',[new_id]).fetchone()[0]==1
        assert 'Room 101' in signup.get('index.php?page=dashboard')[1]
        # Complete tenant-to-staff payment journey on the second tenant's current bill.
        current_bill=con.execute('SELECT id FROM billing WHERE tenant_id=2 AND period=?',[nxt]).fetchone()[0]
        assert 'Pay Now' in other.get('index.php?page=dashboard')[1]
        for label in ['CURRENT STATEMENT','YOUR NEXT STEP','MY ROOM','RECENT PAYMENTS','NOTICE BOARD','Move-in date']:
            assert label in other.get('index.php?page=dashboard')[1]
        assert 'Payment verification' not in admin.get('index.php?page=dashboard')[1]
        assert 'Incoming tenant payments' in admin.get('index.php?page=payments')[1]
        assert 'Incoming tenant payments' in staff.get('index.php?page=payments')[1]

        # Due notices are scoped, deduplicated, and available without SMTP.
        original_due=con.execute('SELECT due_date FROM billing WHERE id=?',[current_bill]).fetchone()[0]
        previous_notices=con.execute("SELECT COUNT(*) FROM notifications WHERE billing_id=? AND dedup_key LIKE 'due-%'",[current_bill]).fetchone()[0]
        for delta,subject in [(7,'Upcoming payment due'),(3,'Upcoming payment due'),(1,'Upcoming payment due'),(0,'Payment due today'),(-1,'Payment overdue')]:
            due=(datetime.date.today()+datetime.timedelta(days=delta)).isoformat()
            con.execute('UPDATE billing SET due_date=? WHERE id=?',[due,current_bill])
            other.get('index.php?page=inbox')
            count=con.execute("SELECT COUNT(*) FROM notifications WHERE billing_id=? AND dedup_key LIKE 'due-%'",[current_bill]).fetchone()[0]
            assert count==previous_notices+1
            previous_notices=count
            assert subject in other.get('index.php?page=inbox')[1]
            assert con.execute("SELECT COUNT(*) FROM notifications WHERE billing_id=? AND dedup_key LIKE 'due-%'",[current_bill]).fetchone()[0]==count
        con.execute('UPDATE billing SET due_date=? WHERE id=?',[original_due,current_bill])
        prepared=other.get('index.php?page=pay')[1]
        assert 'value="8900.00"' in prepared,prepared[-2500:]
        assert 'Paano' not in prepared
        report=admin.get('index.php?page=reports')[1]
        for chart in ['Monthly collections','Payment methods','Current statement status','Outstanding balances by age']:
            assert chart in report
        assert 'Payment activity' in staff.get('index.php?page=dashboard')[1]

        assert 'not configured yet' in other.get('index.php?page=pay')[1]
        admin.post('payment_settings','settings',gcash_name='Test Receiving Account',gcash_number='09171234567',office_hours='Monday to Friday, 9 AM–5 PM',payment_instructions='Include your tenant name in the payment note.')
        assert '09171234567' in other.get('index.php?page=pay')[1]
        submission=dict(billing_id=current_bill,amount='2500',payer='Test Parent',payment_date=today,reference='TENANT-GCASH-1',submission_key='tenant-submit-one',notes='First partial payment')
        assert 'one of your own' in tenant.post('submit_payment','pay',**submission)
        assert 'Payment submitted for verification' in other.post('submit_payment','pay',**submission)
        assert con.execute('SELECT COUNT(*) FROM payments WHERE billing_id=?',[current_bill]).fetchone()[0]==0
        assert 'already in your history' in other.post('submit_payment','pay',**submission)
        assert con.execute('SELECT COUNT(*) FROM payment_submissions').fetchone()[0]==1
        assert 'after pending submissions' in other.post('submit_payment','pay',**{**submission,'amount':'8000','reference':'OVER-PENDING','submission_key':'over-pending'})
        assert 'pending verification' in staff.post('payment','payments',billing_id=current_bill,amount=2500,payer='Test Parent',method='GCash',payment_date=today,reference='TENANT-GCASH-1',idempotency_key='manual-duplicate-submission')
        y2,m2=map(int,nxt.split('-')); later=f'{y2+1 if m2==12 else y2:04d}-{1 if m2==12 else m2+1:02d}'
        assert 'pending payment submissions' in admin.post('bill','billing',tenant_id=2,period=later,cutoff=later+'-15',due_date=later+'-28',rent=5000,electricity=0,water=0,discount=0,adjustment=0)
        assert 'verified receipt of the funds' in staff.post('review_submission','payments',submission_id=1,decision='approved',review_note='Checked')
        assert 'Explain why' in staff.post('review_submission','payments',submission_id=1,decision='rejected',review_note='')
        staff.post('review_submission','payments',submission_id=1,decision='rejected',review_note='Please provide the screenshot to verify the reference.')
        assert 'Please provide the screenshot' in other.get('index.php?page=payments')[1]
        assert con.execute('SELECT COUNT(*) FROM payments WHERE billing_id=?',[current_bill]).fetchone()[0]==0
        assert 'valid JPG, PNG, or WebP' in other.upload('submit_payment','pay','proof.png',b'<?php echo "bad"; ?>',**{**submission,'submission_key':'invalid-proof'})
        png=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jWZQAAAAASUVORK5CYII=')
        assert 'Payment submitted for verification' in other.upload('submit_payment','pay','proof.png',png,**{**submission,'submission_key':'corrected-proof'})
        admin.upload('payment_settings','settings','official-qr.png',png,upload_field='gcash_qr',gcash_name='Angelina',gcash_number='09171234567')
        assert 'SCAN PAYMENT QR' in other.get('index.php?page=pay')[1]
        assert other.get('index.php?payment_qr=1')[0]==200
        assert 'valid JPG, PNG, or WebP' in admin.upload('payment_settings','settings','bad.png',b'bad',upload_field='gcash_qr',gcash_name='Angelina',gcash_number='09171234567')
        qrfile=con.execute("SELECT value FROM settings WHERE `key`='gcash_qr'").fetchone()[0]
        admin.post('payment_settings','settings',gcash_name='Angelina',gcash_number='',remove_qr='1')
        assert other.get('index.php?payment_qr=1')[0]==404
        (ROOT/'storage'/'payment-proofs'/qrfile).unlink()

        assert con.execute('SELECT status FROM payment_submissions WHERE id=2').fetchone()[0]=='pending'
        assert other.get('index.php?proof=2')[0]==200
        assert tenant.get('index.php?proof=2')[0]==404
        assert staff.get('index.php?proof=2')[0]==200
        assert 'do not have access' in other.post('review_submission','pay',submission_id=2,decision='approved',verified=1)
        assert 'Payment verified' in staff.post('review_submission','payments',submission_id=2,decision='approved',verified=1,review_note='Funds verified in test account.')
        pid=con.execute('SELECT payment_id FROM payment_submissions WHERE id=2').fetchone()[0]
        assert con.execute('SELECT amount,balance_after,received_by FROM payments WHERE id=?',[pid]).fetchone()==(250000,640000,2)
        for viewer in [admin,staff,other]:
            assert 'TENANT-GCASH-1' in viewer.get('index.php?page=payments')[1]
            assert '6,400.00' in viewer.get('index.php?page=statement&id='+str(current_bill))[1]
        assert 'already reviewed' in staff.post('review_submission','payments',submission_id=2,decision='approved',verified=1)
        assert con.execute('SELECT COUNT(*) FROM payments WHERE billing_id=?',[current_bill]).fetchone()[0]==1
        assert 'Payment confirmation' in other.get('index.php?page=receipt&id='+str(pid))[1]
        assert 'GCash payment verified' in other.get('index.php?page=inbox')[1]
        inbox=other.get('index.php?page=inbox')[1]
        assert 'href="?page=receipt&amp;id='+str(pid)+'"' in inbox
        for viewer in [admin,staff,other]:
            assert 'notification-bell' in viewer.get('index.php?page=dashboard')[1]
            assert viewer.get('index.php?page=inbox')[0]==200
            assert json.loads(viewer.get('index.php?activity_poll=1')[1])['unread']>0
            viewer.post('read_notifications','inbox',seen_through=json.loads(viewer.get('index.php?activity_poll=1')[1])['latest'])
            assert json.loads(viewer.get('index.php?activity_poll=1')[1])['unread']==0
        assert tenant.get('index.php?page=receipt&id='+str(pid))[0]==404

        assert 'GCash payment verified' not in tenant.get('index.php?page=inbox')[1]
        statement=admin.get('index.php?page=statement&id='+str(current_bill))[1]
        assert 'STATEMENT OF ACCOUNT' in statement and 'Payments applied' in statement
        assert 'Electricity and Water</td>' in statement and 'Utilities <small>Electricity and water</small>' not in statement
        receipt=other.get('index.php?page=receipt&id='+str(pid))[1]
        assert 'PAYMENT RECEIPT' in receipt and 'Transaction reference' in receipt
        # Operating expense idempotency and audited correction preserve the original.
        expense=dict(category='Supplies',description='Replacement bulbs',amount='123.45',expense_date=today,reference='INV-TEST',vendor='Test Hardware',payment_method='GCash',idempotency_key='expense-test')
        admin.post('expense','expenses',**expense);admin.post('expense','expenses',**expense)
        expid=con.execute("SELECT id FROM expenses WHERE idempotency_key='expense-test'").fetchone()[0]
        assert con.execute("SELECT COUNT(*) FROM expenses WHERE idempotency_key='expense-test'").fetchone()[0]==1
        assert 'Test Hardware' in admin.get('index.php?page=expenses')[1]
        assert 'not have access' in staff.post('void_expense','payments',expense_id=expid,reason='Not authorized')
        admin.post('void_expense','expenses',expense_id=expid,reason='Invoice duplicated in paperwork')
        assert con.execute('SELECT void_reason FROM expenses WHERE id=?',[expid]).fetchone()[0]=='Invoice duplicated in paperwork'
        assert 'Replacement bulbs' not in admin.get('index.php?export=expenses')[1]
        assert 'Voided' in admin.get('index.php?page=expenses')[1]

        other.post('submit_payment','pay',**{**submission,'amount':'500','reference':'CANCEL-GCASH','submission_key':'cancel-test'})
        assert 'Only your own pending' in tenant.post('cancel_submission','payments',submission_id=3)
        other.post('cancel_submission','payments',submission_id=3)
        assert con.execute('SELECT status FROM payment_submissions WHERE id=3').fetchone()[0]=='cancelled'
        assert 'already reviewed or cancelled' in staff.post('review_submission','payments',submission_id=3,decision='approved',verified=1)
        other.post('inquiry','support',category='Billing',subject='Question about electricity',message='Please explain the electricity charge.')
        assert 'Question about electricity' in staff.get('index.php?page=support')[1]
        assert 'Question about electricity' not in tenant.get('index.php?page=support')[1]
        staff.post('reply_inquiry','support',inquiry_id=1,reply='Electricity and water are combined in one charge on your statement.')
        assert 'combined in one charge' in other.get('index.php?page=support')[1]
        assert 'combined in one charge' in other.get('index.php?page=inbox')[1]
        other.post('profile_update','profile',name='Updated Resident',email='updated@example.com',contact='09173333333',tenant_id=1)
        assert con.execute('SELECT name,email FROM tenants WHERE id=2').fetchone()==('Updated Resident','updated@example.com')
        assert con.execute('SELECT name FROM tenants WHERE id=1').fetchone()[0]=='Maria Santos (Demo)'
        assert 'linked to another tenant' in other.post('profile_update','profile',name='Updated Resident',email='maria@example.com',contact='09173333333')
        for page in ['pay','payments','inbox','support','profile','dashboard']:
            code,body=other.get('index.php?page='+page)
            assert code==200 and 'Warning:' not in body and 'Fatal error' not in body,(page,body[:200])
        # Notices can be corrected and archived without replacing their identity.
        admin.post('announcement','announcements',title='Notice edit test',message='Original notice',expires_on='')
        notice_id=con.execute('SELECT id FROM announcements WHERE title=?',['Notice edit test']).fetchone()[0]
        admin.post('edit_announcement','announcements',announcement_id=notice_id,title='Updated notice',message='Corrected information',expires_on='',active='1')
        assert con.execute('SELECT title,message,active FROM announcements WHERE id=?',[notice_id]).fetchone()==('Updated notice','Corrected information',1)
        admin.post('edit_announcement','announcements',announcement_id=notice_id,title='Updated notice',message='Corrected information',expires_on='',active='0')
        assert 'Updated notice' not in tenant.get('index.php?page=announcements')[1]
        # Proposal: final billing updates the existing month atomically and frees the bed.
        assert tenant.get('index.php?page=departure')[0] == 403
        assert staff.get('index.php?page=departure')[0] == 403
        today = datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=8))).date()
        first = today.replace(day=1).isoformat()
        room_id = con.execute("SELECT id FROM rooms WHERE status='active' LIMIT 1").fetchone()[0]
        bed_id = con.execute("SELECT b.id FROM bed_spaces b JOIN rooms r ON r.id=b.room_id WHERE r.status='active' AND NOT EXISTS(SELECT 1 FROM tenant_assignments a WHERE a.bed_id=b.id AND a.end_date IS NULL) LIMIT 1").fetchone()[0]
        con.execute("INSERT INTO tenants(name,email,contact,move_in) VALUES(?,?,?,?)", ['Departure Test','departure@example.com','09170000001',first])
        departure_id = con.execute("SELECT id FROM tenants WHERE email='departure@example.com'").fetchone()[0]
        admin.post('assign_tenant','tenants',tenant_id=departure_id,bed_id=bed_id,move_in=first)
        admin.post('bill','billing',tenant_id=departure_id,period=today.strftime('%Y-%m'),cutoff=first,due_date=today.isoformat(),rent='3100',water='100',electricity='200')
        bid = con.execute('SELECT id FROM billing WHERE tenant_id=?',[departure_id]).fetchone()[0]
        preview = dict(tenant_id=departure_id,move_out=today.isoformat(),rent_policy='daily',water='150',electricity='250')
        body = admin.post('preview_departure','departure',**preview)
        assert 'Review departure: Departure Test' in body
        assert con.execute('SELECT end_date FROM tenant_assignments WHERE tenant_id=?',[departure_id]).fetchone()[0] is None
        assert con.execute('SELECT rent,water,electricity FROM billing WHERE id=?',[bid]).fetchone() == (310000,0,0)
        # A payment arriving after preview invalidates confirmation, rather than overwriting it.
        admin.post('payment','payments',billing_id=bid,amount='10',method='Personal Payment',payer='Departure Test',payment_date=today.isoformat(),idempotency_key=uuid.uuid4().hex)
        assert 'account changed' in admin.post('confirm_departure','departure')
        admin.post('preview_departure','departure',**preview)
        body=admin.post('confirm_departure','departure')
        assert 'Departure completed' in body
        import calendar
        days_in_month=calendar.monthrange(today.year,today.month)[1]
        expected_rent=(310000*today.day+days_in_month//2)//days_in_month
        assert con.execute('SELECT rent,water,electricity,total_due FROM billing WHERE id=?',[bid]).fetchone() == (expected_rent,0,0,expected_rent)
        assert con.execute('SELECT COUNT(*) FROM billing WHERE tenant_id=?',[departure_id]).fetchone()[0] == 1
        assert str(con.execute('SELECT end_date FROM tenant_assignments WHERE tenant_id=?',[departure_id]).fetchone()[0]) == today.isoformat()
        assert con.execute('SELECT status FROM tenants WHERE id=?',[departure_id]).fetchone()[0] == 'moved_out'
        assert 'Prepare a departure preview first' in admin.post('confirm_departure','departure')
        assert con.execute("SELECT COUNT(*) FROM audit_logs WHERE action='Finalized departure and released bed' AND record_id=?",[bid]).fetchone()[0] == 1
        # A departure without this month's bill creates one, and missing earlier rent is blocked.
        con.execute("INSERT INTO tenants(name,email,contact,move_in) VALUES(?,?,?,?)", ['New Final Bill','new-final@example.com','09170000002',first])
        fresh_id=con.execute("SELECT id FROM tenants WHERE email='new-final@example.com'").fetchone()[0]
        admin.post('assign_tenant','tenants',tenant_id=fresh_id,bed_id=bed_id,move_in=first)
        earlier=(today.replace(day=1)-datetime.timedelta(days=1)).replace(day=1).isoformat()
        con.execute('UPDATE tenants SET move_in=? WHERE id=?',[earlier,fresh_id])
        assert 'missing monthly statement' in admin.post('preview_departure','departure',tenant_id=fresh_id,move_out=today.isoformat(),rent_policy='full',water='0',electricity='0')
        con.execute('UPDATE tenants SET move_in=? WHERE id=?',[first,fresh_id])
        admin.post('preview_departure','departure',tenant_id=fresh_id,move_out=today.isoformat(),rent_policy='full',water='0',electricity='0')
        assert 'Departure completed' in admin.post('confirm_departure','departure')
        assert con.execute('SELECT COUNT(*) FROM billing WHERE tenant_id=?',[fresh_id]).fetchone()[0] == 1
        assert con.execute('SELECT status FROM tenants WHERE id=?',[fresh_id]).fetchone()[0] == 'moved_out'
        # Legacy utility allocation remains available for existing rooms.
        assert 'at most 72' in admin.post('password','account',current_password='AdminTesting@2026',new_password='A'*73,confirm_password='A'*73)
        expected_rates=[(65000,18000),(85000,22000),(110000,28000),(75000,20000)]
        assert con.execute('SELECT fixed_electricity,fixed_water FROM rooms ORDER BY id LIMIT 4').fetchall()==expected_rates
        for viewer in [staff,tenant,other]:
            assert viewer.get('index.php?utility_preview=1&period='+nxt)[0]==403
            assert 'do not have access' in viewer.post('room_update','payments',room_id=1,rent=4500,status='active',fixed_electricity=1,fixed_water=1)
        assert con.execute('SELECT fixed_electricity,fixed_water FROM rooms WHERE id=1').fetchone()==expected_rates[0]
        assert 'valid amount' in admin.post('room_update','rooms',room_id=1,rent=4500,status='active',fixed_electricity='-1',fixed_water=180)
        admin.post('room','rooms',number='UTIL-A',capacity=3,rent=1000,fixed_electricity='850.01',fixed_water='220.01')
        admin.post('room','rooms',number='UTIL-B',capacity=2,rent=1000,fixed_electricity='750',fixed_water='200')
        room_a=con.execute("SELECT id FROM rooms WHERE number='UTIL-A'").fetchone()[0]
        room_b=con.execute("SELECT id FROM rooms WHERE number='UTIL-B'").fetchone()[0]
        assert 'Saved successfully' in admin.post('room_update','rooms',room_id=room_a,number='UTIL-ALPHA',rent=1000,status='active',fixed_electricity='850.01',fixed_water='220.01')
        assert con.execute('SELECT number FROM rooms WHERE id=?',[room_a]).fetchone()==('UTIL-ALPHA',)
        assert 'Saved successfully' in admin.post('room_update','rooms',room_id=room_a,number='UTIL-ALPHA',capacity=2,rent=1000,status='active',fixed_electricity='850.01',fixed_water='220.01')
        assert con.execute('SELECT capacity FROM rooms WHERE id=?',[room_a]).fetchone()==(2,)
        assert con.execute('SELECT COUNT(*) FROM bed_spaces WHERE room_id=?',[room_a]).fetchone()==(2,)
        assert 'Saved successfully' in admin.post('room_update','rooms',room_id=room_a,number='UTIL-ALPHA',capacity=3,rent=1000,status='active',fixed_electricity='850.01',fixed_water='220.01')
        assert con.execute('SELECT COUNT(*) FROM bed_spaces WHERE room_id=?',[room_a]).fetchone()==(3,)
        assert 'already in use' in admin.post('room_update','rooms',room_id=room_a,number='UTIL-B',rent=1000,status='active',fixed_electricity='850.01',fixed_water='220.01')
        assert '50 characters or fewer' in admin.post('room_update','rooms',room_id=room_a,number='R'*51,rent=1000,status='active',fixed_electricity='850.01',fixed_water='220.01')
        room_screen=admin.get('index.php?page=rooms')[1]
        assert 'Room name / number' in room_screen and 'Bed capacity' in room_screen
        assert 'Fixed room electricity / month' not in room_screen and 'Fixed room water / month' not in room_screen
        admin.post('room','rooms',number='NO-RATES',capacity=1,rent=1000)
        no_rates=con.execute("SELECT fixed_electricity,fixed_water FROM rooms WHERE number='NO-RATES'").fetchone()
        assert no_rates==(None,None)
        admin.post('room','rooms',number='REMOVE-ME',capacity=2,rent=1000)
        removable_room=con.execute("SELECT id FROM rooms WHERE number='REMOVE-ME'").fetchone()[0]
        assert 'Saved successfully' in admin.post('room_delete','rooms',room_id=removable_room)
        assert con.execute('SELECT COUNT(*) FROM rooms WHERE id=?',[removable_room]).fetchone()==(0,)
        beds_a=[v[0] for v in con.execute('SELECT id FROM bed_spaces WHERE room_id=? ORDER BY id',[room_a]).fetchall()]
        bed_b=con.execute('SELECT id FROM bed_spaces WHERE room_id=? ORDER BY id',[room_b]).fetchone()[0]
        people=[]
        for ix,bed in enumerate(beds_a):
            email='utility-'+str(ix)+'@example.com'
            con.execute('INSERT INTO tenants(name,email,contact,move_in) VALUES(?,?,?,?)',['Utility Resident '+str(ix),email,'09170000000',first])
            tid=con.execute('SELECT id FROM tenants WHERE email=?',[email]).fetchone()[0];people.append(tid)
            admin.post('assign_tenant','tenants',tenant_id=tid,bed_id=bed,move_in=first)
        assert 'tenant assignment history' in admin.post('room_delete','rooms',room_id=room_a)
        assert con.execute('SELECT COUNT(*) FROM rooms WHERE id=?',[room_a]).fetchone()==(1,)
        assert 'cannot be lower than the number of occupied beds' in admin.post('room_update','rooms',room_id=room_a,number='UTIL-ALPHA',capacity=2,rent=1000,status='active',fixed_electricity='850.01',fixed_water='220.01')
        assert con.execute('SELECT capacity FROM rooms WHERE id=?',[room_a]).fetchone()==(3,)
        def month_after(period,count):
            year,month=map(int,period.split('-'));number=year*12+month-1+count
            return f'{number//12:04d}-{number%12+1:02d}'
        utility_month=month_after(nxt,2);future_month=month_after(utility_month,1)
        before_bills=con.execute('SELECT id,electricity,water,total_due FROM billing ORDER BY id').fetchall()
        preview=json.loads(admin.get('index.php?utility_preview=1&period='+utility_month)[1])
        assert [(preview['shares'][str(t)]['electricity'],preview['shares'][str(t)]['water']) for t in people]==[(28334,7334),(28334,7334),(28333,7333)]
        # Client-supplied utility overrides are ignored, including forged negative inputs.
        admin.post('bill','billing',tenant_id=people[0],period=utility_month,cutoff=utility_month+'-15',due_date=utility_month+'-28',rent=1000,electricity='99999',water='-1')
        b1=con.execute('SELECT id FROM billing WHERE tenant_id=?',[people[0]]).fetchone()[0]
        assert con.execute('SELECT electricity,water,total_due FROM billing WHERE id=?',[b1]).fetchone()==(28334,7334,135668)
        snapshot=con.execute('SELECT value FROM settings WHERE `key`=?',['utility_snapshot_'+utility_month]).fetchone()[0]
        admin.post('room_update','rooms',room_id=room_a,rent=1000,status='active',fixed_electricity='999.99',fixed_water='333.33')
        assert con.execute('SELECT fixed_electricity,fixed_water FROM rooms WHERE id=?',[room_a]).fetchone()==(99999,33333)
        # A mid-month transfer keeps one share from the original snapshot room.
        admin.post('transfer','tenants',tenant_id=people[1],bed_id=bed_b,transfer_date=today.isoformat())
        room_b_bed=con.execute('SELECT id FROM bed_spaces WHERE room_id=? AND id<>? ORDER BY id LIMIT 1',[room_b,bed_b]).fetchone()[0]
        con.execute('INSERT INTO tenants(name,email,contact,move_in) VALUES(?,?,?,?)',['Billing Room B Resident','billing-room-b@example.com','09170000000',first])
        room_b_person=con.execute('SELECT id FROM tenants WHERE email=?',['billing-room-b@example.com']).fetchone()[0]
        admin.post('assign_tenant','tenants',tenant_id=room_b_person,room_id=room_b,bed_id=room_b_bed,move_in=first)
        admin.post('bill','billing',tenant_id=people[1],period=utility_month,cutoff=utility_month+'-15',due_date=utility_month+'-28',rent=1000)
        admin.post('batch_billing','batch',room_id=room_a,period=utility_month,cutoff=utility_month+'-15',due_date=utility_month+'-28',**{'selected[]':people[2],'room_utilities':'1070.02','discount':'0'})
        assert con.execute('SELECT SUM(electricity),SUM(water) FROM billing WHERE tenant_id IN (?,?,?) AND period=?',[*people,utility_month]).fetchone()==(85001,22001)
        assert con.execute('SELECT value FROM settings WHERE `key`=?',['utility_snapshot_'+utility_month]).fetchone()[0]==snapshot
        assert con.execute('SELECT id,electricity,water,total_due FROM billing WHERE id<? ORDER BY id',[b1]).fetchall()==before_bills
        # Only confirmed payments affect balances; the next bill carries the exact remainder.
        staff.post('payment','payments',billing_id=b1,amount=100,method='Personal Payment',payer='Utility Parent',payment_date=today.isoformat(),idempotency_key='utility-partial')
        receipt_id=con.execute("SELECT id FROM payments WHERE idempotency_key='utility-partial'").fetchone()[0]
        assert con.execute('SELECT balance_after FROM payments WHERE id=?',[receipt_id]).fetchone()[0]==125668
        assert '1,256.68' in staff.get('index.php?page=receipt&id='+str(receipt_id))[1]
        admin.post('bill','billing',tenant_id=people[0],period=future_month,cutoff=future_month+'-15',due_date=future_month+'-28',rent=1000)
        assert con.execute('SELECT electricity,water,previous_balance,total_due FROM billing WHERE tenant_id=? AND period=?',[people[0],future_month]).fetchone()==(50000,16667,125668,292335)
        assert con.execute('SELECT electricity,water FROM billing WHERE id=?',[b1]).fetchone()==(28334,7334)
        # Atomic batch failure must roll back both the first statement and its snapshot.
        rollback_month=month_after(future_month,1)
        status,form=admin.get('index.php?page=batch');csrf=re.search(r'name="csrf" value="([^"]+)"',form)[1]
        payload=[('csrf',csrf),('action','batch_billing'),('return_page','batch'),('room_id',room_a),('period',rollback_month),('cutoff',rollback_month+'-15'),('due_date',rollback_month+'-28'),('room_utilities','1333.33'),('discount','0'),('selected[]',people[0]),('selected[]',999999)]
        admin.opener.open(Request(BASE+'index.php',urlencode(payload).encode())).read()
        assert con.execute('SELECT COUNT(*) FROM billing WHERE period=?',[rollback_month]).fetchone()[0]==0
        assert con.execute('SELECT COUNT(*) FROM settings WHERE `key`=?',['utility_snapshot_'+rollback_month]).fetchone()[0]==0
        room_b_totals=con.execute('SELECT fixed_electricity,fixed_water FROM rooms WHERE id=?',[room_b]).fetchone()
        con.execute('UPDATE rooms SET fixed_electricity=NULL,fixed_water=NULL WHERE id=?',[room_b])
        preview_a=json.loads(admin.get('index.php?batch_preview=1&room_id='+str(room_a)+'&period='+rollback_month)[1])
        assert preview_a['room']['utilities']==con.execute('SELECT fixed_electricity+fixed_water FROM rooms WHERE id=?',[room_a]).fetchone()[0]
        preview_b=json.loads(admin.get('index.php?batch_preview=1&room_id='+str(room_b)+'&period='+rollback_month)[1])
        assert {tenant['tenant_id'] for tenant in preview_a['tenants']}=={people[0],people[2]}
        assert {tenant['tenant_id'] for tenant in preview_b['tenants']}=={people[1],room_b_person}
        room_b_utilities=f'{(room_b_totals[0]+room_b_totals[1])//100}.{(room_b_totals[0]+room_b_totals[1])%100:02d}'
        assert 'not actively assigned to this room' in admin.post('batch_billing','batch',room_id=room_a,period=rollback_month,cutoff=rollback_month+'-15',due_date=rollback_month+'-28',**{'room_utilities':'1333.33','discount':'0','selected[]':room_b_person})
        assert con.execute('SELECT COUNT(*) FROM billing WHERE tenant_id=? AND period=?',[room_b_person,rollback_month]).fetchone()[0]==0
        assert '2 statements created' in admin.post('batch_billing','batch',room_id=room_a,period=rollback_month,cutoff=rollback_month+'-15',due_date=rollback_month+'-28',**{'room_utilities':'1333.33','discount':'10','discount_reason':'Room discount','selected[]':[people[0],people[2]]})
        assert con.execute("SELECT COUNT(*) FROM billing_adjustments ba JOIN billing b ON b.id=ba.billing_id WHERE b.tenant_id IN (?,?) AND b.period=? AND ba.type='discount' AND ba.amount=1000",[people[0],people[2],rollback_month]).fetchone()==(2,)
        assert con.execute('SELECT SUM(electricity+water) FROM billing WHERE tenant_id IN (?,?) AND period=?',[people[0],people[2],rollback_month]).fetchone()==(133333,)
        assert con.execute('SELECT COUNT(*) FROM billing WHERE tenant_id=? AND period=?',[people[2],rollback_month]).fetchone()[0]==1
        assert con.execute('SELECT COUNT(*) FROM billing WHERE tenant_id IN (?,?) AND period=?',[people[1],room_b_person,rollback_month]).fetchone()[0]==0
        con.execute('UPDATE rooms SET fixed_electricity=?,fixed_water=? WHERE id=?',[*room_b_totals,room_b])
        assert '1 statements created' in staff.post('batch_billing','batch',room_id=room_b,period=rollback_month,cutoff=rollback_month+'-15',due_date=rollback_month+'-28',room_utilities=room_b_utilities,discount='0',**{'selected[]':room_b_person})
        assert con.execute('SELECT COUNT(*) FROM billing WHERE tenant_id=? AND period=?',[room_b_person,rollback_month]).fetchone()[0]==1
        assert con.execute('SELECT COUNT(*) FROM billing WHERE tenant_id IN (?,?) AND period=?',[people[0],people[2],rollback_month]).fetchone()[0]==2
        qr_tenant_ids=[row[0] for row in con.execute("SELECT id FROM tenants WHERE status='active' ORDER BY id").fetchall()]
        assert len(qr_tenant_ids)>=2
        qr_tenant=con.execute("SELECT tenant_id FROM users WHERE username='another'").fetchone()[0]
        assert qr_tenant in qr_tenant_ids
        qr_room=con.execute('SELECT r.id FROM tenant_assignments a JOIN bed_spaces b ON b.id=a.bed_id JOIN rooms r ON r.id=b.room_id WHERE a.tenant_id=? AND a.end_date IS NULL',[qr_tenant]).fetchone()[0]
        qr_page=admin.get('index.php?page=qr_management')[1]
        assert all(label in qr_page for label in ['QR Code Management','Total Active Tenants','Tenants with QR Codes','Tenants without QR Codes','Generate All QR','Print All QR','Print per Room','Generate QR','qr-management.js','qr-management.css'])
        assert 'TENANT QR SCANNER' in other.get('index.php?page=dashboard')[1]
        assert 'Tenant QR code generated.' in admin.post('tenant_qr_generate','qr_management',tenant_id=qr_tenant)
        first_qr=con.execute("SELECT id,token FROM tenant_qr_codes WHERE tenant_id=? AND status='active'",[qr_tenant]).fetchone()
        assert first_qr and re.fullmatch(r'[a-f0-9]{64}',first_qr[1])
        assert first_qr[1] not in admin.get('index.php?page=qr_management')[1]
        scan_status,scan_result=scan_tenant_qr(other,'ADQR1:'+first_qr[1])
        assert scan_status==200 and scan_result['tenant']['id']==qr_tenant
        repeat_status,repeat_result=scan_tenant_qr(other,'ADQR1:'+first_qr[1])
        assert repeat_status==200 and repeat_result['tenant']['id']==qr_tenant
        expected_tenant_name=con.execute('SELECT name FROM tenants WHERE id=?',[qr_tenant]).fetchone()[0]
        expected_room_info=con.execute('SELECT r.number,bs.label FROM tenant_assignments a JOIN bed_spaces bs ON bs.id=a.bed_id JOIN rooms r ON r.id=bs.room_id WHERE a.tenant_id=? AND a.end_date IS NULL',[qr_tenant]).fetchone()
        assert (scan_result['tenant']['name'],scan_result['tenant']['room'],scan_result['tenant']['bed'])==(expected_tenant_name,str(expected_room_info[0]),expected_room_info[1])
        bill=scan_result['current_bill']
        expected_bill=con.execute("SELECT b.period,b.rent,b.water,b.electricity,b.previous_balance,b.total_due,COALESCE((SELECT SUM(amount) FROM payments WHERE billing_id=b.id AND voided_at IS NULL),0) FROM billing b WHERE b.tenant_id=? AND b.carried_to IS NULL ORDER BY b.period DESC,b.id DESC LIMIT 1",[qr_tenant]).fetchone()
        assert expected_bill and (bill['period'],bill['rent'],bill['water'],bill['electricity'],bill['previous_balance'],bill['total_due'],bill['total_paid'])==expected_bill
        assert bill['remaining_balance']==max(0,bill['total_due']-bill['total_paid'])
        expected_history=con.execute("SELECT p.payment_date,p.amount,p.method,b.period FROM payments p JOIN billing b ON b.id=p.billing_id WHERE p.tenant_id=? AND p.voided_at IS NULL ORDER BY p.payment_date DESC,p.id DESC",[qr_tenant]).fetchall()
        assert [(p['payment_date'],p['amount'],'GCash' if p['method']=='GCash' else 'Cash',p['period']) for p in scan_result['payment_history']]==[(p[0],p[1],'GCash' if p[2]=='GCash' else 'Cash',p[3]) for p in expected_history]
        assert 'already has an active QR code' in admin.post('tenant_qr_generate','qr_management',tenant_id=qr_tenant)
        assert con.execute("SELECT COUNT(*) FROM tenant_qr_codes WHERE tenant_id=? AND status='active'",[qr_tenant]).fetchone()[0]==1
        image_status,image=admin.get('index.php?tenant_qr_image='+str(first_qr[0]))
        download_status,downloaded=admin.get('index.php?tenant_qr_download='+str(first_qr[0]))
        assert image_status==download_status==200 and '<svg' in image and '<svg' in downloaded
        assert 'QR code(s) generated. Existing active codes were unchanged.' in admin.post('tenant_qr_generate_all','qr_management')
        active_tenant_count=con.execute("SELECT COUNT(*) FROM tenants WHERE status='active'").fetchone()[0]
        assert con.execute("SELECT COUNT(*) FROM tenant_qr_codes WHERE status='active'").fetchone()[0]==active_tenant_count
        qr_page=admin.get('index.php?page=qr_management')[1]
        assert all(label in qr_page for label in ['View QR','Download QR','Print QR','Reissue QR','Tenant ID Card'])
        assert 'Are you sure you want to reissue this tenant&#039;s QR code? The previous QR code will no longer work.' in qr_page
        assert qr_page.count('class="qr-print-card"')==active_tenant_count
        assert con.execute("SELECT token FROM tenant_qr_codes WHERE id=?",[first_qr[0]]).fetchone()[0]==first_qr[1]
        all_active_before=con.execute("SELECT tenant_id,id,token FROM tenant_qr_codes WHERE status='active' AND tenant_id<>? ORDER BY tenant_id",[qr_tenant]).fetchall()
        admin.post('tenant_qr_generate_all','qr_management')
        assert con.execute("SELECT COUNT(*) FROM tenant_qr_codes WHERE status='active'").fetchone()[0]==active_tenant_count
        assert con.execute("SELECT tenant_id,id,token FROM tenant_qr_codes WHERE status='active' AND tenant_id<>? ORDER BY tenant_id",[qr_tenant]).fetchall()==all_active_before
        room_page=admin.get('index.php?'+urlencode({'page':'qr_management','room_id':qr_room}))[1]
        assert 'data-room-id="'+str(qr_room)+'"' in room_page and 'data-qr-id="'+str(first_qr[0])+'"' in room_page
        assert '<article class="qr-print-card" data-qr-id="'+str(first_qr[0])+'" data-room-id="'+str(qr_room)+'">' in room_page
        second_token=con.execute("SELECT token FROM tenant_qr_codes WHERE tenant_id=? AND status='active'",[qr_tenant_ids[1]]).fetchone()[0]
        cross_status,cross_result=scan_tenant_qr(other,'ADQR1:'+second_token)
        assert cross_status==404 and 'tenant' not in cross_result
        guest_status,guest_result=scan_tenant_qr(Client(),'ADQR1:'+first_qr[1])
        assert guest_status==403 and 'tenant' not in guest_result
        assert 'Tenant QR code reissued. The previous QR code is no longer valid.' in admin.post('tenant_qr_reissue','qr_management',tenant_id=qr_tenant)
        new_qr=con.execute("SELECT id,token FROM tenant_qr_codes WHERE tenant_id=? AND status='active'",[qr_tenant]).fetchone()
        assert new_qr and new_qr[0]!=first_qr[0] and new_qr[1]!=first_qr[1]
        assert con.execute("SELECT COUNT(*) FROM tenant_qr_codes WHERE tenant_id=? AND status='revoked'",[qr_tenant]).fetchone()[0]==1
        assert con.execute("SELECT token FROM tenant_qr_codes WHERE tenant_id=? AND status='active'",[qr_tenant_ids[1]]).fetchone()[0]==second_token
        assert admin.get('index.php?tenant_qr_image='+str(first_qr[0]))[0]==404
        assert admin.get('index.php?tenant_qr_image='+str(new_qr[0]))[0]==200
        revoked_status,revoked_result=scan_tenant_qr(other,'ADQR1:'+first_qr[1])
        assert revoked_status==404 and 'tenant' not in revoked_result
        assert scan_tenant_qr(other,'ADQR1:'+new_qr[1])[0]==200
        assert staff.get('index.php?page=qr_management')[0]==403
        assert staff.get('index.php?tenant_qr_image='+str(new_qr[0]))[0]==403
        qr_history=con.execute('SELECT id,tenant_id,token,status,revoked_at FROM tenant_qr_codes ORDER BY id').fetchall()
        # Setup is repeatable and preserves edited rates, bills, and payments.
        frozen=con.execute('SELECT id,total_due,electricity,water FROM billing ORDER BY id').fetchall()
        payment_history=con.execute('SELECT id,amount,balance_after FROM payments ORDER BY id').fetchall()
        subprocess.run([PHP,'database/setup.php'],cwd=ROOT,env=env,check=True,capture_output=True,text=True)
        assert con.execute('SELECT fixed_electricity,fixed_water FROM rooms WHERE id=?',[room_a]).fetchone()==(99999,33333)
        assert con.execute('SELECT id,total_due,electricity,water FROM billing ORDER BY id').fetchall()==frozen
        assert con.execute('SELECT id,amount,balance_after FROM payments ORDER BY id').fetchall()==payment_history
        assert con.execute('SELECT id,tenant_id,token,status,revoked_at FROM tenant_qr_codes ORDER BY id').fetchall()==qr_history
        disable_admin=Client()
        assert 'Verify your sign-in' in disable_admin.post('login','login',username='admin',password='AdminTesting@2026')
        assert 'welcome-banner' in disable_admin.post('verify_admin_otp','otp',code=recovery_codes[2])
        disabled=disable_admin.post('disable_admin_2fa','security',code=recovery_codes[3])
        assert 'Two-factor authentication was disabled' in disabled
        assert con.execute('SELECT COUNT(*) FROM admin_two_factor WHERE user_id=?',[admin_id]).fetchone()[0]==0
        assert 'Sign in to your account' in admin.get('index.php?page=dashboard')[1]
        reenroll=Client().post('login','login',username='admin',password='AdminTesting@2026')
        assert 'Connect Google Authenticator' in reenroll and 'welcome-banner' not in reenroll
        con.close()
        print('PASS: MySQL core flows plus Admin TOTP enrollment and login challenges, invalid-code retry, email sign-in, session and route protection, OTP throttling, one-use recovery codes, Admin logout, secure disable, unchanged Staff login, tenant onboarding and payment flows, final departure billing, utility allocation, batch rollback, QR generation/no-duplicates/view/download/reissue/revocation, tenant self-scan and billing/payment data, cross-tenant privacy, and repeatable migration.')
    finally:
        if con:
            for (filename,) in con.execute('SELECT proof_file FROM payment_submissions WHERE proof_file IS NOT NULL').fetchall():
                if re.fullmatch(r'[a-f0-9]{48}\.(png|jpg|webp)',filename):
                    artifact=(ROOT/'storage'/'payment-proofs'/filename).resolve()
                    assert artifact.parent==(ROOT/'storage'/'payment-proofs').resolve()
                    artifact.unlink(missing_ok=True)
        if con: con.close()
        server.terminate(); server.wait(timeout=10); log.close()
        subprocess.run([PHP,'tests/db.php','--drop-test-database'],cwd=ROOT,env=env,check=True)
