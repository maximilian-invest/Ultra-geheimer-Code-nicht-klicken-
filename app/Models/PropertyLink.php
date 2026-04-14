<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PropertyLink extends Model
{
    use HasFactory;

    protected $fillable = [
        'property_id', 'name', 'token', 'is_default',
        'expires_at', 'revoked_at', 'revoked_by', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(PropertyLinkSession::class);
    }

    /**
     * Document IDs associated with this link (pivot is read-only by design).
     *
     * @return \Illuminate\Support\Collection<int,int>
     */
    public function documentIds(): \Illuminate\Support\Collection
    {
        return \DB::table('property_link_documents')
            ->where('property_link_id', $this->id)
            ->orderBy('sort_order')
            ->pluck('property_file_id');
    }

    public function isAccessible(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }
        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }
        return true;
    }
}
