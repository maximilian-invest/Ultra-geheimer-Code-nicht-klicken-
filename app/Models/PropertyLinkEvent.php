<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyLinkEvent extends Model
{
    use HasFactory;

    public const TYPE_LINK_OPENED = 'link_opened';
    public const TYPE_DOC_VIEWED = 'doc_viewed';
    public const TYPE_DOC_DOWNLOADED = 'doc_downloaded';

    protected $fillable = [
        'session_id', 'property_file_id', 'event_type', 'duration_s',
    ];

    public $timestamps = false;

    protected $dates = ['created_at'];

    public function session(): BelongsTo
    {
        return $this->belongsTo(PropertyLinkSession::class, 'session_id');
    }
}
