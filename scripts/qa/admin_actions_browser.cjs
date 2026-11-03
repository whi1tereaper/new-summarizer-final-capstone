const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'C:/Users/Robert Martin/AppData/Roaming/npm/node_modules/omniroute/node_modules/playwright');
const base=process.env.QA_ACTIONS_BASE,report=process.env.QA_ACTIONS_REPORT,privateDir=process.env.QA_ACTIONS_PRIVATE;
if(!base||!report||!privateDir)throw new Error('Isolated admin Actions QA environment required.');
const sessions=JSON.parse(fs.readFileSync(path.join(privateDir,'sessions.json'),'utf8'));
const fixture=JSON.parse(fs.readFileSync(path.join(privateDir,'admin-actions-fixtures.json'),'utf8'));
const checks=[],samples=[];let browser;
const pass=name=>{checks.push({name,passed:true});console.log('PASS '+name);};
const fail=(name,e)=>{checks.push({name,passed:false,detail:e.message});console.error('FAIL '+name+': '+e.message);};
const test=async(name,fn)=>{try{await fn();pass(name);}catch(e){fail(name,e);}};
async function createContext(role='admin',theme='light',width=1440){
 const context=await browser.newContext({viewport:{width,height:1000},isMobile:width<=640,hasTouch:width<=640,colorScheme:theme,reducedMotion:'reduce'});
 if(sessions[role])await context.addCookies([{name:'LIGHT_QA_SESSID',value:sessions[role],url:base}]);
 await context.addInitScript(theme=>localStorage.setItem('light-results-theme',theme),theme);
 await context.route('https://fonts.googleapis.com/**',route=>route.fulfill({status:200,contentType:'text/css',body:''}));return context;
}
const rowFor=(page,key)=>page.locator('[data-admin-user-row]').filter({hasText:fixture.users[key].username});
async function measure(page){return page.evaluate(()=>{
 const rows=[...document.querySelectorAll('[data-admin-user-row]')].filter(row=>row.textContent.includes('qa_actions_'));
 const token=name=>{const canvas=document.createElement('canvas'),ctx=canvas.getContext('2d');ctx.fillStyle=getComputedStyle(document.documentElement).getPropertyValue(name);return ctx.fillStyle;};
 const color=value=>{const ctx=document.createElement('canvas').getContext('2d');ctx.fillStyle=value;return ctx.fillStyle;};
 const viewport=document.documentElement.clientWidth,overflow=[...document.querySelectorAll('body *')].filter(el=>{const r=el.getBoundingClientRect();if(!r.width||r.right<=viewport+1)return false;for(let parent=el.parentElement;parent&&parent!==document.body;parent=parent.parentElement){if(/auto|scroll|hidden|clip/.test(getComputedStyle(parent).overflowX)&&parent.getBoundingClientRect().right<=viewport+1)return false;}return true;}).map(el=>({tag:el.tagName,id:el.id,classes:el.className,width:el.getBoundingClientRect().width,right:el.getBoundingClientRect().right})).slice(0,16);
 return{width:innerWidth,clientWidth:viewport,scrollWidth:document.documentElement.scrollWidth,overflow,theme:document.documentElement.dataset.theme,neutral:token('--color-text-secondary'),danger:token('--color-danger'),rows:rows.map(row=>({name:row.querySelector('strong').textContent,groupDisplay:getComputedStyle(row.querySelector('.table-actions')||row).display,actions:[...row.querySelectorAll('.text-action')].map(el=>{const s=getComputedStyle(el),r=el.getBoundingClientRect(),svg=el.querySelector('svg');return{text:el.textContent.trim(),classes:el.className,bg:s.backgroundColor,color:color(s.color),border:s.borderTopWidth,shadow:s.boxShadow,font:s.fontFamily,x:r.x,y:r.y,width:r.width,height:r.height,icon:svg?{hidden:svg.getAttribute('aria-hidden'),fill:getComputedStyle(svg).fill,stroke:getComputedStyle(svg).stroke,width:svg.getBoundingClientRect().width}:null};}),dividers:[...row.querySelectorAll('.table-actions__divider')].map(el=>({text:el.textContent.trim(),hidden:el.getAttribute('aria-hidden'),width:el.getBoundingClientRect().width,height:el.getBoundingClientRect().height,bg:getComputedStyle(el).backgroundColor}))}))};
 });}
