<?php

namespace App\Jobs;

use App\Models\AuthenticationAuditLog;
use App\Models\LoginGeolocation;
use App\Services\IpGeolocationService;
use Illuminate\Foundation\Bus\Dispatchable;
use Throwable;

/**
 * Attaches a location to a login audit event: the optional browser fix sent with the sign-in
 * plus the approximate IP estimate. Dispatched after the HTTP
 * response so a slow or unreachable provider never delays sign-in.
 */
class ResolveLoginGeolocation
{
    use Dispatchable;

    /** @param  array<string, mixed>|null  $device  Sanitized by App\Support\DeviceLocation. */
    public function __construct(public readonly int $auditLogId, public readonly ?array $device = null) {}

    public function handle(IpGeolocationService $geolocation): void
    {
        try {
            $log = AuthenticationAuditLog::query()->find($this->auditLogId);
            if (! $log || LoginGeolocation::query()->where('authentication_audit_log_id', $log->id)->exists()) {
                return;
            }

            LoginGeolocation::create(['authentication_audit_log_id' => $log->id] + ($this->device ?? []) + $geolocation->locate($log->ip_address));
        } catch (Throwable $exception) {
            // Location is an enrichment; it must never break authentication.
            report($exception);
        }
    }
}
