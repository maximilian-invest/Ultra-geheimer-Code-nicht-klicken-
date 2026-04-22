<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IntakeProtocolDraft;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntakeProtocolController extends Controller
{
    public function draftSave(Request $request): JsonResponse
    {
        $data = $request->json()->all();
        $draftKey = trim((string) ($data['draft_key'] ?? ''));
        $formData = $data['form_data'] ?? [];
        $currentStep = (int) ($data['current_step'] ?? 1);

        if ($draftKey === '') {
            return response()->json(['error' => 'draft_key required'], 400);
        }

        $userId = (int) \Auth::id();
        $draft = IntakeProtocolDraft::updateOrCreate(
            ['broker_id' => $userId, 'draft_key' => $draftKey],
            [
                'form_data' => is_array($formData) ? json_encode($formData) : (string) $formData,
                'current_step' => $currentStep,
                'last_saved_at' => now(),
            ]
        );

        return response()->json([
            'success' => true,
            'draft_id' => $draft->id,
            'last_saved_at' => $draft->last_saved_at?->toIso8601String(),
        ]);
    }

    public function draftLoad(Request $request): JsonResponse
    {
        $draftKey = $request->query('draft_key');
        if (!$draftKey) {
            return response()->json(['error' => 'draft_key required'], 400);
        }

        $userId = (int) \Auth::id();
        $draft = IntakeProtocolDraft::where('broker_id', $userId)
            ->where('draft_key', $draftKey)
            ->first();

        if (!$draft) {
            return response()->json(['success' => false, 'error' => 'not found'], 404);
        }

        return response()->json([
            'success' => true,
            'draft_id' => $draft->id,
            'form_data' => $draft->form_data_array,
            'current_step' => $draft->current_step,
            'last_saved_at' => $draft->last_saved_at?->toIso8601String(),
        ]);
    }

    public function submit(Request $request): JsonResponse
    {
        $payload = $request->json()->all();
        $form = is_array($payload['form_data'] ?? null) ? $payload['form_data'] : [];
        $signatureDataUrl = (string) ($payload['signature_data_url'] ?? '');
        $signedByName = trim((string) ($payload['signed_by_name'] ?? ''));
        $disclaimerText = trim((string) ($payload['disclaimer_text'] ?? ''));

        if ($disclaimerText === '' || $signedByName === '' || $signatureDataUrl === '') {
            return response()->json(['error' => 'signature/disclaimer/name required'], 422);
        }

        $brokerId = (int) \Auth::id();
        $broker = \App\Models\User::find($brokerId);

        try {
            $result = \DB::transaction(function () use ($form, $signatureDataUrl, $signedByName, $disclaimerText, $brokerId, $broker, $request) {

                // 1) Customer
                $ownerData = is_array($form['owner'] ?? null) ? $form['owner'] : [];
                $customerId = $this->findOrCreateCustomer($ownerData);

                // 2) Portal-User
                $portalAccessGranted = !empty($form['portal_access_granted']);
                $initialPassword = null;
                if ($portalAccessGranted && !empty($ownerData['email'])) {
                    $initialPassword = $this->generatePassword();
                    $this->ensurePortalUser($ownerData, $initialPassword, $customerId);
                }

                // 3) Property
                $property = $this->buildProperty($form, $customerId, $brokerId);
                $property->save();

                // 4) Signature
                $signaturePath = $this->storeSignature($property->id, $signatureDataUrl);

                // 5) IntakeProtocol
                $protocol = \App\Models\IntakeProtocol::create([
                    'property_id' => $property->id,
                    'customer_id' => $customerId,
                    'broker_id' => $brokerId,
                    'signed_at' => now(),
                    'signed_by_name' => $signedByName,
                    'signature_png_path' => $signaturePath,
                    'disclaimer_text' => $disclaimerText,
                    'portal_access_granted' => $portalAccessGranted,
                    'broker_notes' => (string) ($form['broker_notes'] ?? ''),
                    'open_fields' => array_values((array) ($form['open_fields'] ?? [])),
                    'form_snapshot' => json_encode($form, JSON_UNESCAPED_UNICODE),
                    'client_ip' => $request->ip(),
                    'user_agent' => (string) $request->userAgent(),
                ]);

                // 6) Activity
                \DB::table('activities')->insert([
                    'property_id' => $property->id,
                    'stakeholder' => $ownerData['name'] ?? '',
                    'activity' => 'Aufnahmeprotokoll durchgeführt',
                    'category' => 'Aufnahmeprotokoll',
                    'activity_date' => now(),
                    'link_session_id' => 'intake_protocol:' . $protocol->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // 7) PDF
                $pdfPath = $this->generateAndStorePdf($protocol, $property, $ownerData, $broker, $form);
                $protocol->update(['pdf_path' => $pdfPath]);

                // 8) Mails
                $emailService = app(\App\Services\IntakeProtocolEmailService::class);

                if ($portalAccessGranted && $initialPassword && !empty($ownerData['email'])) {
                    $emailService->sendPortalAccess(
                        owner: $ownerData,
                        loginEmail: $ownerData['email'],
                        initialPassword: $initialPassword,
                        broker: ['name' => $broker->name, 'email' => $broker->email],
                    );
                    $protocol->update(['portal_email_sent_at' => now()]);
                }

                $missingDocs = $this->computeMissingDocs($form['documents_available'] ?? []);
                $vermittlungsPath = null;
                if (count($missingDocs) > 0) {
                    $vermittlungsPath = $this->generateVermittlungsauftrag($property, $ownerData, $broker);
                }

                if (!empty($ownerData['email'])) {
                    $emailService->sendProtocol(
                        owner: $ownerData,
                        property: $property->toArray(),
                        broker: ['name' => $broker->name, 'email' => $broker->email],
                        missingDocs: $missingDocs,
                        protocolPdfPath: storage_path('app/' . $pdfPath),
                        vermittlungsauftragPdfPath: $vermittlungsPath ? storage_path('app/' . $vermittlungsPath) : null,
                    );
                    $protocol->update(['owner_email_sent_at' => now()]);
                }

                // 9) Draft cleanup
                if (!empty($form['draft_key'])) {
                    \App\Models\IntakeProtocolDraft::where('broker_id', $brokerId)
                        ->where('draft_key', $form['draft_key'])
                        ->delete();
                }

                return ['property_id' => $property->id, 'protocol_id' => $protocol->id];
            });

            return response()->json(['success' => true] + $result);
        } catch (\Throwable $e) {
            \Log::error('intake_protocol_submit failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Submit failed: ' . $e->getMessage()], 500);
        }
    }

    // --- Helpers ---

    private function findOrCreateCustomer(array $ownerData): ?int
    {
        if (empty($ownerData['email']) && empty($ownerData['name'])) return null;

        if (!empty($ownerData['email'])) {
            $existing = \DB::table('customers')->where('email', $ownerData['email'])->first();
            if ($existing) return (int) $existing->id;
        }

        return (int) \DB::table('customers')->insertGetId([
            'name' => $ownerData['name'] ?? '',
            'email' => $ownerData['email'] ?? null,
            'phone' => $ownerData['phone'] ?? null,
            'address' => $ownerData['address'] ?? null,
            'zip' => $ownerData['zip'] ?? null,
            'city' => $ownerData['city'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ensurePortalUser(array $ownerData, string $password, ?int $customerId): void
    {
        $email = $ownerData['email'];
        $existing = \DB::table('users')->where('email', $email)->first();
        if ($existing) return;

        \DB::table('users')->insert([
            'name' => $ownerData['name'] ?? $email,
            'email' => $email,
            'password' => bcrypt($password),
            'user_type' => 'customer',
            'customer_id' => $customerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function generatePassword(): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#%';
        return substr(str_shuffle(str_repeat($chars, 2)), 0, 12);
    }

    private function buildProperty(array $form, ?int $customerId, int $brokerId): \App\Models\Property
    {
        $fillable = [
            'object_type', 'object_subtype', 'marketing_type',
            'title', 'subtitle', 'ref_id',
            'address', 'house_number', 'zip', 'city',
            'staircase', 'door', 'address_floor', 'latitude', 'longitude',
            'living_area', 'free_area', 'total_area', 'realty_area',
            'rooms_amount', 'bedrooms', 'bathrooms', 'toilets',
            'floor_count', 'floor_number',
            'construction_year', 'year_renovated',
            'realty_condition', 'construction_type', 'quality',
            'ownership_type', 'furnishing', 'condition_note',
            'area_balcony', 'balcony_count', 'area_terrace', 'terrace_count',
            'area_loggia', 'loggia_count', 'area_garden', 'garden_count',
            'area_basement', 'basement_count',
            'has_balcony', 'has_terrace', 'has_loggia', 'has_garden',
            'has_basement', 'has_cellar', 'has_elevator', 'has_fitted_kitchen',
            'has_air_conditioning', 'has_pool', 'has_sauna', 'has_fireplace',
            'has_alarm', 'has_barrier_free', 'has_guest_wc', 'has_storage_room',
            'common_areas', 'flooring', 'bathroom_equipment', 'orientation',
            'energy_certificate', 'heating_demand_value', 'heating_demand_class',
            'energy_efficiency_value', 'energy_primary_source', 'energy_valid_until',
            'heating', 'has_photovoltaik', 'charging_station_status',
            'garage_spaces', 'parking_spaces', 'parking_type', 'parking_assignment',
            'property_manager_id', 'encumbrances',
            'documents_available', 'approvals_status', 'approvals_notes', 'internal_notes',
            'purchase_price', 'rental_price', 'rent_warm', 'rent_deposit', 'price_per_m2',
            'operating_costs', 'maintenance_reserves', 'heating_costs', 'warm_water_costs',
            'admin_costs', 'elevator_costs', 'monthly_costs',
            'commission_percent', 'buyer_commission_percent',
            'available_from', 'property_history',
        ];

        $props = ['broker_id' => $brokerId, 'customer_id' => $customerId, 'realty_status' => 'aktiv'];
        foreach ($fillable as $key) {
            if (array_key_exists($key, $form) && $form[$key] !== '' && $form[$key] !== null) {
                $props[$key] = $form[$key];
            }
        }
        // ref_id: wenn der Makler keine vorgegeben hat, generieren wir eine
        // auftrag-basierte Kennung. ref_id ist in MySQL unique & not-null.
        if (empty($props['ref_id'])) {
            $props['ref_id'] = 'AUF-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        }
        if (!empty($form['broker_notes'])) {
            $props['internal_notes'] = trim(($props['internal_notes'] ?? '') . "\n" . $form['broker_notes']);
        }
        if (isset($props['property_history']) && is_array($props['property_history'])) {
            $props['property_history'] = json_encode($props['property_history'], JSON_UNESCAPED_UNICODE);
        }

        return new \App\Models\Property($props);
    }

    private function storeSignature(int $propertyId, string $dataUrl): string
    {
        $base64 = preg_replace('/^data:image\/png;base64,/', '', $dataUrl);
        $binary = base64_decode($base64);
        if (!$binary) throw new \RuntimeException('Invalid signature data URL');

        $path = "intake-protocols/{$propertyId}/signature-" . time() . '.png';
        \Storage::put($path, $binary);
        return $path;
    }

    private function generateAndStorePdf(
        \App\Models\IntakeProtocol $protocol,
        \App\Models\Property $property,
        array $owner,
        \App\Models\User $broker,
        array $form,
    ): string {
        $pdfService = app(\App\Services\IntakeProtocolPdfService::class);

        $sanierungen = [];
        $history = $form['property_history'] ?? null;
        if (is_string($history)) $history = json_decode($history, true);
        if (is_array($history)) {
            foreach ($history as $h) {
                $sanierungen[] = [
                    'category' => $h['category'] ?? '',
                    'label' => $h['title'] ?? ($h['category'] ?? ''),
                    'year' => $h['year'] ?? null,
                    'description' => $h['description'] ?? '',
                ];
            }
        }

        $binary = $pdfService->render([
            'property' => $property->toArray(),
            'owner' => $owner,
            'broker' => ['name' => $broker->name, 'email' => $broker->email],
            'disclaimer_text' => $protocol->disclaimer_text,
            'signed_at' => $protocol->signed_at,
            'signed_by_name' => $protocol->signed_by_name,
            'signature_png_path' => $protocol->signature_png_path,
            'broker_notes' => $form['broker_notes'] ?? '',
            'sanierungen' => $sanierungen,
            'documents_available' => $form['documents_available'] ?? [],
            'approvals_status' => $form['approvals_status'] ?? null,
            'approvals_notes' => $form['approvals_notes'] ?? null,
            'open_fields' => $form['open_fields'] ?? [],
            'client_ip' => $protocol->client_ip,
            'user_agent' => $protocol->user_agent,
        ]);

        $path = "intake-protocols/{$property->id}/protocol-{$protocol->id}.pdf";
        \Storage::put($path, $binary);
        return $path;
    }

    private function generateVermittlungsauftrag(
        \App\Models\Property $property,
        array $owner,
        \App\Models\User $broker,
    ): string {
        $service = app(\App\Services\VermittlungsauftragPdfService::class);
        $binary = $service->render([
            'property' => $property->toArray(),
            'owner' => $owner,
            'broker' => ['name' => $broker->name, 'email' => $broker->email, 'company' => 'SR-Homes Immobilien GmbH'],
            'commission_percent' => $property->commission_percent ?? 3.0,
        ]);
        $path = "intake-protocols/{$property->id}/vermittlungsauftrag.pdf";
        \Storage::put($path, $binary);
        return $path;
    }

    private function computeMissingDocs(array $documentsAvailable): array
    {
        $labels = [
            'grundbuchauszug' => 'Grundbuchauszug',
            'energieausweis' => 'Energieausweis',
            'plaene' => 'Grundrisse / Pläne',
            'nutzwertgutachten' => 'Nutzwertgutachten',
            'ruecklagenstand' => 'Rücklagenstand',
            'wohnungseigentumsvertrag' => 'Wohnungseigentumsvertrag',
            'hausordnung' => 'Hausordnung',
            'letzte_jahresabrechnung' => 'Letzte Jahresabrechnung',
            'betriebskostenabrechnung' => 'Betriebskostenabrechnung',
            'schaetzwert_gutachten' => 'Schätzwert-Gutachten',
            'baubewilligung' => 'Baubewilligung',
            'mietvertrag' => 'Mietvertrag',
            'hypothekenvertrag' => 'Hypothekenvertrag',
        ];
        $missing = [];
        foreach ($documentsAvailable as $key => $status) {
            if ($status === 'missing' && isset($labels[$key])) $missing[] = $labels[$key];
        }
        return $missing;
    }

    public function getPdf(Request $request)
    {
        $protocolId = (int) $request->query('protocol_id');
        $protocol = \App\Models\IntakeProtocol::find($protocolId);
        if (!$protocol || !$protocol->pdf_path) abort(404);

        $fullPath = storage_path('app/' . $protocol->pdf_path);
        if (!is_file($fullPath)) abort(404);

        return response()->file($fullPath, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="aufnahmeprotokoll-' . $protocol->property_id . '.pdf"',
        ]);
    }

    public function resendEmail(Request $request): JsonResponse
    {
        $data = $request->json()->all();
        $protocolId = (int) ($data['protocol_id'] ?? 0);
        $type = (string) ($data['type'] ?? 'protocol');

        $protocol = \App\Models\IntakeProtocol::find($protocolId);
        if (!$protocol) return response()->json(['error' => 'not found'], 404);

        $property = $protocol->property;
        $owner = $protocol->customer;
        $broker = $protocol->broker;

        if (!$owner || empty($owner->email)) {
            return response()->json(['error' => 'kein Eigentümer mit Email verknüpft'], 422);
        }

        $emailService = app(\App\Services\IntakeProtocolEmailService::class);

        if ($type === 'protocol') {
            $form = is_string($protocol->form_snapshot) ? json_decode($protocol->form_snapshot, true) : [];
            $missingDocs = $this->computeMissingDocs($form['documents_available'] ?? []);
            $emailService->sendProtocol(
                owner: ['name' => $owner->name, 'email' => $owner->email, 'phone' => $owner->phone],
                property: $property->toArray(),
                broker: ['name' => $broker->name, 'email' => $broker->email],
                missingDocs: $missingDocs,
                protocolPdfPath: storage_path('app/' . $protocol->pdf_path),
            );
            $protocol->update(['owner_email_sent_at' => now()]);
            return response()->json(['success' => true, 'type' => 'protocol']);
        }

        return response()->json(['error' => 'invalid type'], 422);
    }
}
