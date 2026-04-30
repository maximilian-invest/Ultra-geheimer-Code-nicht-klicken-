<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Conversation extends Model
{
    protected $fillable = [
        "contact_email", "stakeholder", "property_id",
        "status",
        "first_contact_at", "last_inbound_at", "last_outbound_at",
        "last_activity_at", "auto_replied_at",
        "source_platform", "category",
        "inbound_count", "outbound_count", "followup_count",
        "draft_body", "draft_subject", "draft_to", "draft_generated_at",
        "last_email_id", "last_activity_id",
        "is_read",
        "match_count", "match_dismissed",
    ];

    protected $casts = [
        "first_contact_at" => "datetime",
        "last_inbound_at" => "datetime",
        "last_outbound_at" => "datetime",
        "last_activity_at" => "datetime",
        "auto_replied_at" => "datetime",
        "draft_generated_at" => "datetime",
        "is_read" => "boolean",
        "match_dismissed" => "boolean",
        "property_id" => "integer",
        "inbound_count" => "integer",
        "outbound_count" => "integer",
        "followup_count" => "integer",
    ];

    public function matches(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PropertyMatch::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function lastEmail(): BelongsTo
    {
        return $this->belongsTo(PortalEmail::class, "last_email_id");
    }

    public function daysWaiting(): int
    {
        if (!$this->last_outbound_at) return 0;
        if ($this->last_inbound_at && $this->last_inbound_at > $this->last_outbound_at) return 0;
        return (int) now()->diffInDays($this->last_outbound_at);
    }

    public function scopeOffen($query)
    {
        return $query->where("status", "offen");
    }

    public function scopeNachfassen($query)
    {
        // Nachfass-Regel: Nur klassisches Erstanfrage-Pattern.
        //   inbound_count = 1 → Lead hat 1x angefragt und seitdem nicht mehr.
        //   Sobald > 1 → echte Korrespondenz, kein Auto-Nachfass-Kandidat.
        //   inbound_count = 0 → Person hat NIE per Mail geantwortet (Cold-
        //   Outreach, manueller Eintrag) — kein Erstanfrage-Pattern, raus.
        // Plus: Hausverwaltungen (Domain in property_managers) sind nie
        // Erstanfragen.
        // Plus: category 'absage' / 'archiviert' / 'info-cc' werden ausge-
        // schlossen — eine bereits-abgesagte Conv darf nie nachgefasst werden.
        return $query
            ->whereIn("status", ["beantwortet", "nachfassen_1", "nachfassen_2", "nachfassen_3"])
            ->where("inbound_count", "=", 1)
            ->where(function ($q) {
                $q->whereNull("category")
                  ->orWhereNotIn("category", ["absage", "archiviert", "info-cc"]);
            })
            ->excludePropertyManagers();
    }

    /**
     * Filtert Conversations raus, deren contact_email auf die Domain einer
     * eingetragenen Hausverwaltung (property_managers.email) zeigt. Egal ob
     * dort ein neuer Ansprechpartner schreibt — die Domain ist gleich, also
     * NICHT in den Nachfass-/Erstanfrage-Listen.
     */
    public function scopeExcludePropertyManagers($query)
    {
        $domains = self::propertyManagerDomains();
        if (empty($domains)) return $query;

        $placeholders = implode(',', array_fill(0, count($domains), '?'));
        return $query->whereRaw(
            "LOWER(SUBSTRING_INDEX(contact_email, '@', -1)) NOT IN ({$placeholders})",
            $domains
        );
    }

    /**
     * Liefert die Domains aller eingetragenen Hausverwaltungen (lowercase,
     * dedupliziert). Wird 5 Minuten gecached um pro Listing-Request nicht
     * dieselbe Subquery zu machen.
     */
    public static function propertyManagerDomains(): array
    {
        return Cache::remember('property_manager_domains', 300, function () {
            $emails = DB::table('property_managers')
                ->whereNotNull('email')
                ->where('email', '!=', '')
                ->pluck('email');

            $domains = [];
            foreach ($emails as $email) {
                $at = strrpos((string) $email, '@');
                if ($at === false) continue;
                $domain = strtolower(trim(substr($email, $at + 1)));
                if ($domain !== '') $domains[$domain] = true;
            }
            return array_keys($domains);
        });
    }

    public function scopeErledigt($query)
    {
        return $query->where("status", "erledigt");
    }

    public function scopeWithDraft($query)
    {
        return $query->whereNotNull("draft_body")->where("draft_body", "!=", "");
    }

    public function scopeForBroker($query, ?int $brokerId, string $userType = "makler")
    {
        // Ohne brokerId (z.B. abgelaufene Session) restriktiv sein und
        // LEERES Ergebnis liefern — NIEMALS die volle Liste, das waere
        // ein Datenleck. Bug zuvor: Session expired -> \Auth::id() null ->
        // unscoped query -> User sieht Conversations anderer Makler.
        if (!$brokerId) return $query->whereRaw('1=0');

        // Assistenz/Backoffice: sehen alles (bei gueltiger Session)
        if (in_array($userType, ["assistenz", "backoffice"])) return $query;

        // Admin: see only conversations from own email accounts
        if ($userType === "admin") {
            return $query->whereIn("last_email_id", function ($sub) use ($brokerId) {
                $sub->select("id")->from("portal_emails")
                    ->whereIn("account_id", function ($sub2) use ($brokerId) {
                        $sub2->select("id")->from("email_accounts")->where("user_id", $brokerId);
                    });
            });
        }

        // Makler: only own properties + unassigned from own accounts
        return $query->where(function ($q) use ($brokerId) {
            $q->whereIn("property_id", function ($sub) use ($brokerId) {
                $sub->select("id")->from("properties")->where("broker_id", $brokerId);
            })->orWhere(function ($q2) use ($brokerId) {
                $q2->whereNull("property_id")
                   ->whereIn("last_email_id", function ($sub) use ($brokerId) {
                       $sub->select("id")->from("portal_emails")
                           ->whereIn("account_id", function ($sub2) use ($brokerId) {
                               $sub2->select("id")->from("email_accounts")->where("user_id", $brokerId);
                           });
                   });
            });
        });
    }
}
