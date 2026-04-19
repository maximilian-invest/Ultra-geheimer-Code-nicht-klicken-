<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Generates honest, non-hallucinating German Exposé descriptions for SR-Homes
 * properties.
 *
 *   - Objektbeschreibung: synthesised from the property row + optional
 *     uploaded documents (PDF/DOCX/XLSX text extracted via
 *     DocumentParserService).
 *   - Lagebeschreibung: grounded in Claude's web-search tool, so the text
 *     only claims what actually turned up for the address, zip and city.
 *
 * Prompts are intentionally strict: no marketing clichés, no invented
 * facts, no superlatives without source, sachlich-einladender Ton.
 */
class PropertyDescriptionService
{
    /**
     * Use a stronger model for prose generation than the project default
     * (haiku) — quality matters here and latency isn't a concern since the
     * user triggered the action explicitly.
     */
    private const MODEL = 'claude-sonnet-4-6';

    public function __construct(
        private AnthropicService $anthropic,
        private DocumentParserService $docs,
    ) {}

    /**
     * Generate the Objektbeschreibung from property fields + uploaded files.
     *
     * @param int   $propertyId
     * @param int[] $fileIds  property_files.id of documents to feed into the
     *                        model. Empty = no documents, just the row.
     * @return array{success: bool, text?: string, error?: string}
     */
    public function generateObjekt(int $propertyId, array $fileIds = []): array
    {
        $property = DB::table('properties')->where('id', $propertyId)->first();
        if (!$property) {
            return ['success' => false, 'error' => 'Objekt nicht gefunden'];
        }
        $property = (array) $property;

        $propertyFacts = $this->formatPropertyFacts($property);
        $documentText = $this->extractDocumentText($propertyId, $fileIds);

        $systemPrompt = $this->objektSystemPrompt();
        $userMessage = "PROPERTY-DATENSATZ:\n" . $propertyFacts
            . ($documentText !== '' ? "\n\n---\n\nDOKUMENT-AUSZÜGE:\n" . $documentText : '')
            . "\n\n---\n\nSchreibe jetzt die Objektbeschreibung.";

        $text = $this->anthropic->chat($systemPrompt, $userMessage, maxTokens: 2000, model: self::MODEL);

        if (!$text) {
            Log::warning("generateObjekt: no text for property {$propertyId}");
            return ['success' => false, 'error' => 'KI-Antwort leer. Bitte erneut versuchen.'];
        }

        return ['success' => true, 'text' => trim($text)];
    }

    /**
     * Generate the Lagebeschreibung using Claude's web_search tool so the
     * output is grounded in real, current information about the address.
     *
     * @return array{success: bool, text?: string, error?: string}
     */
    public function generateLage(int $propertyId): array
    {
        $property = DB::table('properties')->where('id', $propertyId)->first();
        if (!$property) {
            return ['success' => false, 'error' => 'Objekt nicht gefunden'];
        }

        $address = trim((string) ($property->address ?? ''));
        $zip = trim((string) ($property->zip ?? ''));
        $city = trim((string) ($property->city ?? ''));

        if ($city === '' && $zip === '' && $address === '') {
            return ['success' => false, 'error' => 'Adresse/PLZ/Ort fehlt — Recherche nicht möglich.'];
        }

        $fullAddress = trim(
            ($address !== '' ? $address . ', ' : '')
            . trim($zip . ' ' . $city)
        );

        // Ground the neighbourhood with OpenStreetMap's geocoder so the model
        // can't hallucinate a wrong Stadtteil from stray search hits.
        $lat = isset($property->latitude) ? (float) $property->latitude : null;
        $lon = isset($property->longitude) ? (float) $property->longitude : null;
        if ($lat === 0.0) $lat = null;
        if ($lon === 0.0) $lon = null;
        $district = $this->resolveNeighbourhood($address, $zip, $city, $lat, $lon);

        $districtLine = $district
            ? "BESTÄTIGTER STADTTEIL / ORTSTEIL (via Geocoding): {$district}\n"
                . "  → Wenn du einen Stadtteilnamen im Text nennst, MUSS es genau dieser sein. Kein anderer. Auch nicht wenn deine Suche einen anderen Namen vorschlägt.\n"
            : "STADTTEIL: konnte nicht verifiziert werden.\n"
                . "  → NENNE KEINEN Stadtteilnamen im Text. Erwähne höchstens die Gemeinde/Stadt '{$city}'. Auch wenn deine Suche Stadtteile vorschlägt — NICHT verwenden.\n";

        $systemPrompt = $this->lageSystemPrompt();
        $userMessage = "INPUT\n"
            . "Gemeinde: " . ($city !== '' ? $city : '(unbekannt)') . "\n"
            . "PLZ: " . ($zip !== '' ? $zip : '(unbekannt)') . "\n"
            . "Interne Adresse (NUR zur Recherche, im Text niemals erwähnen): {$fullAddress}\n"
            . $districtLine
            . "\n"
            . "RECHERCHE-AUFTRAG\n"
            . "Nutze die Web-Suche, um Antworten auf die Fragen zu finden, die ein Kaufinteressent zur Lage hat:\n"
            . "- Wo liegt der Stadtteil / die Gemeinde geografisch (Richtung, Stadt, Umland)?\n"
            . "- Charakter der Lage (Wohngebiet, Mischgebiet, Zentrum, Stadtrand, ländlich)?\n"
            . "- Nahversorgung (Supermärkte generisch; konkrete Ketten wie Spar/Billa/Hofer nur erwähnen wenn die Suche sie als tatsächlich dort ansässig bestätigt).\n"
            . "- Schulen, Kindergärten, Ärzte, Apotheken in der Gemeinde (allgemein beschreiben, falls keine konkreten Namen belegt sind).\n"
            . "- Grünraum / Erholung / Aktivitäten (Seen, Berge, Parks, Wanderwege — nur mit Belegen).\n"
            . "- Autobahn-/Hauptstraßen-Anschluss (A1, A10 etc. — Name nur wenn belegbar).\n"
            . "- ÖPNV (allgemein, wenn keine konkrete Linie belegt).\n\n"
            . "AUSGABE\n"
            . "Schreibe nach den Regeln im System-Prompt: 3 Absätze (Makrolage, Mikrolage, Erreichbarkeit), 80-160 Wörter, dritte Person, sachlich. Kein Marketing, keine Floskeln, keine Trivia (Bewohnerzahlen, Öffnungszeiten, Trägernamen). Keine Straße. Keinen Stadtteilnamen, wenn oben nicht bestätigt. Keine konkreten Namen/Distanzen/Linien ohne Suchergebnis-Beleg. Vor der Ausgabe die Selbstprüfung (Regel 8) komplett durchgehen.";

        $text = $this->anthropic->chatWithWebSearch(
            $systemPrompt,
            $userMessage,
            maxSearches: 6,
            maxTokens: 3000,
            model: self::MODEL,
        );

        if (!$text) {
            Log::warning("generateLage: no text for property {$propertyId}");
            return ['success' => false, 'error' => 'KI-Antwort leer. Bitte erneut versuchen.'];
        }

        $text = $this->sanitizeLageOutput($text, $address, $city);
        if ($text === '') {
            return ['success' => false, 'error' => 'KI-Antwort konnte nicht bereinigt werden. Bitte erneut versuchen.'];
        }

        return ['success' => true, 'text' => $text];
    }

