const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {execFileSync}=require('node:child_process');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'C:/Users/Robert Martin/AppData/Roaming/npm/node_modules/omniroute/node_modules/playwright');
const fixture=JSON.parse(fs.readFileSync(process.env.QA_STORAGE_FIXTURE,'utf8'));
const report=process.env.QA_STORAGE_REPORT,base='http://127.0.0.1:58144/',checks=[];
const record=name=>{checks.push({name,passed:true});fs.writeFileSync(path.join(report,'browser.json'),JSON.stringify(checks,null,2));console.log('PASS '+name);};
const probe=action=>execFileSync('C:/php83/php.exe',['scripts/qa/storage_probe.php',action],{env:process.env});
(async()=>{let browser;
try{
 browser=await chromium.launch({headless:true,executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});
 const context=await browser.newContext({viewport:{width:1506,height:1000}});
 await context.addCookies([{name:'LIGHT_QA_SESSID',value:fixture.sessions.admin,url:base}]);
 await context.route('https://fonts.googleapis.com/**',r=>r.fulfill({contentType:'text/css',body:''}));await context.route('https://fonts.gstatic.com/**',r=>r.abort());
 const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
 assert.equal((await page.goto(base+'admin_dashboard.php',{waitUntil:'domcontentloaded'})).status(),200);
 const panel=page.locator('#storage-maintenance'),button=panel.locator('#storage-refresh');
 await panel.scrollIntoViewIfNeeded();assert.equal(await panel.locator('[data-storage-folder]').count(),6);
 assert.equal(await panel.locator('[data-storage-metric="saved_summaries"]').textContent(),'2');
 assert.equal(await panel.locator('[data-storage-folder="uploads"] [data-folder-files]').textContent(),'2');
 assert.equal(await button.evaluate(el=>getComputedStyle(el).minHeight),'44px');
 record('dashboard renders real MySQL counts six measured categories and usable refresh control');
 probe('add');await button.focus();await button.press('Enter');await page.getByText('Status refreshed from the system.',{exact:true}).waitFor();
 assert.equal(await panel.locator('[data-storage-metric="saved_summaries"]').textContent(),'3');
 assert.equal(await panel.locator('[data-storage-folder="uploads"] [data-folder-files]').textContent(),'3');
 record('keyboard refresh fetches changed MySQL and nested file counts without reloading');
 probe('remove');await button.click();await page.getByText('Status refreshed from the system.',{exact:true}).waitFor();
 assert.equal(await panel.locator('[data-storage-folder="uploads"] [data-folder-files]').textContent(),'2');record('refresh also reflects files removed by the system');
 let release;const gate=new Promise(resolve=>release=resolve);let calls=0;
 const delay=async route=>{calls++;await gate;await route.continue();};await page.route('**/admin_storage_status.php',delay);
 await button.click();await page.waitForFunction(()=>document.querySelector('#storage-refresh').disabled);
 assert.equal(await panel.getAttribute('aria-busy'),'true');await button.evaluate(el=>el.click());release();await page.getByText('Status refreshed from the system.',{exact:true}).waitFor();assert.equal(calls,1);await page.unroute('**/admin_storage_status.php',delay);
 record('in-flight refresh is disabled accessible and cannot send repeated requests');
 const previous=await panel.locator('.storage-metrics').textContent();const stamp=await panel.locator('#storage-checked-at').getAttribute('datetime');
 await page.route('**/admin_storage_status.php',r=>r.abort());await button.click();await page.waitForFunction(()=>document.querySelector('#storage-status-message').dataset.state==='error');
 assert.equal(await panel.locator('.storage-metrics').textContent(),previous);assert.equal(await panel.locator('#storage-checked-at').getAttribute('datetime'),stamp);assert(await button.isEnabled());await page.unroute('**/admin_storage_status.php');
 record('network interruption preserves last known values and timestamp with a retryable error');
 await page.route('**/admin_storage_status.php',r=>r.fulfill({status:403,contentType:'application/json',body:'{}'}));await button.click();await page.getByText('Your administrator session is unavailable. Sign in again to check status.',{exact:true}).waitFor();assert.equal(await panel.locator('.storage-metrics').textContent(),previous);await page.unroute('**/admin_storage_status.php');record('expired session is distinguished from a healthy zero measurement');
 await page.route('**/admin_storage_status.php',r=>r.fulfill({status:200,contentType:'application/json',body:'{"success":true}'}));await button.click();await page.waitForFunction(()=>document.querySelector('#storage-status-message').dataset.state==='error');assert.equal(await panel.locator('.storage-metrics').textContent(),previous);await page.unroute('**/admin_storage_status.php');record('malformed response cannot erase the last known measurements');
 const payload=(await (await context.request.get(base+'admin_storage_status.php')).json()).snapshot;
 payload.runtime.complete=false;payload.runtime.folders.models={...payload.runtime.folders.models,readable:false,complete:false,status:'missing'};payload.database.nutshells=null;payload.warnings=['<img src=x onerror="window.storageXss=1">'];
 await page.route('**/admin_storage_status.php',r=>r.fulfill({status:200,contentType:'application/json',body:JSON.stringify({success:true,snapshot:payload})}));await button.click();await page.waitForFunction(()=>document.querySelector('#storage-status-message').dataset.state==='warning');
 assert((await panel.locator('[data-storage-metric="runtime_bytes"]').textContent()).startsWith('At least '));assert.equal(await panel.locator('[data-storage-metric="nutshells"]').textContent(),'Unavailable');assert.equal(await panel.locator('[data-storage-folder="models"] [data-folder-bytes]').textContent(),'Unavailable');assert.equal(await page.evaluate(()=>window.storageXss),undefined);assert.equal(await panel.locator('#storage-status-message img').count(),0);await page.unroute('**/admin_storage_status.php');record('partial metrics stay explicit and warning content cannot execute HTML');
 await button.click();await page.getByText('Status refreshed from the system.',{exact:true}).waitFor();
 for(const theme of ['light','dark']){await page.evaluate(t=>{document.documentElement.dataset.theme=t;document.body.dataset.theme=t;},theme);
  for(const width of [1506,768,390,320]){await page.setViewportSize({width,height:1000});await panel.scrollIntoViewIfNeeded();
   assert(await panel.evaluate(el=>el.scrollWidth<=el.clientWidth+1));assert(await panel.evaluate(el=>el.getBoundingClientRect().right<=innerWidth+1));assert(await panel.locator('.storage-table-scroll').evaluate(el=>el.scrollWidth>el.clientWidth||innerWidth>=768));
   record(theme+' storage panel at '+width+'px confines wide rows to its accessible scroll region');
   if(width===1506||width===390)await panel.screenshot({path:path.join(report,'storage-'+theme+'-'+width+'.png')});
  }
 }
 assert.deepEqual(errors,[]);record('dashboard and all refresh states produce no JavaScript exceptions');
}catch(error){checks.push({name:'storage browser: '+error.message.slice(0,240),passed:false});console.error(error.stack);process.exitCode=1;}
finally{fs.writeFileSync(path.join(report,'browser.json'),JSON.stringify(checks,null,2));if(browser)await browser.close();}
})();
