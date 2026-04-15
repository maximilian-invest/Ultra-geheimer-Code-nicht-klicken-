// Shared text helpers for the inbox reading pane. Pure functions, no
// Vue dependencies — safe to import from components or tests.
//
// Migrated from the old InboxChatBubble.vue. The five exports cover:
//   - repairUmlauts: best-effort recovery of '?' damage from legacy rows
//   - decodeHtmlEntities: common HTML entity → character
//   - htmlToText: structure-aware HTML → plain text
//   - cleanEmailBody: the full display pipeline (entities → umlaut repair
//     → quote strip → signature strip → run-on unmangle)
//   - extractForwardMetadata: parse a mail body for forwarded-from info

// ──────────────────────────────────────────────────────────────────────
// Umlaut repair dictionary
// ──────────────────────────────────────────────────────────────────────
// Used for legacy rows where the pre-fix IMAP fetcher stored raw
// Windows-1252 bytes and MySQL replaced umlauts with '?'. New rows and
// the 127 refetched rows don't need this, but older rows still do.
//
// IMPORTANT: patterns starting with \? drop the leading \b because \b
// doesn't anchor before non-word characters. Specificity of the rest
// of the pattern prevents false positives.
const UMLAUT_REPAIRS = [
  // — ß family
  [/\bStra\?e\b/g, 'Straße'],
  [/\bstra\?e\b/g, 'straße'],
  [/Bundesstra\?e/g, 'Bundesstraße'],
  [/Hauptstra\?e/g, 'Hauptstraße'],
  [/\bGro\?/g, 'Groß'],
  [/\bgro\?/g, 'groß'],
  [/\bwei\?\b/g, 'weiß'],
  [/\bWei\?\b/g, 'Weiß'],
  [/\bhei\?\b/g, 'heiß'],
  [/\bFu\?/g, 'Fuß'],
  [/\bFlu\?/g, 'Fluß'],
  [/\bRei\?/g, 'Reiß'],
  [/\bausschlie\?lich/g, 'ausschließlich'],
  [/\bAusschlie\?lich/g, 'Ausschließlich'],
  [/\bSchlu\?/g, 'Schluß'],
  [/Gr(?:ü|\?)(?:ß|\?)en/g, 'Grüßen'],
  [/Gr(?:ü|\?)(?:ß|\?)e\b/g, 'Grüße'],
  [/\bgem\?\?/g, 'gemäß'],
  // — ü family (no \b before \?)
  [/\bf\?r\b/g, 'für'],
  [/\bF\?r\b/g, 'Für'],
  [/\?berdachte/g, 'überdachte'],
  [/\?berblick/g, 'Überblick'],
  [/\?bermittelt/g, 'übermittelt'],
  [/\?bersetzt/g, 'übersetzt'],
  [/\?bernehmen/g, 'übernehmen'],
  [/\?bernimmt/g, 'übernimmt'],
  [/\?berweis/g, 'überweis'],
  [/\?berpr\?fen/g, 'überprüfen'],
  [/\?berpr\?ft/g, 'überprüft'],
  [/\?berwiegend/g, 'überwiegend'],
  [/\?ber eine/g, 'Über eine'],
  [/\?ber\b/g, 'über'],
  [/\bw\?rden\b/g, 'würden'],
  [/\bW\?rden\b/g, 'Würden'],
  [/\bw\?rde\b/g, 'würde'],
  [/\bW\?rde\b/g, 'Würde'],
  [/\bm\?ssen/g, 'müssen'],
  [/\bM\?ssen/g, 'Müssen'],
  [/\bm\?glich/g, 'möglich'],
  [/\bM\?glich/g, 'Möglich'],
  [/\bpr\?fen/g, 'prüfen'],
  [/\bPr\?fen/g, 'Prüfen'],
  [/\bzur\?ck/g, 'zurück'],
  [/\bZur\?ck/g, 'Zurück'],
  [/\bSt\?ck/g, 'Stück'],
  [/\bst\?ck/g, 'stück'],
  [/\bnat\?rlich/g, 'natürlich'],
  [/\bNat\?rlich/g, 'Natürlich'],
  [/\bgr\?n/g, 'grün'],
  [/\bGr\?n/g, 'Grün'],
  [/\bn\?tz/g, 'nütz'],
  [/\bGl\?ck/g, 'Glück'],
  [/\bgl\?ck/g, 'glück'],
  [/\bStellpl\?tze/g, 'Stellplätze'],
  [/Immobilientreuh\?nder/g, 'Immobilientreuhänder'],
  [/Gesch\?ftsf\?hrer/g, 'Geschäftsführer'],
  [/Gesch\?ftsabwick/g, 'Geschäftsabwick'],
  [/Vermittlungsb\?hne/g, 'Vermittlungsbühne'],
  [/\bB\?ro/g, 'Büro'],
  [/\bb\?ro/g, 'büro'],
  // — ä family
  [/\bGesch\?ft/g, 'Geschäft'],
  [/\bgesch\?ft/g, 'geschäft'],
  [/\bt\?glich/g, 'täglich'],
  [/\bT\?glich/g, 'Täglich'],
  [/\bn\?chst/g, 'nächst'],
  [/\bN\?chst/g, 'Nächst'],
  [/\bn\?mlich/g, 'nämlich'],
  [/\bst\?ndig/g, 'ständig'],
  [/\bverf\?gbar/g, 'verfügbar'],
  [/\bVerf\?gbar/g, 'Verfügbar'],
  [/\bw\?hrend/g, 'während'],
  [/\bW\?hrend/g, 'Während'],
  [/\btats\?chlich/g, 'tatsächlich'],
  [/\bTats\?chlich/g, 'Tatsächlich'],
  [/\bverst\?ndlich/g, 'verständlich'],
  [/\bL\?nge/g, 'Länge'],
  [/\bl\?nge/g, 'länge'],
  [/\bh\?tte\b/g, 'hätte'],
  [/\bh\?tten\b/g, 'hätten'],
  [/\bw\?re\b/g, 'wäre'],
  [/\bW\?re\b/g, 'Wäre'],
  [/\bS\?tze/g, 'Sätze'],
  [/\bPl\?tze/g, 'Plätze'],
  [/\bB\?ume/g, 'Bäume'],
  [/\bG\?ste/g, 'Gäste'],
  [/\bverk\?uf/g, 'verkäuf'],
  [/\bVerk\?uf/g, 'Verkäuf'],
  [/\?rzte/g, 'Ärzte'],
  [/\?hnlich/g, 'ähnlich'],
  [/\?ltest/g, 'ältest'],
  [/\?ltere/g, 'ältere'],
  [/\bM\?rz/g, 'März'],
  [/\bst\?rker/g, 'stärker'],
  // — ö family
  [/\bk\?nn/g, 'könn'],
  [/\bK\?nn/g, 'Könn'],
  [/\bsch\?n/g, 'schön'],
  [/\bSch\?n/g, 'Schön'],
  [/\bh\?ren/g, 'hören'],
  [/\bH\?ren/g, 'Hören'],
  [/\bm\?cht/g, 'möcht'],
  [/\bM\?cht/g, 'Möcht'],
  [/\bgeh\?rt/g, 'gehört'],
  [/\bgel\?st/g, 'gelöst'],
  [/\?ffn/g, 'öffn'],
  [/\?stlich/g, 'östlich'],
  [/\?ffentlich/g, 'öffentlich'],
  [/\bgr\?\?er/g, 'größer'],
  // — apostrophe mangling
  [/\bgeht\?s\b/g, "geht's"],
  [/\bhat\?s\b/g, "hat's"],
  [/\bist\?s\b/g, "ist's"],
  [/\bgibt\?s\b/g, "gibt's"],
  // — start-of-sentence Über
  [/^\s*\?ber\s/m, 'Über '],
]

