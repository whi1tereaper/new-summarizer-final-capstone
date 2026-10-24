const fs=require('node:fs');
const path=require('node:path');
const assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'C:/Users/Robert Martin/AppData/Roaming/npm/node_modules/omniroute/node_modules/playwright');
(async()=>{
 const installed=['C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe','C:/Program Files/Google/Chrome/Application/chrome.exe'].find(p=>fs.existsSync(p));
 const browser=await chromium.launch({headless:true,...(installed?{executablePath:installed}:{})});
 const report={recorded_utc:new Date().toISOString(),live_localhost:true,nlp_executed:false,account_writes:false,checks:[]};
 const check=(name,ok)=>{assert.ok(ok,name);report.checks.push({name,passed:true});};
 const base='http://localhost/ai-summarizer-123%20-%20quality%20upg/app/public/';
 try{
  const context=await browser.newContext({viewport:{width:1525,height:900},colorScheme:'dark'});
  const page=await context.newPage();
  await page.goto(base+'summarizer.php',{waitUntil:'domcontentloaded'});
  check('dark theme initialized consistently',await page.evaluate(()=>document.documentElement.dataset.theme==='dark'&&document.body.dataset.theme==='dark'));
  check('setup failure absent',!await page.getByText('Summarization access is unavailable until the access-control setup is complete.',{exact:true}).count());
  check('three-success guest counter visible',await page.getByText('3 free summaries remaining',{exact:true}).isVisible());
  check('summarization form restored',await page.locator('#summarize-form').isVisible());
  check('all six summary depths preserved',await page.locator('button[data-depth]').count()===6);
  const status=await context.request.get(base+'access_status.php');
  check('actual Apache status endpoint succeeds',status.status()===200&&(await status.json()).remaining===3);
  await page.reload({waitUntil:'domcontentloaded'});
  check('refresh does not debit allowance',await page.getByText('3 free summaries remaining',{exact:true}).isVisible());
  check('quota identifier remains HttpOnly',(await context.cookies()).find(cookie=>cookie.name==='LIGHT_TRIAL')?.httpOnly===true);
  const directory=path.resolve(__dirname,'../../docs/student-number-access');
  await page.screenshot({path:path.join(directory,'access-restored-dark.png'),fullPage:true});
  await page.setViewportSize({width:390,height:844});
  check('mobile form visible',await page.locator('#summarize-form').isVisible());
  check('mobile layout has no horizontal overflow',await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
  const plain=await browser.newContext({javaScriptEnabled:false});
  const plainPage=await plain.newPage();await plainPage.goto(base+'summarizer.php',{waitUntil:'domcontentloaded'});
  check('form visible without JavaScript',await plainPage.locator('#summarize-form').isVisible());
  report.passed=report.checks.length;report.failed=0;
  fs.writeFileSync(path.join(directory,'tests-live-access-repair.json'),JSON.stringify(report,null,2)+'\n');
  console.log(JSON.stringify(report));
 }finally{await browser.close();}
})().catch(error=>{console.error(error.name+': '+error.message);process.exitCode=1;});