    /**
     * Post-process the model's Lage output:
     *   - strip any meta-commentary Claude prepended (research chatter,
     *     "Hier ist die Beschreibung", markdown separators, etc.)
     *   - strip any street name mentions if the model leaks the address
     *     despite the prompt rules
     *   - collapse accidental runs of blank lines to a single blank line
     *   - strip markdown bold/italic/bullets that aren't wanted in plain text
     */
    private function sanitizeLageOutput(string $text, string $address, string $city): string
    {
        // 1) Drop everything up to and including the last horizontal-rule
        //    separator (e.g. "---\n") — Claude sometimes writes its thinking,
        //    then a separator, then the real answer.
        if (preg_match('/.*(?:^|\n)\s*-{3,}\s*(?:\n|$)/s', $text, $m, PREG_OFFSET_CAPTURE)) {
            $text = substr($text, $m[0][1] + strlen($m[0][0]));
        }

        // 2) Normalise Windows line endings and trim.
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = trim($text);

        // 3) Strip leading meta-commentary paragraphs. Keep cutting whole
        //    paragraphs off the front until one looks like real prose.
        $metaPatterns = [
            '/^\s*I[\'’]ll\s/i',
            '/^\s*I\s+(?:will|\'ll|am\s+going\s+to|now|already)\b/i',
            '/^\s*Let\s+me\b/i',
            '/^\s*Good\s*[—–-]/i',
            '/^\s*Based\s+on\b/i',
            '/^\s*Here\s+is\b/i',
            '/^\s*Hier\s+ist\s+die\b/i',
            '/^\s*Ich\s+(?:werde|habe\s+(?:jetzt|nun|bereits)|recherchiere|suche)\b/i',
            '/^\s*Basierend\s+auf\b/i',
            '/^\s*(?:Zusammenfassung|Übersicht|Recherche)\s*[:\-]/i',
        ];

        $paragraphs = preg_split('/\n\s*\n+/', $text);
        while (!empty($paragraphs)) {
            $first = ltrim($paragraphs[0]);
            if ($first === '') {
                array_shift($paragraphs);
                continue;
            }
            $looksMeta = false;
            foreach ($metaPatterns as $pat) {
                if (preg_match($pat, $first)) { $looksMeta = true; break; }
            }
            if ($looksMeta) {
                array_shift($paragraphs);
                continue;
            }
            break;
        }
        $text = implode("\n\n", $paragraphs);

        // 4) Strip markdown emphasis / bullets — we want plain prose.
        $text = preg_replace('/\*\*([^*]+)\*\*/', '$1', $text);   // **bold**
        $text = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/', '$1', $text); // *italic*
        $text = preg_replace('/^\s*[-•]\s+/m', '', $text);        // leading bullets

        // 5) Strip street names: extract the street part from the address
        //    and remove any occurrence from the output. Best-effort: the
        //    prompt is the primary defence; this is a safety net.
        $streetToken = $this->extractStreetToken($address);
        if ($streetToken !== '') {
            // Word-boundary, case-insensitive removal. Also strip trailing
            // house numbers like "Weiherweg 2" → empty.
            $text = preg_replace(
                '/\b' . preg_quote($streetToken, '/') . '\b(?:\s+\d+\w?)?/iu',
                '',
                $text,
            );
        }

        // 6) Collapse runs of blank lines to exactly one; trim trailing
        //    whitespace on each line; drop empty paragraphs created by
        //    the street strip.
        $lines = explode("\n", $text);
        $lines = array_map(fn ($l) => rtrim($l), $lines);
        $cleaned = [];
        $blankStreak = 0;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                $blankStreak++;
                if ($blankStreak === 1) $cleaned[] = '';
            } else {
                $blankStreak = 0;
                $cleaned[] = $line;
            }
        }
        $text = trim(implode("\n", $cleaned));

        // 7) Cap at exactly 3 paragraphs. The prompt demands Makrolage,
        //    Mikrolage, Erreichbarkeit — if the model adds a fourth, we cut.
        $paragraphs = preg_split('/\n\s*\n+/', $text);
        if (count($paragraphs) > 3) {
            $paragraphs = array_slice($paragraphs, 0, 3);
            $text = implode("\n\n", $paragraphs);
        }

        return $text;
    }

    /**
     * Resolve the Stadtteil / suburb for the given address via OpenStreetMap's
     * Nominatim geocoder, so the prompt can hand Claude the real district
     * name as a hard fact instead of letting it guess from search hits.
     *
     * Prefers reverse geocode (via stored lat/lon) when available, falls back
     * to forward geocode via the full address string. Returns null on any
     * error or when no district-level field comes back. Austria-only.
     */
    private function resolveNeighbourhood(string $address, string $zip, string $city, ?float $lat = null, ?float $lon = null): ?string
    {
        $userAgent = 'SR-Homes-Admin/1.0 (office@sr-homes.at)';

        try {
            // 1) Reverse geocode — most accurate when coords exist.
            if ($lat !== null && $lon !== null && abs($lat) > 0.0001 && abs($lon) > 0.0001) {
                $resp = \Illuminate\Support\Facades\Http::withHeaders([
                    'User-Agent' => $userAgent,
                    'Accept-Language' => 'de',
                ])->timeout(10)->get('https://nominatim.openstreetmap.org/reverse', [
                    'lat' => $lat,
                    'lon' => $lon,
                    'format' => 'json',
                    'addressdetails' => 1,
                    'zoom' => 16,
                ]);
                if ($resp->successful()) {
                    $d = $this->pickDistrict(($resp->json()['address'] ?? []));
                    if ($d) return $d;
                }
            }

            // 2) Forward geocode from the address string.
            $query = trim(
                ($address !== '' ? $address . ', ' : '') .
                trim($zip . ' ' . $city)
            );
            if ($query === '') return null;

            $resp = \Illuminate\Support\Facades\Http::withHeaders([
                'User-Agent' => $userAgent,
                'Accept-Language' => 'de',
            ])->timeout(10)->get('https://nominatim.openstreetmap.org/search', [
                'q' => $query,
                'format' => 'json',
                'addressdetails' => 1,
                'limit' => 1,
                'countrycodes' => 'at',
            ]);
            if (!$resp->successful()) return null;

            $json = $resp->json();
            if (empty($json) || empty($json[0]['address'])) return null;
            return $this->pickDistrict($json[0]['address']);
        } catch (\Throwable $e) {
            Log::warning('resolveNeighbourhood failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Pick the most specific neighbourhood-level name from a Nominatim
     * address object. Nominatim uses different keys depending on how OSM
     * tagged the area (suburb / city_district / quarter / neighbourhood …).
     */
    private function pickDistrict(array $osmAddress): ?string
    {
        foreach (['suburb', 'city_district', 'quarter', 'neighbourhood', 'borough', 'town_district', 'hamlet'] as $key) {
            if (!empty($osmAddress[$key])) {
                return (string) $osmAddress[$key];
            }
        }
        return null;
    }

    /**
     * Take the raw address string and return a single-word street token
     * useful for scrubbing from the output. Examples:
     *   "Enzingergasse 5" → "Enzingergasse"
     *   "Weiherweg 2, Grödig" → "Weiherweg"
     *   "Am Dorfplatz 10" → "Dorfplatz"
     *   "" → ""
     */
    private function extractStreetToken(string $address): string
    {
        $address = trim($address);
        if ($address === '') return '';

        // Take only the part before the first comma or house number.
        $parts = preg_split('/,/', $address, 2);
        $streetPart = trim($parts[0] ?? '');
        // Strip trailing house numbers (digits + optional letter suffix).
        $streetPart = preg_replace('/\s+\d+\w?$/u', '', $streetPart);
        $streetPart = trim($streetPart);
        if ($streetPart === '') return '';

        // Prefer the last word — compound street names like "Am Dorfplatz"
        // still scrub via the distinctive "Dorfplatz" token.
        $words = preg_split('/\s+/', $streetPart);
        $last = end($words) ?: '';
        // Safety: require at least 5 chars so we don't wipe common words.
        return (mb_strlen($last) >= 5) ? $last : '';
    }

    /**
     * Polish an existing description text: fix formatting / paragraph breaks
     * / weird line breaks from copy-paste, correct wording and spelling, but
     * NEVER invent new facts. Unwanted content (banned topics, marketing
     * filler, Sie-Form for Lage, etc.) is removed or neutralised.
     *
     * @param string $type 'objekt' | 'lage'
     * @param string $text the current draft
     * @return array{success: bool, text?: string, error?: string}
     */
    public function polish(string $type, string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['success' => false, 'error' => 'Kein Text zum Verbessern'];
        }
        if (!in_array($type, ['objekt', 'lage'], true)) {
            return ['success' => false, 'error' => 'type must be objekt or lage'];
        }

        $systemPrompt = $type === 'lage'
            ? $this->polishLageSystemPrompt()
            : $this->polishObjektSystemPrompt();

        $improved = $this->anthropic->chat($systemPrompt, $text, maxTokens: 3000, model: self::MODEL);

        if (!$improved) {
            Log::warning("polish: no text returned for type={$type}");
            return ['success' => false, 'error' => 'KI-Antwort leer. Bitte erneut versuchen.'];
        }

        $improved = $this->sanitizeAnyOutput($improved);
        if ($type === 'lage') {
            // Enforce 3-paragraph cap same as generateLage.
            $paragraphs = preg_split('/\n\s*\n+/', $improved);
            if (count($paragraphs) > 3) {
                $paragraphs = array_slice($paragraphs, 0, 3);
                $improved = implode("\n\n", $paragraphs);
            }
        }

        if ($improved === '') {
            return ['success' => false, 'error' => 'Text konnte nicht bereinigt werden.'];
        }

        return ['success' => true, 'text' => $improved];
    }

    /**
     * Cleanup common to both polish modes: strip meta-commentary, drop
     * markdown emphasis/bullets, collapse blank-line runs.
     */
    private function sanitizeAnyOutput(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = trim($text);

        // Drop a leading markdown separator block if present.
        if (preg_match('/.*(?:^|\n)\s*-{3,}\s*(?:\n|$)/s', $text, $m, PREG_OFFSET_CAPTURE)) {
            $text = substr($text, $m[0][1] + strlen($m[0][0]));
            $text = trim($text);
        }

        // Peel off leading meta paragraphs.
        $metaPatterns = [
            '/^\s*(?:Hier\s+ist|Here\s+is|Der\s+(?:verbesserte|polierte))\b/i',
            '/^\s*(?:Polierter|Verbesserter|Finaler)\s+Text\s*[:\-]/i',
            '/^\s*Ich\s+(?:habe|werde)\b/i',
            '/^\s*(?:Basierend|Based)\s+auf\b/i',
        ];
        $paragraphs = preg_split('/\n\s*\n+/', $text);
        while (!empty($paragraphs)) {
            $first = ltrim($paragraphs[0]);
            if ($first === '') { array_shift($paragraphs); continue; }
            $looksMeta = false;
            foreach ($metaPatterns as $pat) {
                if (preg_match($pat, $first)) { $looksMeta = true; break; }
            }
            if ($looksMeta) { array_shift($paragraphs); continue; }
            break;
        }
        $text = implode("\n\n", $paragraphs);

        // Strip markdown emphasis / bullets.
        $text = preg_replace('/\*\*([^*]+)\*\*/', '$1', $text);
        $text = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/', '$1', $text);
        $text = preg_replace('/^\s*[-•]\s+/m', '', $text);

        // Normalise blank lines.
        $lines = array_map(fn ($l) => rtrim($l), explode("\n", $text));
        $cleaned = [];
        $blank = 0;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                $blank++;
                if ($blank === 1) $cleaned[] = '';
            } else {
                $blank = 0;
                $cleaned[] = $line;
            }
        }
        return trim(implode("\n", $cleaned));
    }

    private function polishObjektSystemPrompt(): string
    {
        return <<<'PROMPT'
Du bekommst einen Entwurf einer Objektbeschreibung für ein Immobilien-Exposé. Deine Aufgabe: formatiere und poliere ihn, OHNE Fakten hinzuzufügen oder zu entfernen.

REGELN — NIEMALS BRECHEN:

1. ERHALTE ALLE FAKTEN
   - Jeder Fakt, jede Zahl, jede Eigenschaft aus dem Entwurf muss im polierten Text erhalten bleiben.
   - KEINE neuen Fakten erfinden. Wenn eine Formulierung unklar ist, formuliere sie behutsam um — ergänze aber keine Details, die nicht schon im Entwurf stehen.

2. INHALT AUS DEM TEXT ENTFERNEN, falls vorhanden (verbotene Themen)
   - Kaufpreis, Mietpreis, Betriebskosten, Nebenkosten, Provisionen — alle finanziellen Zahlen.
   - Projektname oder Bauträger-Marken.
   - Energiewerte (HWB, fGEE, Effizienzklasse). Die stehen an eigener Stelle im Exposé.
   - Straßenname, Hausnummer, PLZ — Stadt allein ist OK.

3. FORMATIERUNG
   - Text in saubere Absätze gliedern, mit GENAU EINER Leerzeile dazwischen.
   - Komische Zeilenumbrüche (z. B. nach kurzer Zeichenlänge aus PDF-Copypaste) entfernen; Absätze wieder zu fließendem Text zusammenfügen.
   - Wenn der Entwurf eine einzige lange Textwurst ist: an inhaltlichen Grenzen (Raum → Ausstattung → Technik → Zielgruppe) neue Absätze einfügen.
   - KEIN Markdown (kein **bold**, kein *italic*, keine Bullet-Lists, keine Überschriften).

4. WORDING
   - Grammatik, Rechtschreibung und Interpunktion korrigieren.
   - Sachlich-einladender Ton, Sie-Form, österreichisches Deutsch.
   - Satzbau verbessern wo umständlich.
   - Füllfloskeln streichen: "traumhaft", "einmalig", "Filetstück", "Juwel", "Greifen Sie zu", "keine Wünsche offen lässt", "Wohnen mit Stil".
   - Inhaltslose Wertungs-Adjektive streichen oder neutralisieren: "gehoben", "hochwertig", "modern", "luxuriös", "lichtdurchflutet", "einladend", "großzügig geschnitten", "klare Raumaufteilung", "harmonisches Zusammenspiel" — ENTFERNEN, außer ein Fakt im Text stützt sie konkret (z. B. eine im Text genannte Ausstattungsstufe).

5. AUSGABE
   - Antworte NUR mit dem polierten Text. Keine Einleitung ("Hier ist der verbesserte Text:"), keine Metakommentare, keine Markdown-Syntax.
   - Mehrere Absätze, durch je eine Leerzeile getrennt.
PROMPT;
    }

    private function polishLageSystemPrompt(): string
    {
        return <<<'PROMPT'
Du bekommst einen Entwurf einer Lagebeschreibung für ein Immobilien-Exposé. Deine Aufgabe: formatiere und poliere ihn, OHNE Fakten hinzuzufügen oder zu entfernen.

REGELN — NIEMALS BRECHEN:

1. ERHALTE ALLE FAKTEN
   - Jeder Ortsname, jede Entfernung, jede Linie aus dem Entwurf bleibt erhalten.
   - KEINE neuen Fakten erfinden — auch keine Linien-Nummern, Distanzen, Geschäftsnamen, Stadtteile. Wenn du unsicher bist, formuliere allgemein um ("in fußläufiger Nähe", "gute Anbindung") statt zu spezifizieren.

2. STRUKTUR (ZWINGEND)
   Der polierte Text besteht aus GENAU 3 ABSÄTZEN in dieser Reihenfolge:
   1) Makrolage — Stadt/Gemeinde (+ Stadtteil falls im Entwurf vorhanden), sachliche Einordnung (Wohngebiet / Mischgebiet / Stadtrand / Zentrum / ländlich).
   2) Mikrolage — Nahversorgung, Schulen, Kindergärten, Ärzte, Grünraum, Aktivitäten.
   3) Erreichbarkeit — Autobahn / Hauptstraße + ÖPNV.
   Wenn der Entwurf anders gegliedert ist: umstrukturieren. Inhalte, die in keine der 3 Kategorien passen, weglassen.

