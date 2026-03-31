// Zentrale Kategorie-Farbdefinition — eindeutige Farbe pro Kategorie
// Änderungen hier wirken sich auf alle Vue-Komponenten aus.

const CAT_STYLES = {
  'anfrage':      'background:#ede9fe;color:#6d28d9;border-color:#ddd6fe',   // violet
  'email-in':     'background:#dbeafe;color:#1d4ed8;border-color:#bfdbfe',   // blue
  'email-out':    'background:#dcfce7;color:#15803d;border-color:#bbf7d0',   // green
  'expose':       'background:#fff7ed;color:#c2410c;border-color:#fed7aa',   // orange
  'besichtigung': 'background:#ccfbf1;color:#0f766e;border-color:#99f6e4',   // teal
  'kaufanbot':    'background:#fef3c7;color:#b45309;border-color:#fde68a',   // amber
  'absage':       'background:#fef2f2;color:#dc2626;border-color:#fecaca',   // red
  'update':       'background:#f1f5f9;color:#64748b;border-color:#e2e8f0',   // slate
  'sonstiges':    'background:#f9fafb;color:#9ca3af;border-color:#e5e7eb',   // gray
  'eigentuemer':  'background:#e0e7ff;color:#4338ca;border-color:#c7d2fe',   // indigo
  'partner':      'background:#ffe4e6;color:#be123c;border-color:#fecdd3',   // rose
  'bounce':       'background:#fdf4ff;color:#a21caf;border-color:#f0abfc',   // fuchsia
  'nachfassen':   'background:#f0fdf4;color:#166534;border-color:#86efac',   // emerald
};

const CAT_LABELS = {
  'anfrage':      'Erstanfrage',
  'email-in':     'Eingehend',
  'email-out':    'Ausgehend',
  'expose':       'Exposé',
  'besichtigung': 'Besichtigung',
  'kaufanbot':    'Kaufanbot',
  'absage':       'Absage',
  'update':       'Update',
  'sonstiges':    'Sonstiges',
  'eigentuemer':  'Eigentümer',
  'partner':      'Partner',
  'bounce':       'Unzustellbar',
  'nachfassen':   'Nachgefasst',
};

// Gibt den Inline-Style-String für ein Badge zurück
export function catBadgeStyle(cat) {
  return CAT_STYLES[cat] || CAT_STYLES['sonstiges'];
}

// Gibt das Button-Style-Objekt für den aktiven Filter-Button zurück
export function catFilterStyle(cat, isActive) {
  const baseColor = CAT_STYLES[cat] || CAT_STYLES['sonstiges'];
  // Extract color for active button background
  const colorMatch = baseColor.match(/color:([^;]+)/);
  const color = colorMatch ? colorMatch[1] : '#6b7280';
  if (isActive) {
    return `background:${color};color:#fff;border-color:${color}`;
  }
  return '';
}

// Gibt das lesbare Label für eine Kategorie zurück
export function catLabel(cat) {
  return CAT_LABELS[cat] || cat;
}

// Gibt true zurück wenn Kategorie als Inbound gilt (Pfeilrichtung ←)
export function catIsInbound(cat) {
  return ['anfrage','email-in','besichtigung','kaufanbot','absage','eigentuemer','partner','bounce'].includes(cat);
}
