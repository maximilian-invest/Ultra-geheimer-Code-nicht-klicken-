<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Activity extends Model
{
    protected $fillable = [
        'property_id', 'activity_date', 'stakeholder', 'activity', 'result',
        'duration', 'category', 'source_email_id', 'followup_stage',
    ];

    protected function casts(): array
    {
        return ['activity_date' => 'date'];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
