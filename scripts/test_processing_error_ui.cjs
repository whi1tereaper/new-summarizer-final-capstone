const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'C:/Users/Robert Martin/AppData/Roaming/npm/node_modules/omniroute/node_modules/playwright');
const base = process.env.LIGHT_TEST_URL || 'http://localhost/app/public/';
const output = path.resolve('artifacts/error-feedback-browser');
const catalog = JSON.parse(fs.readFileSync('app/config/error_catalog.json', 'utf8'));
const source = 'The city opened a new public library on Monday. The building provides reading rooms and free internet access for residents. Local officials said the library will support education throughout the district. The library opens every morning and closes at six in the evening.';
const results = [];
async function check(name, operation) {
    try { await operation(); results.push({ name, passed: true }); console.log(`PASS ${name}`); }
    catch (error) { results.push({ name, passed: false, error: error.message }); console.error(`FAIL ${name}: ${error.message}`); }
}
(async () => {
    fs.mkdirSync(output, { recursive: true });
    const browser = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
    const context = await browser.newContext({ viewport: { width: 1100, height: 900 } });
    const page = await context.newPage(); page.setDefaultTimeout(25000);
    const scriptErrors = []; page.on('pageerror', error => scriptErrors.push(error.message));
    let corruptJob;
    let completedResult;
    await check('actual corrupted PDF produces persisted processing failure with no retry or summary', async () => {
        await page.goto(`${base}summarizer.php`, { waitUntil: 'domcontentloaded' });
        await page.locator('#document_title').fill('Malformed PDF recovery');
        await page.locator('#analysis_mode').selectOption('technical');
        await page.locator('#output_format').selectOption('bullets');
        await page.locator('[data-depth="short"]').click();
        await page.locator('#pdf').setInputFiles({ name: 'corrupted.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\nThis file has no readable PDF objects.\n%%EOF') });
        await page.locator('.summary-submit-button').click();
        await page.waitForURL('**/processing.php');
        await page.waitForFunction(() => document.querySelector('#processing-error')?.dataset.errorCode === 'FILE_CORRUPTED');
        corruptJob = await page.locator('#processing-card').getAttribute('data-job-id');
        assert.equal(await page.locator('#processing-retry').isVisible(), false);
        assert.match(await page.locator('#processing-recovery-note').innerText(), /cannot restore/);
        await page.locator('.processing-shell').screenshot({ path: path.join(output, 'corrupted-pdf-processing-light.png') });
        await page.evaluate(() => { localStorage.setItem('light-results-theme', 'dark'); document.documentElement.dataset.theme = 'dark'; });
        await page.locator('.processing-shell').screenshot({ path: path.join(output, 'corrupted-pdf-processing-dark.png') });
        await page.reload({ waitUntil: 'domcontentloaded' });
        await page.waitForFunction(() => document.querySelector('#processing-error')?.dataset.errorCode === 'FILE_CORRUPTED');
        assert.equal(await page.locator('#processing-card').getAttribute('data-job-id'), corruptJob);
        await page.locator('#processing-return').click();
        assert.equal(await page.locator('#document_title').inputValue(), 'Malformed PDF recovery');
        assert.equal(await page.locator('#analysis_mode').inputValue(), 'technical');
        assert.equal(await page.locator('#output_format').inputValue(), 'bullets');
        assert.equal(await page.locator('#summary_depth').inputValue(), 'short');
        assert.equal(await page.locator('#pdf').evaluate(input => input.files.length), 0);
    });
    await check('injected polling outage offers status-only retry for same persisted job', async () => {
        let polls = 0;
        await page.route('**/summarize.php?action=*', async route => { polls++; await route.abort('internetdisconnected'); });
        await page.goto(`${base}processing.php`, { waitUntil: 'domcontentloaded' });
        await page.waitForFunction(() => document.querySelector('#processing-error')?.dataset.errorCode === 'NETWORK_INTERRUPTED');
        assert.equal(polls, 3);
        assert.match(await page.locator('#processing-retry').innerText(), /status/);
        await page.locator('.processing-shell').screenshot({ path: path.join(output, 'processing-network-recovery.png') });
        await page.unroute('**/summarize.php?action=*');
        const requests = []; page.on('request', request => { if (request.url().includes('summarize.php')) requests.push(request.url()); });
        await page.locator('#processing-retry').click();
        await page.waitForFunction(() => document.querySelector('#processing-error')?.dataset.errorCode === 'FILE_CORRUPTED');
        assert.ok(requests.some(url => url.includes('action=status')));
        assert.ok(requests.every(url => !url.includes('action=retry') && !url.includes('action=execute')));
        assert.equal(await page.locator('#processing-card').getAttribute('data-job-id'), corruptJob);
    });
    await check('injected retryable worker failure displays controlled retry; retry response remains same job', async () => {
        const calls = [];
        await page.route('**/summarize.php?action=*', async route => {
            const action = new URL(route.request().url()).searchParams.get('action'); calls.push({ action, method: route.request().method(), body: route.request().postData() });
            await route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ success: false, job_id: corruptJob, status: 'failed', can_retry: action !== 'retry', failure: { code: 'WORKER_UNAVAILABLE', ...catalog.WORKER_UNAVAILABLE, reference: 'abcdef1234567890' } }) });
        });
        await page.reload({ waitUntil: 'domcontentloaded' });
        await page.waitForFunction(() => document.querySelector('#processing-error')?.dataset.errorCode === 'WORKER_UNAVAILABLE');
        assert.equal(await page.locator('#processing-retry').isVisible(), true);
        await page.locator('.processing-shell').screenshot({ path: path.join(output, 'worker-unavailable-injected.png') });
        await page.locator('#processing-retry').click();
        await page.waitForFunction(() => document.querySelector('#processing-retry').hidden);
        const retry = calls.find(call => call.action === 'retry');
        assert.equal(retry.method, 'POST'); assert.match(retry.body, /csrf_token=/); assert.match(retry.body, new RegExp(corruptJob));
        await page.unroute('**/summarize.php?action=*');
    });
    await check('actual valid text completes and processing refresh returns identical result without regeneration', async () => {
        await page.goto(`${base}summarizer.php`, { waitUntil: 'domcontentloaded' });
        await page.locator('[data-source-choice="text"]').click(); await page.locator('#text').fill(source);
        await page.locator('#document_title').fill('Library update'); await page.locator('#analysis_mode').selectOption('general');
        await page.locator('#output_format').selectOption('paragraph'); await page.locator('[data-depth="balanced"]').click();
        await page.locator('.summary-submit-button').click();
        await page.waitForURL('**/result.php?**', { timeout: 120000 });
        const result = new URL(page.url()).searchParams.get('id'); assert.ok(Number(result) > 0);
        completedResult = result;
        assert.equal(await page.evaluate(() => window.LightErrors.draft.read()), null);
        await page.goto(`${base}processing.php`, { waitUntil: 'domcontentloaded' });
        await page.waitForURL('**/result.php?**');
        assert.equal(new URL(page.url()).searchParams.get('id'), result);
        results.push({ name: 'actual completed result', resultId: result, passed: true });
    });
    await check('cancel after completion opens the existing result even when the displayed state was stale', async () => {
        await page.route('**/summarize.php?action=execute*', route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, status: 'processing' }) }));
        await page.goto(`${base}processing.php`, { waitUntil: 'domcontentloaded' });
        await page.locator('#processing-cancel-button').click();
        await page.waitForURL('**/result.php?**');
        assert.equal(new URL(page.url()).searchParams.get('id'), completedResult);
        await page.unroute('**/summarize.php?action=execute*');
    });
    await check('processing/result browser has no JavaScript errors', async () => assert.deepEqual(scriptErrors, []));
    fs.writeFileSync(path.join(output, 'processing-results.json'), JSON.stringify({ base, results }, null, 2));
    await browser.close(); if (results.some(result => !result.passed)) process.exitCode = 1;
})().catch(error => { console.error(error); process.exitCode = 1; });