3. LÄNGE
   - 80-160 Wörter gesamt. Kürzen wo Entwurf zu lang. NICHT künstlich erweitern wenn kurz — keine neuen Fakten erfinden.

4. TON
   - Sachlich, nüchtern, Notariatstext-artig. DRITTE PERSON. Kein "Sie", keine direkte Ansprache.
   - Keine Fragen, keine rhetorischen Figuren. Keine Ausrufezeichen.
   - Präsens, Aktiv.

5. AUS DEM TEXT STREICHEN
   - Marketing-Floskeln: "traumhaft", "einmalig", "Filetstück", "Juwel", "pulsierendes Leben", "grünes Herz", "Wohnen mit Stil", "keine Wünsche offen lässt", "das Beste aus zwei Welten", "hier lässt es sich leben", "zum Wohlfühlen".
   - Superlative ohne Beleg im Entwurf.
   - Trivia ohne Relevanz für Kaufinteressenten: Bewohnerzahlen, Altersverteilungen, Öffnungszeiten, Trägernamen, historische Gründungsjahre, Preisstatistiken.
   - Straßennamen, Hausnummern, PLZ.

6. FORMATIERUNG
   - Zwischen den 3 Absätzen genau EINE Leerzeile.
   - Komische Zeilenumbrüche aus Copypaste entfernen.
   - KEIN Markdown (kein **bold**, keine Bullets, keine Überschriften).