export function repairUmlauts(text) {
  if (!text || !text.includes('?')) return text
  let out = String(text)
  for (const [pattern, replacement] of UMLAUT_REPAIRS) {
    out = out.replace(pattern, replacement)
  }
  return out
}

// ──────────────────────────────────────────────────────────────────────
// HTML entity decode
// ──────────────────────────────────────────────────────────────────────
export function decodeHtmlEntities(s) {
  if (!s) return ''
  return String(s)
    .replace(/&nbsp;/gi, ' ')
    .replace(/&lt;/gi, '<')
    .replace(/&gt;/gi, '>')
    .replace(/&quot;/gi, '"')
    .replace(/&(?:apos|#39);/gi, "'")
    .replace(/&amp;/gi, '&')
    .replace(/&#(\d+);/g, (_, n) => {
      try { return String.fromCharCode(parseInt(n, 10)) } catch (e) { return '' }
    })
    .replace(/&#x([0-9a-f]+);/gi, (_, n) => {
      try { return String.fromCharCode(parseInt(n, 16)) } catch (e) { return '' }
    })
}

// ──────────────────────────────────────────────────────────────────────
// HTML → structured plain text (used as fallback when body_html is
// absent). Converts block-level tags to newlines so table-layout mails
// don't collapse into one sentence.
// ──────────────────────────────────────────────────────────────────────
export function htmlToText(html) {
  if (!html) return ''
  return String(html)
    .replace(/<style[\s\S]*?<\/style>/gi, ' ')
    .replace(/<script[\s\S]*?<\/script>/gi, ' ')
    .replace(/<br\s*\/?>/gi, '\n')
    .replace(/<\/p>/gi, '\n\n')
    .replace(/<\/div>/gi, '\n')
    .replace(/<\/tr>/gi, '\n')
    .replace(/<\/td>/gi, '  ')
    .replace(/<\/li>/gi, '\n')
    .replace(/<\/h[1-6]>/gi, '\n\n')
    .replace(/<\/(?:pre|blockquote)>/gi, '\n')
    .replace(/<[^>]+>/g, ' ')
    .replace(/&nbsp;/gi, ' ')
    .replace(/&amp;/gi, '&')
    .replace(/&lt;/gi, '<')
    .replace(/&gt;/gi, '>')
    .replace(/&quot;/gi, '"')
    .replace(/&(?:apos|#39);/gi, "'")
    .replace(/&#(\d+);/g, (_, n) => {
      try { return String.fromCharCode(parseInt(n, 10)) } catch (e) { return '' }
    })
    .replace(/&#x([0-9a-f]+);/gi, (_, n) => {
      try { return String.fromCharCode(parseInt(n, 16)) } catch (e) { return '' }
    })
    .replace(/\r/g, '')
    .replace(/[ \t]+\n/g, '\n')
    .replace(/\n{3,}/g, '\n\n')
    .trim()
}

