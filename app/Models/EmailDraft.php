<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailDraft extends Model
{
    protected $fillable = [
        'to_email', 'subject', 'body', 'property_id', 'stakeholder',
        'account_id', 'tone', 'source_email_id', 'imap_uid', 'imap_folder',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
