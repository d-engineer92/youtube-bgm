const {chromium}=require('playwright');
const assert=require('node:assert/strict');
const fs=require('node:fs');
let browser;
(async()=>{
 const base=process.env.BGM_URL||'http://127.0.0.1:8089/';
 assert.equal((await fetch(new URL('/up',base))).headers.get('x-bgm-test-database'),'pgsql:youtube_bgm_test');
 const fixture=JSON.parse(fs.readFileSync((process.env.BGM_EVIDENCE||'/tmp/bgm-exploration')+'/brief-expiry.json','utf8'));
 browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 const context=await browser.newContext({viewport:{width:390,height:844}});
 const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
 const ready=()=>page.locator('#selection-tray[data-ready=true]').waitFor();
 const url=new URL('/briefs/'+fixture.brief_id,base).href;
 await page.goto(url);await ready();
 assert(Date.now()<Date.parse(fixture.expires_at),'Fixture must still be live when displayed');
 await page.locator('[name="content[reference]"]').fill('未保存の参考');
 await page.locator('[name="content[avoid]"]').fill('未保存の反例');
 await page.locator('[name="content[one_change]"]').fill('未保存の本人企画');
 await page.locator('[name="reflection[result]"]').fill('未保存の本人結果');
 await page.getByRole('link',{name:'利用・保存の説明',exact:true}).click();
 await page.waitForTimeout(Math.max(0,Date.parse(fixture.expires_at)-Date.now()+200));
 // A request runs server-side prune; back/reload must not resurrect the browser copy.
 assert.equal((await context.request.get(new URL('/policy',base).href)).status(),200);
 await page.goBack();await ready();
 const check=async()=>{
  assert.equal(await page.locator('[name="content[reference]"]').inputValue(),'');
  assert.equal(await page.locator('[name="content[avoid]"]').inputValue(),'');
  assert.equal(await page.locator('[name="content[one_change]"]').inputValue(),'未保存の本人企画');
  assert.equal(await page.locator('[name="reflection[result]"]').inputValue(),'未保存の本人結果');
  assert.equal(await page.locator('[name="content[reference]"]').getAttribute('readonly'),'');
  assert.equal(await page.evaluate(()=>Object.entries(sessionStorage).some(([k,v])=>k.includes('-draft-')&&(v.includes('未保存の参考')||v.includes('未保存の反例')||v.includes('旧形式参考')||v.includes('旧形式反例')))),false);
 };
 await check();
 // A pre-fix mixed draft must also be sanitized rather than replaying old reference text.
 await page.locator('form[data-draft]').evaluate(form=>{const k=Object.keys(sessionStorage).find(k=>k.endsWith('-draft-'+form.dataset.draft));const d=JSON.parse(sessionStorage.getItem(k));d['content[reference]']='旧形式参考';d['content[avoid]']='旧形式反例';sessionStorage.setItem(k,JSON.stringify(d));});
 await page.reload();await ready();await check();
 // Simulate a stale/malicious POST, bypassing disabled fields and browser cleanup.
 const data=await page.locator('form[data-draft]').evaluate(form=>Object.fromEntries(new FormData(form)));
 data['content[reference]']='直接POST参考';data['content[avoid]']='直接POST反例';
 const response=await context.request.post(new URL('/briefs',base).href,{form:data});assert.equal(response.status(),200);
 await page.reload();await ready();await check();
 assert.deepEqual(errors,[]);
 console.log('PASS R2: actual expiry → prune → browser back → reload → direct POST; own plan/reflection retained');
 await browser.close();
})().catch(async e=>{console.error(e);if(browser)await browser.close();process.exitCode=1;});
