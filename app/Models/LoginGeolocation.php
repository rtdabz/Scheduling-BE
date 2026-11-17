<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * Location of a login audit event: an optional browser (device) fix plus the approximate IP estimate.
 * Pruned after the retention window; the audit row itself is kept.
 */
class LoginGeolocation extends Model
{
    use Prunable;

    public const RESOLVED = 'resolved';

    public const PRIVATE = 'private';

    public const UNAVAILABLE = 'unavailable';

    /** Which location the Activity Log should present for this sign-in. */
    public const SOURCE_DEVICE = 'device';

    public const SOURCE_IP = 'ip';

    public const SOURCE_NONE = 'none';

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
        'device_latitude',
        'device_longitude',
        'device_accuracy_m',
        'device_captured_at',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'is_proxy' => 'boolean',
            'device_latitude' => 'float',
            'device_longitude' => 'float',
            'device_accuracy_m' => 'integer',
            'device_captured_at' => 'datetime',
        ];
    }

    public function hasDeviceLocation(): bool
    {
        return $this->device_latitude !== null && $this->device_longitude !== null;
    }

    /** Device fix wins; otherwise a resolved IP estimate; otherwise nothing to map. */
    public function source(): string
    {
        return match (true) {
            $this->hasDeviceLocation() => self::SOURCE_DEVICE,
            $this->lookup_status === self::RESOLVED && $this->latitude !== null && $this->longitude !== null => self::SOURCE_IP,
            default => self::SOURCE_NONE,
        };
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
