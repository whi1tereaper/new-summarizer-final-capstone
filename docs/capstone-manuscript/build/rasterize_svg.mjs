// Optional rasterization for editable-Word-compatible copies of original SVG diagrams.
// Usage: node rasterize_svg.mjs input.svg output.png
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const modulePath = process.env.MANUSCRIPT_PLAYWRIGHT_MODULE || 'C:/Users/Robert Martin/AppData/Roaming/npm/node_modules/omniroute/node_modules/playwright';
const { chromium } = require(modulePath);
const [input, output] = process.argv.slice(2);
if (!input || !output) throw new Error('Pass input SVG and output PNG paths.');
const svg = fs.readFileSync(input, 'utf8');
const browser = await chromium.launch({ headless: true, channel: 'chrome' });
try {
  const page = await browser.newPage({ viewport: { width: 1600, height: 1200 }, deviceScaleFactor: 2 });
  await page.setContent('<!doctype html><html><body style="margin:0;background:white">' + svg + '</body></html>');
  const element = page.locator('svg').first();
  await element.evaluate(node => {
    const box = node.viewBox.baseVal;
    if (box.width && box.height) { node.style.width = '1400px'; node.style.height = `${1400 * box.height / box.width}px`; }
    node.style.display = 'block';
  });
  fs.mkdirSync(path.dirname(output), { recursive: true });
  await element.screenshot({ path: output });
} finally {
  await browser.close();
}
