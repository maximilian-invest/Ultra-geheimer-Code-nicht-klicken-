<?php

namespace App\Services;

use App\Models\PropertyLink;
use Illuminate\Support\Facades\DB;
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

    public function markAsDefault(PropertyLink $link): void
    {
        DB::transaction(function () use ($link) {
            PropertyLink::where('property_id', $link->property_id)
                ->where('id', '!=', $link->id)
                ->update(['is_default' => false]);

            $link->is_default = true;
            $link->save();
        });
    }

    public function isAccessible(PropertyLink $link): bool
    {
        return $link->isAccessible();
    }
}
