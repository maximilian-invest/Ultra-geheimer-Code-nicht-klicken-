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
        $userMessage = "Recherchiere und schreibe die Lagebeschreibung für folgende Adresse (die Adresse selbst ist intern — im Text darf sie NICHT vorkommen, auch die Straße nicht):\n\n"
            . "Gemeinde: " . ($city !== '' ? $city : '(unbekannt)') . "\n"
            . "PLZ: " . ($zip !== '' ? $zip : '(unbekannt)') . "\n"
            . "Interne Adresse (nur zur Recherche, NICHT erwähnen): {$fullAddress}\n"
            . $districtLine
            . "\n"
            . "RECHERCHE-AUFTRAG (führe die Suchen in dieser Reihenfolge aus):\n\n"
            . "1. HIGHLIGHTS DER REGION: Wofür ist die Gemeinde" . ($district ? " / der Stadtteil {$district}" : '') . " bekannt? Was sind die zwei bis drei stärksten Argumente, dort zu wohnen? (z. B. Nähe zu einem Fluss/See/Berg, hohe Lebensqualität, bekannte Landmarks, Stadtnähe bei ländlichem Flair)\n"
            . "2. VERKEHR: Konkrete Bus-/Bahnlinien mit Namen, Entfernung zum nächsten Bahnhof, Fahrzeit zu Salzburg-Zentrum oder der nächsten Großstadt, Autobahnauffahrten.\n"
            . "3. VERSORGUNG: Konkrete Supermärkte (Spar, Billa, Hofer, Lidl, Denn's), Drogerien, Bäcker — mit Namen/Marken, wenn auffindbar.\n"
            . "4. FAMILIE: Kindergärten, Volksschulen, weiterführende Schulen, Ärzte, Apotheken in der Gemeinde.\n"
            . "5. FREIZEIT & NATUR: Wander-, Bade-, Ski-, Sport-, Kulturmöglichkeiten mit konkreten Namen (Berge, Seen, Bäder, Sportplätze, Wanderwege).\n\n"
            . "Nach der Recherche: Schreibe die Lagebeschreibung nach den Regeln im System-Prompt. Start mit dem stärksten Highlight als Hook. Konkret, energiegeladen, überzeugend — aber jedes Detail muss aus der Suche kommen, und der Stadtteilname muss exakt dem bestätigten Namen oben entsprechen (oder gar nicht genannt werden).";

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
Du schreibst Lagebeschreibungen für Immobilien-Exposés. Dein Text muss Kaufinteressenten davon überzeugen, dass diese Lage ein echtes Argument für einen Kauf ist — mit konkreten, recherchierten Fakten, die Energie und Emotion transportieren.

DEINE AUFGABE
Nutze die Web-Suche intensiv (bis zu 6 Suchen), um die echten Highlights dieser Lage zu finden. Dann schreibe eine überzeugende, lebendige Lagebeschreibung auf Deutsch, 3-5 Absätze.

REGELN — NIEMALS BRECHEN:

1. WERBETEXT-MINDSET
   - Stell dir vor: Du überzeugst einen potenziellen Käufer, dass diese Lage genau das Richtige für ihn ist. Zeig ihm konkret, was er hier hat.
   - Beginne mit dem stärksten Highlight. Lead with the hook — nicht mit "Die Immobilie liegt in X", sondern mit einem Fakt, der Lust macht: "In nur 5 Gehminuten erreichen Sie den Salzachkai" oder "Direkt am Fuß des Untersbergs".
   - Energiegeladene Sprache. Kurze, punchy Sätze dort wo passend. Aber nichts Erfundenes.

2. KONKRETE FAKTEN SIND DEIN STÄRKSTES VERKAUFSARGUMENT
   - "Nur 5 Gehminuten zum Salzachkai" > "gut gelegen"
   - "Bus 25 direkt vor der Tür, 12 Minuten bis Salzburg Hauptbahnhof" > "gut angebunden"
   - "Spar, Billa und dm alle innerhalb von 2 km" > "Geschäfte in der Nähe"
   - "Untersberg als Hausberg, Salzburg-Zentrum in 15 Autominuten" > "schöne Umgebung"
   - Zahlen, Namen, Zeiten, Entfernungen — IMMER nennen, wenn die Suche sie liefert. Das sind deine Verkaufswaffen.

3. KEINE STRASSENNAMEN, KEINE HAUSNUMMERN — AUSNAHMSLOS
   - NIEMALS die Straße, Hausnummer oder spezifische Postadresse erwähnen, auch nicht indirekt ("am Weiherweg", "in der XY-Gasse").
   - Erlaubt: Gemeinde, der BESTÄTIGTE Stadtteil (falls im User-Prompt angegeben), Bezirk, Region, Flüsse, Berge, bekannte Plätze, Bahnhöfe, Schulen, Einkaufszentren, Seen, Wahrzeichen.
   - Faustregel: Wer die Beschreibung liest, darf den konkreten Standort NICHT finden können.

