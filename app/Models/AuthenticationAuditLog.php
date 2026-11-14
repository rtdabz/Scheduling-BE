<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Support\ActorIdentity;
use Illuminate\Database\Eloquent\Model;

class AuthenticationAuditLog extends Model
{
    use AppendOnly;

    protected static function booted(): void
    {
        static::creating(fn (self $log) => ActorIdentity::capture($log, 'actor_user_id'));
    }

    public function getRecordedActorAttribute(): ?array
    {
        return ActorIdentity::authentication($this);
    }

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

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function subject()
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    /** Approximate IP location for login events (pruned separately; this row is never changed). */
    public function geolocation()
    {
        return $this->hasOne(LoginGeolocation::class, 'authentication_audit_log_id');
    }
}
