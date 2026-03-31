<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailAccount extends Model
{
    protected $fillable = [
        'label', 'email_address', 'from_name', 'imap_host', 'imap_port', 'imap_encryption',
        'imap_username', 'imap_password', 'smtp_host', 'smtp_port', 'smtp_encryption',
        'smtp_username', 'smtp_password', 'is_active', 'user_id', 'last_fetch_at', 'last_uid', 'last_uid_sent',
    ];

    protected $hidden = ['imap_password', 'smtp_password'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_fetch_at' => 'datetime',
            'imap_password' => 'encrypted',
            'smtp_password' => 'encrypted',
        ];
    }
}
