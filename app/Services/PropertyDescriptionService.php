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
     * Opus 4.7 fuer Prosa-Generierung — deutlich stabilere Grammatik und
     * Stilsicherheit im Deutschen als Sonnet. Latenz ist akzeptabel weil
     * der Makler die Generierung bewusst anstoesst, und die Kosten sind
     * gegenueber dem Wert einer sauberen Exposé-Beschreibung
     * vernachlaessigbar.
     */
    private const MODEL = 'claude-opus-4-7';

    /**
     * Kuratierte Highlight-Bibliothek — Tag → Copy-Direktive fuer den
     * Prompt. Die Direktive beschreibt WIE der Tag im Text aufgegriffen
     * wird, nicht WAS genau zu schreiben ist (der Satzbau bleibt Aufgabe
     * des Models). So nutzt der Text die Fakten aus dem Datensatz (z.B.
     * Flaechenangabe der Dachterrasse) und verbindet sie mit einem
     * hochwertigen Begriff.
     */
    private const HIGHLIGHT_LIBRARY = [
        // Lage & Aussicht
        'Seeblick'           => 'Seeblick als Kern-Verkaufsargument — prominent im Einstieg erwaehnen.',
        'Bergblick'          => 'Bergblick / Panorama hervorheben, idealerweise im Einstieg.',
        'Panoramablick'      => 'Panoramablick im Einstieg hervorheben.',
        'Fernblick'          => 'Weiter Blick / Fernblick hervorheben.',
        'Ruhelage'           => 'Ruhelage als Qualitaetsmerkmal herausstellen.',
        'Grünruhelage'       => 'Gruenruhelage (ruhig mit Blick ins Gruene) als Qualitaetsmerkmal betonen.',
        'Sonnenlage'         => 'Sonnige Ausrichtung aufgreifen.',
        'Zentrumslage'       => 'Zentrale Lage als Pragmatik-Vorteil aufgreifen.',
        'Nähe See'           => 'Seenaehe als Lifestyle-Argument aufgreifen.',
        'Nähe Wald'          => 'Waldnaehe als Naturnaehe-Argument aufgreifen.',
        'Nähe Skigebiet'     => 'Skigebiet-Naehe als Freizeit-Argument aufgreifen.',

        // Aussenbereich
        'Dachterrasse'       => 'Dachterrasse hervorheben — wenn Flaechenwert im Datensatz steht, diesen konkret nennen (z.B. "Dachterrasse mit 40 m²").',
        'Privatgarten'       => 'Privaten Garten prominent erwaehnen; Flaeche nennen falls belegbar.',
        'Terrasse'           => 'Terrasse benennen, Flaeche falls belegbar.',
        'Loggia'             => 'Loggia (geschuetzter Freibereich) benennen, Flaeche falls belegbar.',
        'Balkon'             => 'Balkon benennen — aber nur erwaehnen wenn die Flaeche nennenswert (≥ 4 m²) oder als Teil einer Freibereich-Kombi ist.',
        'Privater Seezugang' => 'Privaten Seezugang als Top-Alleinstellung herausstellen.',
        'Eigener Eingang'    => 'Eigenen Eingang als Privatsphaeren-Plus erwaehnen.',

        // Architektur & Qualitaet
        'Erstbezug'          => 'Erstbezug hervorheben — neuwertiger Zustand darf "neuwertig", "neu errichtet" oder "frisch fertiggestellt" genannt werden (nicht "wird als neuwertig beschrieben").',
        'Neuwertig'          => 'Neuwertiger Zustand; als "neuwertig" oder "neuwertig erhalten" benennen — NIEMALS als "wird als neuwertig beschrieben".',
        'Vollsanierung'      => 'Umfassende Sanierung als Qualitaetsargument aufgreifen — konkrete Massnahmen aus Datensatz verwenden falls vorhanden.',
        'Historische Substanz' => 'Historische Bausubstanz und Charaktermerkmale aufgreifen.',
        'Gewölbedecken'      => 'Gewoelbedecken als Architekturmerkmal erwaehnen.',
        'Stuckdecken'        => 'Stuckdecken als Architekturmerkmal erwaehnen.',
        'Dielenboden'        => 'Dielenboden als Materialmerkmal erwaehnen.',
        'Parkettboden'       => 'Parkettboden erwaehnen — aber nicht als Verkaufsargument-Hauptsatz ("die Immobilie ueberzeugt mit Parkett" ist zu wenig).',
        'Hohe Räume'         => 'Hohe Raumhoehen / grosszuegige Raumproportionen betonen.',
        'Designer-Architektur' => 'Architektonische Qualitaet / Designer-Entwurf aufgreifen.',

        // Ausstattung Premium
        'Hochwertige Einbauküche' => 'Hochwertige Einbaukueche als Komfortmerkmal aufgreifen.',
        'Fußbodenheizung'    => 'Fussbodenheizung als Komfortmerkmal aufgreifen.',
        'Smart Home'         => 'Smart-Home-Ausstattung aufgreifen.',
        'Kamin'              => 'Kamin / Kachelofen als Wohnlichkeits-Merkmal aufgreifen.',
        'Sauna'              => 'Sauna als Wellness-Merkmal aufgreifen.',
        'Pool'               => 'Pool als Lifestyle-Merkmal hervorheben.',
        'Whirlpool'          => 'Whirlpool als Wellness-Merkmal aufgreifen.',
        'Wellness-Bereich'   => 'Wellness-Bereich prominent hervorheben.',
        'Photovoltaik'       => 'Photovoltaik als Nachhaltigkeits-/Kostenargument aufgreifen.',
        'Wärmepumpe'         => 'Waermepumpe als Nachhaltigkeits-Merkmal aufgreifen.',
        'Alarmanlage'        => 'Alarmanlage sachlich als Sicherheitsmerkmal erwaehnen.',
        'Klimaanlage'        => 'Klimaanlage erwaehnen.',

        // Praktisches
        'Aufzug'             => 'Aufzug praktisch erwaehnen, nicht als Kernargument.',
        'Carport'            => 'Carport / Stellplatz praktisch erwaehnen mit Anzahl falls vorhanden.',
        'Garage'             => 'Garage erwaehnen mit Anzahl falls vorhanden.',
        'Tiefgarage'         => 'Tiefgarage erwaehnen mit Anzahl falls vorhanden.',
        'Stellplatz'         => 'Stellplatz / PKW-Abstellplatz praktisch erwaehnen.',
        'Barrierefrei'       => 'Barrierefreiheit als Alltagstauglichkeit aufgreifen.',
        'Homeoffice-geeignet'=> 'Homeoffice-Tauglichkeit als Nutzungsoption erwaehnen.',
    ];

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
        $highlightLines = $this->formatHighlights($property);

        $systemPrompt = $this->objektSystemPrompt();
        $userMessage = "PROPERTY-DATENSATZ:\n" . $propertyFacts
            . ($highlightLines !== '' ? "\n\n---\n\nVOM MAKLER GESETZTE HIGHLIGHTS (Priorität 1 — im Einstieg und Hauptteil prominent aufnehmen):\n" . $highlightLines : '')
            . ($documentText !== '' ? "\n\n---\n\nDOKUMENT-AUSZÜGE:\n" . $documentText : '')
            . "\n\n---\n\nSchreibe jetzt die Objektbeschreibung.";

        Log::info('generateObjekt calling Anthropic', [
            'property_id' => $propertyId,
            'model' => self::MODEL,
            'highlights_count' => substr_count($highlightLines, "\n") + ($highlightLines !== '' ? 1 : 0),
            'has_documents' => $documentText !== '',
        ]);

        $text = $this->anthropic->chat($systemPrompt, $userMessage, maxTokens: 2500, model: self::MODEL);

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

        // Lage-relevante Highlights extrahieren — wenn der Makler "Seeblick",
        // "Nähe See", "Nähe Wald", "Nähe Skigebiet", "Ruhelage" o.Ä. gesetzt
        // hat, sind das BESTÄTIGTE Lage-Eigenschaften die der Text aufgreifen
        // soll. Macht aus einer trockenen Lageinfo eine zielgerichtete
        // Beschreibung der Standort-Stärken.
        $rawHighlights = $property->expose_highlights ?? null;
        if (is_string($rawHighlights) && $rawHighlights !== '') {
            $rawHighlights = json_decode($rawHighlights, true) ?: [];
        }
        $allHighlights = is_array($rawHighlights) ? $rawHighlights : [];
        $lageRelevantTags = [
            'Seeblick', 'Bergblick', 'Panoramablick', 'Fernblick',
            'Ruhelage', 'Grünruhelage', 'Sonnenlage', 'Zentrumslage',
            'Nähe See', 'Nähe Wald', 'Nähe Skigebiet',
            'Privater Seezugang',
        ];
        $relevantHighlights = array_values(array_intersect($allHighlights, $lageRelevantTags));
        $highlightsBlock = '';
        if (!empty($relevantHighlights)) {
            $highlightsBlock = "\nVOM MAKLER BESTÄTIGTE LAGE-EIGENSCHAFTEN (gelten als Fakt — im Text aufgreifen):\n"
                . "  " . implode(', ', $relevantHighlights) . "\n"
                . "  → Diese Aspekte sind authentisch und MÜSSEN den Text strukturieren. Sie zählen als Recherche-Beleg.\n";
        }

        $systemPrompt = $this->lageSystemPrompt();
        $userMessage = "INPUT\n"
            . "Gemeinde: " . ($city !== '' ? $city : '(unbekannt)') . "\n"
            . "PLZ: " . ($zip !== '' ? $zip : '(unbekannt)') . "\n"
            . "Interne Adresse (NUR zur Recherche, im Text niemals erwähnen): {$fullAddress}\n"
            . $districtLine
            . $highlightsBlock
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
            . "Schreibe nach den Regeln im System-Prompt: 3-4 zusammenhängende Absätze (Makrolage, Mikrolage, Erreichbarkeit, optional kurzer Lifestyle-Schluss), 100-200 Wörter, dritte Person, sachlich-einladend. Bestätigte Lage-Highlights zentral aufgreifen. Kein Marketing-Klischee, keine Trivia, keine Straße. Stadtteilname nur wenn bestätigt. Konkrete Namen/Distanzen/Linien nur mit Suchergebnis-Beleg. Vor der Ausgabe die Selbstprüfung komplett durchgehen.";

        Log::info('generateLage calling Anthropic', [
            'property_id' => $propertyId,
            'model' => self::MODEL,
            'city' => $city,
            'district' => $district,
            'lage_highlights' => $relevantHighlights,
        ]);

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

        // Polish preserves the user's structure — no paragraph cap, no
        // forced length. sanitizeAnyOutput only strips meta and markdown.
        $improved = $this->sanitizeAnyOutput($improved);

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
Du bekommst einen Entwurf einer Objektbeschreibung. Deine Aufgabe: den Text VERBESSERN, aber NICHT VERÄNDERN.

