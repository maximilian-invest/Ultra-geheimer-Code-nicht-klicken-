<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortalDocument extends Model
{
    protected $fillable = [
        'property_id', 'filename', 'original_name', 'file_size', 'mime_type',
        'uploaded_by', 'description',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