(async()=>{try{
 browser=await chromium.launch({headless:true,executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});
 for(const theme of['light','dark'])for(const width of[1440,390,320]){
  const context=await createContext('admin',theme,width),page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto(base+'admin_dashboard.php',{waitUntil:'domcontentloaded'});await page.evaluate(()=>document.fonts.ready);
  const label=`${theme} ${width}px`;
  await test(label+' preserves active/inactive/self/admin action availability',async()=>{
   const active=rowFor(page,'active'),inactive=rowFor(page,'inactive');assert.equal(await active.locator('form').count(),2);assert.equal(await active.locator('.text-action').count(),2);assert.equal(await active.locator('.text-action--danger').count(),1);
   assert.equal(await inactive.locator('form').count(),1);assert.equal(await inactive.locator('.text-action--danger').count(),1);assert.equal(await inactive.locator('form[action*="deactivate_user"]').count(),0);
   assert.equal(await rowFor(page,'self').locator('form').count(),0);assert.equal(await rowFor(page,'other_admin').locator('form').count(),0);
  });
  await test(label+' uses one transparent action row, divider and outline icons with 44px targets',async()=>{
   const m=await measure(page);samples.push(m);assert.equal(m.theme,theme);assert(m.scrollWidth<=width+1,'Document must not overflow; only table scrolls');
   const active=m.rows.find(r=>r.name===fixture.users.active.username),inactive=m.rows.find(r=>r.name===fixture.users.inactive.username);assert(active&&inactive);assert(/flex/.test(active.groupDisplay));assert.equal(active.dividers.length,1);assert(active.dividers[0].width>=1&&active.dividers[0].width<=2);assert(active.dividers[0].height>=12&&active.dividers[0].height<=20);assert.notEqual(active.dividers[0].bg,'rgba(0, 0, 0, 0)');assert.equal(active.dividers[0].hidden,'true');assert.equal(inactive.dividers.length,0);
   assert(Math.abs(active.actions[0].y-active.actions[1].y)<1,'Actions stay side by side');assert(active.actions[1].x>active.actions[0].x+active.actions[0].width,'Divider separates actions');
   assert.equal(active.actions[0].color,m.neutral);assert.equal(active.actions[1].color,m.danger);
   for(const row of[active,inactive])for(const action of row.actions){assert.equal(action.bg,'rgba(0, 0, 0, 0)');assert.equal(action.border,'0px');assert.equal(action.shadow,'none');assert(action.width>=44&&action.height>=44,`${action.text}: ${action.width}×${action.height}`);assert(action.icon&&action.icon.hidden==='true');assert.equal(action.icon.fill,'none');assert.notEqual(action.icon.stroke,'none');assert(action.icon.width>=12&&action.icon.width<=22);assert(!/Times New Roman|^serif$/.test(action.font));}
  });
  await test(label+' hover and keyboard focus stay lightweight and accessible',async()=>{
   const active=rowFor(page,'active');for(const button of await active.locator('.text-action').all()){await button.hover();assert.equal(await button.evaluate(el=>getComputedStyle(el).backgroundColor),'rgba(0, 0, 0, 0)');await button.focus();await page.keyboard.press('Tab');await page.keyboard.press('Shift+Tab');assert(await button.evaluate(el=>getComputedStyle(el).outlineStyle!=='none'&&parseFloat(getComputedStyle(el).outlineWidth)>=2));}
   assert.equal(errors.length,0,'No browser script errors');
  });
  const wrapper=page.locator('#admin-user-table').locator('..');await wrapper.evaluate(el=>el.scrollLeft=el.scrollWidth-el.clientWidth);
  if(width===1440)await wrapper.screenshot({path:path.join(report,`actions-focus-${theme}-${width}.png`),animations:'disabled'});
  await page.evaluate(()=>document.activeElement instanceof HTMLElement&&document.activeElement.blur());await page.mouse.move(1,1);
  if(width!==320)await wrapper.screenshot({path:path.join(report,`actions-${theme}-${width}.png`),animations:'disabled'});
  await context.close();
 }
 const context=await createContext('admin'),page=await context.newPage(),submitted=[];
 await page.route('**/admin_dashboard.php?action=*',async route=>{if(route.request().method()==='POST'){submitted.push({url:route.request().url(),body:route.request().postData()});await route.fulfill({status:204});}else await route.continue();});
 await page.goto(base+'admin_dashboard.php',{waitUntil:'domcontentloaded'});
 await test('Shared disabled and busy guards prevent text-action activation',async()=>{
  const button=rowFor(page,'active').locator('form[action*="deactivate_user"] button');const before=submitted.length;let dialogs=0;const handler=async dialog=>{dialogs++;await dialog.dismiss();};page.on('dialog',handler);
  for(const attribute of['aria-disabled','aria-busy']){await button.evaluate((el,attribute)=>el.setAttribute(attribute,'true'),attribute);await button.focus();await page.keyboard.press('Enter');await button.evaluate((el,attribute)=>el.removeAttribute(attribute),attribute);}
  page.off('dialog',handler);assert.equal(dialogs,0);assert.equal(submitted.length,before);
 });
 for(const action of['deactivate_user','delete_user']){
  const button=rowFor(page,'active').locator(`form[action*="${action}"] button`);
  await test(action+' keyboard cancellation retains native confirmation and emits no POST',async()=>{
   const before=submitted.length;let message='';page.once('dialog',async dialog=>{assert.equal(dialog.type(),'confirm');message=dialog.message();await dialog.dismiss();});await button.focus();await page.keyboard.press('Enter');await page.waitForTimeout(100);assert(message.includes(fixture.users.active.username));assert.equal(submitted.length,before);
  });
  await test(action+' confirmation preserves route, CSRF and target account fields',async()=>{
   const token=await button.locator('..').locator('input[name="csrf_token"]').inputValue();assert(token.length>=32);const before=submitted.length;page.once('dialog',dialog=>dialog.accept());const response=page.waitForResponse(r=>r.request().method()==='POST'&&r.url().includes('action='+action));await button.focus();await page.keyboard.press('Enter');await response;assert.equal(submitted.length,before+1);const request=submitted.at(-1),body=new URLSearchParams(request.body);assert.equal(new URL(request.url).searchParams.get('action'),action);assert.equal(body.get('csrf_token'),token);assert.equal(body.get('user_id'),String(fixture.users.active.id));
  });
 }
 await test('Form requestSubmit still confirms and safely displays quoted usernames',async()=>{
  const form=rowFor(page,'quoted').locator('form[action*="deactivate_user"]');const before=submitted.length;let message='';page.once('dialog',async dialog=>{assert.equal(dialog.type(),'confirm');message=dialog.message();await dialog.dismiss();});await form.evaluate(el=>el.requestSubmit());assert(message.includes(fixture.users.quoted.username),'Quoted and HTML-like username is intact in confirmation');assert.equal(submitted.length,before);
  page.once('dialog',dialog=>dialog.accept());const response=page.waitForResponse(r=>r.request().method()==='POST'&&r.url().includes('action=deactivate_user'));await form.evaluate(el=>el.requestSubmit());await response;assert.equal(submitted.length,before+1);assert.equal(new URLSearchParams(submitted.at(-1).body).get('user_id'),String(fixture.users.quoted.id));
 });
 await test('Existing real backend rejects invalid CSRF before changing accounts',async()=>{
  for(const action of['deactivate_user','delete_user']){const response=await context.request.post(base+'admin_dashboard.php?action='+action,{form:{csrf_token:'invalid',user_id:String(fixture.users.active.id)},maxRedirects:0});assert.equal(response.status(),403);assert((await response.text()).includes('Invalid CSRF'));}
 });
 await test('Existing real backend rejects GET mutation requests',async()=>{for(const action of['deactivate_user','delete_user']){const response=await context.request.get(base+'admin_dashboard.php?action='+action,{maxRedirects:0});assert.equal(response.status(),405);}});
 await test('Ordinary and anonymous sessions cannot execute administrator actions',async()=>{
  for(const role of['user','guest']){const outsider=await createContext(role);for(const action of['deactivate_user','delete_user']){const response=await outsider.request.post(base+'admin_dashboard.php?action='+action,{form:{csrf_token:'invalid',user_id:String(fixture.users.active.id)},maxRedirects:0});assert([302,303,403].includes(response.status()));if([302,303].includes(response.status()))assert((response.headers().location||'').startsWith('login.php'));}await outsider.close();}
 });
 await page.goto(base+'admin_dashboard.php',{waitUntil:'domcontentloaded'});await test('Intercepted and denied requests preserve fixture account records/status',async()=>{assert.equal(await rowFor(page,'active').count(),1);assert.equal(await rowFor(page,'active').getAttribute('data-status'),'active');assert.equal(await rowFor(page,'inactive').getAttribute('data-status'),'inactive');assert.equal(await rowFor(page,'other_admin').count(),1);});
 await context.close();
}catch(e){fail('Targeted browser harness completed',e);}finally{
 if(browser)await browser.close();const summary={recorded_at:new Date().toISOString(),passed:checks.filter(c=>c.passed).length,failed:checks.filter(c=>!c.passed).length,checks,samples,synthetic_data:true,confirmed_posts_intercepted:true,backend_deletion_or_deactivation_executed:false};fs.writeFileSync(path.join(report,'browser-results.json'),JSON.stringify(summary,null,2));console.log(`Admin Actions QA: ${summary.passed} passed, ${summary.failed} failed.`);if(summary.failed)process.exitCode=1;
}})();
