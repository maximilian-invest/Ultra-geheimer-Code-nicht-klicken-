#!/usr/bin/env node
/**
 * Rendert eine URL zu einem A4-Querformat-PDF.
 * Usage: node expose-pdf.cjs <url> <outPath> [mapHost]
 *
 * Der optionale `mapHost` Parameter wird via Chromium's
 * `--host-resolver-rules=MAP <mapHost> 127.0.0.1` übergeben — damit
 * kann Puppeteer intern einen Hostnamen auflösen, den das OS/DNS nicht
 * kennt (typisch: VPS löst seinen eigenen FQDN nicht auf). Die URL bleibt
 * unverändert, d.h. Session-Cookies, Routing etc. matchen 1:1.
 */
const puppeteer = require('puppeteer');

(async () => {
    const [,, url, outPath, mapHost] = process.argv;
    if (!url || !outPath) {
        console.error('Usage: expose-pdf.cjs <url> <outPath> [mapHost]');
        process.exit(2);
    }

    const args = [
        '--no-sandbox',
        '--disable-setuid-sandbox',
        '--ignore-certificate-errors',
    ];
    if (mapHost) {
        args.push(`--host-resolver-rules=MAP ${mapHost} 127.0.0.1`);
    }

    const browser = await puppeteer.launch({
        headless: 'new',
        args,
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