7. AUSGABE
   - Antworte NUR mit dem polierten Text. 3 Absätze. Nichts sonst.
PROMPT;
    }

    // ─── Prompts ───────────────────────────────────────────────────────

    private function objektSystemPrompt(): string
    {
        return <<<'PROMPT'
Du schreibst Objektbeschreibungen für ein seriöses österreichisches Immobilienbüro (SR-Homes). Diese Texte landen im Exposé und werden von Kaufinteressenten gelesen.

DEINE AUFGABE
Schreibe eine Objektbeschreibung auf Deutsch, 4-6 sachlich-einladende Absätze, die den Interessenten Lust auf eine Besichtigung macht, OHNE etwas zu erfinden oder schönzureden.

STRIKTE REGELN — NIEMALS BRECHEN:

1. FAKTENBASIS
   - Verwende NUR Fakten, die im bereitgestellten Property-Datensatz oder in den Dokument-Auszügen (z. B. Exposé-PDF) stehen.
   - Wenn ein Fakt dort nicht vorkommt: weglassen. NICHT annehmen, NICHT aus Allgemeinwissen ableiten.
   - Keine Erfindung von Zimmerzahlen, Flächen, Baujahr, Ausstattung, Heizart oder Zustand.

2. HOCHGELADENE DOKUMENTE PRIORISIEREN
   - Wenn ein Exposé oder ähnliches Dokument mitgeliefert wurde: bauformulierungen und Details daraus übernehmen, wo sie zu den strukturierten Fakten passen.
   - Dokument-Fakten und Datensatz-Fakten ZUSAMMENFÜHREN, nicht isoliert nebeneinanderstellen.

3. VERBOTENE THEMEN — NIEMALS ERWÄHNEN
   - Kaufpreis, Mietpreis, Betriebskosten, Nebenkosten, Provisionen, finanzielle Zahlen jeder Art.
   - Projektname oder Projekt-/Bauträger-Marken (auch wenn im Datensatz vermerkt).
   - Energiewerte (HWB, fGEE, Effizienzklasse). Das steht an eigener Stelle im Exposé.
   - Genaue Adresse (Straße + Hausnummer). Stadt OK.
   - PLZ / Postleitzahl — niemals im Text erwähnen. "Salzburg" ja, "Salzburg (PLZ 5020)" nein.

4. KEINE WERTUNGS-ADJEKTIVE OHNE BELEG IM DATENSATZ
   - "gehoben", "hochwertig", "luxuriös", "modern", "exklusiv", "großzügig", "lichtdurchflutet", "einladend", "geräumig", "wohnlich", "komfortabel", "stilvoll", "elegant", "besondere Qualität", "besondere Ausstattung" — NIEMALS schreiben, außer das passende Feld ist im Datensatz EXAKT mit diesem Wort belegt (z. B. quality=gehoben) oder das Exposé-Dokument nennt es wortwörtlich.
   - "Klare Raumaufteilung", "durchdachtes Konzept", "harmonisches Zusammenspiel" und ähnliche inhaltsleere Werturteile sind ebenfalls tabu.
   - Fakt: "74 m² auf 2 Zimmer" — erlaubt. Werturteil dazu ("großzügig geschnittene 74 m²") — verboten.
   - Im Zweifel: Fakt nennen, Wertung weglassen.

5. KEINE FLOSKELN ODER ÜBERTREIBUNGEN
   - Tabu: "traumhaft", "einmalig", "wunderschön", "charmant", "liebevoll", "gemütlich", "familienfreundlich", "perfekt", "einzigartig" — es sei denn, genau so im Quellmaterial.
   - Tabu: "Greifen Sie jetzt zu", "lassen Sie sich verzaubern", "nicht verpassen", "top Investition".
   - Keine rhetorischen Fragen, keine Anreden ("Wünschen Sie sich ...?").

5. AUSFÜHRLICH AUSFORMULIEREN
   - Jeden Fakt in vollständige, fließende Sätze einbetten — nicht als Liste aneinanderreihen. "Mit 110 m² Wohnfläche auf 4 Zimmer verteilt bietet das Objekt Raum für verschiedene Nutzungskonzepte." statt "110 m², 4 Zimmer."
   - Verbinde Fakten mit sachlicher Überleitung. Schreibe ruhig 2-3 Sätze zu einem einzelnen Aspekt, wenn die Fakten das hergeben.
   - ABER: keine neuen Fakten erfinden, keine Annahmen ergänzen. Nur die vorhandenen Fakten wortreicher und lesbarer darstellen.

6. KONKRET STATT VAGE
   - Wo Zahlen/Details vorhanden: NENNEN (ausgenommen die verbotenen Themen aus Regel 3).
   - Wo nicht: Aussage weglassen, nicht durch Floskel ersetzen.

7. TON
   - Sachlich-einladend. Ruhig. Als ob ein erfahrener Makler sachlich erklärt, was das Objekt hat.
   - Deutsch mit österreichischem Sprachgebrauch (Erdgeschoss, Parkett, Fernwärme etc.).
   - Sie-Form, sparsam und unaufdringlich.

8. STRUKTUR (als Leitplanke, nicht starr)
   - Absatz 1: Einstieg — Objekttyp, grobe Lage (Stadt/Ortsteil), eine prägnante Kernbotschaft aus den Fakten.
   - Absatz 2-3: Das Innere — Räume, Aufteilung, Flächen, Ausstattung (belegbar). Ein Absatz für den Wohnbereich, einer für weitere Räume/Ausstattung ist häufig sinnvoll.
   - Absatz 4: Technik & Bauzustand — Heizungsart (ohne Zahlen), Baujahr, Sanierungen, Bauweise. KEINE Energiewerte.
   - Optionaler Schluss: 1-2 Sätze zu möglicher Nutzung ("eignet sich für ..." / "bietet Raum für ...") — NUR wenn aus Fakten ableitbar.

9. WEGLASSEN IST STÄRKE
   - Wenn Daten spärlich: Schreibe weniger. Kurzer ehrlicher Text ist besser als aufgeblasener.
   - Wenn ein Feld widersprüchlich ist: vorsichtig formulieren ("laut Unterlagen ...") oder weglassen.

10. HIGHLIGHTS
    - Wenn "highlights" im Datensatz stehen: deren Kerninhalte in die Beschreibung einweben, nicht wörtlich kopieren.

11. OUTPUT
    - Antworte NUR mit dem Beschreibungstext. Keine Überschrift "Objektbeschreibung:", keine Markdown-Formatierung, keine Metakommentare, keine Quellenangaben.
    - Mehrere Absätze mit Leerzeilen trennen.
PROMPT;
    }

    private function lageSystemPrompt(): string
    {
        return <<<'PROMPT'
Du schreibst eine LAGEBESCHREIBUNG für ein Immobilien-Exposé. Halte dich AUSNAHMSLOS an die folgenden Regeln. Verstöße werden zurückgewiesen.

Du hast Zugang zur Web-Suche. Nutze sie, um die tatsächlichen Fakten über Gemeinde, Bezirk und Umfeld zu recherchieren. Schreibe dann den Text ausschließlich auf Basis dessen, was die Suche konkret bestätigt.

─── HARTE REGELN (nicht verhandelbar) ───────────────────────────────────────

REGEL 1 — KEINE HALLUZINATIONEN
- KEINE Namen von Geschäften, Restaurants, Cafés, Ärzten, Apotheken, Schulen, Kindergärten, Supermärkten — es sei denn, die Web-Suche liefert den exakten Namen als Fakt für genau diese Lage.
- KEINE konkreten Entfernungen ("500 m", "3 Gehminuten") — es sei denn, die Suche liefert die Zahl für genau diese Adresse/diesen Stadtteil.
- KEINE Fahrzeiten ("15 Minuten zur Innenstadt") — es sei denn, die Suche belegt sie konkret.
- KEINE Linien-Nummern (O-Bus 3, S-Bahn S3, Bus 170) — es sei denn, belegt.
- KEINE Bezirks-/Ortsteil-Namen, die nicht im User-Prompt als BESTÄTIGTER STADTTEIL angegeben oder in der Adresse enthalten sind.
- Wenn ein Detail fehlt: ALLGEMEIN formulieren ("in fußläufiger Nähe", "gute Anbindung", "Nahversorger im Ortsgebiet", "öffentliche Verkehrsmittel vorhanden") ODER ganz weglassen. NIEMALS raten.

REGEL 2 — KEIN MARKETING-BLABLA
Verboten sind diese Floskeln (auch sinngemäße Varianten):
- "begehrte Wohngegend", "absolute Top-Lage", "Filetstück", "Juwel", "Schmuckstück", "Hot Spot", "Perle"
- "einmalige Gelegenheit", "nicht alltägliches Angebot", "Liebhaberobjekt"
- "ruhig und zentral zugleich" (Widerspruch ohne Beleg)
- "grünes Herz", "pulsierendes Leben", "urbanes Flair"
- "hier lässt es sich leben", "zum Wohlfühlen", "Wohnen mit Stil"
- "keine Wünsche offen lässt", "das Beste aus zwei Welten"
- Ausrufezeichen sind VERBOTEN.
- Superlative ohne Beleg sind VERBOTEN ("die beste", "der schönste", "die ruhigste", "besonders begehrt").

REGEL 3 — STRUKTUR (EXAKT DIESE REIHENFOLGE)
Der Text besteht aus GENAU 3 ABSÄTZEN, in dieser Reihenfolge:

1) MAKROLAGE (1-3 Sätze):
   Stadt/Gemeinde + Bezirk/Ortsteil. Sachliche Einordnung (Wohngebiet / Mischgebiet / Stadtrand / Zentrum / ländlich). Nur was aus Adresse + Suche belegt ist.

