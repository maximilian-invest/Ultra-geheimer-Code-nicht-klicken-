<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AnthropicService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class MarketIntelligenceController extends Controller
{
    /**
     * Return all cached market data + AI analysis.
     */
    public function index(Request $request): JsonResponse
    {
        $data = [];
        $rows = DB::table('market_data')->get();
        foreach ($rows as $row) {
            $data[$row->data_key] = [
                'value' => json_decode($row->data_value, true),
                'updated_at' => $row->updated_at,
                'source' => $row->source,
            ];
        }

        // If no data yet, return empty with hint
        if (empty($data)) {
            return response()->json([
                'status' => 'empty',
                'message' => 'Keine Marktdaten vorhanden. Klicke auf Aktualisieren.',
                'data' => [],
            ]);
        }

        return response()->json([
            'status' => 'ok',
            'data' => $data,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Trigger a full refresh of all market data sources + AI analysis.
     */
    public function refresh(Request $request): JsonResponse
    {
        $started = microtime(true);

        try {
            // Step 1: Fetch hard data (ECB rates)
            $this->fetchEcbRates();

            // Step 2: Fetch news via web search (broad spectrum)
            $newsResults = $this->fetchAllNews();

            // Step 3: Generate AI analysis from all collected data
            $this->generateAnalysis($newsResults);

            $duration = round(microtime(true) - $started, 1);

            return response()->json([
                'status' => 'ok',
                'message' => "Marktdaten aktualisiert ({$duration}s)",
                'duration' => $duration,
            ], 200, [], JSON_UNESCAPED_UNICODE);

        } catch (\Exception $e) {
            Log::error('Market Intelligence refresh failed: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Aktualisierung fehlgeschlagen: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Fetch ECB key interest rates (main refinancing rate history).
     */
    private function fetchEcbRates(): void
    {
        try {
            // ECB SDW REST API - Main refinancing operations rate
            $response = Http::timeout(15)->get('https://data-api.ecb.europa.eu/service/data/FM/B.U2.EUR.4F.KR.MRR_FR.LEV', [
                'format' => 'jsondata',
                'lastNObservations' => 60, // Last 60 data points (~5 years)
            ]);

            if ($response->successful()) {
                $json = $response->json();
                $observations = $json['dataSets'][0]['series']['0:0:0:0:0:0:0']['observations'] ?? [];
                $timePeriods = $json['structure']['dimensions']['observation'][0]['values'] ?? [];

                $rates = [];
                foreach ($observations as $idx => $obs) {
                    $period = $timePeriods[$idx]['id'] ?? null;
                    $value = $obs[0] ?? null;
                    if ($period && $value !== null) {
                        $rates[] = ['date' => $period, 'rate' => (float) $value];
                    }
                }

                $this->storeData('ecb_rates', $rates, 'ECB SDW API');

                // Store current rate separately for quick access
                if (!empty($rates)) {
                    $latest = end($rates);
                    $prev = count($rates) > 1 ? $rates[count($rates) - 2] : $latest;
                    $this->storeData('ecb_current', [
                        'rate' => $latest['rate'],
                        'date' => $latest['date'],
                        'change' => round($latest['rate'] - $prev['rate'], 2),
                        'direction' => $latest['rate'] < $prev['rate'] ? 'down' : ($latest['rate'] > $prev['rate'] ? 'up' : 'stable'),
                    ], 'ECB SDW API');
                }
            }
        } catch (\Exception $e) {
            Log::warning('ECB rate fetch failed: ' . $e->getMessage());
        }

        // Also try deposit facility rate
        try {
            $response = Http::timeout(15)->get('https://data-api.ecb.europa.eu/service/data/FM/B.U2.EUR.4F.KR.DFR.LEV', [
                'format' => 'jsondata',
                'lastNObservations' => 60,
            ]);

            if ($response->successful()) {
                $json = $response->json();
                $observations = $json['dataSets'][0]['series']['0:0:0:0:0:0:0']['observations'] ?? [];
                $timePeriods = $json['structure']['dimensions']['observation'][0]['values'] ?? [];

                $rates = [];
                foreach ($observations as $idx => $obs) {
                    $period = $timePeriods[$idx]['id'] ?? null;
                    $value = $obs[0] ?? null;
                    if ($period && $value !== null) {
                        $rates[] = ['date' => $period, 'rate' => (float) $value];
                    }
                }
                $this->storeData('ecb_deposit_rates', $rates, 'ECB SDW API');
            }
        } catch (\Exception $e) {
            Log::warning('ECB deposit rate fetch failed: ' . $e->getMessage());
        }
    }

    /**
     * Fetch news and market data using RSS feeds + Claude analysis.
     */
    private function fetchAllNews(): array
    {
        $allResults = [];

        // 1. Fetch RSS feeds from Austrian real estate / finance sources
        $rssFeeds = [
            'https://www.derstandard.at/rss/immobilien' => 'derstandard.at',
            'https://www.diepresse.com/rss/immobilien' => 'diepresse.com',
        ];

        foreach ($rssFeeds as $url => $source) {
            try {
                $response = Http::timeout(10)->get($url);
                if ($response->successful()) {
                    $xml = @simplexml_load_string($response->body());
                    if ($xml && isset($xml->channel->item)) {
                        $count = 0;
                        foreach ($xml->channel->item as $item) {
                            if ($count >= 5) break;
                            $allResults[] = [
                                'headline' => (string) $item->title,
                                'summary' => mb_substr(strip_tags((string) $item->description), 0, 200),
                                'source' => $source,
                                'date' => date('Y-m-d', strtotime((string) $item->pubDate)),
                                'url' => (string) $item->link,
                                'category' => 'immo_at',
                            ];
                            $count++;
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::warning("RSS feed fetch failed for {$url}: " . $e->getMessage());
            }
        }

        // 2. Use Claude to generate comprehensive market news based on current knowledge
        try {
            $ai = app(AnthropicService::class);
            $today = date('d.m.Y');

            $newsPrompt = "Heute ist der {$today}. Erstelle einen umfassenden Nachrichtenüberblick über den aktuellen Immobilienmarkt in Österreich und Europa.

Berücksichtige ALLE relevanten Bereiche:
1. EZB-Zinspolitik und aktuelle Entscheidungen
2. Immobilienpreise in Österreich (pro Bundesland wenn möglich)
3. Regulierung: KIM-Verordnung, Mietrecht, Wohnbauförderungen, Grunderwerbsteuer
4. Geopolitik: Nahost/Iran, Ukraine, China – Auswirkungen auf Energiepreise und Wirtschaft
5. Baukosten und Wohnbautätigkeit
6. Inflation und Wirtschaftslage Österreich/Eurozone
7. Kapitalmarkt: Aktien, Anleihen, Vergleich mit Immobilienrenditen
8. Demografie und Zuwanderung in Österreich

Antworte als JSON-Array mit 15-25 Meldungen:
[{\"headline\": \"...\", \"summary\": \"1-2 Sätze mit konkreten Zahlen/Fakten\", \"category\": \"ecb|immo_at|immo_regional|regulation|geopolitics|construction|inflation|equities|bonds|demographics|energy\", \"impact\": \"positive|negative|neutral\", \"relevance\": \"high|medium|low\"}]

WICHTIG: Nutze dein aktuelles Wissen. Sei so spezifisch wie möglich mit Zahlen und Daten. Keine Platzhalter!";

            $newsJson = $ai->chatJson(
                'Du bist ein Finanzmarkt-Analyst. Antworte NUR als valides JSON-Array.',
                $newsPrompt,
                4000
            );

            if (is_array($newsJson)) {
                $allResults = array_merge($allResults, $newsJson);
            }
        } catch (\Exception $e) {
            Log::warning('Market news AI generation failed: ' . $e->getMessage());
        }

        // Store results
        $this->storeData('news_feed', $allResults, 'RSS + Claude Analysis');

        return $allResults;
    }

        /**
     * Generate comprehensive AI market analysis from all collected data.
     */
    private function generateAnalysis(array $newsResults): void
    {
        $ecbCurrent = $this->getData('ecb_current');
        $ecbRates = $this->getData('ecb_rates');

        // Build compact context
        $context = "HEUTIGE DATEN (" . date('d.m.Y') . "):\n\n";

        if ($ecbCurrent) {
            $context .= "EZB Leitzins: {$ecbCurrent['rate']}% (Aenderung: {$ecbCurrent['change']}pp)\n";
        }
        if ($ecbRates) {
            $context .= "Leitzins letzte 5 Punkte: ";
            foreach (array_slice($ecbRates, -5) as $r) {
                $context .= "{$r['date']}={$r['rate']}% ";
            }
            $context .= "\n\n";
        }

        if (!empty($newsResults)) {
            $context .= "AKTUELLE NACHRICHTEN:\n";
            foreach (array_slice($newsResults, 0, 20) as $item) {
                $h = $item['headline'] ?? '';
                $s = $item['summary'] ?? '';
                $context .= "- {$h}: {$s}\n";
            }
        }

        $system = <<<'PROMPT'
Du bist ein Senior Investment Analyst. Erstelle einen Immobilienmarkt-Report fuer vermogende Privatkunden in Oesterreich.

Antworte als JSON:
{
  "executive_summary": "3-5 Saetze Gesamteinschaetzung",
  "sentiment": "stark_bullish|moderat_bullish|neutral|moderat_bearish|stark_bearish",
  "sentiment_score": 65,
  "sentiment_label": "z.B. Vorsichtig optimistisch",
  "key_metrics": [
    {"label": "EZB Leitzins", "value": "2.50%", "change": "-0.25", "direction": "down", "context": "kurze Erklaerung"},
    {"label": "Inflation AT", "value": "...", "change": "...", "direction": "up/down/stable", "context": "..."},
    {"label": "Hypothekenzins", "value": "...", "change": "...", "direction": "...", "context": "..."},
    {"label": "BIP AT", "value": "...", "change": "...", "direction": "...", "context": "..."},
    {"label": "Baukosten", "value": "...", "change": "...", "direction": "...", "context": "..."},
    {"label": "10J Anleihe", "value": "...", "change": "...", "direction": "...", "context": "..."}
  ],
  "regional": [
    {"region": "Salzburg", "trend": "steigend|stabil|fallend", "price_yoy": "+X%", "outlook": "2 Saetze", "demand": "hoch|mittel|gering"},
    {"region": "Oberoesterreich", "trend": "...", "price_yoy": "...", "outlook": "...", "demand": "..."},
    {"region": "Wien", "trend": "...", "price_yoy": "...", "outlook": "...", "demand": "..."},
    {"region": "Tirol", "trend": "...", "price_yoy": "...", "outlook": "...", "demand": "..."},
    {"region": "Steiermark", "trend": "...", "price_yoy": "...", "outlook": "...", "demand": "..."},
    {"region": "Niederoesterreich", "trend": "...", "price_yoy": "...", "outlook": "...", "demand": "..."},
    {"region": "Kaernten", "trend": "...", "price_yoy": "...", "outlook": "...", "demand": "..."},
    {"region": "Vorarlberg", "trend": "...", "price_yoy": "...", "outlook": "...", "demand": "..."},
    {"region": "Burgenland", "trend": "...", "price_yoy": "...", "outlook": "...", "demand": "..."}
  ],
  "risk_factors": [
    {"title": "...", "severity": "high|medium|low", "description": "...", "transmission": "Wie wirkt es auf Immobilien?"}
  ],
  "opportunities": [
    {"title": "...", "potential": "high|medium|low", "description": "..."}
  ],
  "asset_comparison": {
    "immobilien": {"expected_return": "X%", "risk": "...", "liquidity": "...", "verdict": "1 Satz"},
    "aktien": {"expected_return": "X%", "risk": "...", "liquidity": "...", "verdict": "1 Satz"},
    "anleihen": {"expected_return": "X%", "risk": "...", "liquidity": "...", "verdict": "1 Satz"},
    "gold": {"expected_return": "X%", "risk": "...", "liquidity": "...", "verdict": "1 Satz"}
  },
  "investment_outlook": {
    "short_term": "6-Monats Ausblick (2-3 Saetze)",
    "medium_term": "1-2 Jahre (2-3 Saetze)",
    "long_term": "5-10 Jahre (2-3 Saetze)",
    "recommendation": "Klare Handlungsempfehlung",
    "geopolitical": "Geopolitische Einschaetzung (3-4 Saetze)"
  },
  "regulation_watch": [
    {"title": "...", "status": "in_kraft|geplant|diskutiert", "impact": "..."}
  ],
  "news_highlights": [
    {"headline": "...", "summary": "...", "impact": "positive|negative|neutral", "category": "..."}
  ]
}

REGELN:
- Nutze echte Zahlen aus den bereitgestellten Daten (EZB-Raten sind EXAKT)
- Wenn du eine Zahl nicht sicher weisst, kennzeichne mit ~ davor (z.B. ~2,8%)
- KEINE absoluten Immobilienpreise (Euro/m2) ERFINDEN! Verwende nur relative Trends (YoY %) fuer regionale Analysen
- key_metrics: NUR makrooekonomische Daten (Leitzins, Inflation, Hypothekenzins, BIP, Baukosten, Anleihenrendite). KEINE regionalen Immobilienpreise!
- Schreibe auf Deutsch
- Sei konkret, nicht vage
- news_highlights: die 8-12 wichtigsten Meldungen aus den Nachrichten
PROMPT;

        $ai = app(AnthropicService::class);

        // Use chat() instead of chatJson() for large responses - manual JSON extraction
        $shortSystem = "Du bist ein Senior Investment Analyst fuer vermogende Privatkunden in Oesterreich. "
            . "Erstelle einen professionellen Immobilienmarkt-Report als valides JSON. "
            . "Nutze echte aktuelle Zahlen. Schreibe auf Deutsch. Sei konkret mit Zahlen und Prozenten.";

        $userMsg = $context . "\n\nErstelle Report als JSON mit diesen Feldern:\n"
            . "executive_summary (3-5 Saetze), sentiment (moderat_bullish/neutral/moderat_bearish), "
            . "sentiment_score (0-100), sentiment_label (kurz), "
            . "key_metrics (Array, 6 Items: label/value/change/direction/context — NUR makrooekonomische Metriken wie Leitzins, Inflation, Hypothekenzins, BIP, Baukosten, Anleihen. KEINE regionalen Immobilienpreise pro m2 in den Key Metrics! Immobilienpreise sind UNZUVERLAESSIG wenn geschaetzt — verwende sie NUR wenn du eine konkrete Quelle hast. Kennzeichne Schaetzungen mit ~ davor), "
            . "regional (Array, alle 9 AT Bundeslaender: region/trend/price_yoy/outlook/demand), "
            . "risk_factors (Array, 4-6 Items: title/severity/description/transmission), "
            . "opportunities (Array, 3-5 Items: title/potential/description), "
            . "asset_comparison (Object: immobilien/aktien/anleihen/gold mit expected_return/verdict), "
            . "investment_outlook (Object: short_term/medium_term/long_term/recommendation/geopolitical), "
            . "regulation_watch (Array, 3-5 Items: title/status/impact), "
            . "news_highlights (Array, 8-12 Items: headline/summary/impact/category).\n\n"
            . "WICHTIG: Antworte NUR mit dem JSON, keine Erklaerungen davor oder danach.";

        $raw = $ai->chat($shortSystem, $userMsg, 8000);

        if ($raw) {
            // Extract JSON manually (more robust than chatJson for large payloads)
            $raw = trim($raw);
            // Remove markdown code blocks (multiple formats)
            $raw = preg_replace('/^```(?:json)?\s*\n?/m', '', $raw);
            $raw = preg_replace('/\n?```\s*$/m', '', $raw);
            $raw = trim($raw);
            // Find the JSON object
            $firstBrace = strpos($raw, '{');
            $lastBrace = strrpos($raw, '}');
            if ($firstBrace !== false && $lastBrace !== false) {
                $jsonStr = substr($raw, $firstBrace, $lastBrace - $firstBrace + 1);
                // Fix common JSON issues from LLM output
                $jsonStr = mb_convert_encoding($jsonStr, 'UTF-8', 'UTF-8');
                $jsonStr = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', ' ', $jsonStr);
                // Fix typographic quotes and dashes
                $jsonStr = str_replace(["\xe2\x80\x9c", "\xe2\x80\x9d", "\xe2\x80\x9e"], '"', $jsonStr);
                $jsonStr = str_replace(["\xe2\x80\x93", "\xe2\x80\x94"], '-', $jsonStr);
                $jsonStr = str_replace("\xc2\xa0", ' ', $jsonStr);
                // Remove trailing commas
                $jsonStr = preg_replace('/,\s*}/', '}', $jsonStr);
                $jsonStr = preg_replace('/,\s*]/', ']', $jsonStr);

                $result = json_decode($jsonStr, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);

                // If fails, try removing report_metadata wrapper
                if (!$result && strpos($jsonStr, 'report_metadata') !== false && strpos($jsonStr, 'executive_summary') !== false) {
                    // Remove the report_metadata key and its value object
                    $cleaned = preg_replace('/"report_metadata"\s*:\s*\{[^}]*\}\s*,?\s*/', '', $jsonStr);
                    $cleaned = preg_replace('/,\s*}/', '}', $cleaned);
                    $result = json_decode($cleaned, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
                    if ($result) {
                        Log::info('Market Intelligence: parsed after removing report_metadata wrapper');
                    }
                }

                // If still fails, try single-quote replacement
                if (!$result) {
                    $fixed = str_replace("'", '"', $jsonStr);
                    $result = json_decode($fixed, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
                }

                if (!$result) {
                    Log::warning('Market Intelligence: JSON parse error: ' . json_last_error_msg() . '. First 500 chars: ' . substr($jsonStr, 0, 500));
                }

                if ($result && isset($result['executive_summary'])) {
                    $this->storeData('ai_analysis', $result, 'Claude AI Analysis');
                    Log::info('Market Intelligence: AI analysis generated (' . strlen($jsonStr) . ' bytes)');
                } elseif ($result) {
                    // Model may have wrapped in report_metadata or similar
                    // Try to find the actual analysis inside
                    foreach ($result as $key => $val) {
                        if (is_array($val) && isset($val['executive_summary'])) {
                            $this->storeData('ai_analysis', $val, 'Claude AI Analysis');
                            Log::info('Market Intelligence: AI analysis extracted from nested key: ' . $key);
                            break;
                        }
                    }
                    // If still has executive_summary at any level, use the whole thing
                    if (!DB::table('market_data')->where('data_key', 'ai_analysis')->where('updated_at', '>=', now()->subMinute())->exists()) {
                        $this->storeData('ai_analysis', $result, 'Claude AI Analysis (raw)');
                        Log::info('Market Intelligence: stored raw analysis');
                    }
                } else {
                    Log::warning('Market Intelligence: JSON decode failed. Raw length: ' . strlen($raw));
                }
            } else {
                Log::warning('Market Intelligence: No JSON braces found in response');
            }
        } else {
            Log::warning('Market Intelligence: AI chat returned null');
        }
    }

    /**
     * Store data in market_data table.
     */
    private function storeData(string $key, $value, string $source): void
    {
        DB::table('market_data')->updateOrInsert(
            ['data_key' => $key],
            [
                'data_value' => json_encode($value, JSON_UNESCAPED_UNICODE),
                'source' => $source,
                'updated_at' => now(),
            ]
        );
    }

    /**
     * Get data from market_data table.
     */
    private function getData(string $key)
    {
        $row = DB::table('market_data')->where('data_key', $key)->first();
        if (!$row) return null;
        return json_decode($row->data_value, true);
    }
}
