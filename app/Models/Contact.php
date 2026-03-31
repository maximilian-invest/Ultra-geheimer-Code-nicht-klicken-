<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    protected $fillable = [
        'full_name', 'email', 'phone', 'aliases', 'property_ids', 'source', 'notes', 'lead_data', 'role',
    ];

    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'property_ids' => 'array',
            'lead_data' => 'array',
        ];
    }
}