2) MIKROLAGE (2-4 Sätze):
   Unmittelbares Umfeld. Nahversorgung, Schulen/Kindergärten, Ärzte/Apotheken, Grün-/Erholungsraum, Aktivitäten. Beantwortet dem Leser: "Was habe ich in der Nähe?"
   Konkret NUR bei Belegen. Sonst allgemein ("Nahversorger im Ortsgebiet", "Schulen und Kindergärten in der Gemeinde", "Erholungsmöglichkeiten in unmittelbarer Umgebung").

3) ERREICHBARKEIT (1-2 Sätze):
   Anbindung an Autobahn/Hauptstraße und ÖPNV. Beantwortet: "Wie komme ich weg und wieder her?"
   Konkrete Autobahn-Namen (A1, A10 etc.) nur wenn belegt. ÖPNV nur allgemein beschreiben, wenn keine konkrete Linie bestätigt ist.

KEIN vierter Absatz. KEINE Überschriften. KEINE Bulletpoints.

REGEL 4 — LÄNGE & TON
- 80-160 Wörter gesamt. Nicht mehr, nicht weniger.
- Sachlich, nüchtern, beschreibend. Wie ein Notariatstext mit Immobilienbezug — nicht wie eine Werbeanzeige.
- Kein "Sie" / keine direkte Ansprache. Keine Fragen. Keine rhetorischen Figuren.
- Präsens, Aktiv, DRITTE PERSON ("Das Objekt liegt ...", "Im Ortsgebiet befinden sich ...", "Die Gemeinde verfügt über ...").

