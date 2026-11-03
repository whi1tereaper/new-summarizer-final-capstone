const fs=require('node:fs');
const path=require('node:path');
const assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'C:/Users/Robert Martin/AppData/Roaming/npm/node_modules/omniroute/node_modules/playwright');
const fixture=JSON.parse(fs.readFileSync(process.env.QA_AUDIT_FIXTURE,'utf8'));
const report=process.env.QA_AUDIT_REPORT;
const base='http://127.0.0.1:58142/';
const checks=[];
const record=name=>{checks.push({name,passed:true});fs.writeFileSync(path.join(report,'browser.json'),JSON.stringify(checks,null,2));console.log('PASS '+name);};
(async()=>{
 let browser;
 try{
  browser=await chromium.launch({headless:true,executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});
  const context=await browser.newContext({viewport:{width:1506,height:1000}});
  await context.addCookies([{name:'LIGHT_QA_SESSID',value:fixture.sessions.admin,url:base}]);
  await context.route('https://fonts.googleapis.com/**',route=>route.fulfill({contentType:'text/css',body:''}));
  await context.route('https://fonts.gstatic.com/**',route=>route.abort());
  const page=await context.newPage();const errors=[];page.on('pageerror',error=>errors.push(error.message));
  assert.equal((await page.goto(base+'admin_audit_logs.php',{waitUntil:'domcontentloaded'})).status(),200);
  await page.getByRole('heading',{name:'Audit logs',exact:true}).waitFor();
  assert.equal(await page.locator('.audit-filters label').count(),5);
  assert.equal(await page.locator('.audit-table tbody tr[data-audit-source]').count(),50);
  record('real audit page shows labelled filters and a capped 50-event page');
  await page.locator('#audit-source').selectOption('registry');await page.getByRole('button',{name:'Apply filters'}).click();
  await page.waitForURL('**/*source=registry*');
  assert(await page.locator('tr[data-audit-source="registry"]').count());assert.equal(await page.locator('tr[data-audit-source="admin"]').count(),0);
  assert((await page.locator('.audit-table').textContent()).includes('Student account registered'));
  assert(!(await page.content()).includes('b'.repeat(64)));
  record('source filter exposes real Student ID events without identity fingerprints');
  await page.locator('#audit-action').selectOption('registration_rejected');await page.getByRole('button',{name:'Apply filters'}).click();await page.waitForURL('**/*action=registration_rejected*');
  assert.equal(await page.locator('.audit-event-code').count(),18);assert((await page.locator('#audit-result-count').textContent()).includes('18'));
  record('combined source and event filters display the actual rejection count');
  await page.getByRole('link',{name:'Reset',exact:true}).click();await page.waitForURL('**/admin_audit_logs.php');
  await page.locator('#audit-action').selectOption('qa_pagination');await page.getByRole('button',{name:'Apply filters'}).click();await page.waitForURL('**/*action=qa_pagination*');
  const first=await page.locator('tr[data-audit-id]').evaluateAll(nodes=>nodes.map(node=>node.dataset.auditSource+':'+node.dataset.auditId));
  await page.getByRole('link',{name:'Next →',exact:true}).click();await page.waitForURL('**/*page=2*');
  assert.equal(await page.locator('tr[data-audit-id]').count(),11);assert.equal(await page.locator('#audit-action').inputValue(),'qa_pagination');
  const second=await page.locator('tr[data-audit-id]').evaluateAll(nodes=>nodes.map(node=>node.dataset.auditSource+':'+node.dataset.auditId));assert(!first.some(key=>second.includes(key)));
  record('Next page preserves filters and has no repeated same-second events');
  await page.goto(base+'admin_audit_logs.php?action=qa_escaped',{waitUntil:'domcontentloaded'});
  assert((await page.locator('.audit-details').textContent()).includes('<script>window.auditXss=1</script>'));
  assert.equal(await page.evaluate(()=>window.auditXss),undefined);assert.equal(await page.locator('.audit-table script').count(),0);
  record('stored HTML/script content is escaped and cannot execute');
  await page.goto(base+'admin_audit_logs.php?action=admin_deleted_user',{waitUntil:'domcontentloaded'});
  assert((await page.locator('.audit-table').textContent()).includes('Account deactivated (legacy)'));assert((await page.locator('.audit-table').textContent()).includes('Account permanently deleted (legacy)'));
  const disclosure=page.locator('.audit-browser').first();await disclosure.locator('summary').focus();await disclosure.locator('summary').press('Enter');assert(await disclosure.locator('p').isVisible());
  record('legacy deletion and deactivation labels differ and browser details open by keyboard');
  await page.goto(base+'admin_audit_logs.php?action=not_an_event',{waitUntil:'domcontentloaded'});assert(await page.getByText('No matching events.',{exact:true}).isVisible());
  record('empty-filter results have a clear recovery message');
  assert.equal((await page.goto(base+'admin_audit_logs.php?date_from=2026-02-30',{waitUntil:'domcontentloaded'})).status(),400);assert(await page.getByRole('alert').isVisible());
  record('invalid date filters return an accessible validation error');
  await page.goto(base+'admin_audit_logs.php?source=registry&date_from=2026-02-30',{waitUntil:'domcontentloaded'});
  assert.equal(await page.locator('#audit-source').inputValue(),'registry');record('validation errors preserve the other safe filter selections');
  const today=new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Manila',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
  await page.goto(base+'admin_audit_logs.php?date_from='+today+'&date_to='+today,{waitUntil:'domcontentloaded'});
  for(const theme of ['light','dark']){
   await page.evaluate(theme=>{document.documentElement.dataset.theme=theme;document.body.dataset.theme=theme;},theme);
   for(const width of [1506,768,390,320]){
    await page.setViewportSize({width,height:1000});
    assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
    assert(await page.locator('.audit-panel').evaluate(el=>el.scrollWidth<=el.clientWidth+1));
    assert(await page.locator('.audit-table-scroll').evaluate(el=>el.scrollWidth>el.clientWidth||innerWidth>=1200));
    record(theme+' audit layout at '+width+'px keeps wide rows locally scrollable without page overflow');
    if(width===1506||width===390)await page.screenshot({path:path.join(report,'audit-'+theme+'-'+width+'.png'),fullPage:false});
   }
  }
  assert.deepEqual(errors,[]);record('filter navigation and responsive layouts have no JavaScript exceptions');
 }catch(error){checks.push({name:'audit browser: '+error.message.slice(0,240),passed:false});console.error(error.stack);process.exitCode=1;}
 finally{fs.writeFileSync(path.join(report,'browser.json'),JSON.stringify(checks,null,2));if(browser)await browser.close();}
})();
