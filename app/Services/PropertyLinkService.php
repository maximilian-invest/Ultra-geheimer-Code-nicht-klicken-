<?php

namespace App\Services;

use App\Models\PropertyLink;
use Illuminate\Support\Str;

class PropertyLinkService
{
    public function generateUniqueToken(): string
    {
        do {
            $token = Str::random(43);
        } while (PropertyLink::where('token', $token)->exists());

        return $token;
    }
}
