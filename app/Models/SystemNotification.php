<?php

namespace App\Models;

use App\Services\SystemNotificationAudience;
use App\Support\ActorIdentity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SystemNotification extends Model
{
    protected static function booted(): void
    {
        static::creating(function (self $notification): void {
            $metadata = $notification->metadata ?? [];
            ActorIdentity::capture($notification, 'actor_id');
            if (array_key_exists('_queued_actor', $metadata)) {
                $captured = $notification->metadata;
                $queued = $metadata['_queued_actor'];
                $captured['_actor'] = is_array($queued) && (int) ($queued['id'] ?? 0) === (int) $notification->actor_id ? $queued : null;
                unset($captured['_queued_actor']);
                $notification->metadata = $captured;
            }
        });
    }

    protected $fillable = [
        'user_id',
        'event_id',
        'actor_id',
        'department_id',
        'semester_id',
        'type',
        'title',
        'message',
        'remarks',
        'metadata',
        'read_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'read_at' => 'datetime',
    ];

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return SystemNotificationAudience::scope($query, $user);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function department()
    {
        return $this->belongsTo(Departments::class, 'department_id');
    }

    public function academicSemester()
    {
        return $this->belongsTo(Semester::class, 'semester_id');
    }
}
