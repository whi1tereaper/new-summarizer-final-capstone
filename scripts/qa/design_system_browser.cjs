/* Browser regression checks use only isolated synthetic fixture data. */
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'C:/Users/Robert Martin/AppData/Roaming/npm/node_modules/omniroute/node_modules/playwright');
const base = process.env.QA_DESIGN_BASE || 'http://127.0.0.1:58146/';
const reportDir = process.env.QA_DESIGN_REPORT;
const privateDir = process.env.QA_DESIGN_PRIVATE;
if (!reportDir || !privateDir) throw new Error('Isolated QA directories are required.');
const fixture = JSON.parse(fs.readFileSync(path.join(privateDir,'fixtures.json'),'utf8'));
const sessions = JSON.parse(fs.readFileSync(path.join(privateDir,'sessions.json'),'utf8'));
const foundationOnly=process.env.QA_DESIGN_FOUNDATION==='1';
const checks=[], pages=[], colors=new Map();
const record=(name, detail={})=>{checks.push({name,passed:true,...detail});console.log('PASS '+name);};
const fail=(name,error)=>{checks.push({name,passed:false,detail:error.message});console.error('FAIL '+name+': '+error.message);};
const test=async(name,fn)=>{try{await fn();record(name);}catch(error){fail(name,error);}};
const routes=[
 ['landing','guest','index.php'], ['about','guest','about.php'], ['terms','guest','terms.php'],
 ['accept-terms','guest','accept_terms.php'], ['login','guest','login.php'], ['admin-login','guest','login.php?context=admin'],
 ['register','guest','register.php'], ['forgot-password','guest','forgot_password.php'], ['reset-password','guest','reset_password.php'],
 ['workspace-guest','guest','summarizer.php'], ['workspace-user','user','summarizer.php'], ['workspace-admin','admin','summarizer.php'],
 ['dashboard-user','user','dashboard.php'], ['dashboard-admin','admin','admin_dashboard.php'], ['history','user','history.php'],
 ['profile-settings','user','profile.php'], ['profile-admin','admin','profile.php'], ['analytics','admin','analytics.php'],
 ['student-registry','admin','admin_student_registry.php'], ['audit-logs','admin','admin_audit_logs.php'],
 ['storage','admin','admin_dashboard.php#storage-maintenance'], ['result','user',`result.php?id=${fixture.summary_id}&share=${fixture.share}`],
 ['processing','processing','processing.php'], ['evaluation-report','admin',`evaluation_export.php?run=${fixture.run_id}&format=html`],
];
let browser;
async function contextFor(role='guest',theme='light',width=1440, extra={}) {
 const context=await browser.newContext({viewport:{width,height:1000},isMobile:width<=640,hasTouch:width<=640,colorScheme:theme,reducedMotion:'reduce',...extra});
 if(sessions[role])await context.addCookies([{name:'LIGHT_QA_SESSID',value:sessions[role],url:base}]);
 await context.addInitScript(theme=>{
  if(theme!=='system')localStorage.setItem('light-results-theme',theme);
  window.__designFirstBody=null;
  const inspect=()=>{if(document.body&&!window.__designFirstBody)window.__designFirstBody={root:document.documentElement.dataset.theme,body:document.body.dataset.theme};};
  new MutationObserver(inspect).observe(document,{subtree:true,childList:true,attributes:true,attributeFilter:['data-theme']});
  requestAnimationFrame(inspect);
 },theme);
 await context.route('**/*',async route=>{
  const url=new URL(route.request().url());
  if(url.origin===new URL(base).origin&&url.pathname.endsWith('/summarize.php')) {
   await route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({success:true,status:'processing',job_id:'design-system-controlled-pending'})});return;
  }
  if(url.origin!==new URL(base).origin) {
   if(url.hostname==='fonts.googleapis.com'){await route.fulfill({status:200,contentType:'text/css',body:''});return;}
   if(url.hostname==='fonts.gstatic.com'){await route.abort();return;}
  }
  await route.continue();
 });
 return context;
}
async function settled(page){await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));await page.evaluate(()=>Promise.race([document.fonts.ready,new Promise(resolve=>setTimeout(resolve,1000))]));}
async function shot(page,name){await page.screenshot({path:path.join(reportDir,name+'.png'),fullPage:true,animations:'disabled'});}
async function actionAudit(page){return page.evaluate(async()=>{
 const actual=[...document.querySelectorAll('.btn')].filter(el=>el.getClientRects().length&&!el.closest('[hidden],[inert]')&&getComputedStyle(el).visibility!=='hidden'&&!el.matches('.pw-toggle'));
 const frame=document.createElement('iframe');frame.setAttribute('aria-hidden','true');frame.style.cssText=`position:fixed;left:-10000px;top:0;visibility:hidden;width:${innerWidth}px;height:1000px;pointer-events:none;`;document.body.append(frame);
 const css=[...document.styleSheets].filter(sheet=>sheet.href&&/\/(design-tokens|components)\.css/.test(sheet.href)).flatMap(sheet=>[...sheet.cssRules].filter(rule=>rule.type!==CSSRule.IMPORT_RULE).map(rule=>rule.cssText)).join('\n');
 const doc=frame.contentDocument;doc.open();doc.write('<!doctype html><html><head><style>'+css+'</style></head><body></body></html>');doc.close();doc.documentElement.dataset.theme=document.documentElement.dataset.theme;
 const props=['fontFamily','fontSize','fontWeight','lineHeight','borderRadius','minHeight','paddingTop','paddingRight','paddingBottom','paddingLeft','borderTopWidth'],mismatches=[];
 for(const el of actual){const probe=doc.createElement(el.tagName==='A'?'a':'button');probe.className=el.className;probe.textContent=el.textContent;for(const name of['aria-pressed','aria-selected','aria-current','data-active'])if(el.hasAttribute(name))probe.setAttribute(name,el.getAttribute(name));doc.body.append(probe);const current=getComputedStyle(el),expected=frame.contentWindow.getComputedStyle(probe),diff={};for(const prop of props)if(current[prop]!==expected[prop])diff[prop]={actual:current[prop],expected:expected[prop]};if(Object.keys(diff).length)mismatches.push({id:el.id,classes:el.className,diff});probe.remove();}
 frame.remove();return{count:actual.length,mismatches};
 });}
