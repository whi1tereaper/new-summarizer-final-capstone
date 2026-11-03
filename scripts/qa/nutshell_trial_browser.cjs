const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'C:/Users/Robert Martin/AppData/Roaming/npm/node_modules/omniroute/node_modules/playwright');
const checks = [];
const record = name => { checks.push({name, passed: true}); console.log('PASS ' + name); };
(async () => {
  let browser;
  try {
    browser = await chromium.launch({headless: true, executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});
    const page = await browser.newPage({viewport: {width: 390, height: 844}});
    await page.route('https://fonts.googleapis.com/**', route => route.fulfill({contentType: 'text/css', body: ''}));
    await page.route('https://fonts.gstatic.com/**', route => route.abort());
    await page.goto('http://127.0.0.1:58139/summarizer.php', {waitUntil: 'domcontentloaded'});
    await page.locator('[data-source-choice="text"]').click();
    await page.locator('#text').fill(fs.readFileSync(process.env.QA_NUTSHELL_SOURCE, 'utf8'));
    assert((await page.locator('#student-trial-counter').textContent()).includes('3 free summaries'));
    record('anonymous mobile visitor starts with three shared attempts');
    for (let remaining = 2; remaining >= 0; remaining--) {
      await page.locator('#btn-nutshell-action').click();
      await page.waitForFunction(n => document.getElementById('student-trial-counter').textContent.includes(n + ' free '), remaining);
      assert((await page.locator('#nutshell-text').textContent()).length > 0);
      assert(!page.url().includes('register.php'));
      record('guest Nutshell stays on the page and updates remaining attempts to ' + remaining);
    }
    assert(await page.locator('#student-trial-exhausted').isVisible());
    assert(await page.locator('#nutshell-text').isVisible());
    assert(await page.locator('#nutshell-copy-btn').isVisible());
    assert(!await page.locator('#btn-nutshell-action').isVisible());
    assert(!await page.locator('.summary-submit-button').isVisible());
    record('exhaustion hides generation controls but preserves readable copyable Nutshell');
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
    record('trial limit and result fit the mobile viewport');
    await page.screenshot({path: path.join(process.env.QA_NUTSHELL_REPORT, 'mobile-trial-result.png'), fullPage: true});
  } catch (error) {
    checks.push({name: 'Nutshell browser exception: ' + error.message.slice(0, 180), passed: false});
    console.error(error.message);process.exitCode = 1;
  } finally {
    fs.writeFileSync(path.join(process.env.QA_NUTSHELL_REPORT, 'browser.json'), JSON.stringify(checks, null, 2));
    if (browser) await browser.close();
  }
})();
