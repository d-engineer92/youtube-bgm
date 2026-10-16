<aside id="selection-tray" aria-busy="true" class="panel selection-tray" aria-label="共通の比較選択"><div id="tray-subjects" aria-label="選択した動画"></div><strong>比較の選択</strong> <span id="tray-count">0</span>本 <span id="tray-message" role="status">2〜6本を選んでください。</span><button type="button" disabled id="tray-compare">選択した動画を比較</button><button type="button" disabled id="tray-clear" class="secondary">選択を解除</button></aside>
<script>
(() => {
 Object.keys(sessionStorage).filter(k=>k.startsWith('bgm-selection-')&&k.includes('-draft-')).forEach(k=>{try{const d=JSON.parse(sessionStorage.getItem(k));if(!d.expires_at||d.expires_at<Date.now())sessionStorage.removeItem(k);}catch{sessionStorage.removeItem(k);}});
 const key='bgm-selection-{{ \App\Services\Tracking\Dataset::id() }}-{{ config('youtube.mode') }}';
 let ids; try { ids=JSON.parse(sessionStorage.getItem(key)||'[]'); } catch { ids=[]; }
 const inputs=[...document.querySelectorAll('input[data-tray-id],input[type=checkbox][name="ids[]"]')];
 const idOf=e=>e.dataset.trayId||e.value;
 let selectionRequest=0;
 const loadLabels=async()=>{const ticket=++selectionRequest;const host=document.querySelector('#tray-subjects');host.replaceChildren();if(!ids.length||ids.length>6)return;try{const result=await fetch('{{ route('selection') }}?'+ids.map(id=>'ids[]='+encodeURIComponent(id)).join('&'),{cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}});if(!result.ok)return;const rows=await result.json();if(ticket!==selectionRequest)return;rows.forEach(row=>{const chip=document.createElement('button');chip.type='button';chip.className='secondary';chip.textContent=row.label+' ×';chip.title=row.label;chip.setAttribute('aria-label',row.label+'を比較から外す');chip.onclick=()=>{ids=ids.filter(id=>id!==String(row.id));refresh();};host.append(chip);});}catch{}};
 const refresh=()=>{loadLabels();sessionStorage.setItem(key,JSON.stringify(ids)); document.querySelector('#tray-count').textContent=ids.length;inputs.forEach(e=>{e.checked=ids.includes(idOf(e));}); document.querySelector('#tray-message').textContent=ids.length<2?'2本以上を選んでください。':ids.length>6?'6本以内へ減らしてください。':'別テーマ・別ページの選択を保持しています。';};
 inputs.forEach(e=>e.addEventListener('change',()=>{const id=idOf(e);ids=e.checked?[...new Set([...ids,id])]:ids.filter(v=>v!==id);refresh();}));
 document.querySelector('#tray-clear').onclick=()=>{ids=[];refresh();};
 document.querySelector('#tray-compare').onclick=()=>{if(ids.length<2||ids.length>6)return;sessionStorage.setItem(key+'-return',location.href);sessionStorage.setItem(key+'-scroll',scrollY);const origin=document.querySelector('input[name=exploration]')?.value;location.href='{{ route('compare') }}?'+ids.map(id=>'ids[]='+encodeURIComponent(id)).join('&')+(origin?'&exploration='+encodeURIComponent(origin):'');};
 document.querySelectorAll('form[action="{{ route('compare') }}"]').forEach(form=>form.addEventListener('submit',event=>{if(event.submitter?.hasAttribute('formaction')||!form.id)return;event.preventDefault();document.querySelector('#tray-compare').click();}));
 const returnLink=document.querySelector('#return-to-selection');
 if(returnLink){returnLink.href=sessionStorage.getItem(key+'-return')||'{{ route('tracking') }}';}
 if(location.href===sessionStorage.getItem(key+'-return')){requestAnimationFrame(()=>scrollTo(0,Number(sessionStorage.getItem(key+'-scroll')||0)));}
 // Per-page form drafts survive navigation/polling. API bodies never enter browser storage.
 document.querySelectorAll('form[data-draft]').forEach(form=>{
  const k=key+'-draft-'+form.dataset.draft, evidenceKey=k+'-evidence';
  const read=k=>{try{return JSON.parse(sessionStorage.getItem(k)||'{}');}catch{return {};}};
  let draft=read(k), evidence=read(evidenceKey);
  const evidenceExpiry=Date.parse(form.dataset.evidenceExpires||'');
  const dependent=e=>e.hasAttribute('data-evidence-field');
  const expired=()=>Number.isFinite(evidenceExpiry)&&Date.now()>=evidenceExpiry;
  // Migrate old mixed drafts without restoring reference text from their 28-day TTL.
  const fields=[...form.querySelectorAll('textarea,input:not([type=hidden])')];
  fields.filter(dependent).forEach(e=>{delete draft[e.name];});
  sessionStorage.setItem(k,JSON.stringify(draft));
  const purge=()=>{if(!expired())return;sessionStorage.removeItem(evidenceKey);evidence={};fields.filter(dependent).forEach(e=>{e.value='';e.readOnly=true;});};
  purge();
  fields.forEach(e=>{
   let saved=dependent(e)?evidence:draft;
   if(saved.expires_at&&Date.now()>=saved.expires_at){sessionStorage.removeItem(dependent(e)?evidenceKey:k);saved={};if(dependent(e))evidence={};else draft={};}
   if(!expired()||!dependent(e)){if(saved[e.name]!==undefined)e.value=saved[e.name];}
   e.addEventListener('input',()=>{
    purge();if(dependent(e)&&expired())return;
    const d=dependent(e)?evidence:draft;
    d.expires_at=dependent(e)?evidenceExpiry:Math.min(d.expires_at||Date.now()+28*86400000,Date.parse(form.dataset.expires||'')||Date.now()+28*86400000);
    d[e.name]=e.value;sessionStorage.setItem(dependent(e)?evidenceKey:k,JSON.stringify(d));
    const status=form.querySelector('[data-save-state]');if(status)status.textContent='未保存（このタブに下書き）';
   });
  });
  window.addEventListener('pageshow',purge);window.addEventListener('focus',purge);document.addEventListener('visibilitychange',purge);
  const schedulePurge=()=>{purge();if(Number.isFinite(evidenceExpiry)&&!expired())setTimeout(schedulePurge,Math.min(evidenceExpiry-Date.now()+1,2147483647));};schedulePurge();
  form.addEventListener('submit',()=>{purge();const status=form.querySelector('[data-save-state]');if(status)status.textContent='保存中';});
 });
 refresh();inputs.forEach(e=>{e.disabled=false;});document.querySelector('#tray-compare').disabled=false;document.querySelector('#tray-clear').disabled=false;document.querySelector('#selection-tray').dataset.ready='true';document.querySelector('#selection-tray').setAttribute('aria-busy','false');
})();
</script>
