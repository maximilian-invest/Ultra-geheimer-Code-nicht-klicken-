<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyExposeVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'property_id', 'created_by', 'name', 'config_json', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'config_json' => 'array',
            'is_active'   => 'boolean',
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
}
