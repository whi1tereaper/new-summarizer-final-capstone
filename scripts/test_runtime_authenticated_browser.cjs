const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const cp = require('node:child_process');
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'C:/Users/Robert Martin/AppData/Roaming/npm/node_modules/omniroute/node_modules/playwright');
const base = process.env.LIGHT_TEST_URL || 'http://ai-summarizer.local/app/public/';
const identity = {username:'qa_runtime_'+crypto.randomBytes(6).toString('hex'),password:'Qa!'+crypto.randomBytes(18).toString('hex')};
const source = 'A city library extends evening opening hours\n\nThe city library announced extended evening opening hours to improve access for working residents. The council approved a six month pilot across three branches. Managers surveyed 640 residents before recommending the schedule. The survey found that 58 percent wanted evening access, but the voluntary sample may not represent every neighborhood. Staffing costs are estimated at 24000 dollars. No additional borrowing fees will be charged during the pilot. Officials will publish monthly reports and review costs before deciding whether the extended schedule should continue.';
function fixture(action, data) {
  const r=cp.spawnSync('C:/xampp/php/php.exe',['scripts/runtime_browser_account.php',action],{input:JSON.stringify(data),encoding:'utf8'});
  assert.equal(r.status,0,'isolated fixture '+action+': '+r.stderr);
  return JSON.parse(r.stdout);
}
(async()=>{
  const report={kind:'real_headless_authenticated_browser_on_xampp',checks:[],fixture_cleaned:false};
  let browser, account;
  try {
    account=fixture('create',identity);
    const installed=['C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe','C:/Program Files/Google/Chrome/Application/chrome.exe'].find(p=>fs.existsSync(p));
    browser=await chromium.launch({headless:true,...(installed?{executablePath:installed}:{}),args:['--host-resolver-rules=MAP ai-summarizer.local 127.0.0.1']});
    const context=await browser.newContext();
    const page=await context.newPage();
    await page.goto(base+'login.php');
    await page.locator('#username').fill(identity.username);
    await page.locator('#password').fill(identity.password);
    await Promise.all([page.waitForURL(/terms\.php|dashboard\.php/, {timeout:30000}),page.locator('#btn-submit-auth').click()]);
    report.checks.push('real login with isolated local test account');
    if(page.url().includes('terms.php')) {
      await page.locator('#accept_terms_checkbox').check();
      await Promise.all([page.waitForURL(url=>!url.toString().includes('terms.php')),page.locator('#btn_accept_terms').click()]);
      report.checks.push('terms accepted through normal authenticated form');
    }
    await page.goto(base+'summarizer.php');
    await page.locator('[data-source-choice="text"]').click();
    await page.locator('#text').fill(source);
    const title='QA browser runtime '+crypto.randomBytes(5).toString('hex');
    await page.locator('#document_title').fill(title);
    await Promise.all([page.waitForURL(/processing\.php|result\.php\?id=\d+/, {timeout:30000,waitUntil:'domcontentloaded'}),page.locator('#summarize-form button[type="submit"]').click()]);
    if(page.url().includes('processing.php')) {
      await page.waitForFunction(()=>location.pathname.endsWith('result.php') || document.getElementById('processing-card')?.dataset.state==='error',{},{timeout:330000});
      assert(page.url().includes('result.php'),'processing returned a friendly failure instead of a result');
    }
    assert((await page.content()).includes(title));
    report.checks.push('browser submit, background execution and saved result retrieval');
    const resultUrl=page.url();
    await page.goto(base+'history.php');
    assert((await page.content()).includes(title),'saved item appears in authenticated history');
    report.checks.push('authenticated history persistence');
    const stranger=await browser.newContext();
    const other=await stranger.newPage();
    // The normal result page intentionally appends a bearer sharing token.
    // Test ownership without that token, and sharing separately with it.
    const privateUrl=new URL(resultUrl);
    privateUrl.searchParams.delete('share');
    await other.goto(privateUrl.toString());
    assert(!(await other.content()).includes(title),'foreign session cannot read test result');
    report.checks.push('foreign-session result isolation');
    await other.goto(resultUrl);
    assert((await other.content()).includes(title),'explicit share link retains existing behavior');
    report.checks.push('explicit bearer-link sharing preserved');
    report.passed=true;
  } catch(error) { report.passed=false; report.failure=error.message; process.exitCode=1; }
  finally {
    if(browser) await browser.close();
    if(account) { fixture('delete',{id:account.id,username:identity.username}); report.fixture_cleaned=true; }
    const target=path.resolve('docs/research/runtime-authenticated-browser.json');
    const attempts=path.resolve('docs/research/runtime-browser-attempts.jsonl');
    if(fs.existsSync(target)) fs.appendFileSync(attempts,JSON.stringify(JSON.parse(fs.readFileSync(target,'utf8')))+'\n');
    fs.appendFileSync(attempts,JSON.stringify(report)+'\n');
    fs.writeFileSync(target,JSON.stringify(report,null,2)+'\n');
    console.log(JSON.stringify(report,null,2));
  }
})();
