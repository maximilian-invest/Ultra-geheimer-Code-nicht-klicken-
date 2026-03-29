<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyKnowledge extends Model
{
    protected $table = 'property_knowledge';

    protected $fillable = [
        'property_id', 'category', 'title', 'content', 'source_type', 'source_id',
        'source_description', 'confidence', 'is_verified', 'is_active', 'expires_at',
        'created_by', 'mention_count', 'supersedes_id',
    ];

    protected function casts(): array
    {
        return [
            'is_verified' => 'boolean',
            'is_active' => 'boolean',
            'expires_at' => 'date',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
