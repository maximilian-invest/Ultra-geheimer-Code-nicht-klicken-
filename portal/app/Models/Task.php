<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Task extends Model
{
    protected $fillable = [
        'property_id', 'stakeholder', 'title', 'description', 'priority',
        'is_done', 'due_date', 'source', 'source_email_id', 'assigned_to',
    ];

    protected function casts(): array
    {
        return [
            'is_done' => 'boolean',
            'due_date' => 'datetime',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
