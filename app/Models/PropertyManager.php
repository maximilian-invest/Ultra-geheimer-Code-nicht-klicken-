<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PropertyManager extends Model
{
    protected $fillable = [
        'company_name',
        'address_street',
        'address_zip',
        'address_city',
        'email',
        'phone',
        'contact_person',
        'notes',
        'created_by',
    ];

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