REGEL 5 — KEINE OBJEKTBEWERTUNG
Der Text beschreibt AUSSCHLIESSLICH DIE LAGE, NIE das Objekt selbst:
- Keine Quadratmeter, keine Ausstattung, keine Räume, keine Preise.
- Kein "ideal für Familien" / "perfekt für Paare" — außer die Zielgruppe wird explizit vorgegeben.

REGEL 6 — ADRESSE
- Straße und Hausnummer KOMMEN NIEMALS im Text vor (auch nicht indirekt).
- Nur Gemeinde + bestätigter Stadtteil/Bezirk (falls im User-Prompt angegeben) sind erlaubt.

REGEL 7 — KEINE ÖFFNUNGSZEITEN, KEINE TRIVIA
- Keine Öffnungszeiten, Betriebszeiten, Trägernamen, Vereinsnamen, Pfarrnamen.
- Keine Fakten über Bewohnerzahlen, Altersverteilung, historische Gründungsdaten, Immobilienpreise-Statistiken — das interessiert einen Kaufinteressenten NICHT an dieser Stelle.

REGEL 8 — SELBSTPRÜFUNG VOR AUSGABE (Pflicht, intern)
Vor Rückgabe prüfst du jede Aussage gegen diese Checkliste und überarbeitest bei Bedarf:
- Jeder Eigenname (Geschäft, Schule, Linie) steht belegt in der Web-Suche?
- Jede Zahl (Entfernung, Fahrzeit) steht belegt in der Web-Suche?
- Keine verbotene Floskel aus Regel 2?
- Genau 3 Absätze, 80-160 Wörter?
- Keine Ausrufezeichen, keine "Sie"-Ansprache?
- Keine Straße/Hausnummer?
- Keine Objektbewertung?
- Keine Öffnungszeiten/Trivia?
Wenn irgendein Punkt nicht erfüllt: überarbeiten, NICHT ausgeben.

