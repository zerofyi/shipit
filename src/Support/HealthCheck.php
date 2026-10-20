<?php

namespace Zerofyi\ShipIt\Support;

use Illuminate\Support\Facades\Http;

/**
 * Post-deploy health verification: the site (and any extra configured
 * URLs) must answer with a successful HTTP status. Anything in the
 * 2xx/3xx range counts as healthy; network errors count as failures.
 */
class HealthCheck
{
    public static function isHealthyStatus(int $status): bool
    {
        return $status >= 200 && $status < 400;
    }

    /**
     * @return array{ok:bool, status:int, error:?string}
     */
    public static function check(string $url, int $timeout = 15): array
    {
        try {
            $response = Http::timeout($timeout)
                ->withOptions(['allow_redirects' => true])
                ->get($url);
            $status = $response->status();
            return [
                'ok' => self::isHealthyStatus($status),
                'status' => $status,
                'error' => self::isHealthyStatus($status) ? null : "HTTP {$status}",
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => 0, 'error' => $e->getMessage()];
        }
    }

    /** The public URL of a site, derived from its server directory name. */
    public static function siteUrl(string $siteDir, array $extraPaths = []): string
    {
        return 'https://' . $siteDir;
    }
}
