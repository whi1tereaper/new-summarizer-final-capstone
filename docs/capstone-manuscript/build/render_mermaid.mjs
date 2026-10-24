// Render original Mermaid sources through the already-installed local library.
// Output SVG and PNG stay beside their editable .mmd source under docs/.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const dependencies = process.env.MANUSCRIPT_NODE_MODULES || 'C:/Users/Robert Martin/AppData/Roaming/npm/node_modules/omniroute/node_modules';
const { chromium } = require(path.join(dependencies, 'playwright'));
const docsRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const sources = process.argv.slice(2);
if (!sources.length) throw new Error('Supply one or more .mmd source files.');
const browser = await chromium.launch({ headless: true, channel: 'chrome' });
const report = [];
try {
  const page = await browser.newPage({ viewport: { width: 1800, height: 1200 }, deviceScaleFactor: 2 });
  await page.setContent('<!doctype html><html><body style="margin:0;padding:16px;background:white"><main></main></body></html>');
  await page.addScriptTag({ path: path.join(dependencies, 'mermaid/dist/mermaid.min.js') });
  await page.evaluate(() => mermaid.initialize({
    startOnLoad: false,
    securityLevel: 'strict',
    theme: 'base',
    themeVariables: { fontFamily: 'Arial, sans-serif', fontSize: '18px', primaryColor: '#f0f2f5', primaryTextColor: '#111111', primaryBorderColor: '#333333', lineColor: '#333333', secondaryColor: '#ffffff', tertiaryColor: '#ffffff' },
    er: { useMaxWidth: false, minEntityWidth: 180, minEntityHeight: 60, entityPadding: 16, fontSize: 18, nodeSpacing: 45, rankSpacing: 95, layoutDirection: 'TB' },
    flowchart: { useMaxWidth: false, htmlLabels: false }
  }));
  for (let i = 0; i < sources.length; i++) {
    const input = path.resolve(sources[i]);
    if (!input.toLowerCase().startsWith((docsRoot + path.sep).toLowerCase())) throw new Error('Diagram sources must remain beneath docs/.');
    const code = fs.readFileSync(input, 'utf8');
    const svg = await page.evaluate(async ({ code, i }) => {
      const result = await mermaid.render(`diagram${i}`, code);
      document.querySelector('main').innerHTML = result.svg;
      const node = document.querySelector('main svg');
      node.style.background = 'white';
      node.setAttribute('role', 'img');
      node.setAttribute('aria-label', 'Database entities, declared key fields, and actual foreign-key relationships.');
      return node.outerHTML;
    }, { code, i });
    const output = input.replace(/\.mmd$/i, '.svg');
    fs.writeFileSync(output, svg, 'utf8');
    const element = page.locator('main svg');
    const bounds = await element.boundingBox();
    const png = input.replace(/\.mmd$/i, '.png');
    await element.screenshot({ path: png });
    report.push({ input: path.relative(docsRoot, input), svg: path.relative(docsRoot, output), png: path.relative(docsRoot, png), width: bounds.width, height: bounds.height, renderer: 'locally installed Mermaid + Chromium', code_native: true });
  }
} finally { await browser.close(); }
fs.writeFileSync(path.join(path.dirname(fileURLToPath(import.meta.url)), 'DIAGRAM_RENDER_REPORT.json'), JSON.stringify(report, null, 2));
console.log(JSON.stringify(report));
