#!/usr/bin/env node
const puppeteer = require('puppeteer');
const fs = require('fs');
const [,, htmlPath, outputPath, orientation = 'portrait'] = process.argv;
if (!htmlPath || !outputPath) { console.error('Usage: render-pdf.js <html> <output> [portrait|landscape]'); process.exit(1); }
(async () => {
  const browser = await puppeteer.launch({ args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'], headless: 'new' });
  const page = await browser.newPage();
  const html = fs.readFileSync(htmlPath, 'utf8');
  await page.setContent(html, { waitUntil: 'networkidle0', timeout: 60000 });
  await page.pdf({ path: outputPath, format: 'A4', landscape: orientation === 'landscape', printBackground: true, preferCSSPageSize: true, margin: { top: 0, right: 0, bottom: 0, left: 0 } });
  await browser.close();
  console.log(JSON.stringify({ success: true, path: outputPath }));
})().catch(err => { console.error(JSON.stringify({ success: false, error: err.message })); process.exit(1); });