Inhalte, Aussagen und Ton des Entwurfs müssen im polierten Text erhalten bleiben. Du machst den Text lesbarer und sprachlich sauberer — du schreibst ihn NICHT neu.

WAS DU TUN SOLLST:

1. GRAMMATIK & RECHTSCHREIBUNG
   - Tippfehler, Grammatikfehler, Zeichensetzung korrigieren.
   - Umständliche Satzkonstruktionen leicht entflechten (Satz teilen, Bezüge klären) — ohne den Inhalt zu verändern.

2. FORMATIERUNG
   - Komische Zeilenumbrüche (z. B. aus PDF-Copypaste nach kurzer Zeichenlänge) entfernen; den betroffenen Absatz wieder zu fließendem Text zusammenfügen.
   - Wenn der Entwurf eine einzige lange Textwurst ist: an natürlichen inhaltlichen Grenzen ein oder zwei Absätze einfügen — NUR dort wo Sinn-Einschnitt.
   - Wenn der Entwurf schon sauber in Absätze gegliedert ist: Absatzanzahl und Reihenfolge BEIBEHALTEN.
   - Zwischen Absätzen genau EINE Leerzeile, nicht mehr.
   - KEIN Markdown (kein **bold**, kein *italic*, keine Bullet-Lists, keine Überschriften).

