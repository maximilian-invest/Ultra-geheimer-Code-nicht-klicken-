<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repariert Properties/Users, die vor dem IntakeProtocolController-Bugfix
 * (2026-04-23) ueber das Aufnahmeprotokoll angelegt wurden.
 *
 * Zwei Bugs wurden gefixt:
 *   1) buildProperty() schrieb nur customer_id, aber nicht die denormalisierten
 *      owner_name/owner_email/owner_phone-Felder. Folge: OverviewTab.vue zeigte
 *      einen Eigentuemer-Block samt "Loesen"-Button, aber ohne Namen/Email.
 *   2) ensurePortalUser() legte User mit user_type='customer' an, obwohl
 *      checkPortalAccess() nur user_type='eigentuemer' sucht. Folge:
 *      Portal-Zugang wurde korrekt in users erzeugt, aber auf der Detail-
 *      Seite als nicht existent angezeigt.
 *
 * Diese Migration ist rein kosmetisch: sie setzt korrekte Werte auf
 * Basis der bereits vorhandenen customers-Eintraege, verliert keine Daten
 * und ist idempotent.
 */
return new class extends Migration {
    public function up(): void
    {
        // 1) Portal-User: customer -> eigentuemer, aber NUR die, die via
        //    Aufnahmeprotokoll angelegt wurden. Kriterium: user_type='customer'
        //    AND auf customer_id verknuepft AND kommt in der customers-Tabelle vor.
        //    Andere 'customer'-User (falls es welche gibt) tasten wir nicht an.
        $candidateUsers = DB::table('users as u')
            ->join('customers as c', 'c.id', '=', 'u.customer_id')
            ->where('u.user_type', 'customer')
            ->whereRaw('u.email = c.email')
            ->pluck('u.id');

        if ($candidateUsers->isNotEmpty()) {
            DB::table('users')
                ->whereIn('id', $candidateUsers)
                ->update([
                    'user_type' => 'eigentuemer',
                    'email_verified_at' => DB::raw('COALESCE(email_verified_at, NOW())'),
                    'updated_at' => now(),
                ]);
        }

        // 2) Properties mit customer_id aber leeren owner_name-Feldern:
        //    denormalisierte Felder aus customers nachziehen. Nur befuellen,
        //    wenn leer (keine ueberschreibung bestehender Werte).
        $properties = DB::table('properties as p')
            ->join('customers as c', 'c.id', '=', 'p.customer_id')
            ->whereNotNull('p.customer_id')
            ->where(function ($q) {
                $q->whereNull('p.owner_name')->orWhere('p.owner_name', '');
            })
            ->select('p.id', 'c.name as c_name', 'c.email as c_email', 'c.phone as c_phone')
            ->get();

        foreach ($properties as $row) {
            $update = ['updated_at' => now()];
            if (!empty($row->c_name))  $update['owner_name']  = $row->c_name;
            if (!empty($row->c_email)) $update['owner_email'] = $row->c_email;
            if (!empty($row->c_phone)) $update['owner_phone'] = $row->c_phone;
            if (count($update) > 1) {
                DB::table('properties')->where('id', $row->id)->update($update);
            }
        }
    }

    public function down(): void
    {
        // Keine sinnvolle Rueckabwicklung: die gesetzten Werte sind korrekt
        // und sollten nicht entfernt werden, nur weil die Migration rollt.
    }
};