// ──────────────────────────────────────────────────────────────────────
// Run-on un-mangling
// ──────────────────────────────────────────────────────────────────────
function unmangleRunOns(text) {
  return String(text || '')
    .replace(/([,;])([A-ZÄÖÜ])/g, '$1 $2')
    .replace(/([.!?])(\d{3,})/g, '$1 $2')
    .replace(/(\d)([A-ZÄÖÜ][a-zäöüß])/g, '$1 $2')
}

// ──────────────────────────────────────────────────────────────────────
// Full clean pipeline for display
// ──────────────────────────────────────────────────────────────────────
export function cleanEmailBody(raw) {
  if (!raw) return ''
  const original = String(raw)
  let text = decodeHtmlEntities(original)
  text = repairUmlauts(text)

  // Strip quoted Outlook/Gmail forward header blocks
  text = text.replace(/^\s*(Von|From)\s*:.+\n(?:\s*(Gesendet|Date)\s*:.+\n)?(?:\s*(An|To)\s*:.+\n)?(?:\s*(Cc|CC)\s*:.*\n)?(?:\s*(Betreff|Subject)\s*:.+\n)?/gim, '')

  // Signature / DSGVO stripping — but stop at the forward separator
  // inserted by extractForwardMetadata so we don't eat the payload.
  const forwardSep = text.indexOf('— — —')
  if (forwardSep >= 0) {
    const before = text.slice(0, forwardSep)
    const after = text.slice(forwardSep)
    const cleanedBefore = before
      .replace(/\n\s*Mit freundlichen Gr(ü|\?|ue)(ß|\?|ss)en[\s\S]*$/i, '')
      .replace(/\n\s*Der Schutz von personenbezogenen Daten[\s\S]*$/i, '')
    text = cleanedBefore + after
    text = text.replace(/^\s*— — —\s*/, '')
  } else {
    text = text.replace(/\n\s*Mit freundlichen Gr(ü|\?|ue)(ß|\?|ss)en[\s\S]*$/i, '')
    text = text.replace(/\n\s*Der Schutz von personenbezogenen Daten[\s\S]*$/i, '')
  }

  // Reply-quote chains (English + German)
  text = text.replace(/\n\s*On\s+[A-Z0-9][^\n]{0,240}wrote\s*:[\s\S]*$/im, '')
  text = text.replace(/\n\s*Am \d{1,2}\.\d{1,2}\.\d{2,4}.*schrieb.*:[\s\S]*$/im, '')
  text = text.replace(/\n\s*Am\s+\w+[.,]?\s+\d{1,2}\.\s*\w+[.\s]+\d{2,4}[^\n]*schrieb[\s\S]*$/im, '')

  // "> " quoted lines at the end
  const lines = text.split('\n')
  let cutIdx = lines.length
  for (let i = lines.length - 1; i >= 0; i--) {
    if (lines[i].match(/^\s*>/) || lines[i].match(/^\s*\|/)) {
      cutIdx = i
    } else if (lines[i].trim() === '') {
      // skip blank
    } else {
      break
    }
  }
  text = lines.slice(0, cutIdx).join('\n')

  // Signature after --
  text = text.replace(/\n--\s*\n[\s\S]*$/m, '')
  text = text.replace(/(\n\s*){3,}/g, '\n\n')
  text = unmangleRunOns(text)
  text = text.trim()

  if (text.length < 10 && original.trim().length > 0) {
    return decodeHtmlEntities(original).trim()
  }
  return text
}

