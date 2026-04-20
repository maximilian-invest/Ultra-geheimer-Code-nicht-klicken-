<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\PortalEmail;
use App\Models\Property;
use App\Models\PropertyMatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PropertyMatcherService
{
    protected AnthropicService $ai;

    // Intent -> minimum score threshold
    protected array $thresholds = [
        'high'   => 60,
        'medium' => 80,
        'low'    => 90,
    ];

    public function __construct(AnthropicService $ai)
    {
        $this->ai = $ai;
    }

    /**
     * Main entry point. Analyze an email conversation and find matching properties.
     */
    public function analyzeAndMatch(Conversation $conv, PortalEmail $email): void
    {
        // Skip if already dismissed or already has matches
        if ($conv->match_dismissed || $conv->match_count > 0) {
            return;
        }

        // Need a broker to scope properties
        $brokerId = null;
        if ($conv->property_id) {
            $brokerId = Property::where('id', $conv->property_id)->value('broker_id');
        }
        if (!$brokerId) {
            $brokerId = DB::table('email_accounts')
                ->where('id', DB::table('portal_emails')->where('id', $email->id)->value('account_id'))
                ->value('user_id');
        }
        if (!$brokerId) {
            Log::info("[CrossMatch] No broker found for conv {$conv->id}");
            return;
        }

        // Load thread context (last 3 messages)
        $threadContext = $this->buildThreadContext($conv, $email);

        // Step 1: AI extracts criteria + intent
        $analysis = $this->extractCriteria($threadContext);
        if (!$analysis || ($analysis['cross_match_intent'] ?? 'none') === 'none') {
            Log::info("[CrossMatch] Intent=none for conv {$conv->id}, skipping");
            return;
        }

        $intent = $analysis['cross_match_intent'];
        $criteria = $analysis['criteria'] ?? [];
        $threshold = $this->thresholds[$intent] ?? 90;

        // Step 2: Find and score candidate properties
        $excludeIds = [$conv->property_id];
        // Exclude same project group
        if ($conv->property_id) {
            $groupId = Property::where('id', $conv->property_id)->value('project_group_id');
            if ($groupId) {
                $sameGroup = Property::where('project_group_id', $groupId)->pluck('id')->toArray();
                $excludeIds = array_merge($excludeIds, $sameGroup);
            }
        }

        $candidates = Property::where('broker_id', $brokerId)
            ->where('realty_status', 'aktiv')
            ->whereNotIn('id', array_filter($excludeIds))
            ->get();

        if ($candidates->isEmpty()) {
            Log::info("[CrossMatch] No candidate properties for broker {$brokerId}");
            return;
        }

        // Step 3: Score each candidate
        $matches = [];
        foreach ($candidates as $prop) {
            $score = $this->scoreProperty($prop, $criteria);
            if ($score >= $threshold) {
                $matches[] = [
                    'property' => $prop,
                    'score' => $score,
                    'reason' => $this->buildMatchReason($prop, $criteria),
                ];
            }
        }

        // Sort by score desc, limit to 5
        usort($matches, fn($a, $b) => $b['score'] <=> $a['score']);
        $matches = array_slice($matches, 0, 5);

        if (empty($matches)) {
            Log::info("[CrossMatch] No matches above threshold {$threshold} for conv {$conv->id}");
            return;
        }

        // Step 4: Store matches
        foreach ($matches as $m) {
            PropertyMatch::updateOrCreate(
                ['conversation_id' => $conv->id, 'property_id' => $m['property']->id],
                [
                    'score' => $m['score'],
                    'match_reason' => $m['reason'],
                    'criteria_json' => $criteria,
                    'cross_match_intent' => $intent,
                    'status' => 'pending',
                ]
            );
        }

        $conv->update(['match_count' => count($matches)]);
        Log::info("[CrossMatch] Found " . count($matches) . " matches for conv {$conv->id}");
    }

    /**
     * Build thread context from last 3 emails in conversation.
     */
    protected function buildThreadContext(Conversation $conv, PortalEmail $latestEmail): string
    {
        $query = DB::table('portal_emails')
            ->where(function ($q) use ($conv) { $q->whereRaw("LOWER(from_email) = LOWER(?)", [$conv->contact_email])->orWhereRaw("LOWER(to_email) LIKE CONCAT('%', LOWER(?), '%')", [$conv->contact_email]); })
            ->orderByDesc("email_date")
            ->limit(3);

        if ($conv->property_id) {
            $query->where('property_id', $conv->property_id);
        } else {
            $query->whereNull('property_id');
        }

        $emails = $query->get(['from_name', 'from_email', 'subject', 'body_text', 'direction']);

        $lines = [];
        foreach ($emails->reverse() as $e) {
            $dir = $e->direction === 'inbound' ? 'KUNDE' : 'MAKLER';
            $lines[] = "[{$dir}] {$e->from_name}: {$e->subject}\n" . mb_substr($e->body_text ?? '', 0, 500);
        }
        return implode("\n---\n", $lines);
    }

    /**
     * AI call: extract search criteria and cross-match intent from thread.
     */
    public function extractCriteria(string $threadContext): ?array
    {
        $systemPrompt = <<<'PROMPT'
Du bist ein Immobilien-Analyst. Analysiere den Email-Thread eines Interessenten und extrahiere Suchkriterien.

Antworte als JSON:
{
  "cross_match_intent": "high|medium|low|none",
  "criteria": {
    "object_types": ["Einfamilienhaus", "Wohnung", etc.],
    "min_area": null or number in m²,
    "max_price": null or number in EUR,
    "locations": ["Ort1", "Ort2"],
    "features": ["Garten", "Balkon", etc.],
    "household": "Beschreibung oder null"
  },
  "reason": "Kurze Begründung warum dieser Intent"
}

Intent-Regeln:
- "high": Absage mit konkreten Suchkriterien, oder explizite Wünsche die über das aktuelle Objekt hinausgehen
- "medium": Erwähnt Präferenzen beiläufig, oder Objekt ist reserviert/verkauft
- "low": Standard-Erstanfrage ohne besondere Signale, allgemeines Interesse
- "none": Interne Mail, Spam, Eigentümer-Kommunikation, oder keine verwertbaren Kriterien

Extrahiere so viele Kriterien wie möglich aus dem Kontext. Bei Erstanfragen: leite Kriterien aus dem angefragten Objekt ab (Typ, Lage, Preisklasse).

WICHTIG für locations: Nenne KONKRETE Ortsnamen (Grödig, Hallein, Anif, etc.), KEINE vagen Beschreibungen wie "Salzburg-Süd" oder "Umland". Wenn der Kunde "südlich von Salzburg" sagt, liste die konkreten Orte auf: Grödig, Anif, Hallein, Elsbethen, Kuchl etc.
PROMPT;

        return $this->ai->chatJson($systemPrompt, $threadContext, 500);
    }

    /**
     * Programmatic scoring: weighted match of property against criteria.
     */
    protected function scoreProperty(Property $prop, array $criteria): int
    {
        $score = 0;

        // Object type match (30 points)
        $types = array_map('mb_strtolower', $criteria['object_types'] ?? []);
        if (!empty($types)) {
            $propType = mb_strtolower($prop->object_type ?? '');
            $propSubtype = mb_strtolower($prop->object_subtype ?? '');
            $propCategory = mb_strtolower($prop->property_category ?? '');
            foreach ($types as $t) {
                if (str_contains($propType, $t) || str_contains($propSubtype, $t) || str_contains($propCategory, $t)
                    || ($propType !== '' && str_contains($t, $propType)) || ($propSubtype !== '' && str_contains($t, $propSubtype))) {
                    $score += 30;
                    break;
                }
            }
        }

        // Hard filter: if type was specified but didn't match, exclude entirely
        if (!empty($types) && $score === 0) {
            return 0;
        }

        // Location match (25 points) — HARD FILTER: if locations specified but no match, exclude
        $locations = array_map("mb_strtolower", $criteria["locations"] ?? []);
        if (!empty($locations)) {
            $propCity = mb_strtolower($prop->city ?? "");
            $propAddr = mb_strtolower($prop->address ?? "");
            $propZip = $prop->zip ?? "";
            $locMatched = false;
            foreach ($locations as $loc) {
                if (str_contains($propCity, $loc) || str_contains($propAddr, $loc)
                    || str_contains($loc, $propCity)) {
                    $score += 25;
                    $locMatched = true;
                    break;
                }
            }
            // Regional match: same zip prefix = same region
            if (!$locMatched && $propZip) {
                foreach ($locations as $loc) {
                    // Try matching by first 2 digits of zip (same region)
                    $locZipPrefix = "";
                    if (preg_match('/\b(\d{2})\d{2}\b/', $loc, $zm)) {
                        $locZipPrefix = $zm[1];
                    }
                    if ($locZipPrefix && str_starts_with($propZip, $locZipPrefix)) {
                        $score += 15;
                        $locMatched = true;
                        break;
                    }
                }
            }
            // Hard filter: location specified but no match at all -> exclude
            if (!$locMatched) {
                return 0;
            }
        }
        // Price within range (20 points)
        $maxPrice = $criteria['max_price'] ?? null;
        if ($maxPrice && $prop->purchase_price) {
            if ($prop->purchase_price <= $maxPrice) {
                $score += 20;
            } elseif ($prop->purchase_price <= $maxPrice * 1.15) {
                $score += 10; // within 15% tolerance
            }
        } elseif (!$maxPrice) {
            $score += 10; // no price criteria = neutral
        }

        // Area >= minimum (15 points)
        $minArea = $criteria['min_area'] ?? null;
        $propArea = $prop->living_area ?: $prop->total_area ?: 0;
        if ($minArea && $propArea) {
            if ($propArea >= $minArea) {
                $score += 15;
            } elseif ($propArea >= $minArea * 0.85) {
                $score += 8; // within 15% tolerance
            }
        } elseif (!$minArea) {
            $score += 8; // no area criteria = neutral
        }

        // Features match (10 points)
        $features = array_map('mb_strtolower', $criteria['features'] ?? []);
        if (!empty($features)) {
            $matched = 0;
            foreach ($features as $feat) {
                if (
                    (str_contains($feat, 'garten') && $prop->has_garden) ||
                    (str_contains($feat, 'balkon') && $prop->has_balcony) ||
                    (str_contains($feat, 'terrasse') && $prop->has_terrace) ||
                    (str_contains($feat, 'keller') && ($prop->has_basement || $prop->has_cellar)) ||
                    (str_contains($feat, 'garage') && $prop->garage_spaces > 0) ||
                    (str_contains($feat, 'aufzug') && $prop->has_elevator) ||
                    (str_contains($feat, 'pool') && $prop->has_pool) ||
                    (str_contains($feat, 'barrierefrei') && $prop->has_barrier_free)
                ) {
                    $matched++;
                }
            }
            $score += $matched > 0 ? min(10, (int)(10 * $matched / count($features))) : 0;
        } else {
            $score += 5; // no feature criteria = neutral
        }

        return min(100, $score);
    }

    /**
     * Build human-readable match reason from criteria overlap.
     */
    protected function buildMatchReason(Property $prop, array $criteria): string
    {
        $parts = [];

        $types = $criteria['object_types'] ?? [];
        if (!empty($types) && $prop->object_type) {
            $parts[] = $prop->object_type;
        }

        $propArea = $prop->living_area ?: $prop->total_area;
        if ($propArea && ($criteria['min_area'] ?? null)) {
            $parts[] = $propArea . ' m²';
        }

        if ($prop->city) {
            $parts[] = $prop->city;
        }

        $features = $criteria['features'] ?? [];
        foreach (array_slice($features, 0, 2) as $f) {
            $parts[] = $f;
        }

        return empty($parts) ? 'Allgemeine Übereinstimmung' : implode(', ', $parts);
    }

    /**
     * Get stored matches for a conversation (for API).
     */
    public function getMatchesForConversation(int $convId): array
    {
        $matches = PropertyMatch::where('conversation_id', $convId)
            ->whereIn('status', ['pending', 'selected'])
            ->orderByDesc('score')
            ->with('property:id,ref_id,title,address,city,purchase_price,living_area,total_area,rooms_amount,object_type,main_image_id,expose_path,realty_status')
            ->get();

        $criteria = $matches->first()?->criteria_json;

        return [
            'criteria' => $criteria,
            'matches' => $matches->map(function ($m) {
                $p = $m->property;
                $imageUrl = null;
                if ($p && $p->main_image_id) {
                    $img = DB::table('property_images')->where('id', $p->main_image_id)->value('path');
                    if ($img) $imageUrl = '/storage/' . $img;
                }
                return [
                    'id' => $m->id,
                    'property_id' => $m->property_id,
                    'score' => $m->score,
                    'match_reason' => $m->match_reason,
                    'status' => $m->status,
                    'title' => $p?->title ?? 'Unbekannt',
                    'ref_id' => $p?->ref_id,
                    'address' => trim(($p?->address ?? '') . ', ' . ($p?->city ?? ''), ', '),
                    'price' => $p?->purchase_price,
                    'area' => $p?->living_area ?: $p?->total_area,
                    'rooms' => $p?->rooms_amount,
                    'object_type' => $p?->object_type,
                    'image_url' => $imageUrl,
                    'has_expose' => !empty($p?->expose_path),
                ];
            })->values()->toArray(),
        ];
    }
}
