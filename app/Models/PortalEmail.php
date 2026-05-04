<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortalEmail extends Model
{
    protected $fillable = [
        'message_id', 'thread_id', 'direction', 'from_email', 'from_name',
        'to_email', 'subject', 'body_text', 'body_html', 'has_attachment', 'attachment_names',
        'email_date', 'property_id', 'matched_ref_id', 'property_mismatch_ref_id', 'stakeholder', 'category',
        'ai_summary', 'sentiment', 'key_facts', 'action_required',
        'is_processed', 'imap_uid', 'imap_folder', 'account_id',
        'is_deleted', 'deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'email_date' => 'datetime',
            'has_attachment' => 'boolean',
            'is_processed' => 'boolean',
            'is_deleted' => 'boolean',
            'deleted_at' => 'datetime',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