3. WAS DU ABSOLUT NICHT VERÄNDERST
   - Inhalte, Aussagen, Fakten, Zahlen, Namen — alles bleibt so wie der User es geschrieben hat.
   - Formulierungen, Adjektive, Ton — wenn der User "niveauvoll" oder "gehoben" schreibt, bleibt das stehen. Das ist seine Stimme, nicht deine.
   - Reihenfolge der Inhalte.
   - Ansprache-Form (Sie/Du/dritte Person) — übernimm was der User benutzt.

WAS DU AUSNAHMSWEISE STILL ENTFERNST (ohne zu kommentieren):
   Nur diese HARTEN Verstöße fliegen raus — alles andere bleibt:
   - Straßenname, Hausnummer, Postleitzahl (aus Datenschutzgründen).
   - Expliziter Kaufpreis / Mietpreis / Betriebskosten / Provisionssätze — diese Zahlen erscheinen andernorts im Exposé.
   - Projektname / Bauträger-Markenname.
   - Energiewerte als Zahl (HWB, fGEE, Effizienzklasse-Buchstabe).

Wenn der User NICHTS davon geschrieben hat: Text bleibt exakt wie er ist, abgesehen von Grammatik/Formatierung.

AUSGABE
Antworte NUR mit dem polierten Text. Keine Einleitung, keine Metakommentare, kein Markdown. Absätze durch je eine Leerzeile getrennt.
PROMPT;
    }

    private function polishLageSystemPrompt(): string
    {
        return <<<'PROMPT'
Du bekommst einen Entwurf einer Lagebeschreibung. Deine Aufgabe: den Text VERBESSERN, aber NICHT VERÄNDERN.

Inhalte, Aussagen und Ton des Entwurfs müssen im polierten Text erhalten bleiben. Du machst den Text lesbarer und sprachlich sauberer — du schreibst ihn NICHT neu und strukturierst ihn NICHT um.

WAS DU TUN SOLLST:

1. GRAMMATIK & RECHTSCHREIBUNG
   - Tippfehler, Grammatikfehler, Zeichensetzung korrigieren.
   - Umständliche Satzkonstruktionen leicht entflechten — ohne Inhalt zu ändern.

2. FORMATIERUNG
   - Komische Zeilenumbrüche (z. B. aus Copypaste) entfernen, Absätze zu fließendem Text zusammenfügen.
   - Wenn der Entwurf eine Textwurst ist: an natürlichen inhaltlichen Grenzen Absätze einfügen. Wenn er schon in Absätze gegliedert ist: Absätze BEIBEHALTEN.
   - Zwischen Absätzen genau EINE Leerzeile.
   - KEIN Markdown (kein **bold**, keine Bullets, keine Überschriften).

3. WAS DU ABSOLUT NICHT VERÄNDERST
   - Inhalte, Landmarks (z. B. "Hellbrunner Schlosspark", "Gaisberg", "Universität Salzburg"), Entfernungen, Linien-Nummern, Geschäftsnamen — alles was der User geschrieben hat, bleibt drin.
   - Formulierungen, Adjektive, Ton — übernimm die Stimme des Users.
   - Reihenfolge der Inhalte und Anzahl der Absätze.
   - Wörter die der User gewählt hat (auch "ruhig", "niveauvoll", "besonders attraktiv" etc. — seine Wahl).
   - Ansprache-Form — übernimm sie.
   - Länge: KEINE Kürzung auf bestimmte Wortzahl, KEINE künstliche Erweiterung.

WAS DU AUSNAHMSWEISE STILL ENTFERNST (ohne zu kommentieren):
   Nur diese HARTEN Verstöße fliegen raus — alles andere bleibt:
   - Straßenname, Hausnummer, Postleitzahl.
     Beispiel: Wenn der User "Die Enzingergasse selbst ist eine ruhige Wohnstraße" schreibt, ersetze das durch eine gleichwertige Formulierung ohne Straßennamen: "Die direkte Wohnumgebung ist eine ruhige Wohnstraße". Sinn bleibt, Straße raus.

Wenn der User keine Straße / Hausnummer / PLZ nennt: der Text bleibt exakt wie er ist, abgesehen von Grammatik/Formatierung.

AUSGABE
Antworte NUR mit dem polierten Text. Keine Einleitung, keine Metakommentare, kein Markdown. Absätze durch je eine Leerzeile getrennt.
PROMPT;
    }

    // ─── Prompts ───────────────────────────────────────────────────────

    private function objektSystemPrompt(): string
    {
        return <<<'PROMPT'
Du schreibst die Objektbeschreibung für ein hochwertiges Exposé eines österreichischen Immobilienbüros (SR-Homes). Dein Text ist DAS zentrale Verkaufsdokument — ein Kaufinteressent entscheidet anhand dieser Beschreibung, ob er zur Besichtigung kommt. Die Beschreibung muss *immer* Premium-Niveau haben: selbstbewusst, stilvoll, spürbar auf die konkreten Stärken dieses Objekts zugeschnitten.

DEINE AUFGABE
Schreibe 4–6 lesbare, stilistisch sichere deutsche Absätze — angenehm zu lesen, konkret genug um Vertrauen aufzubauen, elegant genug um die Klasse des Objekts zu transportieren.

GROSSER GRUNDSATZ: DER TEXT *IST* DIE BESCHREIBUNG
Du beschreibst das Objekt direkt. Du berichtest NICHT darüber, was in irgendeinem Formular steht.
  - FALSCH: "Die Immobilie wird als neuwertig beschrieben."
  - RICHTIG: "Die neuwertige Substanz zeigt sich in ..." oder einfach: "Neuwertiger Zustand."
  - FALSCH: "Laut Datensatz verfügt das Objekt über einen Parkettboden."
  - RICHTIG: "Parkett im Wohnbereich."
  - NIEMALS: "wird als ... beschrieben", "laut Unterlagen", "im Datensatz", "der Eigentümer gibt an", "es wird angegeben".

HIGHLIGHTS HABEN ABSOLUTEN VORRANG
Im User-Prompt siehst du einen Block "VOM MAKLER GESETZTE HIGHLIGHTS". Diese Stichworte stehen für die KERN-VERKAUFSARGUMENTE. Jeder dieser Highlights MUSS im Text prominent auftauchen:
  - Der erste Absatz verdichtet die 1–2 stärksten Highlights in einer selbstsicheren Kernaussage.
  - Weitere Highlights werden in den Folgeabsätzen thematisiert, wo sie inhaltlich hingehören.
  - Jeder Highlight wird so formuliert, dass der Leser spürt, dass dieses Merkmal eine bewusste Qualität dieses Objekts ist — nicht als Checklisten-Abhaken.
  - Wo ein Highlight zu einer Fakt-Zahl aus dem Datensatz passt (z. B. "Dachterrasse" + area_dachterrasse = 40 m²), die Zahl konkret nennen.

HIGHLIGHTS DÜRFEN WERTEND FORMULIERT WERDEN
Wenn der Makler "Dachterrasse" als Highlight gesetzt hat, darfst du "großzügige Dachterrasse" schreiben (und Fläche nennen). Wenn er "Seeblick" gesetzt hat, ist "beeindruckender Seeblick" erlaubt. Die Highlight-Liste ist die Freigabe dafür, diese Aspekte gehoben zu formulieren. OHNE Highlight-Freigabe gilt die alte Regel: keine Wertung ohne Beleg.

WAS DER TEXT NIE ENTHÄLT (verbotene Themen)
  - Kaufpreis, Mietpreis, Betriebskosten, Nebenkosten, Provisionen, jede Geldsumme.
  - Projektname oder Bauträger-Marken.
  - Energiewerte (HWB, fGEE, Effizienzklasse). Die haben ihre eigene Stelle im Exposé.
  - Straße + Hausnummer. Stadt OK, Stadtteil OK wenn in den Fakten drin.
  - PLZ / Postleitzahl — nie.

WAS DER TEXT NIE ENTHÄLT (verbotene Tonlage)
  - META-SPRACHE: "wird als ... beschrieben", "laut ...", "die Unterlagen nennen", "im Datensatz", "es wird angegeben", "angabegemäß", "offenbar". Der Text beschreibt, nicht berichtet.
  - KLISCHEES: "traumhaft", "einmalig", "Juwel", "Perle", "Schmuckstück", "Wohnen mit Stil", "keine Wünsche offen lässt", "ein Muss", "nicht alltäglich", "echtes Wohnerlebnis", "Wohlfühloase".
  - WERBEFLOSKELN: "Greifen Sie jetzt zu", "nicht verpassen", "Top-Investition", "Traum vom Eigenheim". Keine Ausrufezeichen. Keine rhetorischen Fragen.
  - TRIVIALITÄTEN: Flächenangaben unter einer sinnvollen Schwelle gar nicht erwähnen. Ein "Abstellraum mit 1,5 m²" oder "Balkon mit 2 m²" macht das Objekt kleiner als es ist. Solche Kleindetails werden im Prompt gar nicht erst übergeben — aber selbst wenn du sie erahnst: nicht nennen.
  - CHECKLISTEN-SÄTZE: "Die Immobilie überzeugt mit Parkettboden." ist eine schlechte Zeile. Wenn Parkettboden nicht als Highlight gesetzt ist, ist er als Beiwerk zu erwähnen, nicht als eigener Hauptsatz.

FAKTENBASIS
  - Nur Fakten, die im Datensatz, in den Dokumenten oder in der Highlight-Liste stehen.
  - Kein Erfinden von Zimmern, Flächen, Baujahr, Ausstattung.
  - Dokument-Auszüge haben Vorrang vor reinen Datensatz-Werten wenn sie detaillierter sind (z. B. Raumbezeichnungen aus einem Original-Exposé).

STIL
  - Deutsche Prosa in österreichischer Lesart (Erdgeschoss, Parkett, Fernwärme, Nutzfläche). Niemals "Keller-Abteil" o.Ä. als seltsamer Bandwurm.
  - Sie-Form SEHR sparsam; vorzugsweise objektseitig formuliert ("Das Haus öffnet sich nach ...") statt käuferseitig ("Sie erwartet ...").
  - Kurze und mittellange Sätze. Keine Schachtelsätze über 3 Zeilen.
  - Aktiv, Präsens, konkret.
  - Beginne NIEMALS mit "Die Immobilie ..." oder "Das Objekt ..." — starte mit dem stärksten Konkret-Bild des Hauses (Lage, Dachterrasse, Architektur, Blick etc.).

STRUKTUR (Leitplanke)
  1. Einstieg (1 Absatz, 2–4 Sätze): Bild + stärkstes Highlight + sofort Richtung (Stadt/Ortsteil, Objekttyp).
  2. Wohnbereich & Räume (1–2 Absätze): Aufteilung, Flächen, besondere Räume. Highlights hier einweben.
  3. Ausstattung & Bauhistorie (1 Absatz): Heizung (Art), Bauhistorie (siehe unten), Zustand. Keine Energiewerte, kein Preis.
  4. Außen & Nebenbereiche (1 Absatz, falls belegbar): Dachterrasse/Garten/Stellplätze — nur die Kategorien die hier zählen, nicht jede winzige Nebenfläche.
  5. Kurzer Schluss (optional, 1–2 Sätze): Nutzungskontext wenn aus Fakten ableitbar ("Als Ihr neues Zuhause ..." oder "Für anspruchsvolle ..." — aber NIE Werbegestus).

BAUHISTORIE ERZÄHLEN, NICHT AUFLISTEN
Wenn im Datensatz „Um- oder Zubauten" und/oder „Letzte Kernsanierung" vermerkt sind, gehört das ZWINGEND in die Beschreibung — als EIN zusammenhängender Satz der Baujahr, Sanierung und Zubauten verbindet. Die Historie eines Hauses ist Teil seines Werts.
  - FALSCH (nur Baujahr): „Das Haus wurde 1994 errichtet."
  - RICHTIG (wenn Zubau vermerkt): „1994 erbaut, 2015 umfassend kernsaniert und um einen Südzubau mit Wohnküche erweitert."
  - RICHTIG (wenn nur Sanierung vermerkt): „Baujahr 1994, 2020 grundlegend kernsaniert."
  - Übernimm die Jahreszahlen/Inhalte aus den Freitextfeldern direkt. Keine eigenen Baudetails erfinden.
  - Wenn Zubauten/Sanierungen inhaltlich sehr detailliert sind: verdichte auf das Wesentliche (1-2 konkrete Maßnahmen nennen, nicht alle).

WEGLASSEN IST STÄRKE
Lieber 4 starke Absätze als 6 aufgeblasene. Wenn ein Bereich im Datensatz dünn ist, hol ihn nicht in Watte, lass ihn weg. Leerlauf-Sätze ("Eine angenehme Atmosphäre rundet das Bild ab.") sind verboten.

OUTPUT
  - NUR der Beschreibungstext. Keine Überschrift "Objektbeschreibung:". Kein Markdown. Kein Meta-Kommentar. Keine Quellenangabe.
  - Absätze durch Leerzeile getrennt.
PROMPT;
    }

    private function lageSystemPrompt(): string
    {
        return <<<'PROMPT'
Du schreibst die LAGEBESCHREIBUNG für ein Premium-Exposé eines österreichischen Immobilienbüros (SR-Homes). Diese Beschreibung soll dem Kaufinteressenten ein klares, ruhiges Bild geben WO das Objekt steht und WAS das Umfeld bietet — präzise, faktentreu, mit gerade so viel Wärme dass sie Lust auf den Standort macht, ohne in Werbeprosa zu kippen.

Du hast Zugang zur Web-Suche. Nutze sie um die tatsächlichen Fakten über Gemeinde, Bezirk und Umfeld zu recherchieren. Schreibe dann ausschließlich auf Basis dessen, was die Suche oder die mitgelieferten BESTÄTIGTEN LAGE-EIGENSCHAFTEN (vom Makler) konkret belegen.

─── KERNPRINZIP ───────────────────────────────────────
Der Text BESCHREIBT die Lage direkt — er BERICHTET nicht über sie.
  - FALSCH: "Die Recherche zeigt, dass …"
  - FALSCH: "Laut Webrecherche befindet sich …"
  - RICHTIG: Direktes Beschreiben wie ein erfahrener regionalkundiger Makler.

─── HARTE GUARDS (nicht verhandelbar) ──────────────────────────────────────

GUARD 1 — KEINE HALLUZINATIONEN
- KEINE Namen von Geschäften, Restaurants, Cafés, Ärzten, Apotheken, Schulen, Kindergärten, Supermärkten — es sei denn die Web-Suche liefert den Namen als Fakt für genau diese Lage.
- KEINE konkreten Entfernungen ("500 m", "3 Gehminuten") — außer die Suche liefert die Zahl für genau diese Adresse/diesen Stadtteil.
- KEINE Fahrzeiten ("15 Minuten zur Innenstadt") — außer belegt.
- KEINE Linien-Nummern (O-Bus 3, S-Bahn S3, Bus 170) — außer belegt.
- KEINE Bezirks-/Ortsteil-Namen außer dem im User-Prompt bestätigten BESTÄTIGTEN STADTTEIL.
- Wenn ein Detail fehlt: allgemein formulieren ("in fußläufiger Nähe", "gute Verkehrsanbindung", "Nahversorger im Ortsgebiet", "öffentliche Verkehrsmittel vorhanden") oder ganz weglassen. NIEMALS raten.

GUARD 2 — BESTÄTIGTE LAGE-HIGHLIGHTS HABEN VORRANG
Wenn der User-Prompt einen Block "VOM MAKLER BESTÄTIGTE LAGE-EIGENSCHAFTEN" enthält (z. B. "Seeblick, Nähe See, Ruhelage"), gelten diese als Fakt. Der Text MUSS sie aufgreifen — sie sind oft das stärkste Lage-Argument und dürfen entsprechend prominent stehen.
  - "Seeblick" / "Nähe See": Gewässer namentlich nennen wenn die Suche es liefert (z. B. "Irrsee"); sonst "der See", "das Seeufer".
  - "Ruhelage" / "Grünruhelage": als ruhige Wohnlage / vom Verkehr abgewandte Lage formulieren.
  - "Nähe Skigebiet": Skigebiete in der Region erwähnen wenn die Suche sie als nahe liegend bestätigt.
  - "Privater Seezugang": als sehr seltenes Plus benennen.

GUARD 3 — VERBOTENE FLOSKELN
Diese Begriffe und ihre sinngemäßen Varianten sind tabu:
- "begehrte Wohngegend", "absolute Top-Lage", "Filetstück", "Juwel", "Perle", "Schmuckstück"
- "einmalige Gelegenheit", "nicht alltäglich", "Liebhaberobjekt"
- "ruhig und zentral zugleich" (Widerspruch ohne Beleg)
- "grünes Herz", "pulsierendes Leben", "urbanes Flair"
- "hier lässt es sich leben", "Wohnen mit Stil", "keine Wünsche offen lässt"
- "das Beste aus zwei Welten", "Wohnglück", "Wohlfühl-Adresse"
- Ausrufezeichen sind verboten.
- Superlative ohne Beleg ("die schönste", "die ruhigste", "besonders begehrt").
- Meta-Sätze wie "Die Lage zeichnet sich aus durch ..." sind blass — direkter formulieren.

GUARD 4 — KEINE OBJEKTBEWERTUNG
Der Text beschreibt AUSSCHLIESSLICH DIE LAGE, NIE das Objekt selbst:
- Keine Quadratmeter, keine Ausstattung, keine Räume, keine Preise.
- Kein "ideal für Familien" / "perfekt für Paare" — außer die Zielgruppe wird explizit vorgegeben.

GUARD 5 — ADRESSE
- Straße und Hausnummer kommen NIEMALS im Text vor (auch nicht indirekt).
- Nur Gemeinde + bestätigter Stadtteil/Bezirk (falls im User-Prompt angegeben) sind erlaubt.
- Keine PLZ.

GUARD 6 — KEINE TRIVIA
- Keine Öffnungszeiten, Betriebszeiten, Trägernamen, Vereinsnamen, Pfarrnamen.
- Keine Bewohnerzahlen, Altersstatistiken, historische Gründungsdaten, Marktstatistiken.

─── STIL ──────────────────────────────────────────────

TON
- Sachlich-einladend, regionalkundig, ruhig. Wie ein erfahrener Lokalmakler, der seinem Gegenüber neutral sagt was die Lage bietet.
- Erlaubt: zurückhaltende positive Adjektive für BELEGTE Aspekte. "Ruhige Wohnlage", "weiter Blick", "intakte Naturkulisse" sind ok wenn die Fakten sie tragen. KEINE Übertreibungen.
- Dritte Person, Präsens, Aktiv. Kein "Sie".
- Mittellange Sätze, klare Verben, keine Schachtelsätze.
- Beginne nicht mit "Die Lage …" oder "Das Objekt liegt …" — start mit einem konkreten Bild der Region (z. B. "Im Salzkammergut, am Ufer des Irrsees, …" oder "Die Marktgemeinde Zell am Moos liegt …").

STRUKTUR (Leitplanke, 3-4 Absätze)
1) MAKROLAGE (2-4 Sätze):
   Stadt/Gemeinde + Bezirk/Region + bestätigter Ortsteil. Geografische Einordnung (welcher Naturraum, welche Region). Lage-Highlights vom Makler (Seeblick, See-Nähe, Bergblick) hier prominent verankern.
