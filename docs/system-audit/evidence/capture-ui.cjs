const fs = require('fs');
const path = require('path');
const { chromium } = require('C:/Users/Robert Martin/AppData/Roaming/npm/node_modules/omniroute/node_modules/playwright');
const root = path.resolve(__dirname, '../../..');
const screenshotDir = path.join(root, 'docs/capstone-manuscript/screenshots');
fs.mkdirSync(screenshotDir, {recursive:true});
const origin = 'http://127.0.0.1:8765';
const cases = [
 ['landing','index.php'],['summarizer','summarizer.php'],['sign-in','login.php'],['register','register.php'],['password-recovery','forgot_password.php'],['password-reset','reset_password.php'],['terms','terms.php'],['accept-terms','accept_terms.php'],
 ['dashboard-empty','dashboard.php?fixture_role=user'],['profile-synthetic','profile.php?fixture_role=user'],['history-empty','history.php?fixture_role=user'],['analytics-empty','analytics.php?fixture_role=user'],['processing-synthetic','processing.php?fixture_pending=1'],['result-synthetic','result.php?id=900001'],['result-unavailable','result.php?id=900002'],['admin-dashboard-empty','admin_dashboard.php?fixture_role=admin'],['admin-audit-logs-empty','admin_audit_logs.php?fixture_role=admin'],['evaluation-denied','evaluate.php'],['evaluation-empty','evaluate.php?fixture_role=user']
];
const denialCases = ['dashboard.php','profile.php','history.php','analytics.php','analytics.php?action=data','admin_dashboard.php','admin_audit_logs.php','admin_db_health.php','admin_feedback_feed.php','evaluation.php','evaluation_export.php?run=1','admin_verify.php','admin_challenge_change.php','admin_challenge_setup.php','processing.php','result.php','tts_generate.php','tts_audio.php','api/qa.php'];
(async()=>{
 const browser=await chromium.launch({executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe', headless:true});
 const result={evidenceClass:'ISOLATED_UI_RENDER',dateManila:'2026-10-17',sourceRoot:root,origin,limitations:['Original PHP templates are rendered with APP_BOOTSTRAPPED set and a synthetic PDO fixture.','No live database, production authentication, model, audio, translation or email flow is tested.','Browser is restricted to loopback GET requests. External fonts, telemetry and backend calls are blocked.','Audit provenance banner is injected into screenshots only. Empty values are fixture data, not collected research results.'],pages:[],denials:[],interactions:[]};
 for(const viewport of [{name:'desktop',width:1440,height:1000},{name:'mobile',width:390,height:844}]){
  for(const [name,url] of cases){
   const ctx=await browser.newContext({viewport:{width:viewport.width,height:viewport.height},colorScheme:'light',reducedMotion:'reduce'});
   const pg=await ctx.newPage(); const events={consoleErrors:[],pageErrors:[],badResponses:[],blocked:[]};
   await ctx.route('**/*',async r=>{const req=r.request();if(!req.url().startsWith(origin+'/')||req.method()!=='GET'){events.blocked.push({url:req.url(),method:req.method()});await r.abort();}else await r.continue();});
   pg.on('console',m=>{if(m.type()==='error')events.consoleErrors.push(m.text())}); pg.on('pageerror',e=>events.pageErrors.push(e.message)); pg.on('response',r=>{if(r.status()>=400)events.badResponses.push({url:r.url(),status:r.status()})});
   let status=null,err=null;
   try {status=(await pg.goto(origin+'/'+url,{waitUntil:'networkidle',timeout:20000})).status();} catch(e){err=e.message;}
   const info=await pg.evaluate(()=>{
    const visible=e=>!!(e.offsetWidth||e.offsetHeight||e.getClientRects().length);
    const all=[...document.querySelectorAll('*')];
    const missingLabels=[...document.querySelectorAll('input:not([type=hidden]),textarea,select')].filter(visible).filter(e=>!e.labels?.length&&!e.getAttribute('aria-label')&&!e.getAttribute('aria-labelledby')).map(e=>({tag:e.tagName,id:e.id,name:e.name,type:e.type}));
    return {title:document.title,url:location.href,lang:document.documentElement.lang,headings:[...document.querySelectorAll('h1,h2,h3')].map(e=>({level:e.tagName,text:e.innerText.trim()})),mainCount:document.querySelectorAll('main').length,bodyWidth:document.body.scrollWidth,viewportWidth:innerWidth,horizontalOverflow:document.documentElement.scrollWidth>innerWidth+1,overflowingElements:all.filter(visible).filter(e=>{const r=e.getBoundingClientRect();return r.right>innerWidth+2||r.left < -2}).slice(0,15).map(e=>({tag:e.tagName,id:e.id,class:e.className})),missingLabels,imagesMissingAlt:[...document.images].filter(e=>!e.hasAttribute('alt')).map(e=>e.getAttribute('src')),imagesFailed:[...document.images].filter(e=>e.complete&&e.naturalWidth===0).map(e=>e.getAttribute('src')),negativeTabButtons:[...document.querySelectorAll('button[tabindex="-1"]')].filter(visible).map(e=>({id:e.id,label:e.getAttribute('aria-label')})),formActions:[...document.forms].map(f=>({method:f.method,action:f.getAttribute('action')})),bodyTextStart:document.body.innerText.slice(0,230)};
   });
   await pg.evaluate(()=>{const d=document.createElement('div');d.textContent='AUDIT ONLY: ISOLATED TEMPLATE • SYNTHETIC DATA • NO LIVE AUTHENTICATION';d.style.cssText='position:fixed;bottom:0;left:0;right:0;z-index:2147483647;background:#202020;color:#fff;padding:5px 8px;font:11px Arial;text-align:center;pointer-events:none';document.body.appendChild(d)});
   const file=`ui-${name}-${viewport.name}.png`;
   await pg.screenshot({path:path.join(screenshotDir,file),fullPage:true});
   result.pages.push({name,viewport:viewport.name,width:viewport.width,height:viewport.height,requestUrl:origin+'/'+url,status,error:err,screenshot:'docs/capstone-manuscript/screenshots/'+file,...info,...events});
   console.log(`${viewport.name} ${name} ${status} overflow=${info.horizontalOverflow} errors=${events.pageErrors.length}`);
   await ctx.close();
  }
 }
 const api=await browser.newContext();
 for(const route of denialCases){const response=await api.request.get(origin+'/'+route,{maxRedirects:0});result.denials.push({route,status:response.status(),location:response.headers().location||null,body:(await response.text()).replace(/<[^>]+>/g,' ').replace(/\s+/g,' ').slice(0,200),scope:'isolated direct guard execution with anonymous synthetic session; bootstrap/authentication untested'});}
 await api.close();
 const ctx=await browser.newContext({viewport:{width:390,height:844},reducedMotion:'reduce'});const pg=await ctx.newPage();await ctx.route('**/*',async r=>{if(!r.request().url().startsWith(origin+'/')||r.request().method()!=='GET')await r.abort();else await r.continue()});
 await pg.goto(origin+'/summarizer.php');
 await pg.locator('#nex-menu-toggle').click(); result.interactions.push({test:'Mobile menu opens',actual:await pg.locator('#nex-menu-toggle').getAttribute('aria-expanded'),expected:'true'});
 await pg.keyboard.press('Escape'); result.interactions.push({test:'Escape closes mobile menu',actual:await pg.locator('#nex-menu-toggle').getAttribute('aria-expanded'),expected:'false'});
 await pg.locator('[data-source-choice="text"]').click();result.interactions.push({test:'Text source selection',actual:await pg.locator('#source_type').inputValue(),expected:'text'});
 await pg.locator('[data-source-choice="url"]').click();result.interactions.push({test:'URL source selection',actual:await pg.locator('#source_type').inputValue(),expected:'url'});
 await pg.locator('[data-depth="comprehensive"]').click();result.interactions.push({test:'Depth selection',actual:await pg.locator('#summary_depth').inputValue(),expected:'comprehensive'});
 await pg.locator('#output_format').selectOption('structured');result.interactions.push({test:'Output format selection',actual:await pg.locator('#output_format').inputValue(),expected:'structured'});
 await pg.locator('#analysis_mode').selectOption('academic');result.interactions.push({test:'Analysis mode selection',actual:await pg.locator('#analysis_mode').inputValue(),expected:'academic'});
 await pg.locator('button[type="submit"]').click();result.interactions.push({test:'Empty URL source rejected before request',actual:await pg.locator('#source-error').isVisible(),expected:true,message:await pg.locator('#source-error').innerText()});
 await pg.goto(origin+'/register.php');await pg.locator('#password').fill('SyntheticExample123');await pg.locator('#toggle-password').click();result.interactions.push({test:'Password visibility click',actual:await pg.locator('#password').getAttribute('type'),expected:'text'});
 await pg.goto(origin+'/summarizer.php');await pg.locator('#btn-nutshell-action').click();result.interactions.push({test:'Guest nutshell registration redirect',actual:new URL(pg.url()).pathname,expected:'/register.php'});
 await ctx.close(); await browser.close();
 fs.writeFileSync(path.join(root,'docs/system-audit/browser-readonly-results.json'),JSON.stringify(result,null,2));
})().catch(e=>{console.error(e);process.exitCode=1});
