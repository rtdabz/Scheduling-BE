<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/** Approximate, IP-derived location of a login audit event. Pruned after the retention window. */
class LoginGeolocation extends Model
{
    use Prunable;

    public const RESOLVED = 'resolved';

    public const PRIVATE = 'private';

    public const UNAVAILABLE = 'unavailable';

    protected $fillable = [
        'authentication_audit_log_id',
        'lookup_status',
        'city',
        'region',
        'country',
        'country_code',
        'latitude',
        'longitude',
        'is_proxy',
        'provider',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'is_proxy' => 'boolean',
        ];
    }

    public function auditLog()
    {
        return $this->belongsTo(AuthenticationAuditLog::class, 'authentication_audit_log_id');
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(self::retentionDays()));
    }

    public static function retentionDays(): int
    {
        return max(1, (int) config('services.ip_geolocation.retention_days', 90));
    }
}