async function geometry(page){return page.evaluate(()=>{
 const visible=el=>{const style=getComputedStyle(el),r=el.getBoundingClientRect();return el.getClientRects().length&&style.visibility!=='hidden'&&style.display!=='none'&&r.width>0&&r.height>0&&!el.closest('[hidden],[inert]');};
 const clipped=[...document.querySelectorAll('main button, main input:not([type="hidden"]), main select, main textarea')].filter(visible).filter(el=>!el.closest('table,.table-wrap')).filter(el=>{const r=el.getBoundingClientRect();return r.left < -1||r.right > innerWidth+1;}).map(el=>el.id||el.className).slice(0,12);
 const body=getComputedStyle(document.body),root=getComputedStyle(document.documentElement);
 const canvas=document.createElement('canvas'),ctx=canvas.getContext('2d');
 const color=value=>{ctx.fillStyle='#000';ctx.fillStyle=value;return ctx.fillStyle;};
 const primary=document.querySelector('.btn--primary');
 return{width:innerWidth,scrollWidth:document.documentElement.scrollWidth,overflow:document.documentElement.scrollWidth>innerWidth+1,clipped,rootTheme:document.documentElement.dataset.theme,bodyTheme:document.body.dataset.theme,firstBody:window.__designFirstBody,bodyBackground:color(body.backgroundColor),bodyColor:color(body.color),surface:root.getPropertyValue('--color-surface').trim(),accent:root.getPropertyValue('--color-accent').trim(),font:body.fontFamily,controlFonts:[...new Set([...document.querySelectorAll('input:not([type="hidden"]),textarea,select')].filter(visible).map(el=>getComputedStyle(el).fontFamily))],themeScripts:[...document.scripts].filter(s=>s.src.includes('/theme-toggle.js')).length,tokenLinks:[...document.querySelectorAll('link[rel="stylesheet"]')].filter(l=>l.href.includes('/design-tokens.css')).length,componentLinks:[...document.querySelectorAll('link[rel="stylesheet"]')].filter(l=>l.href.includes('/components.css')).length,primary:primary?{color:color(getComputedStyle(primary).color),background:color(getComputedStyle(primary).backgroundColor)}:null};
 });}
