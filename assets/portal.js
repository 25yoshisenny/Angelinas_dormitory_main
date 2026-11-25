// Notification counts refresh without interrupting forms or camera sessions.
const notificationBell=document.querySelector('.notification-bell');
if(notificationBell) setInterval(async()=>{if(document.hidden)return;try{const response=await fetch('?activity_poll=1',{cache:'no-store'});if(!response.ok)return;const data=await response.json(),badge=notificationBell.querySelector('.bell-count');badge.textContent=data.unread;badge.hidden=!data.unread;notificationBell.setAttribute('aria-label',`Notifications, ${data.unread} unread`);}catch{}},30000);
const qrStart=document.getElementById('qr-start');
if(qrStart){
 const video=document.getElementById('qr-video'),canvas=document.getElementById('qr-canvas'),stopButton=document.getElementById('qr-stop'),status=document.getElementById('qr-status'),result=document.getElementById('qr-result'),details=document.getElementById('qr-details'),raw=document.getElementById('qr-raw'),file=document.getElementById('qr-file'),camera=document.getElementById('qr-camera');let stream=null,running=false,generation=0,officialData=null;
 const decode=image=>{const width=image.videoWidth||image.naturalWidth,height=image.videoHeight||image.naturalHeight;if(!width||!height)return null;if(typeof window.jsQR!=='function')throw new Error('QR decoder unavailable. Reload this page.');const scale=Math.min(1,(image===video?1000:1800)/Math.max(width,height));canvas.width=Math.round(width*scale);canvas.height=Math.round(height*scale);const ctx=canvas.getContext('2d',{willReadFrequently:true});ctx.drawImage(image,0,0,canvas.width,canvas.height);const pixels=ctx.getImageData(0,0,canvas.width,canvas.height);return window.jsQR(pixels.data,pixels.width,pixels.height,{inversionAttempts:'attemptBoth'})?.data||null;};
 const stop=()=>{generation++;running=false;stream?.getTracks().forEach(track=>track.stop());stream=null;video.srcObject=null;video.hidden=true;qrStart.disabled=false;stopButton.disabled=true;camera.disabled=false;};
 const clear=()=>{result.hidden=true;raw.hidden=true;details.hidden=true;result.textContent='';details.textContent='';};
 const paymentFields=data=>{const fields={};if(!data.startsWith('000201'))return fields;let pos=0;while(pos+4<=data.length){const tag=data.slice(pos,pos+2),length=Number(data.slice(pos+2,pos+4));if(!/^\d{2}$/.test(tag)||!Number.isFinite(length)||pos+4+length>data.length)return {};fields[tag]=data.slice(pos+4,pos+4+length);pos+=4+length;}return pos===data.length?fields:{};};
 const display=data=>{stop();const matched=officialData&&data===officialData,fields=paymentFields(data);status.textContent=matched?'QR matches the official dormitory QR. Confirm the recipient and amount in GCash before paying.':fields['59']?'Payment QR detected. Check the receiving account in GCash before confirming the transfer.':'QR detected, but no recognized payment details were found. Use the dormitory?s official GCash QR.';details.hidden=false;const heading=document.createElement('strong');heading.textContent=matched?'Official dormitory QR':fields['59']?'Scanned payment details':'QR scan result';details.replaceChildren(heading);const description=document.createElement('p');description.textContent=fields['59']?'Recipient: '+fields['59']+(fields['54']?' ? QR amount: PHP '+fields['54']:' ? Enter the amount in GCash'):matched?'Complete your payment in GCash, then submit the reference for verification.':'This code is not confirmed as a dormitory payment QR. No transfer has been made.';details.append(description);result.hidden=false;raw.hidden=false;result.textContent=data;};
 const official=document.getElementById('official-payment-qr');if(official){const load=()=>{try{officialData=decode(official);}catch{}};official.addEventListener('load',load);if(official.complete)load();}
 const start=async()=>{stop();clear();const attempt=generation;qrStart.disabled=true;stopButton.disabled=false;camera.disabled=true;status.textContent='Requesting camera access...';try{if(!window.isSecureContext||!navigator.mediaDevices?.getUserMedia)throw new Error('Camera access needs localhost or HTTPS. On a phone, an HTTP computer address cannot access the camera. Choose a QR image instead.');let incoming;try{incoming=await navigator.mediaDevices.getUserMedia({audio:false,video:{facingMode:{ideal:camera.value},width:{ideal:1280},height:{ideal:720}}});}catch(error){if(!['OverconstrainedError','NotFoundError'].includes(error.name))throw error;incoming=await navigator.mediaDevices.getUserMedia({audio:false,video:{facingMode:{ideal:camera.value},width:{ideal:1280},height:{ideal:720}}});}if(attempt!==generation){incoming.getTracks().forEach(track=>track.stop());return;}stream=incoming;video.muted=true;video.srcObject=stream;video.hidden=false;await video.play();if(attempt!==generation)return;running=true;status.textContent='Hold the complete payment QR steady in the camera view. Avoid glare and move closer if needed.';const scan=()=>{if(!running||attempt!==generation)return;try{const data=decode(video);if(data){display(data);return;}}catch(error){stop();status.textContent=error.message;return;}setTimeout(scan,180);};scan();}catch(error){if(attempt!==generation)return;stop();status.textContent=error.name==='NotAllowedError'?'Camera permission was denied. Allow camera access in your browser settings, or choose a QR image instead.':error.name==='NotReadableError'?'The camera is busy or unavailable. Close other camera apps, then try again or choose a QR image instead.':error.name==='NotFoundError'?'No usable camera was found. Choose a QR image instead.':error.message;}};
 document.addEventListener('paymentmethodchange',()=>{stop();clear();status.textContent='Select Start scanner or choose a QR image.';});
 qrStart.addEventListener('click',start);stopButton.addEventListener('click',()=>{stop();status.textContent='Camera stopped.';});camera.addEventListener('change',()=>{stop();status.textContent='Camera selection updated. Select Start scanner to continue.';});file.addEventListener('change',async()=>{stop();clear();const attempt=generation,selected=file.files[0];if(!selected)return;if(selected.size>5*1024*1024){status.textContent='Choose an image smaller than 5 MB.';return;}status.textContent='Reading QR image...';const url=URL.createObjectURL(selected);try{const image=new Image();image.src=url;await image.decode();if(attempt!==generation)return;const data=decode(image);if(data)display(data);else status.textContent='No readable QR found. Choose a clear image containing the complete QR and its white border.';}catch(error){if(attempt===generation)status.textContent='The image could not be scanned. Choose a JPG, PNG, or WebP image.';}finally{URL.revokeObjectURL(url);}});window.addEventListener('pagehide',stop);document.addEventListener('visibilitychange',()=>{if(document.hidden){stop();status.textContent='Camera paused. Start the scanner again when you are ready.';}});
}

