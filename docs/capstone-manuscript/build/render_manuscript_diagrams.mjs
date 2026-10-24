// Render original diagrams locally; no network assets or image-generation service.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const dependencies = process.env.MANUSCRIPT_NODE_MODULES || 'C:/Users/Robert Martin/AppData/Roaming/npm/node_modules/omniroute/node_modules';
const { chromium } = require(path.join(dependencies, 'playwright'));
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../diagrams');
const models = JSON.parse(fs.readFileSync(path.join(root, 'DIAGRAM_MODELS.json'), 'utf8'));
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const report = [];
try {
  const page = await browser.newPage({ viewport: { width: 2000, height: 1500 }, deviceScaleFactor: 2 });
  await page.setContent('<!doctype html><html><body style="margin:0;background:white"><main></main></body></html>');
  await page.addScriptTag({ path: path.join(dependencies, 'mermaid/dist/mermaid.min.js') });
  await page.evaluate(() => mermaid.initialize({ startOnLoad: false, securityLevel: 'strict', theme: 'base', themeVariables: { fontFamily: 'Arial, sans-serif', fontSize: '24px', primaryColor: '#edf1f5', primaryTextColor: '#111', primaryBorderColor: '#222', lineColor: '#333', secondaryColor: '#fff', tertiaryColor: '#fff' }, flowchart: { useMaxWidth: false, htmlLabels: false, nodeSpacing: 35, rankSpacing: 55, curve: 'basis', padding: 18 } }));
  let index = 0;
  for (const [name, model] of Object.entries(models)) {
    const filename = path.join(root, name);
    const input = model.svg_preserved ? fs.readFileSync(filename + '.svg', 'utf8') : fs.readFileSync(filename + '.mmd', 'utf8');
    const result = await page.evaluate(async ({ input, preserved, index, title }) => {
      const svg = preserved ? input : (await mermaid.render(`sourceDiagram${index}`, input)).svg;
      document.querySelector('main').innerHTML = svg;
      const node = document.querySelector('main svg');
      node.style.background = 'white'; node.setAttribute('role', 'img'); node.setAttribute('aria-label', title);
      return node.outerHTML;
    }, { input, preserved: !!model.svg_preserved, index: index++, title: model.title });
    fs.writeFileSync(filename + '.svg', result, 'utf8');
    const element = page.locator('main svg');
    const box = await element.boundingBox();
    await element.screenshot({ path: filename + '.png' });
    const baseFont = model.svg_preserved ? 19 : 24;
    const scale = Math.min(335.88 / box.width, 489.52 / box.height);
    report.push({ name, width: box.width, height: box.height, png_width: Math.ceil(box.width * 2), png_height: Math.ceil(box.height * 2), approximate_node_font_pt_at_manuscript_size: +(baseFont * scale).toFixed(1), prefer_focused_panel_for_print: baseFont * scale < 8, title: model.title });
  }
} finally { await browser.close(); }
fs.writeFileSync(path.join(root, '../build/MANUSCRIPT_DIAGRAM_RENDER_REPORT.json'), JSON.stringify(report, null, 2));
console.log(JSON.stringify(report));
