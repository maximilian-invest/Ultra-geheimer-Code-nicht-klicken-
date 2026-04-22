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

                // 3b) Photos: persist each as property_files row with base64-decoded binary
                $this->storeSubmittedPhotos($property->id, (array) ($form['photos'] ?? []));

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
                // NUR Portal-Zugang wird sofort versendet (braucht der Eigentuemer sonst nicht).
                // Die Protokoll-Mail an den Eigentuemer schickt der Makler spaeter bewusst
                // aus der Property-Detail-Seite (MailComposer) — damit er sie in Ruhe
                // von zuhause aus bearbeiten kann, nicht mehr vor Ort.
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

                // Vermittlungsauftrag-PDF vorhalten falls spaeter noetig (bei Missing-Docs)
                $missingDocs = $this->computeMissingDocs($form['documents_available'] ?? []);
                if (count($missingDocs) > 0) {
                    $this->generateVermittlungsauftrag($property, $ownerData, $broker);
                }
                // owner_email_sent_at bleibt bewusst null — Banner auf Property-Detail
                // triggert dann den MailComposer-Dialog.

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

        // Array-Felder, die ohne Cast als String in die DB muessen — sonst
        // kracht der Insert mit "Array to string conversion".
        // common_areas: ist im Model NICHT als 'array' gecastet (aus Kompat-Gruenden
        // mit Legacy-Freitext-Werten), deshalb hier manuell JSON-encoden.
        // documents_available: Property-Model cast'et als 'array' → Laravel erledigt's,
        // aber wir senden vorsorglich einen sauberen Wert.
        foreach (['common_areas', 'flooring', 'bathroom_equipment', 'heating'] as $jsonField) {
            if (isset($props[$jsonField]) && is_array($props[$jsonField])) {
                $props[$jsonField] = json_encode($props[$jsonField], JSON_UNESCAPED_UNICODE);
            }
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

    /**
     * Persist submitted photos into property_files, decoding base64 data URLs
     * and saving the binary to the public disk. The category ('exterior',
     * 'interior', 'floor_plan', 'documents') is encoded into the label so we
     * can keep the existing property_files schema unchanged.
     */
    private function storeSubmittedPhotos(int $propertyId, array $photos): void
    {
        if (empty($photos)) return;

        $labelMap = [
            'exterior'   => 'Außenansicht',
            'interior'   => 'Innenraum',
            'floor_plan' => 'Grundriss',
            'documents'  => 'Dokument',
        ];

        foreach ($photos as $photo) {
            if (!is_array($photo)) continue;
            if (empty($photo['dataUrl'])) continue;
            if (!preg_match('/^data:image\/(\w+);base64,(.+)$/', $photo['dataUrl'], $m)) continue;

            $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
            $binary = base64_decode($m[2]);
            if (!$binary) continue;

            $category = (string) ($photo['category'] ?? 'exterior');
            $label = $labelMap[$category] ?? 'Foto';

            $filename = 'property-' . $propertyId . '-' . \Illuminate\Support\Str::random(8) . '.' . $ext;
            $path = 'properties/' . $propertyId . '/' . $filename;

            \Storage::disk('public')->put($path, $binary);

            \DB::table('property_files')->insert([
                'property_id'         => $propertyId,
                'label'               => $label,
                'filename'            => $photo['filename'] ?? $filename,
                'path'                => $path,
                'mime_type'           => 'image/' . $m[1],
                'file_size'           => strlen($binary),
                'sort_order'          => 0,
                'is_website_download' => 0,
                'created_at'          => now(),
            ]);
        }
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

    public function computeDefaultMailContent(array $form, array $owner, array $broker, array $missingDocs): array
    {
        $refId = $form['ref_id'] ?? 'neu';
        $subject = count($missingDocs) > 0
            ? "Ihr Aufnahmeprotokoll · {$refId} — noch fehlende Unterlagen"
            : "Ihr Aufnahmeprotokoll · {$refId}";

        $ownerName = trim((string) ($owner['name'] ?? '')) ?: 'Damen und Herren';
        $address = trim(($form['address'] ?? '') . ' ' . ($form['house_number'] ?? ''));
        $brokerName = $broker['name'] ?? 'Ihr SR-Homes Team';

        // Plain-Text Version — User kann bearbeiten, wir rendern das spaeter in einen
        // simplen HTML-Wrapper, der Umbrueche respektiert.
        if (count($missingDocs) === 0) {
            $body = "Sehr geehrte/r {$ownerName},\n\n"
                  . "vielen Dank für unseren heutigen Termin zur Aufnahme Ihrer Immobilie {$address}.\n\n"
                  . "Anbei finden Sie das unterschriebene Aufnahmeprotokoll als PDF-Anhang zu Ihrer Unterlage.\n\n"
                  . "Wir melden uns in den nächsten Tagen mit dem Vermittlungsauftrag und den weiteren Schritten.\n\n"
                  . "Herzliche Grüße\n"
                  . "{$brokerName}\n"
                  . "SR-Homes Immobilien";
        } else {
            $missingList = implode("\n", array_map(fn($d) => "· {$d}", $missingDocs));
            $brokerEmail = $broker['email'] ?? 'office@sr-homes.at';
            $body = "Sehr geehrte/r {$ownerName},\n\n"
                  . "vielen Dank für unseren heutigen Termin zur Aufnahme Ihrer Immobilie {$address}.\n\n"
                  . "Anbei finden Sie das unterschriebene Aufnahmeprotokoll als PDF.\n\n"
                  . "Damit wir Ihr Objekt bestmöglich vermarkten können, benötigen wir noch folgende Unterlagen:\n\n"
                  . $missingList . "\n\n"
                  . "Zwei Möglichkeiten:\n\n"
                  . "Variante A — Sie senden uns diese Unterlagen per E-Mail an {$brokerEmail}.\n\n"
                  . "Variante B — Sie unterschreiben den beigefügten Vermittlungsauftrag, dann holen wir die fehlenden Unterlagen direkt bei Ihrer Hausverwaltung ein.\n\n"
                  . "Herzliche Grüße\n"
                  . "{$brokerName}\n"
                  . "SR-Homes Immobilien";
        }

        return [
            'subject' => $subject,
            'body'    => $body,
            'missing_docs' => $missingDocs,
        ];
    }

    /**
     * Liefert Default-Betreff + Default-Body fuer die Eigentuemer-Mail.
     * Akzeptiert:
     *   - `form_data` (Payload direkt aus dem Wizard), ODER
     *   - `protocol_id` (liefert Daten aus persistiertem Protokoll) —
     *     wird vom MailComposer auf der Property-Detail-Seite genutzt.
     */
    public function previewMail(Request $request): JsonResponse
    {
        $payload = $request->method() === 'GET' ? $request->query() : $request->json()->all();

        // Variante A: protocol_id → vorhandenes Protokoll
        $protocolId = isset($payload['protocol_id']) ? (int) $payload['protocol_id'] : 0;
        if ($protocolId > 0) {
            $protocol = \App\Models\IntakeProtocol::with(['customer', 'broker', 'property'])->find($protocolId);
            if (!$protocol) return response()->json(['error' => 'not found'], 404);

            $form = is_string($protocol->form_snapshot) ? json_decode($protocol->form_snapshot, true) : [];
            if (!is_array($form)) $form = [];
            $customer = $protocol->customer;
            $ownerData = $customer ? [
                'name'  => $customer->name ?? '',
                'email' => $customer->email ?? '',
                'phone' => $customer->phone ?? '',
            ] : (is_array($form['owner'] ?? null) ? $form['owner'] : []);
            $broker = $protocol->broker;
            $missingDocs = $this->computeMissingDocs($form['documents_available'] ?? []);
            $content = $this->computeDefaultMailContent(
                $form,
                $ownerData,
                ['name' => $broker?->name ?? '', 'email' => $broker?->email ?? ''],
                $missingDocs,
            );
            return response()->json([
                'success' => true,
                'subject' => $content['subject'],
                'body'    => $content['body'],
                'missing_docs' => $missingDocs,
                'owner_email' => $ownerData['email'] ?? null,
                'already_sent_at' => $protocol->owner_email_sent_at?->toIso8601String(),
            ]);
        }

        // Variante B: form_data — Wizard-Preview (historisch, bleibt erhalten)
        $form = is_array($payload['form_data'] ?? null) ? $payload['form_data'] : [];
        $ownerData = is_array($form['owner'] ?? null) ? $form['owner'] : [];
        $brokerId = (int) \Auth::id();
        $broker = \App\Models\User::find($brokerId);

        $missingDocs = $this->computeMissingDocs($form['documents_available'] ?? []);
        $content = $this->computeDefaultMailContent(
            $form,
            $ownerData,
            ['name' => $broker?->name ?? '', 'email' => $broker?->email ?? ''],
            $missingDocs,
        );

        return response()->json([
            'success' => true,
            'subject' => $content['subject'],
            'body'    => $content['body'],
            'missing_docs' => $missingDocs,
            'owner_email' => $ownerData['email'] ?? null,
        ]);
    }

    /**
     * Versendet (oder re-versendet) die Protokoll-Mail an den Eigentuemer.
     * Vom MailComposer auf der Property-Detail-Seite aufgerufen.
     * Akzeptiert optional custom_subject + custom_body — sonst Default-Template.
     */
    public function resendEmail(Request $request): JsonResponse
    {
        $data = $request->json()->all();
        $protocolId = (int) ($data['protocol_id'] ?? 0);
        $type = (string) ($data['type'] ?? 'protocol');
        $customSubject = isset($data['subject']) ? trim((string) $data['subject']) : null;
        $customBody    = isset($data['body'])    ? trim((string) $data['body'])    : null;

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

            // Vermittlungsauftrag-PDF anhaengen falls Missing-Docs vorhanden
            $vermittlungsPath = null;
            if (count($missingDocs) > 0) {
                $vermittlungsRelative = "intake-protocols/{$property->id}/vermittlungsauftrag.pdf";
                $vermittlungsFull = storage_path('app/' . $vermittlungsRelative);
                if (is_file($vermittlungsFull)) {
                    $vermittlungsPath = $vermittlungsFull;
                }
            }

            $emailService->sendProtocol(
                owner: ['name' => $owner->name, 'email' => $owner->email, 'phone' => $owner->phone],
                property: $property->toArray(),
                broker: ['name' => $broker->name, 'email' => $broker->email],
                missingDocs: $missingDocs,
                protocolPdfPath: storage_path('app/' . $protocol->pdf_path),
                vermittlungsauftragPdfPath: $vermittlungsPath,
                customSubject: $customSubject,
                customBody: $customBody,
            );
            $protocol->update(['owner_email_sent_at' => now()]);
            return response()->json([
                'success' => true,
                'type' => 'protocol',
                'sent_at' => now()->toIso8601String(),
            ]);
        }

        return response()->json(['error' => 'invalid type'], 422);
    }
}