function luminance(hex){const v=hex.replace('#','');if(v.length!==6)return null;const rgb=[0,2,4].map(i=>parseInt(v.slice(i,i+2),16)/255).map(v=>v<=.04045?v/12.92:((v+.055)/1.055)**2.4);return .2126*rgb[0]+.7152*rgb[1]+.0722*rgb[2];}
function contrast(a,b){const x=luminance(a),y=luminance(b);return x===null||y===null?null:(Math.max(x,y)+.05)/(Math.min(x,y)+.05);}

(async()=>{
 try {
  fs.mkdirSync(reportDir,{recursive:true});
  browser=await chromium.launch({headless:true,executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});
  for(const theme of foundationOnly?[]:['light','dark'])for(const width of [1440,390]) {
   const contexts={};
   for(const [name,role,url] of routes) {
    const label=`${name} ${theme} ${width}px`;
    if(!contexts[role])contexts[role]=await contextFor(role,theme,width);
    const page=await contexts[role].newPage();page.setDefaultTimeout(7000);
    const errors=[],badResponses=[];
    page.on('pageerror',e=>errors.push(e.message));
    page.on('response',r=>{if(r.url().startsWith(base)&&r.status()>=400)badResponses.push({url:r.url().replace(base,''),status:r.status()});});
    try {
     const response=await page.goto(base+url,{waitUntil:'domcontentloaded',timeout:30000});
     assert(response&&response.status()<400,`Page status ${response?.status()}`);
     await settled(page);
     const g=await geometry(page),actions=await actionAudit(page);pages.push({name,theme,width,...g,actions,errors,badResponses});
     assert.equal(g.rootTheme,theme,'root theme');assert.equal(g.bodyTheme,theme,'legacy body theme mirror');
     assert.equal(g.firstBody?.root,theme,'theme was resolved before first body insertion');
     assert.equal(g.themeScripts,1,'one central theme script');assert.equal(g.tokenLinks,1,'one canonical token stylesheet');assert.equal(g.componentLinks,1,'one shared component stylesheet');
     assert(!g.overflow,`Horizontal overflow: scroll ${g.scrollWidth}, viewport ${width}`);assert.equal(g.clipped.length,0,'Clipped controls: '+g.clipped.join(', '));
     assert.equal(errors.length,0,'Browser script errors: '+errors.join('; '));assert.equal(badResponses.length,0,'HTTP failures: '+JSON.stringify(badResponses));
     assert.equal(actions.mismatches.length,0,'Page overrides canonical button metrics: '+JSON.stringify(actions.mismatches));
     const ratio=contrast(g.bodyBackground,g.bodyColor);if(ratio!==null)assert(ratio>=4.5,`Body text contrast ${ratio.toFixed(2)}`);
     assert(!/Times New Roman|^serif$/i.test(g.font),'Body falls back to browser serif font');assert(!g.controlFonts.some(font=>/Times New Roman|^serif$/i.test(font)),'Form control falls back to browser serif font');
     colors.set(`${name}-${width}-${theme}`,g.bodyBackground);
     record(label,{background:g.bodyBackground,text:g.bodyColor});
     if(['landing','workspace-user','dashboard-admin','history','profile-settings','result','login','student-registry'].includes(name))await shot(page,`${name}-${theme}-${width}`);
    }catch(error){fail(label,error);try{await shot(page,`FAILED-${name}-${theme}-${width}`);}catch{}}
    finally{await page.close();}
   }
   for(const context of Object.values(contexts))await context.close();
  }
  if(!foundationOnly)await test('Major page backgrounds change between light and dark tokens',async()=>{
   for(const [name] of routes)for(const width of[1440,390]){const light=colors.get(`${name}-${width}-light`),dark=colors.get(`${name}-${width}-dark`);assert(light&&dark,`Missing passing sample ${name}/${width}`);assert.notEqual(light,dark,`${name} keeps the same background in both themes`);}
  });

  const context=await contextFor('user','system',1440,{colorScheme:'dark',permissions:['clipboard-read','clipboard-write']});
  const page=await context.newPage();page.setDefaultTimeout(8000);
  await test('System theme resolves before body paint and follows live system changes',async()=>{
   await page.goto(base+'summarizer.php',{waitUntil:'domcontentloaded'});await settled(page);
   assert.equal(await page.locator('html').getAttribute('data-theme'),'dark');
   assert.equal(await page.evaluate(()=>window.__designFirstBody?.root),'dark');
   await page.emulateMedia({colorScheme:'light'});await page.waitForFunction(()=>document.documentElement.dataset.theme==='light');
   await page.emulateMedia({colorScheme:'dark'});await page.waitForFunction(()=>document.documentElement.dataset.theme==='dark');
   assert.equal(await page.evaluate(()=>localStorage.getItem('light-results-theme')),null,'System mode must not persist a forced selection');
  });
  await test('Explicit preference updates all controls, charts and components without reload',async()=>{
   await page.evaluate(()=>{window.__themeEvents=[];window.addEventListener('themechange',e=>window.__themeEvents.push(e.detail.theme));window.LightTheme.set('light');});
   assert.equal(await page.locator('html').getAttribute('data-theme'),'light');assert.equal(await page.locator('body').getAttribute('data-theme'),'light');
   assert.equal(await page.evaluate(()=>localStorage.getItem('light-results-theme')),'light');
   const controls=await page.locator('[data-theme-toggle]').evaluateAll(nodes=>nodes.map(el=>({pressed:el.getAttribute('aria-pressed'),label:el.getAttribute('aria-label')})));
   assert(controls.length&&controls.every(c=>c.pressed==='false'&&/dark/i.test(c.label)));
   await page.emulateMedia({colorScheme:'dark'});await settled(page);assert.equal(await page.locator('html').getAttribute('data-theme'),'light','Explicit preference survives system changes');
   assert((await page.evaluate(()=>window.__themeEvents)).includes('light'));
  });
  await test('Preference persists across navigation and reload with no wrong-theme body',async()=>{
   await page.evaluate(()=>window.LightTheme.set('dark'));
   await page.goto(base+'history.php',{waitUntil:'domcontentloaded'});await settled(page);assert.equal(await page.locator('html').getAttribute('data-theme'),'dark');assert.equal(await page.evaluate(()=>window.__designFirstBody?.root),'dark');
   await page.reload({waitUntil:'domcontentloaded'});assert.equal(await page.locator('html').getAttribute('data-theme'),'dark');
  });
  await test('Theme choice synchronizes between tabs and reset restores system mode',async()=>{
   const other=await context.newPage();await other.goto(base+'profile.php',{waitUntil:'domcontentloaded'});
   await page.evaluate(()=>window.LightTheme.set('light'));await other.waitForFunction(()=>document.documentElement.dataset.theme==='light');
   await page.evaluate(()=>window.LightTheme.reset());await page.waitForFunction(()=>document.documentElement.dataset.theme==='dark');await other.waitForFunction(()=>document.documentElement.dataset.theme==='dark');
   assert.equal(await page.evaluate(()=>localStorage.getItem('light-results-theme')),null);await other.close();
  });
  await page.goto(base+'summarizer.php',{waitUntil:'domcontentloaded'});
  await test('Shared theme control activates once and updates accessible state',async()=>{
   await page.evaluate(()=>{window.LightTheme.set('light');window.__toggleEvents=0;window.addEventListener('themechange',()=>window.__toggleEvents++);});
   await page.locator('#sidebar-account-toggle').click();await page.locator('#sidebar-account-menu [data-theme-toggle]').click();
   assert.equal(await page.locator('html').getAttribute('data-theme'),'dark');assert.equal(await page.locator('#sidebar-account-menu [data-theme-toggle]').getAttribute('aria-pressed'),'true');assert.equal(await page.evaluate(()=>window.__toggleEvents),1);await page.keyboard.press('Escape');
  });
  await test('One semantic token updates every shared surface and primary button live',async()=>{
   await settled(page);
   const result=await page.evaluate(async()=>{
    const probe=document.createElement('div');probe.innerHTML='<section class="card"><button class="btn btn--primary" type="button">Token probe</button></section>';document.querySelector('main').append(probe);
    const section=probe.firstElementChild,button=section.firstElementChild,before={body:getComputedStyle(document.body).backgroundColor,button:getComputedStyle(button).backgroundColor,root:getComputedStyle(document.documentElement).getPropertyValue('--color-bg'),bodyToken:getComputedStyle(document.body).getPropertyValue('--color-bg')};
    document.documentElement.style.setProperty('--color-bg','#123456');document.documentElement.style.setProperty('--color-accent','#225588');
    await new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(()=>setTimeout(resolve,100))));
    const after={body:getComputedStyle(document.body).backgroundColor,button:getComputedStyle(button).backgroundColor,root:getComputedStyle(document.documentElement).getPropertyValue('--color-bg'),bodyToken:getComputedStyle(document.body).getPropertyValue('--color-bg')};
    document.documentElement.style.removeProperty('--color-bg');document.documentElement.style.removeProperty('--color-accent');probe.remove();return{before,after};
   });
   assert.notEqual(result.before.body,result.after.body,'body follows --color-bg '+JSON.stringify(result));assert.notEqual(result.before.button,result.after.button,'primary follows --color-accent '+JSON.stringify(result));
   assert.equal(result.after.button,'rgb(34, 85, 136)');
  });
  for(const theme of['light','dark'])await test(`Shared button variants/states/contrast ${theme}`,async()=>{
   await page.evaluate(theme=>{window.LightTheme.set(theme);document.getElementById('qa-components')?.remove();const section=document.createElement('section');section.id='qa-components';section.style.cssText='display:flex;flex-wrap:wrap;gap:16px;padding:24px;';for(const variant of['primary','secondary','outline','ghost','destructive']){const b=document.createElement('button');b.className='btn btn--'+variant;b.type='button';b.textContent=variant;b.dataset.variant=variant;section.append(b);}document.querySelector('main').prepend(section);},theme);
   const states=await page.locator('#qa-components button').evaluateAll(nodes=>nodes.map(el=>{const s=getComputedStyle(el),r=el.getBoundingClientRect(),c=document.createElement('canvas').getContext('2d');const normalize=v=>{c.fillStyle=v;return c.fillStyle;};return{variant:el.dataset.variant,height:r.height,padding:s.padding,bg:normalize(s.backgroundColor),color:normalize(s.color),border:s.borderRadius};}));
   for(const state of states){assert(state.height>=44,`${state.variant} height ${state.height}`);if(['primary','destructive'].includes(state.variant)){const ratio=contrast(state.bg,state.color);assert(ratio!==null&&ratio>=4.5,`${state.variant} contrast ${ratio}`);}}
   const primary=page.locator('#qa-components .btn--primary');await primary.focus();await page.keyboard.press('Tab');await page.keyboard.press('Shift+Tab');
   assert(await primary.evaluate(el=>getComputedStyle(el).outlineStyle!=='none'),'Visible keyboard focus');
   const before=(await primary.boundingBox()).width;await primary.evaluate(el=>el.setAttribute('aria-busy','true'));assert(Math.abs((await primary.boundingBox()).width-before)<1,'Loading keeps button width');
   await primary.evaluate(el=>{el.removeAttribute('aria-busy');el.disabled=true;});assert(await primary.isDisabled());assert.equal(await primary.evaluate(el=>getComputedStyle(el).cursor),'not-allowed');
   await primary.evaluate(el=>el.disabled=false);await primary.hover();await settled(page);assert.equal(await primary.evaluate(el=>getComputedStyle(el).animationName),'none','No decorative button animation');
   const hover=await primary.evaluate(el=>{const c=document.createElement('canvas').getContext('2d');c.fillStyle=getComputedStyle(document.documentElement).getPropertyValue('--button-primary-hover-bg');const expected=c.fillStyle;c.fillStyle=getComputedStyle(el).backgroundColor;return{actual:c.fillStyle,expected};});assert.equal(hover.actual,hover.expected,'Hover follows canonical variant state');
   await page.mouse.down();const active=await primary.evaluate(el=>{const c=document.createElement('canvas').getContext('2d');c.fillStyle=getComputedStyle(document.documentElement).getPropertyValue('--button-primary-active-bg');const expected=c.fillStyle;c.fillStyle=getComputedStyle(el).backgroundColor;return{actual:c.fillStyle,expected};});await page.mouse.up();assert.equal(active.actual,active.expected,'Active follows canonical variant state');
   const guarded=await primary.evaluate(el=>{window.__guardedActionCount=0;el.addEventListener('click',()=>window.__guardedActionCount++);el.setAttribute('aria-busy','true');el.click();el.removeAttribute('aria-busy');el.setAttribute('aria-disabled','true');el.click();el.removeAttribute('aria-disabled');return window.__guardedActionCount;});assert.equal(guarded,0,'Busy and aria-disabled actions cannot activate');
  });
  await test('Workspace source selection, preferences, sidebar and profile interactions remain functional',async()=>{
   await page.goto(base+'summarizer.php',{waitUntil:'domcontentloaded'});await page.locator('[data-source-choice="text"]').click();await page.locator('#text').fill('');assert(await page.locator('.summary-submit-button').isDisabled());
   await page.locator('#text').fill(fixture.source);assert(await page.locator('.summary-submit-button').isEnabled());
   await page.locator('[data-source-choice="url"]').click();assert(await page.locator('#source_url').isVisible());await page.locator('[data-source-choice="text"]').click();assert.equal(await page.locator('#text').inputValue(),fixture.source);
   await page.locator('#sidebar-account-toggle').click();assert(await page.locator('#sidebar-account-menu').isVisible());await page.keyboard.press('Escape');assert(await page.locator('#sidebar-account-menu').isHidden());
   await page.setViewportSize({width:390,height:844});await page.locator('#sidebar-open').click();assert.equal(await page.locator('html').getAttribute('data-sidebar-open'),'true');await page.keyboard.press('Escape');assert.equal(await page.locator('html').getAttribute('data-sidebar-open'),'false');
   await page.goto(base+'profile.php',{waitUntil:'domcontentloaded'});const toggle=page.locator('.pw-toggle').first();if(await toggle.count()){await toggle.click();assert.equal(await page.locator('.profile-deactivation input[type="text"]').count(),1);await toggle.click();}
   const appearance=page.locator('select[data-theme-preference]');assert.equal(await appearance.count(),1);await appearance.selectOption('light');assert.equal(await page.locator('html').getAttribute('data-theme'),'light');await appearance.selectOption('dark');assert.equal(await page.locator('html').getAttribute('data-theme'),'dark');
  });
  await test('Result copy action and export controls remain connected',async()=>{
   await page.goto(base+`result.php?id=${fixture.summary_id}&share=${fixture.share}`,{waitUntil:'domcontentloaded'});
   assert(await page.locator('#copy-summary-btn').isEnabled());await page.locator('#copy-summary-btn').click();await page.waitForFunction(()=>document.getElementById('summary-status')?.textContent.includes('copied'));
   assert(await page.locator('#export-pdf-btn').isEnabled());
  });
  await test('Dashboard charts update shared colors immediately on a theme event',async()=>{
   await page.setViewportSize({width:1440,height:1000});await page.goto(base+'dashboard.php',{waitUntil:'domcontentloaded'});
   await page.waitForFunction(()=>window.Chart&&Object.keys(Chart.instances).length>0);
   const changed=await page.evaluate(()=>{window.LightTheme.set('light');const before=Object.values(Chart.instances).map(c=>c.options.plugins?.tooltip?.backgroundColor);window.LightTheme.set('dark');const after=Object.values(Chart.instances).map(c=>c.options.plugins?.tooltip?.backgroundColor);return{before,after};});
   assert(changed.before.length);assert.notDeepEqual(changed.before,changed.after,'Charts use refreshed surface tokens');
  });
  await test('Existing invalid-CSRF protection still rejects generation before work begins',async()=>{
   const response=await context.request.post(base+'summarize.php',{headers:{Accept:'application/json'},form:{csrf_token:'invalid',source_type:'text',original_text:fixture.source}});assert.equal(response.status(),403);assert.equal((await response.json()).success,false);
  });
  await context.close();
  const blocked=await contextFor('guest','system',390,{colorScheme:'dark'});
  await blocked.addInitScript(()=>{Object.defineProperty(Storage.prototype,'getItem',{value(){throw new DOMException('Blocked','SecurityError');}});Object.defineProperty(Storage.prototype,'setItem',{value(){throw new DOMException('Blocked','SecurityError');}});Object.defineProperty(Storage.prototype,'removeItem',{value(){throw new DOMException('Blocked','SecurityError');}});});
  const bp=await blocked.newPage();
  await test('Blocked browser storage preserves system detection and in-page theme controls',async()=>{
   await bp.goto(base+'login.php',{waitUntil:'domcontentloaded'});assert.equal(await bp.locator('html').getAttribute('data-theme'),'dark');await bp.evaluate(()=>window.LightTheme.set('light'));assert.equal(await bp.locator('html').getAttribute('data-theme'),'light');assert.equal(await bp.locator('body').getAttribute('data-theme'),'light');
  });
  await blocked.close();
 }catch(error){fail('Browser harness completed',error);}
 finally{
  if(browser)await browser.close();
  const actionConflicts=pages.flatMap(p=>(p.actions?.mismatches||[]).map(m=>({name:p.name,theme:p.theme,width:p.width,...m})));
  const summary={recorded_at:new Date().toISOString(),pages:pages.length,button_instances:pages.reduce((sum,p)=>sum+(p.actions?.count||0),0),action_conflicts:actionConflicts.length,passed:checks.filter(c=>c.passed).length,failed:checks.filter(c=>!c.passed).length,checks,synthetic_fixture:true,processing_network:'Controlled pending response; no NLP request executed.',fonts:'External Google font stylesheets replaced with empty CSS; bundled/system font fallbacks used.',desktop_width:1440,mobile_width:390};
  fs.writeFileSync(path.join(reportDir,foundationOnly?'foundation-results.json':'browser-results.json'),JSON.stringify(summary,null,2));if(!foundationOnly){fs.writeFileSync(path.join(reportDir,'page-measurements.json'),JSON.stringify(pages,null,2));fs.writeFileSync(path.join(reportDir,'action-conflicts.json'),JSON.stringify(actionConflicts,null,2));}
  console.log(`Design-system QA: ${summary.passed} passed, ${summary.failed} failed; ${summary.pages} page/theme/layout samples.`);if(summary.failed)process.exitCode=1;
 }
})();