REGEL 9 — AUSGABEFORMAT
- Gib AUSSCHLIESSLICH die Lagebeschreibung als Fließtext aus.
- Keine Einleitung ("Hier ist ..."), keine Nachbemerkung, keine Meta-Kommentare, keine "I'll research", "Let me search", "Ich habe jetzt alle Fakten" etc.
- Keine Markdown-Formatierung (kein **bold**, kein *italic*, keine Bullet-Points, keine Überschrift).
- 3 Absätze durch je EINE Leerzeile getrennt.

Wenn der Input so dünn ist, dass du auch mit der Web-Suche keine Makrolage beschreiben kannst (z. B. keine Stadt, keine Gemeinde, keinerlei Treffer), gib STATT einer Beschreibung genau diese eine Zeile zurück:
FEHLT: <Komma-separierte Liste der fehlenden Pflichtangaben>
PROMPT;
    }

    // ─── Helpers ───────────────────────────────────────────────────────

    /**
     * Convert the property row into a compact, labeled fact list for the
     * prompt. Empty fields are omitted entirely so the model doesn't try to
     * fabricate around them.
     */
    private function formatPropertyFacts(array $p): string
    {
        // Excluded on purpose (must not appear in the Objektbeschreibung):
        //   project_name, purchase_price, rental_price, operating_costs,
        //   heating_demand_*, energy_efficiency_value  (covered elsewhere
        //   in the Exposé; forbidden topic per the prompt).
        $labels = [
            'object_type' => 'Objekttyp',
            'property_category' => 'Kategorie',
            'object_subtype' => 'Subtyp',
            'city' => 'Stadt',
            'marketing_type' => 'Vermarktungsart',
            'living_area' => 'Wohnfläche (m²)',
            'realty_area' => 'Grundstücksfläche (m²)',
            'free_area' => 'Freifläche (m²)',
            'rooms_amount' => 'Zimmer',
            'bedrooms' => 'Schlafzimmer',
            'bathrooms' => 'Badezimmer',
            'floor_number' => 'Geschoss',
            'floor_count' => 'Stockwerke',
            'construction_year' => 'Baujahr',
            'year_renovated' => 'Saniert',
            'realty_condition' => 'Zustand',
            'quality' => 'Qualität',
            'heating' => 'Heizungsart',
            'construction_type' => 'Bauweise',
            'ownership_type' => 'Eigentumsart',
            'orientation' => 'Ausrichtung',
            'kitchen_type' => 'Küche',
            'flooring' => 'Bodenbelag',
            'parking_type' => 'Parkplatz-Art',
            'garage_spaces' => 'Garagenplätze',
            'parking_spaces' => 'Stellplätze',
            'area_basement' => 'Kellerfläche (m²)',
            'basement_count' => 'Keller-Anzahl',
            'area_garage' => 'Garagenfläche (m²)',
            'area_balcony' => 'Balkonfläche (m²)',
            'area_terrace' => 'Terrassenfläche (m²)',
            'area_loggia' => 'Loggiafläche (m²)',
            'area_garden' => 'Gartenfläche (m²)',
            'balcony_count' => 'Balkone',
            'terrace_count' => 'Terrassen',
            'loggia_count' => 'Loggias',
            'garden_count' => 'Gartenbereiche',
        ];

        $booleans = [
            'has_balcony' => 'Balkon',
            'has_terrace' => 'Terrasse',
            'has_loggia' => 'Loggia',
            'has_garden' => 'Garten',
            'has_basement' => 'Keller',
            'has_elevator' => 'Aufzug',
            'has_pool' => 'Pool',
            'has_sauna' => 'Sauna',
            'has_fireplace' => 'Kamin',
            'has_fitted_kitchen' => 'Einbauküche',
            'has_air_conditioning' => 'Klimaanlage',
            'has_barrier_free' => 'Barrierefrei',
            'has_guest_wc' => 'Gäste-WC',
        ];

        $lines = [];
        foreach ($labels as $key => $label) {
            $v = $p[$key] ?? null;
            if ($v === null || $v === '' || $v === 0 || $v === '0') continue;
            $lines[] = "- {$label}: {$v}";
        }

        $flags = [];
        foreach ($booleans as $key => $label) {
            if (!empty($p[$key])) {
                $flags[] = $label;
            }
        }
        if (!empty($flags)) {
            $lines[] = "- Ausstattungsmerkmale: " . implode(', ', $flags);
        }

        // Free-text notes that may already exist
        foreach (['highlights', 'other_description'] as $freeKey) {
            $v = trim((string) ($p[$freeKey] ?? ''));
            if ($v !== '') {
                $lines[] = "- " . ($freeKey === 'highlights' ? 'Highlights (bestehend)' : 'Sonstiges (bestehend)') . ": {$v}";
            }
        }

        return $lines ? implode("\n", $lines) : "(keine strukturierten Daten)";
    }

    /**
     * Extract text from the requested uploaded files. Returns "" when no
     * files were provided or none yielded usable text.
     */
    private function extractDocumentText(int $propertyId, array $fileIds): string
    {
        if (empty($fileIds)) {
            return '';
        }

        try {
            $paths = $this->resolveFilePaths($propertyId, $fileIds);
            if (empty($paths)) return '';

            $parts = [];
            foreach ($paths as $path) {
                $c = $this->docs->extractContent($path);
                $text = trim((string) ($c['text'] ?? ''));
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
            $joined = implode("\n\n---\n\n", $parts);
            // Keep the prompt bounded — models drift with too much context.
            if (mb_strlen($joined) > 40000) {
                $joined = mb_substr($joined, 0, 40000) . "\n\n[... Text gekürzt ...]";
            }
            return $joined;
        } catch (\Exception $e) {
            Log::warning('extractDocumentText failed: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Look up absolute file paths on disk for the given property_files ids.
     */
    private function resolveFilePaths(int $propertyId, array $fileIds): array
    {
        $cleaned = array_values(array_filter(array_map('intval', $fileIds), fn ($x) => $x > 0));
        if (empty($cleaned)) return [];

        $rows = DB::table('property_files')
            ->where('property_id', $propertyId)
            ->whereIn('id', $cleaned)
            ->pluck('path')
            ->all();

        $base = storage_path('app/public/');
        $paths = [];
        foreach ($rows as $relPath) {
            $abs = $base . $relPath;
            if (is_file($abs)) $paths[] = $abs;
        }
        return $paths;
    }
}