const paymentChoice=document.querySelector('[name="payment_choice"]');
if(paymentChoice){const recipient=document.querySelector('[name="payment_recipient"]');const updatePaymentMethod=()=>{const method=paymentChoice.value,digital=method==='gcash';document.querySelectorAll('[data-payment-panel]').forEach(panel=>panel.hidden=!panel.dataset.paymentPanel.split(' ').includes(method));document.querySelectorAll('[data-qr-panel]').forEach(panel=>panel.hidden=!digital);document.querySelectorAll('[data-digital-submission]').forEach(panel=>{panel.hidden=!digital;panel.querySelectorAll('input,select,textarea,button').forEach(field=>field.disabled=!digital);const channel=panel.querySelector('[name="payment_channel"]');if(channel)channel.value='gcash';});if(recipient){recipient.value=digital?'landlady':'staff';recipient.querySelectorAll('option').forEach(option=>{option.disabled=option.value!==(digital?'landlady':'staff');});}document.dispatchEvent(new Event('paymentmethodchange'));};paymentChoice.addEventListener('change',updatePaymentMethod);updatePaymentMethod();}

(() => {
 const startButton=document.getElementById('tenant-qr-start');
 if(!startButton)return;
 const panel=document.getElementById('tenant-qr-scanner'),video=document.getElementById('tenant-qr-video'),canvas=document.getElementById('tenant-qr-canvas'),stopButton=document.getElementById('tenant-qr-stop'),file=document.getElementById('tenant-qr-file'),status=document.getElementById('tenant-qr-status'),result=document.getElementById('tenant-qr-result');
 let stream=null,running=false,generation=0;
 const stop=()=>{generation++;running=false;stream?.getTracks().forEach(track=>track.stop());stream=null;video.srcObject=null;video.hidden=true;startButton.disabled=false;stopButton.disabled=true;};
 const clear=()=>{result.replaceChildren();result.hidden=true;};
 const decode=image=>{
  const width=image.videoWidth||image.naturalWidth,height=image.videoHeight||image.naturalHeight;
  if(!width||!height)return null;
  if(typeof window.jsQR!=='function')throw new Error('QR scanner is unavailable. Reload the page and try again.');
  const scale=Math.min(1,1800/Math.max(width,height));
  canvas.width=Math.round(width*scale);canvas.height=Math.round(height*scale);
  const context=canvas.getContext('2d',{willReadFrequently:true});
  context.drawImage(image,0,0,canvas.width,canvas.height);
  const pixels=context.getImageData(0,0,canvas.width,canvas.height);
  return window.jsQR(pixels.data,pixels.width,pixels.height,{inversionAttempts:'attemptBoth'})?.data||null;
 };
 const createNativeDetector=async()=>{
  const Detector=window.BarcodeDetector;
  if(typeof Detector!=='function'||typeof Detector.getSupportedFormats!=='function')return null;
  try{return (await Detector.getSupportedFormats()).includes('qr_code')?new Detector({formats:['qr_code']}):null;}
  catch{return null;}
 };
 const addDetail=(list,label,value)=>{
  const wrapper=document.createElement('div'),term=document.createElement('dt'),description=document.createElement('dd');
  term.textContent=label;description.textContent=value;wrapper.append(term,description);list.append(wrapper);
 };
 const renderAccount=data=>{
  const heading=document.createElement('h3');heading.textContent='Tenant Information';
  const tenantList=document.createElement('dl');tenantList.className='tenant-qr-details';
  addDetail(tenantList,'Name',data.tenant.name);
  addDetail(tenantList,'Tenant ID',String(data.tenant.id));
  addDetail(tenantList,'Room',data.tenant.room||'Unassigned');
  addDetail(tenantList,'Bed',data.tenant.bed||'Unassigned');
  result.append(heading,tenantList);

  const bill=data.current_bill;
  const billHeading=document.createElement('h3');billHeading.textContent='Current Bill';
  result.append(billHeading);
  if(!bill){
   const empty=document.createElement('p');empty.textContent='No current bill is available.';result.append(empty);
  }else{
   const billList=document.createElement('dl');billList.className='tenant-qr-details';
   addDetail(billList,'Billing Period',bill.period);
   addDetail(billList,'Rent',formatMoney(bill.rent));
   addDetail(billList,'Electricity and Water',formatMoney(Number(bill.electricity||0)+Number(bill.water||0)));
   addDetail(billList,'Other charges',formatMoney(bill.other_charges));
   addDetail(billList,'Previous balance',formatMoney(bill.previous_balance));
   addDetail(billList,'Discounts',formatMoney(bill.discounts));
   addDetail(billList,'Total Amount Due',formatMoney(bill.total_due));
   addDetail(billList,'Total Paid (verified)',formatMoney(bill.total_paid));
   addDetail(billList,'Remaining Balance',formatMoney(bill.remaining_balance));
   addDetail(billList,'Payment Status',bill.payment_status);
   result.append(billList);
  }

  const historyHeading=document.createElement('h3');historyHeading.textContent='Payment History';
  result.append(historyHeading);
  if(!data.payment_history.length){
   const empty=document.createElement('p');empty.textContent='No verified payments yet.';result.append(empty);
   return;
  }
  const table=document.createElement('table'),thead=document.createElement('thead'),headerRow=document.createElement('tr'),tbody=document.createElement('tbody');
  ['Billing Period','Date','Amount','Method'].forEach(label=>{const th=document.createElement('th');th.textContent=label;headerRow.append(th);});
  thead.append(headerRow);
  data.payment_history.forEach(payment=>{
   const row=document.createElement('tr');
   [payment.period,payment.payment_date,formatMoney(payment.amount),payment.method].forEach(value=>{const cell=document.createElement('td');cell.textContent=value;row.append(cell);});
   tbody.append(row);
  });
  table.append(thead,tbody);result.append(table);
 };
 const formatMoney=value=>new Intl.NumberFormat('en-PH',{style:'currency',currency:'PHP'}).format(Number(value||0)/100);
 const verify=async payload=>{
  stop();clear();
  if(!payload.startsWith('ADQR1:')){status.textContent='This is not a tenant QR code. Scan the QR card provided by Admin.';return;}
  status.textContent='Verifying QR code and loading your account...';
  try{
   const response=await fetch('index.php?tenant_qr_lookup=1',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':panel.dataset.tenantQrCsrf},body:new URLSearchParams({token:payload}),cache:'no-store'});
   const data=await response.json();
   if(!response.ok)throw new Error(data.error||'The QR code could not be verified.');
   renderAccount(data);result.hidden=false;status.textContent='QR verified. Only information for your signed-in account is shown. Start the scanner again to scan another QR.';
  }catch(error){status.textContent=error.message||'The QR code could not be verified. Try again.';}
 };
 const start=async()=>{
  stop();clear();const attempt=generation;
  startButton.disabled=true;stopButton.disabled=false;status.textContent='Requesting rear camera access...';
  try{
   if(!window.isSecureContext||!navigator.mediaDevices?.getUserMedia)throw new Error('Camera access needs localhost or HTTPS. Choose a QR image instead.');
   let incoming;
   try{incoming=await navigator.mediaDevices.getUserMedia({audio:false,video:{facingMode:{exact:'environment'},width:{ideal:1280},height:{ideal:720}}});}
   catch(error){if(!['OverconstrainedError','NotFoundError'].includes(error.name))throw error;incoming=await navigator.mediaDevices.getUserMedia({audio:false,video:{facingMode:{ideal:'environment'},width:{ideal:1280},height:{ideal:720}}});}
   if(attempt!==generation){incoming.getTracks().forEach(track=>track.stop());return;}
   stream=incoming;video.srcObject=stream;video.hidden=false;await video.play();
   if(attempt!==generation)return;
   let detector=await createNativeDetector(),nativeFramesSinceFallback=0;
   if(attempt!==generation)return;
   running=true;status.textContent='Show the complete tenant QR code to the rear camera. It will detect automatically.';
   const scan=async()=>{
    if(!running||attempt!==generation)return;
    try{
     let payload=null;
     if(detector){
      try{payload=(await detector.detect(video))[0]?.rawValue||null;}
      catch{detector=null;}
     }
     if(!detector)payload=decode(video);
     else if(!payload&&++nativeFramesSinceFallback>=4){payload=decode(video);nativeFramesSinceFallback=0;}
     if(payload){void verify(payload);return;}
    }
    catch(error){stop();status.textContent=error.message;return;}
    setTimeout(()=>{void scan();},100);
   };
   void scan();
  }catch(error){
   if(attempt!==generation)return;
   stop();
   status.textContent=error.name==='NotAllowedError'?'Camera permission was denied. Allow camera access or choose a QR image instead.':error.name==='NotReadableError'?'The camera is busy or unavailable. Close other camera apps and try again.':error.name==='NotFoundError'?'No usable camera was found. Choose a QR image instead.':error.message;
  }
 };
 startButton.addEventListener('click',start);
 stopButton.addEventListener('click',()=>{stop();status.textContent='Camera stopped.';});
 file.addEventListener('change',async()=>{
  stop();clear();const attempt=generation,selected=file.files[0];if(!selected)return;file.value='';
  if(selected.size>5*1024*1024){status.textContent='Choose an image smaller than 5 MB.';return;}
  status.textContent='Reading QR image...';const url=URL.createObjectURL(selected);
  try{
   const image=new Image();image.src=url;await image.decode();
   if(attempt!==generation)return;
   const payload=decode(image);
   if(payload)await verify(payload);
   else status.textContent='No readable QR found. Choose a clear image of the complete QR code.';
  }catch(error){if(attempt===generation)status.textContent=error.message||'The image could not be scanned.';}
  finally{URL.revokeObjectURL(url);}
 });
 window.addEventListener('pagehide',stop);
 document.addEventListener('visibilitychange',()=>{if(document.hidden&&running){stop();status.textContent='Camera paused. Start the scanner again when ready.';}});
})();
