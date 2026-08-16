<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuthenticationAuditLog extends Model
{
    protected $fillable = [
        'actor_user_id',
        'subject_user_id',
        'event',
        'ip_address',
        'user_agent',
        'metadata',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
