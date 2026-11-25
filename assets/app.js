const peso=value=>new Intl.NumberFormat('en-PH',{style:'currency',currency:'PHP'}).format(value/100);
document.querySelectorAll('[data-copy]').forEach(button=>button.addEventListener('click',async()=>{try{await navigator.clipboard.writeText(button.dataset.copy);button.textContent='Copied ✓';}catch{button.textContent='Number: '+button.dataset.copy;}}));
document.querySelectorAll('[data-tenant-room]').forEach(roomSelect=>{
 const form=roomSelect.closest('form'),bedSelect=form?.querySelector('[data-tenant-bed]'),status=form?.querySelector('[data-tenant-bed-status]'),submitButton=form?.querySelector('.form-actions button');
 if(!bedSelect||!status)return;
 let requestId=0;
 const refreshBeds=async()=>{
  const currentRequest=++requestId,roomId=roomSelect.value;
  bedSelect.replaceChildren();bedSelect.disabled=true;if(submitButton)submitButton.disabled=true;
  bedSelect.add(new Option('Loading available beds…',''));status.textContent='Checking room availability…';
  const url=new URL(window.location.href);url.search='';url.searchParams.set('tenant_beds','1');url.searchParams.set('room_id',roomId);
  try{
   const response=await fetch(url,{headers:{Accept:'application/json'},cache:'no-store'}),data=await response.json();
   if(currentRequest!==requestId)return;
   if(!response.ok)throw new Error(data.error||'Unable to load available beds.');
   bedSelect.replaceChildren();
   if(!data.beds.length){bedSelect.add(new Option('No available beds in this room.',''));status.textContent='No available beds in this room.';return;}
   data.beds.forEach(bed=>bedSelect.add(new Option(bed.label,bed.id)));
   bedSelect.disabled=false;if(submitButton)submitButton.disabled=false;status.textContent='Only unoccupied beds in the selected room are shown.';
  }catch(error){
   if(currentRequest!==requestId)return;
   bedSelect.replaceChildren(new Option('Unable to load beds.',''));status.textContent=error.message+' Please try selecting the room again.';
  }
 };
 roomSelect.addEventListener('change',refreshBeds);refreshBeds();
});
document.querySelectorAll('[data-assignment-room]').forEach(roomSelect=>{
 const form=roomSelect.closest('form'),bedSelect=form?.querySelector('[data-assignment-bed]'),status=form?.querySelector('[data-assignment-status]');
 if(!bedSelect||!status)return;
 const submitButton=form.querySelector('.form-actions button');
 const options=Array.from(bedSelect.options).filter(option=>option.dataset.room);
 const update=()=>{
  const roomId=roomSelect.value,available=options.filter(option=>option.dataset.room===roomId);
  bedSelect.replaceChildren();
  if(!available.length){
   bedSelect.add(new Option('No available beds in this room.',''));
   bedSelect.disabled=true;if(submitButton)submitButton.disabled=true;status.textContent='No available beds in this room.';return;
  }
  available.forEach(option=>{const clone=option.cloneNode(true);clone.removeAttribute('hidden');bedSelect.add(clone);});
  bedSelect.disabled=false;if(submitButton)submitButton.disabled=false;status.textContent='Only available beds in the selected room are shown.';
 };
 roomSelect.addEventListener('change',update);update();
});
const paymentLimits=document.getElementById('payment-limits');
document.querySelectorAll('form').forEach(form=>{if(form.querySelector('[name="action"]')?.value!=='review_submission')return;const decision=form.querySelector('[name="decision"]'),note=form.querySelector('[name="review_note"]'),verified=form.querySelector('[name="verified"]');const update=()=>{const approve=decision.value==='approved';verified.required=approve;verified.closest('label').hidden=!approve;note.required=!approve;};decision.addEventListener('change',update);update();});
if(paymentLimits){const form=paymentLimits.closest('form'),limits=JSON.parse(paymentLimits.value),amount=form.querySelector('[name="amount"]');const hint=document.createElement('p');hint.className='submission-hint';form.querySelector('.form-actions').before(hint);const update=()=>{const available=limits[form.querySelector('[name="billing_id"]').value]||0;amount.max=(available/100).toFixed(2);amount.min='0.01';hint.textContent='You may submit up to '+peso(available)+'. Pending submissions do not reduce your bill until verified.';};form.querySelector('[name="billing_id"]').addEventListener('change',()=>{amount.value=((limits[form.querySelector('[name="billing_id"]').value]||0)/100).toFixed(2);update();});update();}
document.querySelectorAll('.table-wrap table:not(.batch-table)').forEach(table=>{
 const entries=Array.from(table.querySelectorAll('tbody tr')).filter(row=>!row.querySelector('.empty'));
 if(!entries.length)return;
 let page=0,term='',filter='';const pageSize=10;
 const panel=table.closest('.panel'),search=table.id?document.querySelector('[data-search="'+table.id+'"]'):null;
 const pager=document.createElement('div');pager.className='table-pager no-print';
 const label=document.createElement('span');label.className='pager-label';
 const previous=document.createElement('button');previous.type='button';previous.textContent='Previous';
 const next=document.createElement('button');next.type='button';next.textContent='Next';
 const pageLabel=document.createElement('span');pager.append(label,previous,pageLabel,next);table.parentNode.after(pager);
 const update=()=>{const matching=entries.filter(row=>row.textContent.toLowerCase().includes(term)&&(!filter||row.dataset.status===filter));page=Math.max(0,Math.min(page,Math.ceil(matching.length/pageSize)-1));entries.forEach(row=>row.hidden=true);matching.slice(page*pageSize,(page+1)*pageSize).forEach(row=>row.hidden=false);label.textContent=matching.length?(page*pageSize+1)+'–'+Math.min((page+1)*pageSize,matching.length)+' of '+matching.length+' records':'No matching records';pageLabel.textContent=(page+1)+' / '+Math.max(1,Math.ceil(matching.length/pageSize));previous.disabled=page===0;next.disabled=(page+1)*pageSize>=matching.length;};
 if(search){const toolbar=document.createElement('div');toolbar.className='table-toolbar';search.before(toolbar);toolbar.append(search);search.addEventListener('input',()=>{term=search.value.toLowerCase();page=0;update();});if(table.id==='bill-table'){const select=document.createElement('select');select.setAttribute('aria-label','Filter statement status');['All statuses','Unpaid','Partial','Paid','Overdue','Carried'].forEach((text,i)=>{const option=document.createElement('option');option.value=i?text.toLowerCase():'';option.textContent=text;select.append(option);});toolbar.append(select);select.addEventListener('change',()=>{filter=select.value;page=0;update();});}}
 previous.addEventListener('click',()=>{page--;update();});next.addEventListener('click',()=>{page++;update();});update();
});
const menu=document.getElementById('menu-toggle');
if(menu){const close=()=>{document.body.classList.remove('sidebar-open');menu.setAttribute('aria-expanded','false');};menu.addEventListener('click',()=>{const open=document.body.classList.toggle('sidebar-open');menu.setAttribute('aria-expanded',String(open));});document.getElementById('sidebar-scrim').addEventListener('click',close);document.addEventListener('keydown',event=>{if(event.key==='Escape')close();});}
document.querySelectorAll('[data-confirm]').forEach(button=>button.addEventListener('click',event=>{if(!window.confirm(button.dataset.confirm))event.preventDefault();}));
document.querySelectorAll('form[data-confirm-checkbox]').forEach(form=>form.addEventListener('submit',event=>{const checkbox=form.elements.namedItem(form.dataset.confirmCheckbox);if(checkbox?.checked&&!window.confirm(form.dataset.confirmMessage)){event.preventDefault();event.stopImmediatePropagation();}}));
document.querySelectorAll('[data-print]').forEach(button=>button.addEventListener('click',()=>window.print()));
const batchRoom=document.querySelector('[data-batch-room]');
const batch=batchRoom?.closest('form')||null;
if(batch){
 const roomSelect=batch.querySelector('[data-batch-room]'),period=batch.querySelector('[data-batch-period]'),rows=batch.querySelector('[data-batch-rows]'),status=batch.querySelector('[data-batch-status]'),all=batch.querySelector('[data-batch-select-all]'),generate=batch.querySelector('.form-actions button'),roomTotal=batch.querySelector('[data-batch-grand-total]'),selectedTotal=batch.querySelector('[data-batch-selected-total]');
 const utilitiesInput=batch.elements.namedItem('room_utilities'),discountInput=batch.elements.namedItem('discount');
 let requestId=0,utilityTotalsEdited=false,ready=false;
 const updateTotals=()=>{
  let roomCents=0,selectedCents=0,selectedCount=0;
  batch.querySelectorAll('[data-batch-row]').forEach(row=>{
   const total=Number(row.dataset.rent)+Number(row.dataset.previous)+Number(row.dataset.utilities)-Math.round((Number(discountInput.value)||0)*100);
   row.querySelector('[data-batch-total]').textContent=peso(total);
   if(row.dataset.eligible==='1'){roomCents+=total;const checkbox=row.querySelector('[name="selected[]"]');if(checkbox.checked){selectedCents+=total;selectedCount++;}}
  });
  roomTotal.textContent=peso(roomCents);selectedTotal.textContent=peso(selectedCents);
  const checkboxes=Array.from(batch.querySelectorAll('[name="selected[]"]:not(:disabled)'));
  all.checked=checkboxes.length>0&&checkboxes.every(box=>box.checked);
  all.indeterminate=checkboxes.some(box=>box.checked)&&!all.checked;
  generate.disabled=selectedCount===0||!ready;
 };
 const cell=(row,text)=>{const item=document.createElement('td');item.textContent=text;row.append(item);return item;};
 const updatePreview=async()=>{
  const version=++requestId;ready=false;generate.disabled=true;all.checked=true;all.indeterminate=false;
  const previouslySelected=new Set(Array.from(batch.querySelectorAll('[name="selected[]"]:checked')).map(box=>box.value)),hadRows=rows.querySelector('[data-batch-row]')!==null;
  status.textContent='Loading selected room and billing period…';
  rows.replaceChildren();const loading=document.createElement('tr');const loadingCell=cell(loading,'Loading billing details…');loadingCell.colSpan=6;loadingCell.className='empty';rows.append(loading);
  const url=new URL(window.location.href);url.search='';url.searchParams.set('batch_preview','1');url.searchParams.set('room_id',roomSelect.value);url.searchParams.set('period',period.value);
  if(utilityTotalsEdited&&utilitiesInput.value!=='')url.searchParams.set('room_utilities',utilitiesInput.value);
  try{
   const response=await fetch(url,{headers:{Accept:'application/json'},cache:'no-store'}),data=await response.json();
   if(version!==requestId)return;
   if(!response.ok)throw new Error(data.error||'Unable to load this room’s billing details.');
   if(!utilityTotalsEdited)utilitiesInput.value=(Number(data.room.utilities||0)/100).toFixed(2);
   utilitiesInput.readOnly=data.room.utility_locked;
   rows.replaceChildren();
   if(!data.tenants.length){const empty=document.createElement('tr');const message=cell(empty,'No active tenants are assigned to this room.');message.colSpan=6;message.className='empty';rows.append(empty);}
   data.tenants.forEach(tenant=>{
    const row=document.createElement('tr');row.dataset.batchRow='';row.dataset.tenantId=tenant.tenant_id;row.dataset.rent=tenant.rent;row.dataset.previous=tenant.previous;row.dataset.eligible=tenant.eligible?'1':'0';
   row.dataset.utilities=Number(tenant.electricity)+Number(tenant.water);
    const selectCell=document.createElement('td'),checkbox=document.createElement('input');checkbox.type='checkbox';checkbox.name='selected[]';checkbox.value=tenant.tenant_id;checkbox.checked=tenant.eligible&&(hadRows?previouslySelected.has(String(tenant.tenant_id)):tenant.eligible);checkbox.disabled=!tenant.eligible;checkbox.setAttribute('aria-label','Bill '+tenant.name);selectCell.append(checkbox);row.append(selectCell);
    const identity=cell(row,'');const name=document.createElement('strong');name.textContent=tenant.name;identity.append(name);const bed=document.createElement('small');bed.textContent='Room '+data.room.number+' / '+tenant.bed;identity.append(bed);
    if(!tenant.eligible){const reason=document.createElement('small');reason.className='muted';reason.textContent=tenant.reason;identity.append(reason);}
    const rentCell=cell(row,peso(tenant.rent));rentCell.dataset.batchRent='';
    cell(row,peso(row.dataset.utilities));
    cell(row,peso(tenant.previous));const totalCell=document.createElement('td'),amount=document.createElement('strong');amount.dataset.batchTotal='';totalCell.append(amount);row.append(totalCell);rows.append(row);
   });
   ready=true;status.textContent='Room '+data.room.number+' · '+data.tenants.length+' active tenant(s) · '+data.tenants.filter(tenant=>tenant.eligible).length+' eligible for '+data.period+'.';
   if(data.room.utility_locked)status.textContent+=' Utility totals are locked because a statement already exists for this room and month.';
   if(data.tenants.length&&!data.tenants.some(tenant=>tenant.eligible))status.textContent+=' All tenants are already billed or otherwise ineligible for this period.';
   updateTotals();
  }catch(error){
   if(version!==requestId)return;
   rows.replaceChildren();const failed=document.createElement('tr');const message=cell(failed,'Billing details could not be loaded: '+error.message);message.colSpan=6;message.className='empty';rows.append(failed);status.textContent='Unable to prepare billing. Correct the room or billing month and try again.';generate.disabled=true;roomTotal.textContent='—';selectedTotal.textContent='—';
  }
 };
 all.addEventListener('change',()=>{batch.querySelectorAll('[name="selected[]"]:not(:disabled)').forEach(box=>box.checked=all.checked);updateTotals();});
 roomSelect.addEventListener('change',()=>{utilityTotalsEdited=false;updatePreview();});
 period.addEventListener('change',()=>{utilityTotalsEdited=false;updatePreview();});
 utilitiesInput.addEventListener('input',()=>{utilityTotalsEdited=true;updatePreview();});
 discountInput.addEventListener('input',updateTotals);
 batch.addEventListener('change',event=>{if(event.target.name==='selected[]')updateTotals();});
 batch.addEventListener('submit',event=>{if(!ready){event.preventDefault();status.textContent='Wait for the room utility allocation to finish loading.';}});
 updatePreview();
}
document.querySelectorAll('form').forEach(form=>form.addEventListener('submit',()=>{const button=form.querySelector('button');if(button){button.disabled=true;setTimeout(()=>button.disabled=false,5000);}}));
document.querySelectorAll('form').forEach(form=>{
 const password=form.querySelector('[name="temporary_password"]'),confirmation=form.querySelector('[name="confirm_password"]');
 if(password&&confirmation){const validate=()=>confirmation.setCustomValidity(confirmation.value&&password.value!==confirmation.value?'The passwords do not match.':'');password.addEventListener('input',validate);confirmation.addEventListener('input',validate);}
});
// Open the office form addressed by a dashboard or directory action.
function revealLinkedPanel(){const id=window.location.hash.slice(1);if(!id)return;const panel=document.getElementById(id);if(panel?.tagName==='DETAILS')panel.open=true;}
window.addEventListener('hashchange',revealLinkedPanel);revealLinkedPanel();
const dormGallery=document.querySelector('.dorm-gallery');
if(dormGallery&&'IntersectionObserver' in window){const galleryObserver=new IntersectionObserver(entries=>{if(entries.some(entry=>entry.isIntersecting)){dormGallery.classList.add('is-entered');galleryObserver.disconnect();}},{threshold:.15});galleryObserver.observe(dormGallery);}
if(dormGallery){
 const galleryItems=Array.from(dormGallery.querySelectorAll('.dorm-gallery-item'));
 const lightbox=dormGallery.querySelector('.dorm-gallery-lightbox');
 if(lightbox&&galleryItems.length){
  const stage=lightbox.querySelector('.dorm-gallery-stage');
  const layers=Array.from(lightbox.querySelectorAll('.dorm-gallery-lightbox-image'));
  const caption=lightbox.querySelector('.dorm-gallery-caption');
  const counter=lightbox.querySelector('.dorm-gallery-count');
  const closeButton=lightbox.querySelector('.dorm-gallery-close');
  let activeLayer=0,currentIndex=0,opener=null,closeTimer=null,switchTimer=null,switching=false;
  const reducedMotion=window.matchMedia('(prefers-reduced-motion: reduce)');
  const setImage=(index,layerIndex)=>{
   const thumbnail=galleryItems[index].querySelector('img');
   layers[layerIndex].src=thumbnail.currentSrc||thumbnail.src;
   layers[layerIndex].alt=thumbnail.alt;
   caption.textContent=galleryItems[index].querySelector('figcaption').textContent;
   counter.textContent=(index+1)+' / '+galleryItems.length;
  };
  const finishClose=()=>{
   if(closeTimer)window.clearTimeout(closeTimer);
   closeTimer=null;
   lightbox.close();
   lightbox.classList.remove('is-closing');
   if(opener)opener.focus({preventScroll:true});
  };
  const closeLightbox=()=>{
   if(!lightbox.open||lightbox.classList.contains('is-closing'))return;
   if(switchTimer)window.clearTimeout(switchTimer);
   switchTimer=null;
   switching=false;
   stage.classList.remove('is-curtaining-next','is-curtaining-previous');
   if(reducedMotion.matches){finishClose();return;}
   lightbox.classList.add('is-closing');
   closeTimer=window.setTimeout(finishClose,360);
  };
  const openLightbox=item=>{
   if(lightbox.open)return;
   opener=item;
   currentIndex=galleryItems.indexOf(item);
   activeLayer=0;
   setImage(currentIndex,activeLayer);
   layers[activeLayer].classList.add('is-active');
   layers[1-activeLayer].classList.remove('is-active');
   layers[1-activeLayer].removeAttribute('src');
   lightbox.showModal();
   closeButton.focus({preventScroll:true});
  };
  const switchImage=direction=>{
   if(!lightbox.open||switching)return;
   switching=true;
   const nextIndex=(currentIndex+direction+galleryItems.length)%galleryItems.length;
   const nextLayer=1-activeLayer;
   const curtainClass=direction>0?'is-curtaining-next':'is-curtaining-previous';
   if(reducedMotion.matches){
    setImage(nextIndex,nextLayer);
    layers[activeLayer].classList.remove('is-active');
    layers[nextLayer].classList.add('is-active');
    activeLayer=nextLayer;
    currentIndex=nextIndex;
    switching=false;
    return;
   }
   stage.classList.add(curtainClass);
   switchTimer=window.setTimeout(()=>{
    setImage(nextIndex,nextLayer);
    layers[activeLayer].classList.remove('is-active');
    layers[nextLayer].classList.add('is-active');
    activeLayer=nextLayer;
    currentIndex=nextIndex;
    stage.classList.remove(curtainClass);
    switchTimer=window.setTimeout(()=>{switching=false;switchTimer=null;},520);
   },270);
  };
  dormGallery.addEventListener('click',event=>{
   const item=event.target.closest('.dorm-gallery-item');
   if(item&&dormGallery.contains(item))openLightbox(item);
  });
  dormGallery.addEventListener('keydown',event=>{
   const item=event.target.closest('.dorm-gallery-item');
   if(item&&(event.key==='Enter'||event.key===' ')){
    event.preventDefault();
    openLightbox(item);
   }
  });
  closeButton.addEventListener('click',closeLightbox);
  lightbox.querySelector('.dorm-gallery-previous').addEventListener('click',()=>switchImage(-1));
  lightbox.querySelector('.dorm-gallery-next').addEventListener('click',()=>switchImage(1));
  lightbox.addEventListener('click',event=>{if(event.target===lightbox)closeLightbox();});
  lightbox.addEventListener('cancel',event=>{event.preventDefault();closeLightbox();});
  lightbox.addEventListener('keydown',event=>{
   if(event.key==='Escape'){event.preventDefault();closeLightbox();return;}
   if(event.key==='ArrowRight'){event.preventDefault();switchImage(1);}
   if(event.key==='ArrowLeft'){event.preventDefault();switchImage(-1);}
  });
 }
}
