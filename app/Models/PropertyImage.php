<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyImage extends Model
{
    protected $fillable = [
        'property_id', 'filename', 'original_name', 'path', 'mime_type',
        'file_size', 'width', 'height', 'category', 'title', 'description',
        'is_title_image', 'is_floorplan', 'is_public', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_title_image' => 'boolean',
            'is_floorplan' => 'boolean',
            'is_public' => 'boolean',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function getUrlAttribute(): string
    {
        return asset('storage/property-images/' . $this->path);
    }
}
