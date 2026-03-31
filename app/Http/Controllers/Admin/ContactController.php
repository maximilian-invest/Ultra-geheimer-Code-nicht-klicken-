<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContactController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $search     = $request->query('search', '');
        $propertyId = $request->query('property_id', '');

        $sql    = 'SELECT * FROM contacts';
        $params = [];
        $where  = [];

        // Exclude contacts that are owners (their email exists in customers table)
        $where[] = "NOT EXISTS (SELECT 1 FROM customers cu WHERE cu.email IS NOT NULL AND cu.email != '' AND cu.email NOT LIKE 'placeholder%' AND LOWER(cu.email) = LOWER(contacts.email))";

        if ($search) {
            $where[] = '(full_name LIKE ? OR email LIKE ? OR phone LIKE ? OR aliases LIKE ?)';
            $s = "%{$search}%";
            array_push($params, $s, $s, $s, $s);
        }
        if ($propertyId) {
            $where[]  = 'property_ids LIKE ?';
            $params[] = "%{$propertyId}%";
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY updated_at DESC';

        $contacts = DB::select($sql, $params);

        // Decode JSON fields
        $contacts = array_map(function ($c) {
            $c = (array) $c;
            $c['aliases']      = json_decode($c['aliases'] ?? '[]', true) ?: [];
            $c['property_ids'] = json_decode($c['property_ids'] ?? '[]', true) ?: [];
            return $c;
        }, $contacts);

        return response()->json([
            'contacts' => $contacts,
            'count'    => count($contacts),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function update(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        $id    = $input['id'] ?? null;
        if (!$id) {
            return response()->json(['error' => 'id required'], 400);
        }

        $fields = [];
        $params = [];
        foreach (['full_name', 'email', 'phone', 'notes'] as $f) {
            if (isset($input[$f])) {
                $fields[] = "{$f} = ?";
                $params[] = $input[$f];
            }
        }
        if (isset($input['aliases'])) {
            $fields[] = 'aliases = ?';
            $params[] = json_encode($input['aliases']);
        }
        if (isset($input['role'])) {
            $allowed = ['kunde','partner','bautraeger','intern','makler','eigentuemer'];
            $role = in_array($input['role'], $allowed) ? $input['role'] : 'kunde';
            $fields[] = 'role = ?';
            $params[] = $role;
        }
        if (isset($input['property_ids'])) {
            $fields[] = 'property_ids = ?';
            $params[] = json_encode(array_values(array_unique(array_map('intval', (array)$input['property_ids']))));
        }

        if (empty($fields)) {
            return response()->json(['success' => true, 'message' => 'nothing to update']);
        }

        $params[] = $id;
        DB::update('UPDATE contacts SET ' . implode(', ', $fields) . ' WHERE id = ?', $params);

        return response()->json(['success' => true], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function delete(Request $request): JsonResponse
    {
        $id = $request->json('id');
        if (!$id) {
            return response()->json(['error' => 'id required'], 400);
        }

        DB::delete('DELETE FROM contacts WHERE id = ?', [$id]);

        return response()->json(['success' => true], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function addAlias(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        $id    = $input['id'] ?? null;
        $alias = trim($input['alias'] ?? '');

        if (!$id || !$alias) {
            return response()->json(['error' => 'id and alias required'], 400);
        }

        $row = DB::selectOne('SELECT aliases FROM contacts WHERE id = ?', [$id]);
        if (!$row) {
            return response()->json(['error' => 'contact not found'], 404);
        }

        $aliases = json_decode($row->aliases ?? '[]', true) ?: [];
        if (!in_array($alias, $aliases)) {
            $aliases[] = $alias;
            DB::update('UPDATE contacts SET aliases = ? WHERE id = ?', [json_encode($aliases), $id]);
        }

        return response()->json(['success' => true, 'aliases' => $aliases], 200, [], JSON_UNESCAPED_UNICODE);
    }
    /**
     * List all property owners (customers) with their properties.
     */
    public function listOwners(Request $request): JsonResponse
    {
        $owners = DB::select("
            SELECT c.id, c.name, c.email, c.phone, c.address, c.city, c.zip, c.notes, c.created_at,
                   COUNT(p.id) as property_count
            FROM customers c
            LEFT JOIN properties p ON p.customer_id = c.id
            GROUP BY c.id, c.name, c.email, c.phone, c.address, c.city, c.zip, c.notes, c.created_at
            ORDER BY c.name
        ");

        // Attach properties + portal user to each owner
        foreach ($owners as &$owner) {
            $owner = (array) $owner;
            $owner['properties'] = array_map(fn($p) => (array) $p, DB::select("
                SELECT id, ref_id, address, city, realty_status as status, object_type as type, purchase_price as price
                FROM properties
                WHERE customer_id = ?
                ORDER BY address
            ", [$owner['id']]));

            // Portal user info
            $portalUser = DB::table('users')
                ->where('customer_id', $owner['id'])
                ->whereIn('user_type', ['eigentuemer', ''])
                ->select('id', 'name', 'email', 'created_at')
                ->first();
            if (!$portalUser && !empty($owner['email'])) {
                $portalUser = DB::table('users')
                    ->where('email', $owner['email'])
                    ->whereIn('user_type', ['eigentuemer', ''])
                    ->select('id', 'name', 'email', 'created_at')
                    ->first();
            }
            $owner['portal_user'] = $portalUser ? (array) $portalUser : null;
        }

        return response()->json(['owners' => $owners], 200, [], JSON_UNESCAPED_UNICODE);
    }


    /**
     * contact_timeline - Return all activities + portal_emails for a contact, merged & sorted.
     */
    public function timeline(Request $request): JsonResponse
    {
        $name  = trim($request->query('name', ''));
        $email = trim($request->query('email', ''));

        if (!$name && !$email) {
            return response()->json(['error' => 'name or email required'], 400);
        }

        // Activities matching stakeholder name
        $activities = [];
        if ($name) {
            $nameLike = '%' . $name . '%';
            $rows = DB::select("
                SELECT a.id, a.activity_date as event_date, a.stakeholder, a.activity as title,
                       a.result as detail, a.category, 'activity' as event_type,
                       a.source_email_id, p.ref_id, p.address, p.city
                FROM activities a
                LEFT JOIN properties p ON a.property_id = p.id
                WHERE a.stakeholder LIKE ?
                ORDER BY a.activity_date DESC
                LIMIT 200
            ", [$nameLike]);
            $activities = array_map(fn($r) => (array)$r, $rows);
        }

        // Portal emails matching from_email or stakeholder name
        $emails = [];
        $emailWhere = [];
        $emailParams = [];
        if ($email) {
            $emailWhere[] = '(pe.from_email = ? OR pe.to_email LIKE ?)';
            $emailParams[] = $email;
            $emailParams[] = '%' . $email . '%';
        }
        if ($name) {
            $emailWhere[] = 'pe.stakeholder LIKE ?';
            $emailParams[] = '%' . $name . '%';
        }
        if ($emailWhere) {
            $rows2 = DB::select("
                SELECT pe.id, pe.email_date as event_date, pe.stakeholder, pe.subject as title,
                       pe.ai_summary as detail, pe.category, 'email' as event_type,
                       pe.direction, pe.from_email, pe.to_email,
                       p.ref_id, p.address, p.city
                FROM portal_emails pe
                LEFT JOIN properties p ON pe.property_id = p.id
                WHERE " . implode(' OR ', $emailWhere) . "
                AND (pe.is_deleted IS NULL OR pe.is_deleted = 0)
                ORDER BY pe.email_date DESC
                LIMIT 200
            ", $emailParams);
            $emails = array_map(fn($r) => (array)$r, $rows2);
        }

        // Merge and sort
        $all = array_merge($activities, $emails);
        usort($all, fn($a, $b) => strcmp($b['event_date'] ?? '', $a['event_date'] ?? ''));

        return response()->json(['timeline' => $all, 'count' => count($all)], 200, [], JSON_UNESCAPED_UNICODE);
    }
    /**
     * E-Mail-Adresse eines Empfängers direkt aus Unbeantwortet/Nachfassen korrigieren.
     */
    public function updateRecipientEmail(Request $request): JsonResponse
    {
        $stakeholder = trim($request->input('stakeholder', ''));
        $propertyId  = intval($request->input('property_id', 0));
        $newEmail    = strtolower(trim($request->input('new_email', '')));

        if (!$stakeholder || !$newEmail || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['error' => 'Ungültige Parameter'], 400);
        }

        // 1. contacts-Tabelle aktualisieren
        $contact = DB::selectOne(
            "SELECT id, email FROM contacts WHERE full_name COLLATE utf8mb4_unicode_ci = ? LIMIT 1",
            [$stakeholder]
        );
        if ($contact) {
            $oldEmail = $contact->email;
            DB::update("UPDATE contacts SET email = ?, updated_at = NOW() WHERE id = ?", [$newEmail, $contact->id]);
            // Alte Adresse als Alias merken
            $row = DB::selectOne("SELECT aliases FROM contacts WHERE id = ?", [$contact->id]);
            $aliases = json_decode($row->aliases ?? '[]', true) ?: [];
            if ($oldEmail && !in_array($oldEmail, $aliases)) {
                $aliases[] = $oldEmail;
                DB::update("UPDATE contacts SET aliases = ? WHERE id = ?", [json_encode($aliases, JSON_UNESCAPED_UNICODE), $contact->id]);
            }
        } else {
            DB::insert(
                "INSERT INTO contacts (full_name, email, source, created_at, updated_at) VALUES (?, ?, 'manual', NOW(), NOW())",
                [$stakeholder, $newEmail]
            );
        }

        // 2. portal_emails: Adressen für diese Person anpassen
        if ($propertyId) {
            DB::update(
                "UPDATE portal_emails SET from_email = ?
                 WHERE stakeholder = ? AND direction = 'inbound' AND property_id = ?
                   AND from_email NOT LIKE '%sr-homes%' AND from_email NOT LIKE '%hoelzl%'",
                [$newEmail, $stakeholder, $propertyId]
            );
            DB::update(
                "UPDATE portal_emails SET to_email = ?
                 WHERE stakeholder = ? AND direction = 'outbound' AND property_id = ?",
                [$newEmail, $stakeholder, $propertyId]
            );
        }

        // 3. Update-Activity loggen
        if ($propertyId) {
            DB::insert(
                "INSERT INTO activities (property_id, activity_date, stakeholder, activity, result, category, created_at, updated_at)
                 VALUES (?, CURDATE(), ?, 'E-Mail-Adresse aktualisiert', ?, 'update', NOW(), NOW())",
                [$propertyId, $stakeholder, "E-Mail korrigiert auf: {$newEmail}"]
            );
        }

        \Log::info("[Contact] Email updated: {$stakeholder} -> {$newEmail} (property {$propertyId})");

        return response()->json(['success' => true, 'new_email' => $newEmail]);
    }

    /**
     * Liest Lead-Profil-Daten fuer einen Kontakt.
     * GET ?contact_id=X  oder  ?stakeholder=NAME&property_id=Y
     */
    public function getLeadData(Request $request = null): JsonResponse
    {
        if ($request === null) {
            $request = app(Request::class);
        }

        $defaultData = [
            'budget_min'    => null,
            'budget_max'    => null,
            'property_type' => null,
            'location_pref' => null,
            'rooms_min'     => null,
            'size_min_m2'   => null,
            'financing'     => null,
            'timeline'      => null,
            'priority'      => 'warm',
            'notes_lead'    => null,
        ];

        $contactId   = $request->query('contact_id');
        $stakeholder = trim($request->query('stakeholder', ''));
        $propertyId  = $request->query('property_id');

        $contact = null;

        if ($contactId) {
            $contact = DB::selectOne('SELECT * FROM contacts WHERE id = ?', [$contactId]);
        } elseif ($stakeholder) {
            $contact = DB::selectOne(
                "SELECT * FROM contacts
                 WHERE email = ?
                    OR full_name COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci
                 LIMIT 1",
                [$stakeholder, $stakeholder]
            );
        }

        if (!$contact) {
            return response()->json([
                'found'        => false,
                'lead_data'    => $defaultData,
                'full_name'    => $stakeholder ?: null,
                'email'        => null,
                'phone'        => null,
                'property_ids' => [],
            ], 200, [], JSON_UNESCAPED_UNICODE);
        }

        $leadData    = json_decode($contact->lead_data ?? 'null', true);
        $leadData    = is_array($leadData) ? array_merge($defaultData, $leadData) : $defaultData;
        $propertyIds = json_decode($contact->property_ids ?? '[]', true) ?: [];

        // Auto-fill lead profile from known data if empty
        $hasAnyData = false;
        foreach ($defaultData as $k => $v) {
            if ($k === 'priority') continue;
            if (!empty($leadData[$k])) { $hasAnyData = true; break; }
        }

        if (!$hasAnyData) {
            $leadData = $this->autoFillLeadProfile($contact, $leadData, $propertyIds);
        }

        return response()->json([
            'found'        => true,
            'contact_id'   => $contact->id,
            'lead_data'    => $leadData,
            'full_name'    => $contact->full_name,
            'email'        => $contact->email,
            'phone'        => $contact->phone,
            'property_ids' => $propertyIds,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Auto-fill lead profile from activities, emails, property data and units.
     */
    private function autoFillLeadProfile($contact, array $leadData, array $propertyIds): array
    {
        // 1. Get all property IDs this contact interacted with
        $contactPropertyIds = !empty($propertyIds) ? $propertyIds : [];

        // From activities
        $activityPropIds = DB::select(
            "SELECT DISTINCT property_id FROM activities
             WHERE stakeholder COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci
               AND property_id IS NOT NULL",
            [$contact->full_name]
        );
        foreach ($activityPropIds as $row) {
            if (!in_array($row->property_id, $contactPropertyIds)) {
                $contactPropertyIds[] = $row->property_id;
            }
        }

        // From emails
        $emailPropIds = DB::select(
            "SELECT DISTINCT property_id FROM portal_emails
             WHERE (stakeholder COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci
                    OR from_email = ?)
               AND direction = 'inbound' AND property_id IS NOT NULL",
            [$contact->full_name, $contact->email ?? '']
        );
        foreach ($emailPropIds as $row) {
            if (!in_array($row->property_id, $contactPropertyIds)) {
                $contactPropertyIds[] = $row->property_id;
            }
        }

        if (empty($contactPropertyIds)) return $leadData;

        // 2. Load full property data
        $properties = DB::select(
            "SELECT id, ref_id, address, city, purchase_price as price, object_type as type, rooms_amount, total_area, living_area
             FROM properties WHERE id IN (" . implode(',', array_map('intval', $contactPropertyIds)) . ")"
        );

        // 3. Load property units for these properties (to get accurate room/size/price data)
        $units = DB::select(
            "SELECT pu.property_id, pu.unit_type, pu.price, pu.area_m2, pu.rooms_amount, pu.status
             FROM property_units pu
             WHERE pu.property_id IN (" . implode(',', array_map('intval', $contactPropertyIds)) . ")
               AND pu.is_parking = 0 AND pu.status != 'verkauft'"
        );

        // 4. Try to match specific unit from email subject
        $emails = DB::select(
            "SELECT subject, body_text, from_email FROM portal_emails
             WHERE (stakeholder COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci
                    OR from_email = ?)
               AND direction = 'inbound'
             ORDER BY email_date DESC LIMIT 5",
            [$contact->full_name, $contact->email ?? '']
        );

        // Extract room count from subjects/body
        $requestedRooms = null;
        $requestedType = null;
        foreach ($emails as $e) {
            $text = ($e->subject ?? '') . ' ' . mb_substr($e->body_text ?? '', 0, 500);
            // Match "2-Zimmer", "3 Zimmer", "4-Zi"
            if (preg_match('/(\d)\s*[-\s]?(?:Zimmer|Zi\.?|Raum)/i', $text, $m)) {
                $requestedRooms = (int)$m[1];
            }
            // Match unit types
            if (preg_match('/(Penthouse|Dachgeschoss|Erdgeschoss|Gartenwohnung|Maisonette)/i', $text, $m)) {
                $requestedType = $m[1];
            }
            if ($requestedRooms) break;
        }

        // 5. Determine source platform
        $source = $contact->source ?? null;
        if (!$source || $source === 'auto') {
            foreach ($emails as $e) {
                $from = strtolower($e->from_email ?? '');
                $subj = strtolower($e->subject ?? '');
                if (str_contains($from, 'willhaben') || str_contains($subj, 'willhaben')) { $source = 'willhaben'; break; }
                if (str_contains($from, 'immowelt') || str_contains($subj, 'immowelt')) { $source = 'immowelt'; break; }
                if (str_contains($from, 'typeform')) { $source = 'sr-homes.at'; break; }
                if (str_contains($from, 'immoscout') || str_contains($subj, 'immoscout')) { $source = 'ImmoScout24'; break; }
            }
        }

        // 6. Collect data from properties and matching units
        $prices = [];
        $types = [];
        $locations = [];
        $roomCounts = [];
        $sizes = [];
        $propNames = [];

        foreach ($properties as $p) {
            $propLabel = $p->ref_id . ' (' . $p->address . ', ' . $p->city . ')';
            $propNames[] = $propLabel;
            if ($p->city) $locations[] = $p->city;

            // Get matching units for this property
            $propUnits = array_filter($units, fn($u) => $u->property_id == $p->id);

            if (!empty($propUnits)) {
                // If we know requested rooms, filter to matching units
                $matchingUnits = $propUnits;
                if ($requestedRooms) {
                    $roomMatch = array_filter($propUnits, fn($u) => $u->rooms_amount && abs($u->rooms_amount - $requestedRooms) < 0.5);
                    if (!empty($roomMatch)) $matchingUnits = $roomMatch;
                }

                foreach ($matchingUnits as $u) {
                    if ($u->price && $u->price > 0) $prices[] = (float)$u->price;
                    if ($u->area_m2 && $u->area_m2 > 0) $sizes[] = (float)$u->area_m2;
                    if ($u->rooms_amount && $u->rooms_amount > 0) $roomCounts[] = (float)$u->rooms_amount;
                    if ($u->unit_type) $types[] = $u->unit_type;
                }
            } else {
                // No units — use property-level data
                if ($p->price && $p->price > 0) $prices[] = (float)$p->price;
                $sz = $p->living_area ?: $p->total_area;
                if ($sz && $sz > 0) $sizes[] = (float)$sz;
                if ($p->rooms_amount && $p->rooms_amount > 0) $roomCounts[] = (float)$p->rooms_amount;
                if ($p->type) $types[] = $p->type;
            }
        }

        // Use requested rooms if extracted from email
        if ($requestedRooms) $roomCounts[] = (float)$requestedRooms;

        // 7. Fill the profile
        // Budget
        if (empty($leadData['budget_min']) && !empty($prices)) {
            $minP = min($prices);
            $maxP = max($prices);
            $leadData['budget_min'] = (int)floor($minP * 0.9 / 5000) * 5000;
            $leadData['budget_max'] = (int)ceil($maxP * 1.1 / 5000) * 5000;
        }

        // Property type
        if (empty($leadData['property_type']) && !empty($types)) {
            $typeMap = [
                'wohnung' => 'Wohnung', 'eigentumswohnung' => 'Wohnung', 'apartment' => 'Wohnung',
                '1-zimmer' => 'Wohnung', '2-zimmer' => 'Wohnung', '3-zimmer' => 'Wohnung', '4-zimmer' => 'Wohnung',
                '4-zimmer penthouse' => 'Wohnung', 'penthouse' => 'Wohnung', 'maisonette' => 'Wohnung',
                'gartenwohnung' => 'Wohnung', 'dachgeschoss' => 'Wohnung',
                'haus' => 'Haus', 'einfamilienhaus' => 'Haus', 'reihenhaus' => 'Haus', 'doppelhaus' => 'Haus',
                'grundstueck' => 'Grundstueck', 'grundstück' => 'Grundstueck', 'baugrund' => 'Grundstueck',
                'gewerbe' => 'Gewerbe', 'neubauprojekt' => 'Wohnung', 'neubau' => 'Wohnung',
                'neubau - 2 reihenhäuser, 6 eigentumswohnungen' => 'Wohnung',
            ];
            // Find most common type
            $mapped = [];
            foreach ($types as $t) {
                $key = strtolower(trim($t));
                $mapped[] = $typeMap[$key] ?? ucfirst($t);
            }
            $counts = array_count_values($mapped);
            arsort($counts);
            $leadData['property_type'] = array_key_first($counts);
        }

        // Location
        if (empty($leadData['location_pref']) && !empty($locations)) {
            $leadData['location_pref'] = implode(', ', array_unique($locations));
        }

        // Rooms
        if (empty($leadData['rooms_min']) && !empty($roomCounts)) {
            $leadData['rooms_min'] = (int)min($roomCounts);
        }

        // Size
        if (empty($leadData['size_min_m2']) && !empty($sizes)) {
            $leadData['size_min_m2'] = (int)floor(min($sizes) * 0.9);
        }

        // Notes with source, platform, properties
        if (empty($leadData['notes_lead'])) {
            $parts = [];
            if ($source && $source !== 'auto') $parts[] = 'Quelle: ' . $source;
            if (!empty($propNames)) $parts[] = 'Objekte: ' . implode(', ', array_unique($propNames));
            if ($requestedRooms) $parts[] = 'Gesucht: ' . $requestedRooms . '-Zimmer';
            if ($requestedType) $parts[] = 'Typ: ' . $requestedType;
            if (!empty($parts)) $leadData['notes_lead'] = implode("\n", $parts);
        }

        // Priority: if they asked about specific unit type -> warm, if Kaufanbot exists -> heiss
        $hasKaufanbot = DB::selectOne(
            "SELECT 1 FROM activities WHERE stakeholder COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci AND category = 'kaufanbot' LIMIT 1",
            [$contact->full_name]
        );
        if ($hasKaufanbot) {
            $leadData['priority'] = 'heiss';
        }

        return $leadData;
    }

    /**
     * Speichert Lead-Profil-Daten fuer einen Kontakt (partial update / merge).
     * POST mit contact_id ODER stakeholder + optionalem property_id
     */
    public function updateLeadData(Request $request = null): JsonResponse
    {
        if ($request === null) {
            $request = app(Request::class);
        }

        $input       = $request->json()->all();
        $contactId   = $input['contact_id'] ?? null;
        $stakeholder = trim($input['stakeholder'] ?? '');
        $propertyId  = $input['property_id'] ?? null;

        $contact = null;
        if ($contactId) {
            $contact = DB::selectOne('SELECT * FROM contacts WHERE id = ?', [$contactId]);
        } elseif ($stakeholder) {
            $contact = DB::selectOne(
                "SELECT * FROM contacts
                 WHERE email = ?
                    OR full_name COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci
                 LIMIT 1",
                [$stakeholder, $stakeholder]
            );
        }

        if (!$contact) {
            if (!$stakeholder) {
                return response()->json(['error' => 'contact_id oder stakeholder erforderlich'], 400);
            }
            DB::insert(
                "INSERT INTO contacts (full_name, source, created_at, updated_at) VALUES (?, 'lead', NOW(), NOW())",
                [$stakeholder]
            );
            $newId   = DB::getPdo()->lastInsertId();
            $contact = DB::selectOne('SELECT * FROM contacts WHERE id = ?', [$newId]);
        }

        $existingData = json_decode($contact->lead_data ?? 'null', true);
        if (!is_array($existingData)) {
            $existingData = [];
        }

        $allowedFields = [
            'budget_min', 'budget_max', 'property_type', 'location_pref',
            'rooms_min', 'size_min_m2', 'financing', 'timeline', 'priority', 'notes_lead',
        ];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $input)) {
                $existingData[$field] = $input[$field];
            }
        }

        DB::update(
            'UPDATE contacts SET lead_data = ?, updated_at = NOW() WHERE id = ?',
            [json_encode($existingData, JSON_UNESCAPED_UNICODE), $contact->id]
        );

        \Log::info("[Contact] lead_data updated for contact #{$contact->id} ({$contact->full_name})");

        return response()->json([
            'success'    => true,
            'contact_id' => $contact->id,
            'lead_data'  => $existingData,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

}