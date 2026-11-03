const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'C:/Users/Robert Martin/AppData/Roaming/npm/node_modules/omniroute/node_modules/playwright');
const base = 'http://127.0.0.1:58141/';
const report = process.env.QA_REGISTRY_UI_REPORT;
const fixture = JSON.parse(fs.readFileSync(process.env.QA_REGISTRY_UI_FIXTURE, 'utf8'));
const checks = [];
const record = name => { checks.push({name, passed: true}); fs.writeFileSync(path.join(report, 'browser.json'), JSON.stringify(checks, null, 2)); console.log('PASS ' + name); };
(async () => {
  let browser;
  try {
    browser = await chromium.launch({headless: true, executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});
    const context = await browser.newContext({viewport: {width: 1506, height: 1000}});
    await context.addCookies([{name: 'LIGHT_QA_SESSID', value: fixture.sessions.admin, url: base}]);
    await context.route('https://fonts.googleapis.com/**', route => route.fulfill({contentType: 'text/css', body: ''}));
    await context.route('https://fonts.gstatic.com/**', route => route.abort());
    const page = await context.newPage();const errors = [];page.on('pageerror', error => errors.push(error.message));
    const response = await page.goto(base + 'admin_dashboard.php', {waitUntil: 'domcontentloaded'});
    assert.equal(response.status(), 200);assert(page.url().includes('admin_dashboard.php'));
    await page.evaluate(() => document.fonts.ready);
    const panel = page.locator('#student-enrollment-registry');
    await panel.getByRole('heading', {name: 'Student ID Management', exact: true}).waitFor();
    assert(await page.locator('link[href*="student-access.css?v="]').count());
    assert.equal(await panel.locator('.registry-stats').evaluate(el => getComputedStyle(el).display), 'grid');
    record('actual administrator dashboard loads cache-busted registry styles and grid layout');
    for (const [key, value] of Object.entries(fixture.stats)) assert.equal((await panel.locator('[data-stat="' + key + '"]').textContent()).trim(), String(value));
    assert.equal(await panel.locator('dt').count(), 7);assert.equal(await panel.locator('.registry-stat--primary').count(), 3);
    record('all seven cards display the unchanged isolated MySQL statistics with proper definition labels');
    assert.equal(await panel.evaluate(el => el.getAttribute('aria-labelledby')), 'registry-overview-title');
    await panel.getByRole('link', {name: 'Manage Student IDs'}).focus();
    assert(await panel.getByRole('link', {name: 'Manage Student IDs'}).evaluate(el => el === document.activeElement && getComputedStyle(el).outlineStyle !== 'none'));
    record('section is labelled and Manage Student IDs has visible keyboard focus');
    for (const theme of ['light', 'dark']) {
      await page.evaluate(theme => { localStorage.setItem('light-results-theme', theme); document.documentElement.dataset.theme = theme; document.body.dataset.theme = theme; }, theme);
      for (const width of [1506, 1024, 768, 390, 320]) {
        await page.setViewportSize({width, height: 1000});
        const layout = await panel.evaluate(el => {
          const bounds = el.getBoundingClientRect();
          const cards = [...el.querySelectorAll('.registry-stat')].map(card => card.getBoundingClientRect());
          const numbers = [...el.querySelectorAll('dd')];
          const style = getComputedStyle(el.querySelector('.registry-stats'));
          return {inside: bounds.left >= 0 && bounds.right <= innerWidth + 1, noOverflow: el.scrollWidth <= el.clientWidth + 1,
            allCardsInside: cards.every(card => card.left >= bounds.left && card.right <= bounds.right + 1),
            numericOverflow: numbers.some(number => number.scrollWidth > number.clientWidth + 1),
            columns: style.gridTemplateColumns.split(' ').length,
            primaryRow: cards.slice(0,3).every(card => Math.abs(card.top - cards[0].top) < 1)};
        });
        assert(layout.inside && layout.noOverflow && layout.allCardsInside && !layout.numericOverflow, JSON.stringify({theme, width, layout}));
        assert.equal(layout.columns, width <= 760 ? 2 : 12);
        if (width > 760) assert(layout.primaryRow);
        const action = await panel.getByRole('link', {name: 'Manage Student IDs'}).boundingBox();assert(action.height >= 44);
        record(theme + ' layout at ' + width + 'px: aligned cards, accessible action target and no panel overflow');
        if ([1506,390].includes(width)) await panel.screenshot({path: path.join(report, 'registry-' + theme + '-' + width + '.png')});
      }
    }
    await page.setViewportSize({width: 390, height: 1000});
    await panel.locator('dd').evaluateAll(nodes => nodes.forEach(node => node.textContent = '123456789'));
    assert(await panel.evaluate(el => el.scrollWidth <= el.clientWidth + 1 && [...el.querySelectorAll('dd')].every(node => node.scrollWidth <= node.clientWidth + 1)));
    await panel.locator('dd').evaluateAll(nodes => nodes.forEach(node => node.textContent = '0'));
    assert.equal(await panel.locator('dd').count(), 7);
    record('zero and large synthetic display values remain readable on mobile');
    await page.reload({waitUntil: 'domcontentloaded'});
    for (const [key, value] of Object.entries(fixture.stats)) assert.equal((await panel.locator('[data-stat="' + key + '"]').textContent()).trim(), String(value));
    await panel.getByRole('link', {name: 'Manage Student IDs'}).click();await page.waitForURL('**/admin_student_registry.php');
    assert.equal(await page.locator('.registry-stat').count(), 7);
    for (const [key, value] of Object.entries(fixture.stats)) assert.equal((await page.locator('[data-stat="' + key + '"]').textContent()).trim(), String(value));
    assert(await page.locator('#registry-import-form').isVisible());
    record('Manage Student IDs opens the existing protected import/search page with matching real database totals');
    assert.deepEqual(errors, []);record('dashboard and registry UI produce no JavaScript exceptions');
    for (const [role, cookie] of [['anonymous', null], ['ordinary user', fixture.sessions.user]]) {
      const denied = await browser.newContext();
      if (cookie) await denied.addCookies([{name:'LIGHT_QA_SESSID',value:cookie,url:base}]);
      const request = await denied.request.get(base + 'admin_dashboard.php', {maxRedirects: 0});
      assert.equal(request.status(), 302);assert(request.headers().location.includes('login.php'));
      const registry = await denied.request.get(base + 'admin_student_registry.php', {maxRedirects: 0});assert.equal(registry.status(), 403);
      record(role + ' remains unable to access dashboard or registry');await denied.close();
    }
  } catch (error) {
    checks.push({name: 'Registry UI exception: ' + error.message.slice(0, 250), passed: false});console.error(error.stack);process.exitCode = 1;
  } finally {
    fs.writeFileSync(path.join(report, 'browser.json'), JSON.stringify(checks, null, 2));
    if (browser) await browser.close();
  }
})();
