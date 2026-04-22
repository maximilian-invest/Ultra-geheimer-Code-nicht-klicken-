<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntakeProtocol extends Model
{
    protected $fillable = [
        'property_id', 'customer_id', 'broker_id',
        'signed_at', 'signed_by_name', 'signature_png_path',
        'disclaimer_text', 'pdf_path',
        'owner_email_sent_at', 'portal_email_sent_at',
        'portal_access_granted', 'broker_notes',
        'open_fields', 'form_snapshot', 'client_ip', 'user_agent',
    ];

    protected $casts = [
        'signed_at' => 'datetime',
        'owner_email_sent_at' => 'datetime',
        'portal_email_sent_at' => 'datetime',
        'portal_access_granted' => 'boolean',
        'open_fields' => 'array',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function broker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'broker_id');
    }

    public function getFormSnapshotArrayAttribute(): array
    {
        if (!$this->form_snapshot) return [];
        $decoded = json_decode($this->form_snapshot, true);
        return is_array($decoded) ? $decoded : [];
    }
}
