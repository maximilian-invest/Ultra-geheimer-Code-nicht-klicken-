<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ImmojiUploadService
{
    private const API_URL = 'https://api.immoji.org/graphql';

    private string $token;

    /**
     * Set to true by updateRealty when the media-error recovery falls through
     * to the metadata-only last-resort path. pushProperty reads this after
     * each call so it can keep the files hash stale (i.e. not snapshot a
     * "synced" state for files that never actually reached Immoji).
     */
    private bool $lastSyncDroppedFiles = false;

    public function __construct(string $token)
    {
        $this->token = $token;
    }

    public function didLastSyncDropFiles(): bool
    {
        return $this->lastSyncDroppedFiles;
    }

    /**
     * Sign in to Immoji and return an access token.
     */
    public static function signIn(string $email, string $password): string
    {
        $query = 'mutation { signIn(loginUserInput: {email: "' . addslashes($email) . '", password: "' . addslashes($password) . '"}) { accessToken } }';

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->timeout(30)->post(self::API_URL, ['query' => $query]);

        if ($response->failed()) {
            throw new \RuntimeException('Immoji signIn HTTP ' . $response->status());
        }

        $data = $response->json();

        if (isset($data['errors'])) {
            throw new \RuntimeException($data['errors'][0]['message'] ?? 'SignIn fehlgeschlagen');
        }

        $token = $data['data']['signIn']['accessToken'] ?? null;
        if (!$token) {
            throw new \RuntimeException('Kein accessToken in Immoji Antwort');
        }

        return $token;
    }

    /**
     * Test the connection by querying the total realty count.
     */
    public function testConnection(): int
    {
        $result = $this->query('{ realties(page: 1) { totalCount } }');

        if (isset($result['errors'])) {
            throw new \RuntimeException($result['errors'][0]['message'] ?? 'Unknown error');
        }

        return $result['data']['realties']['totalCount'] ?? 0;
    }

    /**
     * Push a property to Immoji. Uses section-level diff against the last
     * successful sync to minimise traffic. Falls back to full sync if:
     *   - the property has no Immoji ID yet (createRealty path)
     *   - there is no prior sync state
     *   - the stored immoji_id no longer matches the property's openimmo_id
     *   - $forceFullSync is true
     *
     * Returns: [
     *   'action' => 'created'|'updated'|'skipped'|'would_create'|'would_update',
     *   'immoji_id' => string|null,
     *   'sections_synced' => string[],
     * ]
     *
     * With $dryRun = true, no network calls are made; the result reflects what
     * WOULD happen on a real sync. 'would_create' / 'would_update' replace the
     * normal 'created' / 'updated' actions so the caller can distinguish a
     * preview from an executed sync. 'skipped' is returned in both modes.
     */
    public function pushProperty(array $property, bool $forceFullSync = false, bool $dryRun = false): array
    {
        $propertyId = $property['id'] ?? null;
        $immojiId = $property['openimmo_id'] ?? null;
        $stateService = app(\App\Services\ImmojiSyncStateService::class);

        // ─── CREATE path ───
        if (!$immojiId) {
            if ($dryRun) {
                return [
                    'action' => 'would_create',
                    'immoji_id' => null,
                    'sections_synced' => \App\Services\ImmojiSyncStateService::SECTIONS,
                ];
            }

            $immojiId = $this->createRealty($property);
            // Snapshot everything on initial create so the next sync can diff.
            if ($propertyId) {
                $hashes = $this->computeAllHashes($property, $stateService);
                $stateService->saveState($propertyId, $immojiId, $hashes);
            }
            return [
                'action' => 'created',
                'immoji_id' => $immojiId,
                'sections_synced' => \App\Services\ImmojiSyncStateService::SECTIONS,
            ];
        }

        // ─── UPDATE path ───
        $newHashes = $this->computeAllHashes($property, $stateService);
        $oldState = $propertyId ? $stateService->loadState($propertyId) : null;

        $sections = \App\Services\ImmojiSyncStateService::SECTIONS;
        if (!$forceFullSync && $oldState && $oldState['immoji_id'] === $immojiId) {
            $sections = $stateService->diffSections($oldState, $newHashes);
        }

        if (empty($sections) && !$forceFullSync) {
            return [
                'action' => 'skipped',
                'immoji_id' => $immojiId,
                'sections_synced' => [],
            ];
        }

        if ($dryRun) {
            return [
                'action' => 'would_update',
                'immoji_id' => $immojiId,
                'sections_synced' => $sections,
            ];
        }

        $this->updateRealty($immojiId, $property, $sections);

        // If the media-error fallback dropped files, do NOT snapshot the files
        // hash — next sync must retry the images instead of skipping them.
        $snapshotSections = $sections;
        if ($this->lastSyncDroppedFiles) {
            Log::warning("Immoji pushProperty: files were dropped during sync; keeping stale files hash so next sync retries.", ['property_id' => $propertyId]);
            $snapshotSections = array_values(array_diff($sections, ['files']));
        }

        // Snapshot: only update hashes for sections that were actually pushed,
        // so untouched sections keep their last-known-good fingerprint.
        $partialHashes = array_intersect_key($newHashes, array_flip($snapshotSections));
        if ($propertyId && !empty($partialHashes)) {
            $stateService->saveState($propertyId, $immojiId, $partialHashes);
        }

        return [
            'action' => 'updated',
            'immoji_id' => $immojiId,
            'sections_synced' => $snapshotSections,
        ];
    }

    /**
     * Compute hashes for all sections of the property as Immoji sees them.
     * Image rows are fetched fresh so we reflect the current DB state.
     */
    private function computeAllHashes(array $property, \App\Services\ImmojiSyncStateService $stateService): array
    {
        $propertyId = $property['id'] ?? null;
        $images = $propertyId
            ? \Illuminate\Support\Facades\DB::table('property_images')
                ->where('property_id', $propertyId)
                ->orderBy('sort_order')
                ->get()
            : collect();

        return [
            'general' => $stateService->hashSection(self::mapPropertyToImmojiGeneral($property)),
            'costs' => $stateService->hashSection(self::mapPropertyToImmojiCosts($property)),
            'areas' => $stateService->hashSection(self::mapPropertyToImmojiAreas($property)),
            'descriptions' => $stateService->hashSection(self::mapPropertyToImmojiDescriptions($property)),
            'building' => $stateService->hashSection(self::mapPropertyToImmojiBuilding($property)),
            'files' => $stateService->filesSignature($images),
        ];
    }

    /**
     * Push a single unit to Immoji. Merges master property data with unit overrides.
     */
    public function pushUnit(array $masterProperty, array $unit): array
    {
        // Merge: start with master, override with unit fields
        // Keep master id so uploadAndMapImages uses master's images
        // But set _forceUpload flag so images are re-uploaded (tmp refs are per-realty)
        $merged = $masterProperty;
        $merged['_forceUploadImages'] = true;
        $merged['title'] = ($masterProperty['project_name'] ?? $masterProperty['title'] ?? '') . ' - ' . ($unit['unit_number'] ?? '');
        $merged['living_area'] = $unit['area_m2'] ?? null;
        $merged['purchase_price'] = $unit['price'] ?? $unit['purchase_price'] ?? null;
        $merged['rooms_amount'] = $unit['rooms'] ?? $unit['rooms_amount'] ?? null;
        $merged['floor_number'] = $unit['floor'] ?? null;
        if (!empty($unit['unit_type'])) {
            $merged['object_type'] = $unit['unit_type'];
        }

        $immojiId = $unit['immoji_id'] ?? null;

        if ($immojiId) {
            try {
                $merged['openimmo_id'] = $immojiId;
                $this->updateRealty($immojiId, $merged);
                return ['action' => 'updated', 'immoji_id' => $immojiId];
            } catch (\RuntimeException $e) {
                if (str_contains($e->getMessage(), 'Entity not found for ID')) {
                    Log::warning("Immoji unit {$immojiId} deleted, re-creating.");
                    // Fall through to createRealty below
                } else {
                    throw $e;
                }
            }
        }

        $immojiId = $this->createRealty($merged);
        return ['action' => 'created', 'immoji_id' => $immojiId];
    }

    /**
     * Push all units with active portal exports to Immoji.
     */
    public function pushPropertyUnits(array $masterProperty): array
    {
        $propertyId = $masterProperty['id'] ?? null;
        if (!$propertyId) return ['error' => 'No property ID'];

        $units = \DB::table('property_units')
            ->where('property_id', $propertyId)
            ->where('is_parking', 0)
            ->whereNotNull('portal_exports')
            ->get();

        $results = [];
        foreach ($units as $unit) {
            $unitArr = (array) $unit;
            $exports = json_decode($unit->portal_exports ?? '{}', true);
            if (is_string($exports)) $exports = json_decode($exports, true); // handle double-encoded

            // Only push if immoji export is enabled
            if (empty($exports['immoji'])) continue;

            try {
                $result = $this->pushUnit($masterProperty, $unitArr);

                $immojiId = $result['immoji_id'];

                // Always save immoji_id + sync timestamp
                if (!empty($immojiId)) {
                    \DB::table('property_units')
                        ->where('id', $unit->id)
                        ->update(['immoji_id' => $immojiId, 'last_synced_at' => now()]);
                }

                // Set portal export flags on immoji (willhaben, immowelt etc.)
                if (!empty($immojiId)) {
                    $portalMap = self::portalFieldMap();
                    $portalFlags = [];
                    foreach ($exports as $key => $enabled) {
                        if ($key === 'immoji') continue; // immoji itself is not a portal flag
                        if (isset($portalMap[$key])) {
                            $portalFlags[$portalMap[$key]] = (bool) $enabled;
                        }
                    }
                    if (!empty($portalFlags)) {
                        try {
                            $this->setPortalExports($immojiId, $portalFlags);
                        } catch (\Exception $e) {
                            Log::warning("Failed to set portal exports for unit {$unit->unit_number}: " . $e->getMessage());
                        }
                    }
                }

                $results[] = ['unit' => $unit->unit_number, 'status' => 'ok', 'action' => $result['action']];
            } catch (\Exception $e) {
                $results[] = ['unit' => $unit->unit_number, 'status' => 'error', 'message' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * Create a new realty in Immoji and return the Immoji UUID.
     */
    public function createRealty(array $property): string
    {
        $general = self::mapPropertyToImmojiGeneral($property);

        $query = 'mutation($input: CreateRealtyGeneralInput!) { createRealty(createRealtyGeneralInput: $input) { id } }';

        $variables = [
            'input' => [
                'object' => $general['object'] ?? ['title' => $property['title'] ?? 'Untitled'],
                'address' => $general['address'] ?? [],
                'general' => $general['general'] ?? [],
            ],
        ];

        $result = $this->query($query, $variables);

        $immojiId = $result['data']['createRealty']['id'] ?? null;

        if (!$immojiId) {
            throw new \RuntimeException('Immoji createRealty returned no ID. Response: ' . json_encode($result));
        }

        // Now do a full update with all fields
        $this->updateRealty($immojiId, $property);

        return $immojiId;
    }

    /**
     * @param string   $immojiId  Immoji realty ID.
     * @param array    $property  Mapped SR-Homes property row.
     * @param string[]|null $sections  Whitelist of sections to include. Valid:
     *                                 ['general','costs','areas','descriptions','building','files'].
     *                                 null or empty => include all (legacy full-sync).
     */
    public function updateRealty(string $immojiId, array $property, ?array $sections = null): void
    {
        $this->lastSyncDroppedFiles = false;

        $wantsAll = $sections === null || $sections === [];
        $wants = fn(string $s) => $wantsAll || in_array($s, $sections, true);

        $input = ['id' => $immojiId];

        if ($wants('general')) {
            $input['generalInput'] = self::mapPropertyToImmojiGeneral($property);
        }
        if ($wants('costs')) {
            $input['costsInput'] = self::mapPropertyToImmojiCosts($property);
        }
        if ($wants('areas')) {
            $input['areasInput'] = self::mapPropertyToImmojiAreas($property);
        }
        if ($wants('descriptions')) {
            $input['descriptionsInput'] = self::mapPropertyToImmojiDescriptions($property);
        }
        if ($wants('building')) {
            $building = self::mapPropertyToImmojiBuilding($property);
            if ($building !== null) {
                $input['buildingInput'] = $building;
            }
        }
        if ($wants('files')) {
            $filesInput = $this->uploadAndMapImages($property);
            if ($filesInput !== null) {
                $input['filesInput'] = $filesInput;
            }
        }

        $query = 'mutation($input: UpdateRealtyInput!) { updateRealty(updateRealtyInput: $input) { id } }';
        $variables = [
            'input' => array_filter($input, fn($v) => $v !== null),
        ];

        $result = $this->query($query, $variables);

        if (isset($result['errors'])) {
            $errorMsg = json_encode($result['errors']);

            // Media-error recovery: stale tmp/ tokens are consumed one-shot.
            // Clear the cached immoji_source for this property and re-upload fresh.
            if (str_contains($errorMsg, 'media') || str_contains($errorMsg, 'file') || str_contains($errorMsg, 'image')) {
                Log::warning("Immoji updateRealty: media error, clearing stale tmp refs and re-uploading. Error: {$errorMsg}");
                $propertyId = $property['id'] ?? null;
                if ($propertyId) {
                    \Illuminate\Support\Facades\DB::table('property_images')
                        ->where('property_id', $propertyId)
                        ->update(['immoji_source' => null]);
                }

                $property['_forceUploadImages'] = true;
                $retryFilesInput = $this->uploadAndMapImages($property);
                if ($retryFilesInput !== null) {
                    $variables['input']['filesInput'] = $retryFilesInput;
                } else {
                    unset($variables['input']['filesInput']);
                }
                $result = $this->query($query, $variables);

                if (isset($result['errors'])) {
                    Log::warning("Immoji updateRealty: re-upload retry still failing, updating metadata only. Error: " . json_encode($result['errors']));
                    unset($variables['input']['filesInput']);
                    $result = $this->query($query, $variables);
                    if (isset($result['errors'])) {
                        throw new \RuntimeException('Immoji updateRealty failed (final retry without files): ' . json_encode($result['errors']));
                    }
                    // Metadata succeeded but images were dropped. Flag it so
                    // pushProperty keeps the files hash stale and next sync retries.
                    $this->lastSyncDroppedFiles = true;
                }
                return;
            }
            throw new \RuntimeException('Immoji updateRealty failed: ' . $errorMsg);
        }
    }

    /**
     * Map SR-Homes property to Immoji generalInput structure.
     */
    public static function mapPropertyToImmojiGeneral(array $prop): array
    {
        $marketingType = match (strtolower($prop['marketing_type'] ?? '')) {
            'kauf' => 'PURCHASE',
            'miete' => 'RENTAL',
            default => null,
        };

        $realtyStatus = match (strtolower($prop['status'] ?? '')) {
            'aktiv', 'auftrag', 'inserat', 'anfragen', 'besichtigungen' => 'ACTIVE',
            'verkauft' => 'SOLD',
            'inaktiv' => 'INACTIVE',
            default => null,
        };

        $furnishing = match (strtolower($prop['furnishing'] ?? '')) {
            'voll', 'vollmöbliert', 'full' => 'FULL',
            'teil', 'teilmöbliert', 'partial' => 'PARTIAL',
            'keine', 'unmöbliert', 'none', '' => null,
            default => null,
        };

        // Energy certificate
        $energyCertificate = [];
        if (!empty($prop['heating_demand_value'])) {
            $energyCertificate['heatingDemand'] = [
                'value' => (string) $prop['heating_demand_value'],
                'class' => in_array($prop['heating_demand_class'] ?? '', ['A','B','C','D','E','F']) ? $prop['heating_demand_class'] : null,
            ];
        }
        if (!empty($prop['energy_efficiency_value'])) {
            $fgee = str_replace('.', ',', (string) $prop['energy_efficiency_value']);
            $energyCertificate['energyEfficiencyFactor'] = [
                'value' => $fgee,
                'class' => null,
            ];
        }

        return [
            'object' => array_filter([
                'realtyStatus' => $realtyStatus,
                'usageType' => 'PRIVATE',
                'marketingType' => $marketingType,
                'title' => $prop['title'] ?? $prop['project_name'] ?? 'Untitled',
                'realtyManagerId' => self::mapBrokerToImmojiUser($prop['broker_id'] ?? null),
                'subTitle' => $prop['subtitle'] ?? null,
                'adTag' => $prop['ad_tag'] ?? null,
                'closingDate' => $prop['closing_date'] ?? null,
                'internalRating' => isset($prop['internal_rating']) ? (float) $prop['internal_rating'] : null,
            ], fn($v) => $v !== null),
            'general' => array_filter([
                'objectType' => self::mapObjectType($prop['object_type'] ?? ''),
                'objectSubtype' => self::mapObjectSubtype($prop['object_subtype'] ?? null),
                'objectNumber' => $prop['ref_id'] ?? null,
                'constructionType' => self::mapConstructionType($prop['construction_type'] ?? null),
                // ownershipType removed — Immoji's current RealtyGeneralInfoInput
                //   schema rejects it ("Field 'ownershipType' is not defined").
                //   We still keep ownership_type in our DB; it just no longer
                //   travels to Immoji until/unless they restore the field.
                'residentialUnits' => isset($prop['unit_count']) ? (int) $prop['unit_count'] : null,
                'realtyCondition' => self::mapCondition($prop['realty_condition'] ?? null),
                'constructionYear' => isset($prop['construction_year']) ? (int) $prop['construction_year'] : null,
                'furnishing' => $furnishing,
                'roomsAmount' => isset($prop['rooms_amount']) ? (float) $prop['rooms_amount'] : null,
                // freeFrom is not a valid field in RealtyGeneralInfoInput — removed
            ], fn($v) => $v !== null),
            'address' => array_filter([
                'country' => 'AT',
                'street' => $prop['address'] ?? null,
                'postalCode' => $prop['zip'] ?? null,
                'city' => $prop['city'] ?? null,
                'latitude' => isset($prop['latitude']) ? (float) $prop['latitude'] : null,
                'longitude' => isset($prop['longitude']) ? (float) $prop['longitude'] : null,
                'houseNumber' => $prop['house_number'] ?? null,
                'staircase' => $prop['staircase'] ?? null,
                'door' => $prop['door'] ?? null,
                'entrance' => $prop['entrance'] ?? null,
                'floor' => $prop['address_floor'] ?? null,
            ], fn($v) => $v !== null),
            'energyCertificate' => !empty($energyCertificate) ? $energyCertificate : null,
        ];
    }

    /**
     * Map SR-Homes property to Immoji costsInput structure.
     */
    public static function mapPropertyToImmojiCosts(array $prop): array
    {
        // Bei Vermarktungsart 'miete' gibt es in SR-Homes keine eigene
        // Mietpreis-Sektion mehr — der User traegt den Mietpreis in das
        // Feld 'Kaufpreis / Miete' (DB: purchase_price) ein. Hier routen
        // wir den Wert beim Push Richtung Immoji auf costs.rentalPrice
        // (net Amount) um, statt ihn als purchasePrice zu senden.
        $isRental = strtolower((string) ($prop['marketing_type'] ?? 'kauf')) === 'miete';
        $mainPrice = isset($prop['purchase_price']) ? (float) $prop['purchase_price'] : null;
        $legacyRental = isset($prop['rental_price']) ? (float) $prop['rental_price'] : null;

        if ($isRental) {
            $rentalNet = $mainPrice ?: $legacyRental;   // primaer purchase_price, sonst legacy rental_price
            $purchaseNet = null;                         // purchasePrice explizit NICHT fuellen bei Miete
        } else {
            $rentalNet = $legacyRental;                  // bei Kauf weiter alte rental_price-Logik
            $purchaseNet = $mainPrice;
        }

        $costs = [
            'purchasePrice' => ['netAmount' => $purchaseNet, 'vat' => null],
            'rentalPrice' => ['netAmount' => $rentalNet, 'vat' => null],
            'operatingCosts' => ['netAmount' => isset($prop['operating_costs']) ? (float) $prop['operating_costs'] : null, 'vat' => null],
            'heatingCosts' => ['netAmount' => isset($prop['heating_costs']) ? (float) $prop['heating_costs'] : null, 'vat' => null],
            'warmWaterCosts' => ['netAmount' => isset($prop['warm_water_costs']) ? (float) $prop['warm_water_costs'] : null, 'vat' => null],
            'coolingCosts' => ['netAmount' => isset($prop['cooling_costs']) ? (float) $prop['cooling_costs'] : null, 'vat' => null],
            'maintenanceReserves' => ['netAmount' => isset($prop['maintenance_reserves']) ? (float) $prop['maintenance_reserves'] : null, 'vat' => null],
            'administrativeCosts' => ['netAmount' => isset($prop['admin_costs']) ? (float) $prop['admin_costs'] : null, 'vat' => null],
            'elevatorCosts' => ['netAmount' => isset($prop['elevator_costs']) ? (float) $prop['elevator_costs'] : null, 'vat' => null],
            'parkingCosts' => ['netAmount' => isset($prop['parking_costs_monthly']) ? (float) $prop['parking_costs_monthly'] : null, 'vat' => null],
            'otherCosts' => ['netAmount' => isset($prop['other_costs']) ? (float) $prop['other_costs'] : null, 'vat' => null],
        ];

        $customerCommission = null;
        if (!empty($prop['buyer_commission_percent'])) {
            $customerCommission = [
                'amount' => (float) $prop['buyer_commission_percent'],
                'unit' => 'PERCENT_OF_PURCHASE_PRICE',
                'vat' => null,
            ];
        }

        $sellerCommission = null;
        if (!empty($prop['commission_percent'])) {
            $sellerCommission = [
                'amount' => (float) $prop['commission_percent'],
                'unit' => 'PERCENT_OF_PURCHASE_PRICE',
                'vat' => null,
            ];
        }

        $provision = array_filter([
            'commissionPaidBySeller' => !empty($prop['buyer_commission_free']),
            'customerCommission' => $customerCommission,
            'sellerCommission' => $sellerCommission,
        ], fn($v) => $v !== null);

        return [
            'costs' => $costs,
            'provision' => $provision,
        ];
    }

    /**
     * Map SR-Homes property to Immoji areasInput structure.
     */
    public static function mapPropertyToImmojiAreas(array $prop): array
    {
        // totalArea wird bewusst NICHT mehr an Immoji gesendet — das Feld ist
        // bei Einzelobjekten redundant (Immoji summiert die Einzelflaechen
        // selbst) und wir haben in SR-Homes keine UI zur expliziten Pflege.
        // Bestandsdaten aus alten Exposé-Imports fuehren sonst zu unerwarteten
        // Werten im Immoji-Inserat (z.B. Klessheimer Allee 74: 166 m² aus
        // Altbestand, obwohl nie manuell gesetzt).
        $generalAreas = array_filter([
            'livingArea' => isset($prop['living_area']) ? (float) $prop['living_area'] : null,
            'realtyArea' => isset($prop['realty_area']) ? (float) $prop['realty_area'] : null,
            'freeArea' => isset($prop['free_area']) ? (float) $prop['free_area'] : null,
            'officeSpace' => isset($prop['office_space']) ? (float) $prop['office_space'] : null,
        ], fn($v) => $v !== null);

        // Room areas
        $roomAreas = [];
        if (!empty($prop['bedrooms'])) {
            $roomAreas['bedroom'] = ['amount' => (int) $prop['bedrooms'], 'area' => null];
        }
        if (!empty($prop['bathrooms'])) {
            $roomAreas['bathroom'] = ['amount' => (int) $prop['bathrooms'], 'area' => null];
        }
        if (!empty($prop['toilets'])) {
            $roomAreas['toilet'] = ['amount' => (int) $prop['toilets'], 'area' => null];
        }

        // Other areas
        $otherAreas = [];

        // Garden
        $gardenAmount = !empty($prop['area_garden']) ? 1 : (!empty($prop['has_garden']) ? 1 : null);
        if ($gardenAmount || !empty($prop['area_garden'])) {
            $otherAreas['garden'] = [
                'amount' => $gardenAmount ?? 1,
                'area' => isset($prop['area_garden']) ? (float) $prop['area_garden'] : null,
            ];
        }

        // Balcony
        $balconyAmount = !empty($prop['area_balcony']) ? 1 : (!empty($prop['has_balcony']) ? 1 : null);
        if ($balconyAmount || !empty($prop['area_balcony'])) {
            $otherAreas['balcony'] = [
                'amount' => $balconyAmount ?? 1,
                'area' => isset($prop['area_balcony']) ? (float) $prop['area_balcony'] : null,
            ];
        }

        // Terrace
        $terraceAmount = !empty($prop['area_terrace']) ? 1 : (!empty($prop['has_terrace']) ? 1 : null);
        if ($terraceAmount || !empty($prop['area_terrace'])) {
            $otherAreas['terrace'] = [
                'amount' => $terraceAmount ?? 1,
                'area' => isset($prop['area_terrace']) ? (float) $prop['area_terrace'] : null,
            ];
        }

        // Loggia
        $loggiaAmount = !empty($prop['area_loggia']) ? 1 : (!empty($prop['has_loggia']) ? 1 : null);
        if ($loggiaAmount || !empty($prop['area_loggia'])) {
            $otherAreas['loggia'] = [
                'amount' => $loggiaAmount ?? 1,
                'area' => isset($prop['area_loggia']) ? (float) $prop['area_loggia'] : null,
            ];
        }

        // Basement
        $basementAmount = !empty($prop['area_basement']) ? 1 : (!empty($prop['has_basement']) ? 1 : null);
        if ($basementAmount || !empty($prop['area_basement'])) {
            $otherAreas['basement'] = [
                'amount' => $basementAmount ?? 1,
                'area' => isset($prop['area_basement']) ? (float) $prop['area_basement'] : null,
            ];
        }

        // Pool
        if (!empty($prop['has_pool'])) {
            $otherAreas['pool'] = ['amount' => 1, 'area' => null];
        }

        // Sauna
        if (!empty($prop['has_sauna'])) {
            $otherAreas['sauna'] = ['amount' => 1, 'area' => null];
        }

        // Parking spaces — structured Stellplatz-Entries aus building_details
        // (neuer Flow). Legacy-Fallback für Property-Datensätze, in denen
        // noch die flachen Felder parking_spaces / garage_spaces gefüllt sind.
        $parkingSpaces = self::mapParkingSpaces($prop);

        return [
            'generalAreas' => $generalAreas ?: null,
            'roomAreas' => $roomAreas ?: null,
            'otherAreas' => $otherAreas ?: null,
            'parkingSpaces' => $parkingSpaces ?: [],
        ];
    }

    /**
     * Map SR-Homes property to Immoji descriptionsInput structure.
     */
    public static function mapPropertyToImmojiDescriptions(array $prop): array
    {
        return array_filter([
            'realtyDescription' => self::textToHtml($prop['realty_description'] ?? null),
            'locationDescription' => self::textToHtml($prop['location_description'] ?? null),
            'equipmentDescription' => self::textToHtml($prop['equipment_description'] ?? null),
            'otherDescription' => self::textToHtml($prop['other_description'] ?? null),
        ], fn($v) => $v !== null);
    }

    /**
     * Convert plain text with newlines to HTML paragraphs for immoji rich-text fields.
     */
    private static function textToHtml(?string $text): ?string
    {
        if (empty($text)) return null;
        // If already contains HTML tags, return as-is
        if (preg_match('/<[a-z][\s\S]*>/i', $text)) return $text;
        // Convert double newlines to paragraphs, single newlines to <br>
        $paragraphs = preg_split('/\n\s*\n/', trim($text));
        if (count($paragraphs) <= 1) {
            return '<p>' . nl2br(e(trim($text))) . '</p>';
        }
        return implode('', array_map(fn($p) => '<p>' . nl2br(e(trim($p))) . '</p>', $paragraphs));
    }

    /**
     * Map SR-Homes building_details to Immoji buildingInput structure.
     */
    public static function mapPropertyToImmojiBuilding(array $prop): ?array
    {
        // building_details kommt aus DB als JSON-String — zuerst dekodieren.
        $bd = $prop['building_details'] ?? null;
        if (is_string($bd)) {
            $decoded = json_decode($bd, true);
            $bd = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($bd)) $bd = [];

        $result = [];

        // ─── Heizung, Befeuerung, Warmwasser (buildingInput.heatingWarmWater) ───
        // Schema-Discovery (gegen Immojis GraphQL-API, mit Network-Trace der
        // Immoji-Admin-UI: Feldnamen heatingWarmWater.heatingType/firing/warmWater).
        //   type: RealtyHeatingWarmWaterInput {
        //     heatingType: [RealtyHeating!]   — Multi-Select Array
        //     firing:      RealtyFiring       — Single Enum (Befeuerung)
        //     warmWater:   RealtyWarmWater    — Single Enum
        //   }
        $heatingWW = self::mapHeatingWarmWater($bd);
        if ($heatingWW !== null) {
            $result['heatingWarmWater'] = $heatingWW;
        }

        return !empty($result) ? $result : null;
    }

    /**
     * Maps building_details.heating (aus dem Energie-Tab) auf Immojis
     * RealtyHeatingWarmWaterInput. Deutsche UI-Labels werden zu den
     * Enum-Werten gemappt die per Brute-Force gegen die GraphQL-API
     * verifiziert wurden. Unmappable Werte werden still gedroppt.
     */
    private static function mapHeatingWarmWater(array $bd): ?array
    {
        $heating = is_array($bd['heating'] ?? null) ? $bd['heating'] : [];

        // UI-Label -> Enum RealtyHeating (Multi-Select)
        $heatingTypeMap = [
            'Zentralheizung'   => 'CENTRAL_HEATING',
            'Fernwärme'        => 'DISTRICT_HEATING',
            'Etagenheizung'    => 'STOREY_HEATING',
            'Kamin'            => 'FIREPLACE',
            'Fußbodenheizung'  => 'FLOOR_HEATING',
            'Offener Kamin'    => 'OPEN_FIREPLACE',
            'Heizkörper'       => 'RADIATOR',
            'Heizofen'         => 'STOVE',
            'Kachelofen'       => 'TILE_STOVE',
            'Wandheizung'      => 'WALL_HEATING',
        ];

        // UI-Label -> Enum RealtyFiring (Befeuerung)
        $firingMap = [
            'Luftwärmepumpe'           => 'AIR_HEAT_PUMP',
            'Sole-Wasser-Wärmepumpe'   => 'HEAT_PUMP',
            'Wasser-Wasser-Wärmepumpe' => 'HEAT_PUMP',
            'Erdwärme'                 => 'GEOTHERMAL',
            'Gas'                      => 'GAS',
            'Öl'                       => 'OIL',
            'Holz'                     => 'WOOD',
            'Solar'                    => 'SOLAR_ENERGY',
            'Fernwärme'                => 'DISTRICT_HEATING',
            'Blockheizkraftwerk'       => 'BLOCK_HEATING_POWER_PLANT',
            'Elektro'                  => 'ELECTRIC',
            'Kohle'                    => 'COAL',
            'Alternativ'               => 'ALTERNATIVE',
            // Brennwerttechnik und Pellets haben (Stand jetzt) keine
            // passende Enum-Entsprechung — diese Auswahlen werden fuer
            // den Sync gedroppt, bleiben aber in der DB/UI sichtbar.
        ];

        // UI-Label -> Enum RealtyWarmWater
        $warmWaterMap = [
            'Boiler'                   => 'BOILER',
            'Brauchwasserwärmepumpe'   => 'BRAUCHWASSERWARMEPUMPE',
            'Fernwärme'                => 'DISTRICT_HEATING',
            'Durchlauferhitzer Strom'  => 'ELECTRIC_BOILER',
            'Durchlauferhitzer Gas'    => 'GAS_WATER_HEATER',
            'Frischwasserstation'      => 'FRESH_WATER_STATION',
            'Gaskessel'                => 'GAS_BOILER',
            'Ölkessel'                 => 'OIL_BOILER',
            // Zentral / Solar / Wärmepumpe haben aktuell keine saubere
            // Enum-Entsprechung in RealtyWarmWater — werden gedroppt.
        ];

        $out = [];

        // Heizungsart: Multi-Select (bd.heating.types = Array von UI-Labels)
        if (!empty($heating['types']) && is_array($heating['types'])) {
            $enumList = [];
            foreach ($heating['types'] as $label) {
                $key = trim((string) $label);
                if ($key === '') continue;
                if (isset($heatingTypeMap[$key])) $enumList[] = $heatingTypeMap[$key];
            }
            $enumList = array_values(array_unique($enumList));
            if (!empty($enumList)) $out['heatingType'] = $enumList;
        }

        // Befeuerung: Single
        if (!empty($heating['fuel'])) {
            $key = trim((string) $heating['fuel']);
            if (isset($firingMap[$key])) $out['firing'] = $firingMap[$key];
        }

        // Warmwasser: Single
        if (!empty($heating['hot_water'])) {
            $key = trim((string) $heating['hot_water']);
            if (isset($warmWaterMap[$key])) $out['warmWater'] = $warmWaterMap[$key];
        }

        return !empty($out) ? $out : null;
    }

    /**
     * Map Stellplatz-Entries (building_details.parking_spaces) to Immoji's
     * parkingSpaces[] array. Each entry becomes one object with type + amount
     * + optional maxWidth / area / suitableFor / description. Falls back to
     * the legacy flat columns parking_spaces / garage_spaces for properties
     * that haven't been touched under the new structured UI yet.
     */
    private static function mapParkingSpaces(array $prop): array
    {
        $bd = $prop['building_details'] ?? null;
        if (is_string($bd)) {
            $decoded = json_decode($bd, true);
            $bd = is_array($decoded) ? $decoded : null;
        }
        $entries = is_array($bd) && is_array($bd['parking_spaces'] ?? null)
            ? $bd['parking_spaces']
            : null;

        // Structured path: use the array from the new Stellplätze UI.
        if (is_array($entries) && !empty($entries)) {
            $typeMap = [
                'barn'                => 'BARN',
                'outdoor'             => 'OUTDOOR_PARKING_SPACE',
                'carport'             => 'CARPORT',
                'duplex_garage'       => 'DUPLEX_GARAGE',
                'garage'              => 'GARAGE',
                'general'             => 'GENERAL_PARKING_SPACE',
                'hall'                => 'HALL',
                'underground_garage'  => 'UNDERGROUND_GARAGE',
                'car_park'            => 'CAR_PARK',
                'other'               => 'OTHER',
            ];
            $suitableMap = [
                'car'        => 'CAR',
                'truck'      => 'TRUCK',
                'motorcycle' => 'MOTORCYCLE',
                'bike'       => 'BIKE',
                'motorhome'  => 'MOTORHOME',
                'boat'       => 'BOAT',
            ];

            $out = [];
            foreach ($entries as $e) {
                if (!is_array($e)) continue;
                $typeKey = $e['type'] ?? null;
                if (!$typeKey) continue;
                $enum = $typeMap[$typeKey] ?? 'OTHER';
                $row = [
                    'type' => $enum,
                    'amount' => (int) ($e['count'] ?? 1) ?: 1,
                ];
                if (!empty($e['area'])) $row['area'] = (float) $e['area'];
                if (!empty($e['max_vehicle_width'])) $row['maxWidth'] = (float) $e['max_vehicle_width'];
                if (!empty($e['suitable_for'])) {
                    $row['suitableFor'] = $suitableMap[$e['suitable_for']] ?? strtoupper((string) $e['suitable_for']);
                }
                if (!empty($e['description'])) $row['description'] = (string) $e['description'];
                $out[] = $row;
            }
            if (!empty($out)) return $out;
        }

        // Legacy-Fallback aus flachen Spalten.
        $out = [];
        if (!empty($prop['parking_spaces'])) {
            $out[] = ['type' => 'OUTDOOR_PARKING_SPACE', 'amount' => (int) $prop['parking_spaces']];
        }
        if (!empty($prop['garage_spaces'])) {
            $out[] = ['type' => 'GARAGE', 'amount' => (int) $prop['garage_spaces']];
        }
        return $out;
    }

    /**
     * Map SR-Homes property_history entries to Immoji's refurbishments array.
     * Each entry becomes { type: ENUM, year: INT, note: STRING } — fields
     * with no value are dropped. Entries without a mapped category are
     * silently skipped (legacy free-text history stays local).
     */
    private static function mapRefurbishments($history): array
    {
        if (is_string($history)) {
            $decoded = json_decode($history, true);
            $history = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($history)) return [];

        $typeMap = [
            'general'     => 'COMPLETE',
            'windows'     => 'WINDOWS',
            'doors'       => 'DOORS',
            'floors'      => 'FLOORS',
            'heating'     => 'HEATING',
            'pipes'       => 'PIPES',
            'connections' => 'CONNECTIONS',
            'facade'      => 'FACADE',
            'bathrooms'   => 'BATHROOMS',
            'kitchen'     => 'KITCHEN',
            'other'       => 'OTHER',
            'required'    => 'REQUIRED_ACTIONS',
        ];

        $out = [];
        foreach ($history as $entry) {
            if (!is_array($entry)) continue;
            $cat = $entry['category'] ?? null;
            if (!$cat || !isset($typeMap[$cat])) continue;
            $item = ['type' => $typeMap[$cat]];
            $year = (int) ($entry['year'] ?? 0);
            if ($year > 0) $item['year'] = $year;
            $note = trim((string) ($entry['description'] ?? ''));
            if ($note !== '') $item['note'] = $note;
            $out[] = $item;
        }
        return $out;
    }

    /**
     * Map SR-Homes type to Immoji objectType ENUM.
     */
    public static function mapObjectType(string $srType): string
    {
        return match (strtolower(trim($srType))) {
            'eigentumswohnung', 'wohnung', 'apartment' => 'APARTMENT',
            'einfamilienhaus', 'haus', 'house', 'reihenhaus', 'doppelhaus', 'mehrfamilienhaus', 'bungalow', 'villa' => 'HOUSE',
            // Immoji enum currently accepts PROPERTY for lots/plots.
            'grundstueck', 'grundstück', 'land' => 'PROPERTY',
            // Immoji enum currently accepts OFFICE for commercial objects in this integration.
            'gewerbe', 'commercial', 'geschäft', 'geschaeft' => 'OFFICE',
            'büro', 'buero', 'office' => 'OFFICE',
            'garage', 'stellplatz', 'parking' => 'GARAGE',
            default => 'APARTMENT',
        };
    }

    /**
     * Map SR-Homes sub_type to Immoji objectSubtype.
     */
    public static function mapObjectSubtype(?string $srSubType): ?string
    {
        if (empty($srSubType)) {
            return null;
        }

        return match (strtolower(trim($srSubType))) {
            'etagenwohnung', 'floor_apartment' => 'FLOOR_APARTMENT',
            'penthouse' => 'PENTHOUSE',
            'maisonette' => 'MAISONETTE',
            'dachgeschoss', 'dachgeschosswohnung', 'attic' => 'ATTIC_APARTMENT',
            'gartenwohnung', 'garden_apartment' => 'GARDEN_APARTMENT',
            'erdgeschoss', 'erdgeschosswohnung', 'ground_floor' => 'GROUND_FLOOR_APARTMENT',
            'doppelhaushälfte', 'doppelhaushaelfte', 'semi_detached' => 'SEMI_DETACHED_HOUSE',
            'einfamilienhaus', 'detached', 'freistehend' => 'DETACHED_HOUSE',
            'reihenhaus', 'terraced', 'row_house' => 'TERRACED_HOUSE',
            'bungalow' => 'BUNGALOW',
            'villa' => 'VILLA',
            'mehrfamilienhaus', 'multi_family' => 'MULTI_FAMILY_HOUSE',
            'bauernhaus', 'farmhouse' => 'FARMHOUSE',
            default => null,
        };
    }

    /**
     * Map SR-Homes object_condition to Immoji realtyCondition ENUM.
     */
    public static function mapCondition(?string $condition): ?string
    {
        if (empty($condition)) {
            return null;
        }

        return match (strtolower(trim($condition))) {
            'erstbezug', 'first_occupancy', 'neubau' => 'FIRST_OCCUPANCY',
            'modernisiert', 'modernized' => 'MODERNIZED',
            'saniert', 'refurbished', 'renoviert' => 'FULLY_RENOVATED',
            'teilsaniert', 'partially_renovated' => 'PARTIALLY_RENOVATED',
            'sanierungsbedürftig', 'sanierungsbedurftig', 'need_of_renovation', 'renovierungsbedürftig', 'abbruchreif' => 'DILAPIDATED',
            'gepflegt', 'well_maintained', 'gut' => 'MAINTAINED',
            'neuwertig', 'as_new', 'mint_condition' => 'AS_NEW',
            default => null,
        };
    }

    /**
     * Map SR-Homes ownership_type to Immoji ownershipType ENUM.
     */
    public static function mapConstructionType(?string $type): ?string
    {
        // TODO: immoji enum values for constructionType are unknown (introspection blocked)
        // Field is stored locally but not sent to immoji until enums are confirmed
        return null;
    }

    public static function mapOwnershipType(?string $type): ?string
    {
        if (empty($type)) return null;
        return match (strtolower(trim($type))) {
            'wohnungseigentum' => 'CONDOMINIUM',
            'miteigentum' => 'CO_OWNERSHIP',
            'alleineigentum' => 'SOLE_OWNERSHIP',
            'baurecht' => 'BUILDING_RIGHT',
            'genossenschaft' => 'COOPERATIVE',
            default => null,
        };
    }

    /**
     * Map SR-Homes broker_id to Immoji user UUID.
     */
    public static function mapBrokerToImmojiUser($brokerId): ?string
    {
        if (!$brokerId) return null;

        // SR-Homes user_id => Immoji user UUID
        $map = [
            1  => 'be606262-830c-4580-bd97-03f9da45b960', // Maximilian Hölzl (hoelzl@sr-homes.at)
            16 => '2e583e32-80a1-4e9a-aed8-3a017a5450bb', // Susanne Renzl (renzl@sr-homes.at)
            21 => 'acf0a7b1-d22f-4c3d-a478-404e682d510a', // Roland Felfernig (felfernig@sr-homes.at)
        ];

        return $map[(int) $brokerId] ?? null;
    }

    /**
     * Upload property images to Immoji and return filesInput structure.
     * 1. Upload each image file to POST /upload/realty/type/media
     * 2. Get back tmp/UUID.ext source references
     * 3. Return filesInput with images array + optional coverImage
     */
    public function uploadAndMapImages(array $prop): ?array
    {
        $propertyId = $prop['id'] ?? null;
        if (!$propertyId) return null;

        $forceUpload = !empty($prop['_forceUploadImages']);

        $images = \Illuminate\Support\Facades\DB::table('property_images')
            ->where('property_id', $propertyId)
            ->orderBy('sort_order')
            ->get();

        if ($images->isEmpty()) return null;

        $storagePath = storage_path('app/public/');
        $mediaItems = [];
        $coverImage = null;

        foreach ($images as $index => $img) {
            $title = $img->title ?: $img->original_name ?: ('Bild ' . ($index + 1));
            $order = (float) ($img->sort_order ?? $index);

            // Skip upload if already synced — unless force flag is set (for units)
            if (!$forceUpload && !empty($img->immoji_source)) {
                $source = $img->immoji_source;
                if ($img->is_title_image) {
                    $coverImage = ['source' => $source, 'title' => $title, 'order' => 0.0];
                } else {
                    $mediaItems[] = ['source' => $source, 'title' => $title, 'order' => $order];
                }
                continue;
            }

            $filePath = $storagePath . $img->path;
            if (!file_exists($filePath)) {
                Log::warning("Immoji upload: file not found: {$filePath}");
                continue;
            }

            try {
                $mediaType = $img->is_title_image ? 'cover' : 'media';
                $source = $this->uploadFile($filePath, $img->mime_type ?? 'image/jpeg', $mediaType);

                // Save immoji_source so we skip this image next time
                \Illuminate\Support\Facades\DB::table('property_images')
                    ->where('id', $img->id)
                    ->update(['immoji_source' => $source]);

                if ($img->is_title_image) {
                    $coverImage = ['source' => $source, 'title' => $title, 'order' => 0.0];
                } else {
                    $mediaItems[] = ['source' => $source, 'title' => $title, 'order' => $order];
                }
            } catch (\Exception $e) {
                Log::warning("Immoji image upload failed for {$img->original_name}: " . $e->getMessage());
                continue;
            }
        }

        if (empty($mediaItems) && !$coverImage) return null;

        $result = [];
        if (!empty($mediaItems)) {
            $result['images'] = $mediaItems;
        }
        if ($coverImage) {
            $result['coverImage'] = $coverImage;
        }
        return $result;
    }

    /**
     * Upload a single file to Immoji's REST upload endpoint.
     * Returns the temporary source path (e.g. "tmp/UUID.ext").
     */
    private function uploadFile(string $filePath, string $mimeType, string $type = 'media'): string
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->timeout(120)->attach(
            'file',
            file_get_contents($filePath),
            basename($filePath),
            ['Content-Type' => $mimeType]
        )->post("https://api.immoji.org/upload/realty/type/{$type}");

        if ($response->failed()) {
            throw new \RuntimeException('Immoji file upload failed: HTTP ' . $response->status());
        }

        $data = $response->json();
        $source = $data['file'] ?? null;

        if (!$source) {
            throw new \RuntimeException('Immoji file upload returned no source: ' . json_encode($data));
        }

        return $source;
    }

    /**
     * Execute a GraphQL query against the Immoji API.
     */
    private function query(string $query, array $variables = []): array
    {
        $payload = ['query' => $query];
        if (!empty($variables)) {
            $payload['variables'] = $variables;
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Content-Type' => 'application/json',
        ])->timeout(120)->post(self::API_URL, $payload);

        if ($response->failed()) {
            Log::error('Immoji GraphQL request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('Immoji API request failed with status ' . $response->status() . ': ' . $response->body());
        }

        $data = $response->json();

        if (isset($data['errors'])) {
            Log::warning('Immoji GraphQL returned errors', ['errors' => $data['errors']]);
        }

        return $data;
    }

    /**
     * Get portal export status for a realty from Immoji.
     */
    public function getPortalExportStatus(string $immojiId): array
    {
        $query = <<<GQL
        {
            realty(id: "$immojiId") {
                id
                portalData {
                    willhabenExportEnabled immoweltExportEnabled immoscoutExportEnabled
                    dibeoExportEnabled kurierExportEnabled immoSNExportEnabled
                    allesKralleExportEnabled homepageExportEnabled
                    willhabenLastExport immoweltLastExport immoscoutLastExport
                    dibeoLastExport kurierLastExport immoSNLastExport
                }
            }
        }
        GQL;

        // Use inline query (no variables needed for simple ID)
        $result = $this->query($query);

        if (isset($result["errors"])) {
            throw new \RuntimeException("Immoji getPortalExportStatus failed: " . json_encode($result["errors"]));
        }

        $portalData = $result["data"]["realty"]["portalData"] ?? null;
        if (empty($portalData)) {
            return [
                'willhabenExportEnabled' => null,
                'immoweltExportEnabled' => null,
                'immoscoutExportEnabled' => null,
                'dibeoExportEnabled' => null,
                'kurierExportEnabled' => null,
                'immoSNExportEnabled' => null,
                'allesKralleExportEnabled' => null,
                'homepageExportEnabled' => null,
                'willhabenLastExport' => null,
                'immoweltLastExport' => null,
                'immoscoutLastExport' => null,
                'dibeoLastExport' => null,
                'kurierLastExport' => null,
                'immoSNLastExport' => null,
            ];
        }
        return $portalData;
    }

    /**
     * Set portal export flags for a realty.
     * $portals is an associative array like ["willhabenExportEnabled" => true, "immoweltExportEnabled" => false]
     */
    public function setPortalExports(string $immojiId, array $portals): void
    {
        $query = "mutation(\$input: UpdateRealtyInput!) { updateRealty(updateRealtyInput: \$input) { id } }";

        $variables = [
            "input" => [
                "id" => $immojiId,
                "portalDataInput" => $portals,
            ],
        ];

        $result = $this->query($query, $variables);

        if (isset($result["errors"])) {
            throw new \RuntimeException("Immoji setPortalExports failed: " . json_encode($result["errors"]));
        }
    }

    /**
     * Portal name mapping: SR-Homes portal names -> Immoji field names
     */
    public static function portalFieldMap(): array
    {
        return [
            "willhaben" => "willhabenExportEnabled",
            "immowelt" => "immoweltExportEnabled",
            "immoscout24" => "immoscoutExportEnabled",
            "dibeo" => "dibeoExportEnabled",
            "kurier" => "kurierExportEnabled",
            "immoSN" => "immoSNExportEnabled",
            "allesKralle" => "allesKralleExportEnabled",
            "homepage" => "homepageExportEnabled",
        ];
    }


    /**
     * Get portal capacity overview: limits and active export counts.
     */
    public function getPortalCapacity(): array
    {
        // Only query limits per portal (usage counts not reliably available via API)
        $portalEnums = ['WILLHABEN' => 'willhaben', 'IMMOWELT' => 'immowelt', 'IMMOSCOUT' => 'immoscout', 'IMMO_SN' => 'immoSN', 'DIBEO' => 'dibeo', 'KURIER' => 'kurier'];
        $result = [];
        foreach ($portalEnums as $enum => $key) {
            try {
                $r = $this->query("{ portalData(portal: $enum) { exportLimit } }");
                $limit = (isset($r['errors'])) ? null : ($r['data']['portalData']['exportLimit'] ?? null);
            } catch (\Exception $e) {
                $limit = null;
            }
            $result[$key] = ['limit' => $limit];
        }
        $result['allesKralle'] = ['limit' => null];
        return $result;
    }

}
