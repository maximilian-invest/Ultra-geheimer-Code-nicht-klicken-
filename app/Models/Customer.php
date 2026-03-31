<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $fillable = [
        'name', 'email', 'password_hash', 'phone', 'address', 'city', 'zip', 'notes', 'active',
    ];

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }

    public function user()
    {
        return $this->hasOne(User::class);
    }
}
