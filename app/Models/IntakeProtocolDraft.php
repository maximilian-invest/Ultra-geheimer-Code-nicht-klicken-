<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntakeProtocolDraft extends Model
{
    protected $fillable = [
        'broker_id', 'draft_key', 'form_data', 'current_step', 'last_saved_at',
    ];

    protected $casts = [
        'last_saved_at' => 'datetime',
        'current_step' => 'integer',
    ];

    public function broker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'broker_id');
    }

    public function getFormDataArrayAttribute(): array
    {
        if (!$this->form_data) return [];
        $decoded = json_decode($this->form_data, true);
        return is_array($decoded) ? $decoded : [];
    }
}