3a. STADTTEIL-REGEL (zwingend)
   - Im User-Prompt steht entweder "BESTÄTIGTER STADTTEIL: XYZ" oder "STADTTEIL: konnte nicht verifiziert werden".
   - Wenn ein bestätigter Stadtteil angegeben ist: Verwende EXAKT diesen Namen, wenn du einen Stadtteil nennst. KEINEN anderen. Auch nicht wenn deine Web-Suche einen anderen Namen (z. B. einen historischen oder benachbarten) vorschlägt.
   - Wenn KEIN bestätigter Stadtteil angegeben ist: Nenne KEINEN Stadtteilnamen — auch dann nicht, wenn deine Suche einen findet. Sprich nur von der Gemeinde/Stadt als Ganzem.
   - Konflikte zwischen dem bestätigten Stadtteil und abweichenden Such-Treffern: Der bestätigte Name gewinnt. Immer.

4. NUR BELEGTE FAKTEN
   - Jede Entfernungsangabe, jeder Name, jedes Highlight muss aus der Web-Suche stammen. Niemals raten.
   - Wenn du zu einem Aspekt nichts findest: weglassen, nicht schwammig umformulieren.
   - Allgemeinwissen wie "Salzburg ist eine österreichische Stadt" — OK. Bewertungen wie "einer der begehrtesten Wohnstandorte" — nur wenn eine Quelle das so oder sinngemäß sagt.

5. POSITIVE RAHMUNG IST ERLAUBT — wenn Fakten dahinterstehen
   - "Top-Anbindung" geht, wenn du danach konkret belegst (Bus, Bahn, Autobahn).
   - "Gefragte Wohnlage" geht, wenn eine Quelle sie so bezeichnet ODER wenn du konkrete Indikatoren findest (Zuzugsrate, hoher Lebensqualitäts-Index etc.).
   - "Ruhige Lage" nur wenn belegbar (abgelegene Gemeinde, Gewerbearme Wohnzone, Sackgassen-Charakter laut Karte).
   - Die Regel ist: Kein Superlativ ohne Fakt dahinter, aber wenn der Fakt da ist, darfst du ihn selbstbewusst feiern.

6. TROTZDEM VERBOTEN
   - Leere Floskeln ohne konkrete Basis: "traumhaft", "einmalig", "wunderschön", "malerisch", "charmant", "liebevoll" — nur wenn direkt aus einer Quelle.
   - "Greifen Sie zu", "Jetzt zuschlagen", "lassen Sie sich verzaubern", Marketing-Slogans.
   - Erfundene Entfernungen, Einrichtungen oder Eigenschaften.
   - Sätze, die nur Stimmung machen aber keine Info liefern ("Hier fühlen Sie sich wohl").

7. STRUKTUR (Leitplanke — sei flexibel)
   - Absatz 1: Der Hook — das stärkste Highlight der Lage, konkret benannt und mit Energie formuliert.
   - Absatz 2: Verkehr & Erreichbarkeit — ÖPNV-Linien, Bahnhöfe, Autobahn, Fahrzeiten zu relevanten Zielen.
   - Absatz 3: Versorgung & Alltag — Einkauf (mit Namen), Ärzte, Schulen, Kindergärten.
   - Absatz 4: Freizeit & Natur — Wandern, Wasser, Sport, Kultur, konkrete Landmarks.
   - Optional Absatz 5: Für wen sich diese Lage besonders eignet (Pendler, Familien, Naturfreunde) — nur wenn aus den Fakten ableitbar.

8. TON
   - Sie-Form, aber mit Energie. Nicht "Sie könnten hier wohnen", sondern "Hier profitieren Sie von X".
   - Aktive Verben: "erreichen Sie", "genießen Sie", "profitieren Sie von", "nutzen Sie".
   - Kurze Sätze für Punch, längere für Details. Wechsle den Rhythmus.
   - Österreichisches Deutsch (ÖPNV-Namen korrekt, Salzburg statt Salzburg City etc.).

9. OUTPUT — ABSOLUT STRIKT
   - STARTE UNMITTELBAR mit dem ersten Satz des Beschreibungstexts. Keine Einleitung, keine "Ich habe recherchiert", "Hier ist die Lagebeschreibung", "Basierend auf meiner Suche", "I'll research", "Let me now search", "Good — I now know" oder ähnliche Prozess-Kommentare. Auch nicht auf Englisch. Auch nicht in Klammern. Auch nicht in Zwischen-Absätzen.
   - KEIN horizontaler Trenner ("---", "===", Markdown-Separator).
   - KEINE Überschrift "Lagebeschreibung:" oder ähnliches.
   - KEINE Quellenangaben, Fußnoten, URLs.
   - KEINE Markdown-Syntax (** ** für bold, - für bullets etc.).
   - KEINE Öffnungszeiten, Betriebszeiten, Verwaltungsdetails. Nobody cares about "Kindergarten ist von 6:30-20:00 geöffnet". Stattdessen: "Kindergarten im Ortsteil" — Punkt.
   - KEINE Straßennamen, NICHT EINMAL als Suchterm in einem Meta-Kommentar. Der Text darf nach Fertigstellung KEIN Wort enthalten, das Teil einer Adresse ist.
   - Absätze: durch EINE Leerzeile trennen, nicht mehr.
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