2) MIKROLAGE (2-4 Sätze):
   Unmittelbares Umfeld. Charakter (Wohngebiet, ländlich, Zentrum, Stadtrand). Was hat der Bewohner in der Nähe? Nahversorgung, Schulen, Ärzte, Erholungsraum. Konkret nur bei Belegen, sonst allgemein.
3) ERREICHBARKEIT (1-2 Sätze):
   Anbindung an Autobahn/Hauptstraße und ÖPNV. "Wie komme ich weg und wieder her?"
4) OPTIONALER LIFESTYLE-SCHLUSS (1-2 Sätze, NUR wenn aus belegten Fakten ableitbar):
   Was macht die Region für Bewohner aus — z. B. "Die Region des Salzkammerguts mit ihren Seen und Bergen ist ganzjährig Naherholungsgebiet." Keine Werbephrase. Wenn der Lifestyle-Schluss erzwungen wirken würde: weglassen.

LÄNGE
- 100-200 Wörter gesamt. Nicht weniger als 100 (zu dünn), nicht mehr als 200 (verliert Schärfe).

─── SELBSTPRÜFUNG VOR AUSGABE (intern, Pflicht) ───────

Bevor du den Text ausgibst, prüfe jede Aussage:
- Jeder Eigenname / jede Zahl / jede Linie steht in den BESTÄTIGTEN LAGE-EIGENSCHAFTEN oder in der Web-Suche?
- Keine Floskel aus GUARD 3?
- Keine Objektbewertung (GUARD 4)?
- Keine Straße/PLZ (GUARD 5)?
- Keine Trivia (GUARD 6)?
- 100-200 Wörter? 3-4 Absätze?
- Keine Ausrufezeichen, kein "Sie", keine rhetorische Frage?
- Beginnt nicht mit "Die Lage …"?
- Keine Meta-Sprache ("Recherche zeigt", "laut Suche")?
Wenn ein Punkt scheitert: überarbeiten, NICHT ausgeben.

