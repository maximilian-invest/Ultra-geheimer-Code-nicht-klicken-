<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyPortal extends Model
{
    protected $fillable = [
        'property_id', 'portal_name', 'external_id', 'external_url',
        'status', 'last_synced_at', 'last_sync_error', 'sync_enabled',
        'portal_config', 'published_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'sync_enabled' => 'boolean',
            'portal_config' => 'array',
            'last_synced_at' => 'datetime',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
