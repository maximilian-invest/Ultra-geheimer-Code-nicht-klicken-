<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortalMessage extends Model
{
    protected $fillable = [
        'property_id', 'author_name', 'author_role', 'message', 'is_pinned',
    ];

    protected function casts(): array
    {
        return ['is_pinned' => 'boolean'];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