─── AUSGABEFORMAT ─────────────────────────────────────

- AUSSCHLIESSLICH die Lagebeschreibung als Fließtext.
- Keine Einleitung ("Hier ist …"), keine Nachbemerkung, keine Meta-Kommentare, keine "I'll research", "Let me search", "Ich habe nun …".
- Keine Markdown (kein **bold**, kein *italic*, keine Bullets, keine Überschriften).
- Absätze durch je EINE Leerzeile getrennt.

Wenn der Input so dünn ist, dass du auch mit der Web-Suche keine Makrolage beschreiben kannst (keine Stadt, keine Treffer), gib STATT einer Beschreibung exakt diese eine Zeile zurück:
FEHLT: <Komma-separierte Liste der fehlenden Pflichtangaben>
PROMPT;
    }

    // ─── Helpers ───────────────────────────────────────────────────────

    /**
     * Baut aus den Highlight-Tags eine Direktiven-Liste, die dem Prompt
     * mitgegeben wird. Tags die in der HIGHLIGHT_LIBRARY bekannt sind,
     * bekommen eine Copy-Direktive; unbekannte Tags werden als reiner
     * Tag-Name durchgereicht (damit individuelle Highlights nicht
     * verloren gehen).
     */
    private function formatHighlights(array $p): string
    {
        $raw = $p['expose_highlights'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) return '';

        $lines = [];
        foreach ($raw as $tag) {
            $tag = trim((string) $tag);
            if ($tag === '') continue;
            $hint = self::HIGHLIGHT_LIBRARY[$tag] ?? null;
            if ($hint) {
                $lines[] = "- {$tag} → {$hint}";
            } else {
                $lines[] = "- {$tag} → aufgreifen und stimmig in die Beschreibung einweben.";
            }
        }
        return $lines ? implode("\n", $lines) : '';
    }

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

        // Triviale Detail-Schwellen — kleine Flaechen sollen im Text nicht
        // namentlich auftauchen ("Abstellraum mit 1,5 m²" klingt laecherlich).
        // Die Modell-Instanz darf den Raum allgemein nennen, aber die konkrete
        // Zahl geht nicht in den Prompt.
        $areaMinimums = [
            'area_balcony' => 4.0,
            'area_terrace' => 6.0,
            'area_loggia' => 3.0,
            'area_garden' => 20.0,
            'area_basement' => 5.0,
            'area_storage_room' => 3.0,
            'area_garage' => 10.0,
            'free_area' => 5.0,
        ];

        $lines = [];
        foreach ($labels as $key => $label) {
            $v = $p[$key] ?? null;
            if ($v === null || $v === '' || $v === 0 || $v === '0') continue;

            // Triviale Flaechen-Werte ausblenden, damit der Prompt sie gar
            // nicht erst "sieht".
            if (isset($areaMinimums[$key]) && (float) $v < $areaMinimums[$key]) {
                continue;
            }

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

        // Freitext-Felder fuer Historie und Sonstiges. Diese geben dem Modell
        // Kontext jenseits der Struktur-Werte — insbesondere `conversions_additions`
        // und `last_renovation_note` ermoeglichen eine erzaehlerisch reiche
        // Geschichte ("Baujahr 1994, 2015 umfassend kernsaniert und zugebaut"),
        // statt dass der Text nur mit "errichtet 1994" endet.
        $freeTextLabels = [
            'highlights'             => 'Highlights (Freitext, bestehend)',
            'other_description'      => 'Sonstiges (bestehend)',
            'conversions_additions'  => 'Um- oder Zubauten (Historie — in der Beschreibung als Teil der Bau-/Sanierungsgeschichte aufgreifen)',
            'last_renovation_note'   => 'Letzte Kernsanierung (Historie — in Verbindung mit Baujahr/Saniert-Jahr erzaehlen)',
            'equipment_description'  => 'Ausstattungs-Freitext',
        ];
        foreach ($freeTextLabels as $freeKey => $freeLabel) {
            $v = trim((string) ($p[$freeKey] ?? ''));
            if ($v !== '') {
                $lines[] = "- {$freeLabel}: {$v}";
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
