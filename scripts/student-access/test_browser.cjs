const fs=require('node:fs');
const path=require('node:path');
const assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'C:/Users/Robert Martin/AppData/Roaming/npm/node_modules/omniroute/node_modules/playwright');
const root=path.resolve(__dirname,'../..');
(async()=>{
 const installed=['C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe','C:/Program Files/Google/Chrome/Application/chrome.exe'].find(p=>fs.existsSync(p));
 const browser=await chromium.launch({headless:true,...(installed?{executablePath:installed}:{})});
 const report={recorded_utc:new Date().toISOString(),isolated_fixture:true,checks:[]};
 const check=(name,ok)=>{assert.ok(ok,name);report.checks.push({name,passed:true});console.log('PASS '+name);};
 try{
  const context=await browser.newContext({viewport:{width:390,height:844}});
  const page=await context.newPage();
  await page.goto('http://127.0.0.1:58139/register.php');
  check('mobile registration contains required accessible fields',await page.getByLabel('Full Student Name',{exact:true}).count()===1&&await page.getByLabel('Official Student Number',{exact:true}).count()===1);
  check('mobile registration has no horizontal overflow',await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
  await page.screenshot({path:path.join(root,'docs/student-number-access/registration-mobile.png'),fullPage:true});
  await page.goto('http://127.0.0.1:58139/summarizer.php');
  check('all six existing summary depths remain',await page.locator('button[data-depth]').count()===6);
  await context.request.get('http://127.0.0.1:58139/__fixture_exhaust',{headers:{'X-Fixture-Key':'isolated-fixture-only'}});
  await page.reload();
  check('exhausted counter and encouragement visible',await page.getByText("You've Used All 3 Free Summaries!",{exact:true}).isVisible());
  check('exhausted form cannot be submitted in browser',!(await page.locator('#summarize-form').isVisible()));
  await page.screenshot({path:path.join(root,'docs/student-number-access/trial-exhausted-mobile.png'),fullPage:true});
  await page.setViewportSize({width:1366,height:900});
  await page.evaluate(()=>document.documentElement.setAttribute('data-theme','dark'));
  await page.screenshot({path:path.join(root,'docs/student-number-access/trial-exhausted-dark.png'),fullPage:true});
  check('desktop dark trial text differs from surface',await page.locator('#student-trial-counter').evaluate(el=>getComputedStyle(el).color!==getComputedStyle(el.closest('section')).backgroundColor));
  const noJs=await browser.newContext({javaScriptEnabled:false});const plain=await noJs.newPage();
  await plain.goto('http://127.0.0.1:58139/summarizer.php');
  check('durable trial cookie issued without JavaScript',(await noJs.cookies()).some(c=>c.name==='LIGHT_TRIAL'&&c.httpOnly));
  await noJs.request.get('http://127.0.0.1:58139/__fixture_exhaust',{headers:{'X-Fixture-Key':'isolated-fixture-only'}});await plain.reload();
  check('exhaustion interface enforced without JavaScript',!(await plain.locator('#summarize-form').isVisible()));
 }finally{await browser.close();}
 report.passed=report.checks.length;report.failed=0;
 fs.writeFileSync(path.join(root,'docs/student-number-access/tests-browser.json'),JSON.stringify(report,null,2)+'\n');
})().catch(e=>{console.error(e);process.exit(1);});
