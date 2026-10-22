/* Real Chromium against XAMPP. Network faults below are explicitly injected. */
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'C:/Users/Robert Martin/AppData/Roaming/npm/node_modules/omniroute/node_modules/playwright');
const base = process.env.LIGHT_TEST_URL || 'http://localhost/app/public/';
const output = path.resolve('artifacts/error-feedback-browser');
fs.mkdirSync(output, { recursive: true });
const findings = [];
async function check(name, operation) {
    try { await operation(); findings.push({ name, passed: true }); console.log(`PASS ${name}`); }
    catch (error) { findings.push({ name, passed: false, error: error.message }); console.error(`FAIL ${name}: ${error.message}`); }
}
(async () => {
    const browser = await chromium.launch({ executablePath: process.env.CHROME_BIN || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 1050 } });
    const page = await context.newPage();
    page.setDefaultTimeout(15000);
    const scriptErrors = [];
    page.on('pageerror', error => scriptErrors.push(error.message));
    await page.goto(`${base}summarizer.php`, { waitUntil: 'domcontentloaded' });
    const submit = () => page.locator('.summary-submit-button').click();
    const code = async value => { await page.waitForFunction(expected => document.querySelector('#source-error')?.dataset.errorCode === expected && !document.querySelector('#source-error').hidden, value); };
    await check('configured limits and all existing mode/format/depth choices', async () => {
        const values = await page.locator('#summarize-form').evaluate(form => JSON.parse(form.dataset.capabilities));
        assert.equal(values.analysisModes.length, 8); assert.equal(values.formats.length, 5); assert.equal(values.depths.length, 6);
        assert.equal(values.maxTextBytes, 200000); assert.ok(values.maxUploadBytes > 0 && values.maxUploadBytes <= 30 * 1024 * 1024);
    });
    await check('empty text inline accessible error, no request', async () => {
        await page.locator('[data-source-choice="text"]').click();
        await submit(); await code('INPUT_EMPTY');
        assert.equal(await page.locator('#text').getAttribute('aria-invalid'), 'true');
        assert.equal(await page.locator('#source-retry').isVisible(), false);
        await page.locator('#source-method').screenshot({ path: path.join(output, 'empty-text-light.png') });
        await page.evaluate(() => document.documentElement.dataset.theme = 'dark');
        await page.locator('#source-method').screenshot({ path: path.join(output, 'empty-text-dark.png') });
        const colors = await page.locator('#source-error').evaluate(element => ({ color: getComputedStyle(element).color, background: getComputedStyle(element).backgroundColor }));
        assert.equal(colors.background, 'rgb(53, 33, 40)'); assert.equal(colors.color, 'rgb(245, 216, 221)');
        assert.equal(await page.locator('#text').evaluate(element => getComputedStyle(element).backgroundColor), 'rgb(36, 39, 35)');
        assert.equal(await page.locator('#source-selection-note').evaluate(element => getComputedStyle(element).color), 'rgb(205, 209, 201)');
        await page.evaluate(() => document.documentElement.dataset.theme = 'light');
    });
    await check('title length limit renders next to title without truncating input', async () => {
        await page.locator('#document_title').fill('é'.repeat(256));
        await submit();
        assert.equal(await page.locator('#document-title-error').getAttribute('data-error-code'), 'TITLE_TOO_LONG');
        assert.equal(await page.locator('#document-title-error').isVisible(), true);
        assert.equal(await page.locator('#source-error').isVisible(), false);
        assert.equal((await page.locator('#document_title').inputValue()).length, 256);
        await page.locator('#document_title').fill('');
    });
    await check('oversized UTF-8 text is rejected without truncation', async () => {
        await page.locator('#text').fill('é'.repeat(100001));
        await submit(); await code('INPUT_TOO_LARGE');
        assert.equal((await page.locator('#text').inputValue()).length, 100001);
    });
    await check('punctuation-only text has unreadable-content feedback', async () => {
        await page.locator('#text').fill('!!! ... ??'); await submit(); await code('CONTENT_UNREADABLE');
    });
    await check('invalid URL receives contextual feedback', async () => {
        await page.locator('[data-source-choice="url"]').click(); await page.locator('#source_url').fill('not a valid url');
        await submit(); await code('URL_INVALID');
    });
    await check('unsupported extension rejected before upload', async () => {
        await page.locator('[data-source-choice="file"]').click();
        await page.locator('#pdf').setInputFiles({ name: 'notes.exe', mimeType: 'application/octet-stream', buffer: Buffer.from('not a document') });
        await submit(); await code('FILE_UNSUPPORTED');
    });
    await check('refresh restores title and all settings, explicitly requests file reselection', async () => {
        await page.locator('#document_title').fill('Recovery test document');
        await page.locator('#analysis_mode').selectOption('academic');
        await page.locator('#output_format').selectOption('hybrid');
        await page.locator('[data-depth="detailed"]').click();
        await submit(); await code('FILE_UNSUPPORTED');
        await page.reload({ waitUntil: 'domcontentloaded' }); await code('FILE_UNSUPPORTED');
        assert.equal(await page.locator('#document_title').inputValue(), 'Recovery test document');
        assert.equal(await page.locator('#analysis_mode').inputValue(), 'academic');
        assert.equal(await page.locator('#output_format').inputValue(), 'hybrid');
        assert.equal(await page.locator('#summary_depth').inputValue(), 'detailed');
        assert.match(await page.locator('#draft-recovery-note').innerText(), /cannot restore local file/);
        assert.equal(await page.locator('#pdf').evaluate(input => input.files.length), 0);
        await page.locator('#source-method').screenshot({ path: path.join(output, 'file-reselection.png') });
    });
    await check('injected submission network failure retains source and request token across retry and refresh', async () => {
        await page.locator('[data-source-choice="text"]').click();
        await page.locator('#text').fill('The research team studied water quality in three districts. Their findings showed that cleaner water reduced disease. The report recommends regular monitoring and improved water treatment.');
        const tokens = [];
        await page.route('**/summarize.php', async route => {
            const body = route.request().postData() || '';
            const match = body.match(/name="request_token"\r\n\r\n([^\r]+)/);
            tokens.push(match?.[1]); await route.abort('internetdisconnected');
        });
        await submit(); await code('NETWORK_INTERRUPTED');
        const token = await page.locator('[name="request_token"]').inputValue();
        assert.equal(await page.locator('#source-retry').isVisible(), true);
        await page.locator('#source-retry').click(); await page.waitForTimeout(300);
        assert.equal(tokens.length, 2); assert.deepEqual(tokens, [token, token]);
        await page.reload({ waitUntil: 'domcontentloaded' }); await code('NETWORK_INTERRUPTED');
        assert.equal(await page.locator('[name="request_token"]').inputValue(), token);
        assert.match(await page.locator('#text').inputValue(), /water quality/);
        await page.locator('#source-method').screenshot({ path: path.join(output, 'submission-network-recovery.png') });
        await page.unroute('**/summarize.php');
    });
    await check('uncertain upload refresh preserves token when identical local file is reselected', async () => {
        const fixture = path.join(output, 'retry-file.pdf'); fs.writeFileSync(fixture, '%PDF-1.4\n%%EOF\n');
        await page.locator('[data-source-choice="file"]').click(); await page.locator('#pdf').setInputFiles(fixture);
        await page.route('**/summarize.php', route => route.abort('internetdisconnected'));
        await submit(); await code('NETWORK_INTERRUPTED');
        const token = await page.locator('[name="request_token"]').inputValue();
        await page.reload({ waitUntil: 'domcontentloaded' }); await code('NETWORK_INTERRUPTED');
        await page.locator('#pdf').setInputFiles(fixture);
        assert.equal(await page.locator('[name="request_token"]').inputValue(), token);
        await submit(); await code('NETWORK_INTERRUPTED');
        assert.equal(await page.locator('[name="request_token"]').inputValue(), token);
        await page.unroute('**/summarize.php');
    });
    await check('actual XAMPP source validation returns structured unsafe-URL feedback', async () => {
        await page.unroute('**/summarize.php');
        await page.locator('[data-source-choice="url"]').click(); await page.locator('#source_url').fill('http://127.0.0.1/private');
        await submit(); await code('URL_UNSAFE');
        assert.equal(await page.locator('#source-retry').isVisible(), false);
        assert.equal(await page.locator('#source-sign-in').isVisible(), false);
        assert.equal(await page.locator('[data-recovery-source="text"]').isVisible(), true);
        await page.reload({ waitUntil: 'domcontentloaded' }); await code('URL_UNSAFE');
        assert.equal(await page.locator('#source_url').inputValue(), 'http://127.0.0.1/private');
        await page.locator('#source-method').screenshot({ path: path.join(output, 'unsafe-url-server.png') });
        await page.setViewportSize({ width: 390, height: 844 });
        await page.locator('#source-method').screenshot({ path: path.join(output, 'unsafe-url-mobile.png') });
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        await page.setViewportSize({ width: 1440, height: 1050 });
    });
    await check('unavailable result has safe 404 and recovery in light and dark modes', async () => {
        const response = await page.goto(`${base}result.php?id=2147483647`, { waitUntil: 'domcontentloaded' });
        assert.equal(response.status(), 404);
        assert.equal(await page.locator('[data-error-code="RESULT_UNAVAILABLE"]').count(), 1);
        await page.locator('.result-error-box').screenshot({ path: path.join(output, 'result-unavailable-light.png') });
        await page.locator('#result-theme-toggle').click();
        await page.locator('.result-error-box').screenshot({ path: path.join(output, 'result-unavailable-dark.png') });
    });
    await check('editing a failed source and refreshing does not resurrect stale server feedback', async () => {
        await page.goto(`${base}summarizer.php`, { waitUntil: 'domcontentloaded' });
        await page.locator('#source_url').fill('https://example.com/corrected-source');
        await page.reload({ waitUntil: 'domcontentloaded' });
        assert.equal(await page.locator('#source-error').isVisible(), false);
        assert.equal(await page.locator('#source_url').inputValue(), 'https://example.com/corrected-source');
    });
    await check('native POST without JavaScript keeps structured failure and settings after repeated refresh', async () => {
        const native = await browser.newContext({ javaScriptEnabled: false });
        const nativePage = await native.newPage();
        await nativePage.goto(`${base}summarizer.php`, { waitUntil: 'domcontentloaded' });
        await nativePage.locator('#document_title').fill('Native recovery title');
        await nativePage.locator('#analysis_mode').selectOption('scientific');
        await nativePage.locator('#pdf').setInputFiles({ name: 'unsupported.exe', mimeType: 'application/octet-stream', buffer: Buffer.from('unsupported') });
        await nativePage.locator('.summary-submit-button').click();
        await nativePage.waitForLoadState('domcontentloaded');
        for (let i = 0; i < 2; i++) {
            assert.equal(await nativePage.locator('#source-error').getAttribute('data-error-code'), 'FILE_UNSUPPORTED');
            assert.equal(await nativePage.locator('#source-error').isVisible(), true);
            assert.equal(await nativePage.locator('#document_title').inputValue(), 'Native recovery title');
            assert.equal(await nativePage.locator('#analysis_mode').inputValue(), 'scientific');
            await nativePage.reload({ waitUntil: 'domcontentloaded' });
        }
        await native.close();
    });
    await check('draft TTL removes old text from tab recovery storage', async () => {
        await page.goto(`${base}summarizer.php`, { waitUntil: 'domcontentloaded' });
        await page.evaluate(() => {
            const key = Object.keys(sessionStorage).find(key => key.startsWith('light-summary-draft:'));
            const old = JSON.parse(sessionStorage.getItem(key));
            old.savedAt = Date.now() - 3600001; sessionStorage.setItem(key, JSON.stringify(old));
            if (window.LightErrors.draft.read() !== null) throw new Error('expired draft returned');
            if (sessionStorage.getItem(key) !== null) throw new Error('expired content remained');
        });
    });
    await check('new browser session cannot restore the previous viewer draft', async () => {
        await page.locator('#document_title').fill('Previous viewer private title');
        const previousScope = await page.evaluate(() => window.lightDraftScope);
        await context.clearCookies(); await page.reload({ waitUntil: 'domcontentloaded' });
        assert.notEqual(await page.evaluate(() => window.lightDraftScope), previousScope);
        assert.equal(await page.locator('#document_title').inputValue(), '');
        assert.equal(await page.evaluate(() => window.LightErrors.draft.read()), null);
    });
    await check('no browser JavaScript errors', async () => assert.deepEqual(scriptErrors, []));
    fs.writeFileSync(path.join(output, 'results.json'), JSON.stringify({ base, browser: 'Chrome via Playwright', findings }, null, 2));
    await browser.close();
    if (findings.some(item => !item.passed)) process.exitCode = 1;
})().catch(error => { console.error(error); process.exitCode = 1; });
