// Browser transport fault injection. Forwarded generations use the real isolated
// PHP endpoint/MySQL database; the NLP worker is explicitly controlled by QA.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'C:/Users/Robert Martin/AppData/Roaming/npm/node_modules/omniroute/node_modules/playwright');
const checks = [];
const record = name => { checks.push({name, passed: true}); console.log('PASS ' + name); fs.writeFileSync(path.join(report, 'browser-recovery.json'), JSON.stringify(checks, null, 2)); };
const source = fs.readFileSync(process.env.QA_NUTSHELL_SOURCE, 'utf8');
const report = process.env.QA_NUTSHELL_REPORT;
const fields = request => {
  const body = request.postData() || '';
  const value = name => body.match(new RegExp('name="' + name + '"\\r\\n\\r\\n([^\\r]*)'))?.[1] || '';
  return {action: value('action') || 'generate', key: value('request_key')};
};
const html = (route, status) => route.fulfill({status, contentType: 'text/html', body: '<html><body>Simulated gateway error</body></html>'});
const waitError = (page, code) => page.waitForFunction(code => document.getElementById('nutshell-panel')?.dataset.errorCode === code && document.getElementById('nutshell-loading')?.style.display === 'none', code);
const waitRemaining = (page, count) => page.waitForFunction(n => document.getElementById('student-trial-counter').textContent.includes(n + ' free '), count);
(async () => {
  let browser;
  const pageErrors = [];
  async function visitor() {
    const context = await browser.newContext({viewport: {width: 390, height: 844}});
    await context.route('https://fonts.googleapis.com/**', route => route.fulfill({contentType: 'text/css', body: ''}));
    await context.route('https://fonts.gstatic.com/**', route => route.abort());
    const page = await context.newPage();page.on('pageerror', error => pageErrors.push(error.message));
    await page.goto('http://127.0.0.1:58139/summarizer.php', {waitUntil: 'domcontentloaded'});
    await page.locator('[data-source-choice="text"]').click();await page.locator('#text').fill(source);
    return page;
  }
  try {
    browser = await chromium.launch({headless: true, executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});
    for (const [status, code] of [[504, 'PROCESSING_TIMEOUT'], [502, 'WORKER_UNAVAILABLE'], [200, 'PROCESSING_FAILED']]) {
      const page = await visitor();let fail = true;const keys = [];let lookups = 0;
      await page.route('**/nutshell_generate.php', async route => {
        const data = fields(route.request());
        if (data.action === 'status') { lookups++;return route.continue(); }
        keys.push(data.key);if (fail) { fail = false;return html(route, status); }return route.continue();
      });
      await page.locator('#btn-nutshell-action').click();await waitError(page, code);
      assert.equal(lookups, 1);await waitRemaining(page, 3);
      assert(!(await page.locator('#nutshell-error-message').textContent()).includes('Simulated gateway'));
      record('HTTP ' + status + ' non-JSON reply is classified as ' + code + ', not a connection error');
      if (status === 504) {
        await page.locator('#nutshell-retry-btn').click();await waitRemaining(page, 2);
        assert.equal(keys.length, 2);assert.equal(keys[0], keys[1]);
        record('not-found status preserves the same idempotency key on manual retry');
      }
      await page.context().close();
    }
    for (const transport of ['gateway', 'disconnect']) {
      const page = await visitor();let generations = 0;let lookups = 0;
      await page.route('**/nutshell_generate.php', async route => {
        if (fields(route.request()).action === 'status') { lookups++;return route.continue(); }
        generations++;const reply = await route.fetch();assert(reply.ok());
        if (transport === 'gateway') return html(route, 504);
        return route.abort('connectionreset');
      });
      await page.locator('#btn-nutshell-action').click();await waitRemaining(page, 2);
      assert(await page.locator('#nutshell-text').isVisible());assert.equal(generations, 1);assert.equal(lookups, 1);
      record('completed result recovered after ' + transport + ' response loss with one generation and one charge');
      await page.context().close();
    }
    {
      const page = await visitor();const keys = [];
      await page.route('**/nutshell_generate.php', async route => {
        assert.equal(fields(route.request()).action, 'generate');keys.push(fields(route.request()).key);
        if (keys.length === 1) return route.fulfill({status: 504, contentType: 'application/json', body: JSON.stringify({success: false, request_state: 'failed', failure: {code: 'PROCESSING_TIMEOUT'}})});
        return route.continue();
      });
      await page.locator('#btn-nutshell-action').click();await waitError(page, 'PROCESSING_TIMEOUT');
      assert.equal(await page.locator('#nutshell-retry-btn').textContent(), 'Try again');await waitRemaining(page, 3);
      await page.locator('#nutshell-retry-btn').click();await waitRemaining(page, 2);
      assert.equal(keys.length, 2);assert.notEqual(keys[0], keys[1]);
      record('confirmed failed generation retries using a fresh key instead of replaying its failure forever');
      await page.context().close();
    }
    {
      const page = await visitor();let generations = 0;let lookups = 0;
      await page.route('**/nutshell_generate.php', async route => {
        if (fields(route.request()).action === 'status') {
          lookups++;
          if (lookups === 1) return route.fulfill({status: 202, contentType: 'application/json', body: JSON.stringify({success: false, request_state: 'processing', status: 'processing'})});
          return route.continue();
        }
        generations++;assert((await route.fetch()).ok());return html(route, 504);
      });
      await page.locator('#btn-nutshell-action').click();await waitError(page, 'ACCESS_BUSY');
      assert.equal(await page.locator('#nutshell-retry-btn').textContent(), 'Check status');
      await page.locator('#nutshell-retry-btn').click();await page.locator('#nutshell-text').waitFor({state: 'visible'});
      assert.equal(generations, 1);assert.equal(lookups, 2);await waitRemaining(page, 2);
      record('in-flight status offers read-only recovery without resubmitting source or starting another job');
      await page.context().close();
    }
    {
      const page = await visitor();let generations = 0;let lookups = 0;
      await page.route('**/nutshell_generate.php', async route => {
        if (fields(route.request()).action === 'status') { lookups++;return lookups === 1 ? route.abort('connectionreset') : route.continue(); }
        generations++;if (generations < 3) return route.continue();
        assert((await route.fetch()).ok());return route.abort('connectionreset');
      });
      for (const count of [2, 1]) { await page.locator('#btn-nutshell-action').click();await waitRemaining(page, count); }
      await page.locator('#btn-nutshell-action').click();await waitError(page, 'NETWORK_INTERRUPTED');await waitRemaining(page, 0);
      assert(await page.locator('#nutshell-retry-btn').isVisible());
      assert.equal(await page.locator('#nutshell-retry-btn').textContent(), 'Check status');
      record('last trial response loss leaves Check status available despite zero remaining uses');
      await page.reload({waitUntil: 'domcontentloaded'});await page.locator('#nutshell-retry-btn').waitFor({state: 'visible'});
      await page.locator('#nutshell-retry-btn').click();await page.locator('#nutshell-text').waitFor({state: 'visible'});
      assert.equal(generations, 3);assert.equal(lookups, 2);await waitRemaining(page, 0);
      assert(await page.locator('#nutshell-copy-btn').isVisible());
      record('reload restores uncertain request key and recovers the third result without reupload or extra charge');
      await page.screenshot({path: path.join(report, 'mobile-recovered-third-result.png'), fullPage: true});
      await page.context().close();
    }
    assert.deepEqual(pageErrors, []);record('recovery workflows have no browser JavaScript exceptions');
  } catch (error) {
    checks.push({name: 'Nutshell recovery browser exception: ' + error.message.slice(0, 220), passed: false});console.error(error.stack);process.exitCode = 1;
  } finally {
    fs.writeFileSync(path.join(report, 'browser-recovery.json'), JSON.stringify(checks, null, 2));
    if (browser) await browser.close();
  }
})();
