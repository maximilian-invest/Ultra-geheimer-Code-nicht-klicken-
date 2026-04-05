<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyMatch extends Model
{
    protected $fillable = [
        'conversation_id',
        'property_id',
        'score',
        'match_reason',
        'criteria_json',
        'cross_match_intent',
        'status',
    ];

    protected $casts = [
        'criteria_json' => 'array',
        'score' => 'integer',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
