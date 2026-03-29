<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Viewing extends Model
{
    protected $fillable = [
        'property_id', 'viewing_date', 'viewing_time', 'person_name', 'person_email',
        'person_phone', 'status', 'notes', 'calendar_event_id',
    ];

    protected function casts(): array
    {
        return ['viewing_date' => 'date'];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
