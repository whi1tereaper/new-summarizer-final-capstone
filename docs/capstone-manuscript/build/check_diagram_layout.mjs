// Read-only visual-boundary check for all locally generated SVG diagrams.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const { chromium } = require('C:/Users/Robert Martin/AppData/Roaming/npm/node_modules/omniroute/node_modules/playwright');
const build = path.dirname(fileURLToPath(import.meta.url));
const folder = path.resolve(build, '../diagrams');
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const rows = [];
try {
  const page = await browser.newPage({ viewport: { width: 2000, height: 1600 } });
  for (const file of fs.readdirSync(folder).filter(file => file.endsWith('.svg')).sort()) {
    await page.setContent('<html><body style="margin:0">' + fs.readFileSync(path.join(folder, file), 'utf8') + '</body></html>');
    const result = await page.evaluate(() => {
      const svg = document.querySelector('svg');
      const box = svg.getBoundingClientRect();
      const outside = [];
      for (const text of svg.querySelectorAll('text')) {
        const bounds = text.getBoundingClientRect();
        if (bounds.left < box.left - 1 || bounds.top < box.top - 1 || bounds.right > box.right + 1 || bounds.bottom > box.bottom + 1) {
          outside.push({ text: text.textContent, bounds: { x: bounds.left - box.left, y: bounds.top - box.top, width: bounds.width, height: bounds.height } });
        }
      }
      return { width: box.width, height: box.height, text_elements: svg.querySelectorAll('text').length, outside };
    });
    rows.push({ file, ...result });
  }
} finally { await browser.close(); }
fs.writeFileSync(path.join(build, 'DIAGRAM_LAYOUT_CHECK.json'), JSON.stringify(rows, null, 2));
console.log(JSON.stringify({ diagrams: rows.length, diagrams_with_outside_text: rows.filter(row => row.outside.length) }));
