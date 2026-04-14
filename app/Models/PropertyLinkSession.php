<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PropertyLinkSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'property_link_id', 'email', 'dsgvo_accepted_at',
        'ip_hash', 'user_agent_hash', 'first_seen_at', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'dsgvo_accepted_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public $timestamps = false; // we manage first_seen_at / last_seen_at manually

    public function link(): BelongsTo
    {
        return $this->belongsTo(PropertyLink::class, 'property_link_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PropertyLinkEvent::class, 'session_id');
    }
}