// ──────────────────────────────────────────────────────────────────────
// Forward metadata extraction
// ──────────────────────────────────────────────────────────────────────
// Returns { fromName, fromEmail, subject } when the body looks like a
// forward, otherwise null. Does NOT modify the message — the caller
// uses the metadata to render a header strip above the body.

const REPLY_ATTRIBUTION_RE = /(?:^|\n)\s*(?:>\s*)?(On|Am)\b[^\n]{0,240}\b(wrote|schrieb)\s*:/i
const BARE_ATTRIBUTION_RE = /\n\s*(?:>\s*)?[^\n<>]{1,120}\s(wrote|schrieb)\s*:\s*(?:\n|$)/i
const OWN_DOMAIN_RE = /@sr-homes\.at$/i

function normalizeForForwardSplit(text) {
  return String(text || '')
    .replace(/\r\n/g, '\n')
    .replace(/\r/g, '\n')
    .replace(/^\s*>\s?/gm, '')
    .replace(/\s+(Von|From|Gesendet|Date|An|To|Betreff|Subject)\s*:/gi, '\n$1:')
    .replace(/\n{3,}/g, '\n\n')
    .trim()
}

export function extractForwardMetadata(msg) {
  const bodyText = String(msg?.body_text || '')
  const htmlText = htmlToText(msg?.body_html || '')
  const body = normalizeForForwardSplit(bodyText || htmlText)
  if (!body) return null
  if (REPLY_ATTRIBUTION_RE.test(body) || BARE_ATTRIBUTION_RE.test(body)) return null

  const markerRegex = /\n-{2,}\s*(Weitergeleitete Nachricht|Forwarded message|Original Message|Original-Nachricht|Urspruengliche Nachricht)\s*-{2,}\n/i
  const markerMatch = body.match(markerRegex)
  let markerIndex = markerMatch?.index
  let markerLength = markerMatch?.[0]?.length || 0

  if (typeof markerIndex !== 'number') {
    const headerFallback = body.match(/\n\s*(Von|From)\s*:.+\n\s*(Gesendet|Date)\s*:.+\n\s*(An|To)\s*:.+\n\s*(Betreff|Subject)\s*:.+/im)
    if (headerFallback && typeof headerFallback.index === 'number') {
      markerIndex = headerFallback.index
      markerLength = 1
    }
  }
  if (typeof markerIndex !== 'number') {
    const genericHeader = body.match(/\n\s*(Von|From)\s*:.+\n[\s\S]{0,600}?\n\s*(Betreff|Subject)\s*:.+/im)
    if (genericHeader && typeof genericHeader.index === 'number' && genericHeader.index > 20) {
      markerIndex = genericHeader.index
      markerLength = 1
    }
  }
  if (typeof markerIndex !== 'number') return null

  const forwardedBlock = body.slice(markerIndex + markerLength).trim()
  if (!forwardedBlock) return null

  let forwardedFrom = ''
  let forwardedFromEmail = ''
  let forwardedSubject = ''

  const headerSplit = forwardedBlock.search(/\n\s*\n/)
  if (headerSplit >= 0) {
    const headerPart = forwardedBlock.slice(0, headerSplit)
    const fromMatch = headerPart.match(/^\s*(Von|From)\s*:\s*(.+)$/im)
    const subjectMatch = headerPart.match(/^\s*(Betreff|Subject)\s*:\s*(.+)$/im)
    forwardedFrom = (fromMatch?.[2] || '').trim()
    forwardedSubject = (subjectMatch?.[2] || '').trim()
    const angle = forwardedFrom.match(/<([^>]+@[^>]+)>/)
    const bare = forwardedFrom.match(/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-z]{2,})/i)
    if (angle?.[1]) {
      forwardedFromEmail = angle[1].trim().toLowerCase()
      forwardedFrom = forwardedFrom.replace(/\s*<[^>]+>\s*/, '').trim()
    } else if (bare?.[1]) {
      forwardedFromEmail = bare[1].trim().toLowerCase()
      forwardedFrom = forwardedFrom.replace(bare[1], '').replace(/\s+/g, ' ').trim()
    }
  }

  if (!forwardedFromEmail) {
    const directEmailMatch = forwardedBlock.match(/(?:^|\n)\s*(?:E-?Mail|Email)\s*:\s*([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-z]{2,})/i)
    const mailtoMatch = forwardedBlock.match(/mailto:([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-z]{2,})/i)
    const candidate = (directEmailMatch?.[1] || mailtoMatch?.[1] || '').trim().toLowerCase()
    if (candidate && !candidate.includes('noreply') && !candidate.includes('no-reply')) {
      forwardedFromEmail = candidate
      if (!forwardedFrom || /noreply|no-reply|immoji/i.test(forwardedFrom)) {
        forwardedFrom = candidate
      }
    }
  }

  // Don't attribute our own outbound as "forward" — legacy reply chains
  // in the body will have our own domain here.
  if (forwardedFromEmail && OWN_DOMAIN_RE.test(forwardedFromEmail)) return null

  return {
    fromName: forwardedFrom || forwardedFromEmail || null,
    fromEmail: forwardedFromEmail || null,
    subject: forwardedSubject || null,
  }
}
