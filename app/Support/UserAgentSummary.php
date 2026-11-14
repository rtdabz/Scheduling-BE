<?php

namespace App\Support;

/** Best-effort browser / OS / device summary derived from a stored user-agent string. */
final class UserAgentSummary
{
    /** @return array{browser: string, os: string, device: string} */
    public static function parse(?string $userAgent): array
    {
        $ua = (string) $userAgent;
        if (trim($ua) === '') {
            return ['browser' => 'Unknown browser', 'os' => 'Unknown OS', 'device' => 'Unknown device'];
        }

        return ['browser' => self::browser($ua), 'os' => self::os($ua), 'device' => self::device($ua)];
    }

    private static function browser(string $ua): string
    {
        $patterns = [
            'Edge' => '/Edg(?:e|A|iOS)?\/([\d]+)/',
            'Opera' => '/(?:OPR|Opera)\/([\d]+)/',
            'Samsung Internet' => '/SamsungBrowser\/([\d]+)/',
            'Firefox' => '/(?:Firefox|FxiOS)\/([\d]+)/',
            'Chrome' => '/(?:Chrome|CriOS)\/([\d]+)/',
            'Safari' => '/Version\/([\d]+).*Safari/',
        ];
        foreach ($patterns as $name => $pattern) {
            if (preg_match($pattern, $ua, $match)) {
                return $name.' '.$match[1];
            }
        }

        return match (true) {
            str_contains($ua, 'PostmanRuntime') => 'Postman',
            str_contains($ua, 'curl/') => 'curl',
            str_contains($ua, 'Symfony') => 'Test client',
            default => 'Other browser',
        };
    }

    private static function os(string $ua): string
    {
        return match (true) {
            (bool) preg_match('/Windows NT 10/', $ua) => 'Windows 10/11',
            str_contains($ua, 'Windows') => 'Windows',
            (bool) preg_match('/(?:iPhone|iPad|iPod).*OS ([\d_]+)/', $ua, $m) => 'iOS '.explode('_', $m[1])[0],
            (bool) preg_match('/Android ([\d.]+)/', $ua, $m) => 'Android '.explode('.', $m[1])[0],
            str_contains($ua, 'CrOS') => 'ChromeOS',
            str_contains($ua, 'Mac OS X') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Unknown OS',
        };
    }

    private static function device(string $ua): string
    {
        return match (true) {
            str_contains($ua, 'iPad') || str_contains($ua, 'Tablet')
                || (str_contains($ua, 'Android') && ! str_contains($ua, 'Mobile')) => 'Tablet',
            (bool) preg_match('/Mobi|iPhone|iPod|Android/', $ua) => 'Mobile',
            (bool) preg_match('/Windows|Macintosh|X11|CrOS|Linux/', $ua) => 'Desktop',
            default => 'Unknown device',
        };
    }
}
