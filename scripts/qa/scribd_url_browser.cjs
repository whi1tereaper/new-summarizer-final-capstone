const fs=require('node:fs');
const path=require('node:path');
const assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'C:/Users/Robert Martin/AppData/Roaming/npm/node_modules/omniroute/node_modules/playwright');
const report=process.env.QA_SCRIBD_REPORT;
const base='http://127.0.0.1:58143/';
const url='https://www.scribd.com/document/840282331/I-Dont-love-you-anymore-Brighten-Up-Your-Mind';
const checks=[];
const record=name=>{checks.push({name,passed:true});fs.writeFileSync(path.join(report,'browser.json'),JSON.stringify(checks,null,2));console.log('PASS '+name);};
(async()=>{
 let browser;
 try{
  browser=await chromium.launch({headless:true,executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});
  const page=await browser.newPage({viewport:{width:390,height:844}});const errors=[];page.on('pageerror',error=>errors.push(error.message));
  await page.route('https://fonts.googleapis.com/**',route=>route.fulfill({contentType:'text/css',body:''}));await page.route('https://fonts.gstatic.com/**',route=>route.abort());
  await page.goto(base+'summarizer.php',{waitUntil:'domcontentloaded'});
  await page.locator('[data-source-choice="url"]').click();await page.locator('#source_url').fill(url);
  await page.locator('.summary-submit-button').click();await page.waitForURL('**/processing.php');
  await page.waitForFunction(()=>document.getElementById('processing-error')?.dataset.errorCode==='URL_ACCESS_RESTRICTED',null,{timeout:45000});
  record('actual Scribd URL follows the real form, queued job and processing failure workflow');
  const message=await page.locator('#processing-error').textContent();
  assert(message.includes('upload its PDF/DOCX'));assert(message.includes('authorized access'));
  assert(!message.includes('The article content is unavailable.'));
  record('processing UI explains source access restrictions and the permitted upload/paste alternative');
  assert(!await page.locator('#processing-retry').isVisible());
  const access=await page.request.get(base+'access_status.php');assert.equal((await access.json()).remaining,3);
  record('source access failure keeps all three trial uses and does not suggest automatic retries');
  assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
  await page.screenshot({path:path.join(report,'scribd-access-guidance-mobile.png'),fullPage:true});
  await page.getByRole('link',{name:/Back to summary settings/}).click();await page.waitForURL('**/summarizer.php');
  assert.equal(await page.locator('#source_url').inputValue(),url);
  record('Back to settings preserves the entered URL for correction or replacement');
  assert.deepEqual(errors,[]);record('mobile recovery workflow has no JavaScript exceptions or page overflow');
 }catch(error){checks.push({name:'Scribd browser exception: '+error.message.slice(0,220),passed:false});console.error(error.stack);process.exitCode=1;}
 finally{fs.writeFileSync(path.join(report,'browser.json'),JSON.stringify(checks,null,2));if(browser)await browser.close();}
})();
