#!/usr/bin/env node
/**
 * Rendert eine URL zu einem A4-Querformat-PDF.
 * Usage: node expose-pdf.cjs <url> <outPath>
 */
const puppeteer = require('puppeteer');

(async () => {
    const [,, url, outPath] = process.argv;
    if (!url || !outPath) {
        console.error('Usage: expose-pdf.cjs <url> <outPath>');
        process.exit(2);
    }

    const browser = await puppeteer.launch({
        headless: 'new',
        args: ['--no-sandbox', '--disable-setuid-sandbox'],
    });

    try {
        const page = await browser.newPage();
        await page.goto(url, { waitUntil: 'networkidle0', timeout: 30000 });

        await page.pdf({
            path: outPath,
            format: 'A4',
            landscape: true,
            printBackground: true,
            margin: { top: 0, right: 0, bottom: 0, left: 0 },
            preferCSSPageSize: false,
        });
    } finally {
        await browser.close();
    }
})().catch((err) => {
    console.error(err.message);
    process.exit(1);
});
