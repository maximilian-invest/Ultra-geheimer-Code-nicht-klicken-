<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyBriefing extends Model
{
    protected $fillable = [
        'user_id', 'briefing_date', 'data', 'model_used', 'generated_at',
    ];

    protected $casts = [
        'briefing_date' => 'date',
        'generated_at' => 'datetime',
        'data' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
