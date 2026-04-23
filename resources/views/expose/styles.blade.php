<style>
  @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400&family=Cormorant+Garamond:ital,wght@0,300;0,500;0,700;1,300;1,500;1,700&family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,700;1,9..144,300&display=swap');

  :root {
    --accent: #ee7600;
    --text-primary: #1a1a1a;
    --text-secondary: #666;
    --border: #e5e7eb;
    --bg-cream: #fdfcfa;
    --font-serif: Georgia, serif;
    --font-sans: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
  }

  * { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: var(--font-sans);
    color: var(--text-primary);
    background: #f3f4f6;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
  }

  .page {
    width: 297mm; height: 210mm;
    background: #fff;
    position: relative;
    overflow: hidden;
    page-break-after: always;
    box-shadow: 0 4px 24px rgba(0,0,0,0.1);
    margin: 24px auto;
  }

  .page:last-child { page-break-after: auto; }

  @media print {
    body { background: #fff; }
    .page { box-shadow: none; margin: 0; }
  }

  .page .pn {
    position: absolute; top: 30px; right: 42px;
    font-size: 12px; color: #bbb; letter-spacing: 2.5px; font-weight: 500;
  }
  .page .title-s {
    position: absolute; top: 38px; left: 48px;
    font-family: var(--font-serif); font-size: 36px; font-weight: 400;
    color: var(--text-primary); letter-spacing: 0.5px; line-height: 1;
  }
  .page .aline {
    position: absolute; top: 92px; left: 48px;
    width: 48px; height: 3px; background: var(--accent);
  }

  /* Gruppen (Details-Seite) */
  .grp { break-inside: avoid; margin-bottom: 14px; }
  .grp:last-child { margin-bottom: 0; }
  .grp .gh {
    font-size: 11px; color: var(--accent); letter-spacing: 2.5px;
    text-transform: uppercase; font-weight: 700;
    padding-bottom: 5px; margin-bottom: 6px;
    border-bottom: 1px solid var(--border);
  }
  .grp .r {
    display: flex; justify-content: space-between;
    padding: 3.5px 0; border-bottom: 1px dotted #f0f0f0; gap: 14px;
  }
  .grp .r:last-child { border-bottom: none; }
  .grp .r .k { color: var(--text-secondary); font-size: 12px; flex-shrink: 0; }
  .grp .r .v { font-family: var(--font-serif); color: var(--text-primary); font-size: 13px; text-align: right; }
  .grp .r .v.total { color: var(--accent); font-weight: 700; }
</style>
