const fs=require('node:fs');
const assert=require('node:assert/strict');
const {execFileSync}=require('node:child_process');
const probe=(...args)=>execFileSync('C:/xampp/php/php.exe',['scripts/qa/registry_probe.php',...args],{encoding:'utf8'});
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'C:/Users/Robert Martin/AppData/Roaming/npm/node_modules/omniroute/node_modules/playwright');
(async()=>{
 const checks=[];let browser;
 try{
  const exe=['C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe','C:/Program Files/Google/Chrome/Application/chrome.exe'].find(fs.existsSync);
  browser=await chromium.launch({headless:true,...(exe?{executablePath:exe}:{})});
  const ctx=await browser.newContext({viewport:{width:390,height:844}});
  // Remote typography is not required for the local workflows under test.
  await ctx.route('https://fonts.googleapis.com/**',r=>r.fulfill({contentType:'text/css',body:''}));
  await ctx.route('https://fonts.gstatic.com/**',r=>r.abort());
  // Exercise the application's existing WAV fallback without depending on OS speech voices.
  await ctx.addInitScript(()=>Object.defineProperty(window,'SpeechSynthesisUtterance',{value:undefined}));
  const page=await ctx.newPage();
  const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto('http://127.0.0.1:58139/register.php',{waitUntil:'domcontentloaded'});
  assert.equal(await page.locator('input[type=file],input[name=full_name]').count(),0);
  assert(await page.locator('[name=student_number]').isVisible());
  checks.push('mobile student-ID form has no name or image requirements');
  assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
  checks.push('mobile registration has no horizontal overflow');
  await page.goto('http://127.0.0.1:58139/login.php',{waitUntil:'domcontentloaded'});
  await page.locator('#username').fill('qa_existing');await page.locator('#password').fill('QaNewStrong123!');
  await Promise.all([page.waitForURL(/index\.php/),page.locator('#btn-submit-auth').click()]);
  checks.push('real browser password login');
  await page.goto('http://127.0.0.1:58139/dashboard.php',{waitUntil:'domcontentloaded'});
  assert(!(await page.content()).includes('Fatal error'));checks.push('authenticated dashboard renders');
  await page.goto('http://127.0.0.1:58139/history.php',{waitUntil:'domcontentloaded'});
  assert(!(await page.content()).includes('Fatal error'));checks.push('history renders');
  await page.goto('http://127.0.0.1:58139/login.php',{waitUntil:'domcontentloaded'});
  await page.locator('#username').fill('PEN2023-02071');await page.locator('#password').fill('QaStrong123!');
  await Promise.all([page.waitForURL(/index\.php/),page.locator('#btn-submit-auth').click()]);
  checks.push('browser login using student ID');
  await page.goto('http://127.0.0.1:58139/history.php',{waitUntil:'domcontentloaded'});
  await page.locator('a[href*="result.php?id="]').first().click();
  await page.locator('#play-audio-btn').click();
  await page.waitForFunction(()=>{const audio=document.getElementById('summary-audio-player');return audio&&audio.readyState>=2&&audio.duration>0;},null,{timeout:90000});
  checks.push('existing audio button WAV fallback decoded by browser (Web Speech unavailable simulated)');
  await page.screenshot({path:'docs/qa/browser-playback.png',fullPage:false});
  const adminContext=await browser.newContext();const adminPage=await adminContext.newPage();
  await adminContext.route('https://fonts.googleapis.com/**',r=>r.fulfill({contentType:'text/css',body:''}));
  await adminContext.route('https://fonts.gstatic.com/**',r=>r.abort());
  await adminPage.goto('http://127.0.0.1:58139/login.php?context=admin',{waitUntil:'domcontentloaded'});
  await adminPage.locator('#username').fill('qa_admin');await adminPage.locator('#password').fill('QaStrong123!');
  await adminPage.locator('#btn-submit-auth').click();
  await adminPage.locator('#admin-login-code').waitFor();
  const captured=JSON.parse(fs.readFileSync(process.env.QA_PRIVATE_DIR+'/mail.json','utf8')).filter(m=>m.to.includes('admin@example.invalid')).at(-1);
  await adminPage.locator('#admin-login-code').fill(captured.body.match(/\b\d{6}\b/)[0]);
  await Promise.all([adminPage.waitForURL(/admin_dashboard\.php/),adminPage.locator('#admin-code-form button[type=submit]').click()]);
  assert(await adminPage.locator('#student-verification').isVisible());
  checks.push('browser admin login with captured email second factor and verification dashboard');
  assert.equal(JSON.parse(probe('prepare-browser')).pending,true);
  await adminPage.locator('#student-enrollment-registry a').click();
  await adminPage.getByRole('heading',{name:'Student Enrollment Registry',exact:true}).waitFor();
  const ids=Array.from({length:1000},(_,i)=>`WEB-${String(i).padStart(6,'0')}`).join('\n');
  await adminPage.locator('#student-ids').fill(ids);
  assert((await adminPage.locator('#registry-detected').textContent()).startsWith('1000 IDs'));
  if (await adminPage.locator('#registry-period').count() > 0) {
    await adminPage.locator('#registry-period').fill('SYNTHETIC browser enrollment period');
    const until=new Date(Date.now()+60*86400000).toISOString().slice(0,10);
    await adminPage.locator('#registry-until').fill(until);
  }
  if (await adminPage.locator('[name=authorized_list]').count() > 0) {
    await adminPage.locator('[name=authorized_list]').check();
  }
  const importBtn = adminPage.getByRole('button', { name: 'Import', exact: true });
  if (await importBtn.count() > 0) {
    await Promise.all([adminPage.waitForURL(/admin_student_registry\.php/), importBtn.click()]);
  } else {
    await Promise.all([adminPage.waitForURL(/admin_student_registry\.php/), adminPage.getByRole('button', { name: 'Preview Import', exact: true }).click()]);
    if (await adminPage.locator('[name=confirm_import]').count() > 0) {
      await adminPage.locator('[name=confirm_import]').check();
      await adminPage.getByRole('button', { name: 'Confirm Import', exact: true }).click();
    }
  }
  await adminPage.getByRole('heading', { name: 'Import Results', exact: true }).waitFor();
  assert.equal((await adminPage.locator('[data-count=inserted_count]').textContent()).trim(),'1000');
  assert.equal((await adminPage.locator('[data-count=approved_count]').textContent()).trim(),'1');
  const pending=JSON.parse(probe('inspect','WEB-000000'));
  assert.equal(pending.web_registry_count,1000);assert.equal(pending.application.status,'verified');assert.equal(pending.application.approval_method,'registry_match');
  checks.push('browser confirms 1000 records and database proves pending registry_match approval');
  await adminPage.setViewportSize({width:390,height:844});
  assert(await adminPage.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
  checks.push('mobile registry remains within viewport with scrollable tables');
  await adminPage.screenshot({path:'docs/qa/browser-registry.png',fullPage:false});
  probe('reset-test-rate-limits');
  const studentContext=await browser.newContext();const studentPage=await studentContext.newPage();
  await studentContext.route('https://fonts.googleapis.com/**',r=>r.fulfill({contentType:'text/css',body:''}));
  await studentContext.route('https://fonts.gstatic.com/**',r=>r.abort());
  await studentPage.goto('http://127.0.0.1:58139/register.php',{waitUntil:'domcontentloaded'});
  await studentPage.locator('#student_number').fill('WEB-000001');await studentPage.locator('#password').fill('QaStrong123!');await studentPage.locator('#confirm_password').fill('QaStrong123!');await studentPage.locator('#terms_accept').check();
  await Promise.all([studentPage.waitForURL(/student_verification\.php/),studentPage.locator('#btn-submit-register').click()]);
  assert(await studentPage.getByRole('heading',{name:'Your Student Account Has Been Verified!',exact:true}).isVisible());
  assert.equal(JSON.parse(probe('inspect','WEB-000001')).application.approval_method,'registry_match');
  checks.push('browser student-ID-only registration automatically verified without email or further approval');
  await studentPage.goto('http://127.0.0.1:58139/logout.php');
  await studentPage.goto('http://127.0.0.1:58139/login.php',{waitUntil:'domcontentloaded'});
  await studentPage.locator('#username').fill('WEB-000001');await studentPage.locator('#password').fill('QaStrong123!');
  await Promise.all([studentPage.waitForURL(/index\.php/),studentPage.locator('#btn-submit-auth').click()]);
  checks.push('automatically approved student logs in using student ID and password');
  await studentPage.goto('http://127.0.0.1:58139/summarizer.php',{waitUntil:'domcontentloaded'});
  await studentPage.locator('[data-source-choice=text]').click();
  const source=fs.readFileSync('scripts/test_runtime_repair.py','utf8').match(/SOURCE\s*=\s*\"\"\"([\s\S]*?)\"\"\"/)[1];
  const title='QA registry browser generated summary';
  await studentPage.locator('#text').fill(source);await studentPage.locator('#document_title').fill(title);
  await studentPage.locator('#summarize-form button[type=submit]').click();
  await studentPage.waitForURL(/result\.php\?id=/,{timeout:240000});
  assert((await studentPage.content()).includes(title));assert.equal(JSON.parse(probe('verify-summary',title)).stored,1);
  checks.push('registry-approved browser student completes real NLP summary saved in MySQL');
  assert.deepEqual(errors,[]);checks.push('no browser script exceptions');
  fs.writeFileSync('docs/qa/browser-results.json',JSON.stringify({passed:true,checks},null,2));console.log(JSON.stringify({passed:true,checks}));
 }catch(e){fs.writeFileSync('docs/qa/browser-results.json',JSON.stringify({passed:false,checks,error:e.message},null,2));console.error(e.message);process.exitCode=1;}
 finally{if(browser)await browser.close();}
})();
