#!/usr/bin/env node
/**
 * Rendert eine URL zu einem A4-Querformat-PDF.
 * Usage: node expose-pdf.cjs <url> <outPath> [hostHeader]
 *
 * Der optionale hostHeader wird bei allen Requests als Host gesetzt —
 * nötig wenn der VPS seinen eigenen FQDN intern nicht auflöst und die
 * URL stattdessen auf 127.0.0.1 zeigt (nginx matched dann den VHost
 * anhand des Host-Headers).
 */
const puppeteer = require('puppeteer');

(async () => {
    const [,, url, outPath, hostHeader] = process.argv;
    if (!url || !outPath) {
        console.error('Usage: expose-pdf.cjs <url> <outPath> [hostHeader]');
        process.exit(2);
    }

    const browser = await puppeteer.launch({
        headless: 'new',
        args: [
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--ignore-certificate-errors',
        ],
    });

    try {
        const page = await browser.newPage();
        if (hostHeader) {
            await page.setExtraHTTPHeaders({ Host: hostHeader });
        }
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
