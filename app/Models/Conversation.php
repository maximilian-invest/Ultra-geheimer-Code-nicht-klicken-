<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        return $query->whereIn("status", ["beantwortet", "nachfassen_1", "nachfassen_2", "nachfassen_3"]);
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
        if (!$brokerId || in_array($userType, ["assistenz", "backoffice"])) return $query;
        return $query->where(function ($q) use ($brokerId) {
            $q->whereIn("property_id", function ($sub) use ($brokerId) {
                $sub->select("id")->from("properties")->where("broker_id", $brokerId);
            })->orWhere(function ($q2) use ($brokerId) {
                // Unassigned: only show if received by this broker's email account
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
