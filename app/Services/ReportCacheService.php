<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;

class ReportCacheService
{
    /**
     * Default cache TTL (Time To Live) in seconds
     * 1 hour for reports (dapat disesuaikan per report)
     */
    const DEFAULT_TTL = 3600; // 1 hour

    /**
     * Cache TTL untuk laporan yang heavy
     * 6 hours
     */
    const HEAVY_REPORT_TTL = 21600; // 6 hours

    /**
     * Cache TTL untuk laporan yang sering berubah
     * 15 minutes
     */
    const QUICK_REPORT_TTL = 900; // 15 minutes

    /**
     * Cache TTL untuk laporan standard (alias untuk DEFAULT_TTL)
     * 1 hour
     */
    const STANDARD_REPORT_TTL = 3600; // 1 hour (same as DEFAULT_TTL)

    /**
     * Generate cache key with user context and branch
     *
     * @param string $reportName
     * @param array $params
     * @return string
     */
    public static function generateKey(string $reportName, array $params = []): string
    {
        $user = Auth::user();
        $branchId = session('active_branch_id', 'all');

        // Sort params untuk konsistensi key
        ksort($params);

        // Remove empty values
        $params = array_filter($params, function($value) {
            return $value !== null && $value !== '';
        });

        $paramsString = http_build_query($params);
        $userId = $user ? $user->id : 'guest';

        return sprintf(
            'report:%s:user:%s:branch:%s:params:%s',
            $reportName,
            $userId,
            $branchId,
            md5($paramsString)
        );
    }

    /**
     * Get cached report data or execute callback
     *
     * @param string $reportName
     * @param array $params
     * @param callable $callback
     * @param int|null $ttl
     * @return mixed
     */
    public static function remember(string $reportName, array $params, callable $callback, ?int $ttl = null)
    {
        $key = self::generateKey($reportName, $params);
        $ttl = $ttl ?? self::DEFAULT_TTL;

        return Cache::remember($key, $ttl, $callback);
    }

    /**
     * Clear cache for specific report
     *
     * @param string $reportName
     * @param array $params
     * @return bool
     */
    public static function forget(string $reportName, array $params = []): bool
    {
        $key = self::generateKey($reportName, $params);
        return Cache::forget($key);
    }

    /**
     * Clear all report caches for current user and branch
     *
     * @return void
     */
    public static function flushReportCache(): void
    {
        $user = Auth::user();
        $branchId = session('active_branch_id', 'all');
        $userId = $user ? $user->id : 'guest';

        // Karena database cache driver tidak support tags,
        // kita gunakan pattern matching untuk clear cache
        $pattern = sprintf('report:*:user:%s:branch:%s:*', $userId, $branchId);

        // Note: Untuk production dengan Redis, bisa gunakan Cache::tags()
        // Cache::tags(['reports', "user:{$userId}", "branch:{$branchId}"])->flush();

        // Fallback: Clear specific known reports
        $reports = [
            'sales', 'payments', 'receivables', 'orders',
            'expenses', 'hpp', 'profit-loss', 'cash-flow',
            'treatments', 'customers', 'google-ads', 'meta-ads'
        ];

        foreach ($reports as $report) {
            // Clear dengan berbagai kombinasi params
            self::forget($report, []);
        }
    }

    /**
     * Clear cache when data changes
     * Call this after create/update/delete operations
     *
     * @param array $affectedReports List of report names affected
     * @return void
     */
    public static function invalidate(array $affectedReports): void
    {
        foreach ($affectedReports as $reportName) {
            self::forget($reportName, []);
        }
    }

    /**
     * Get appropriate TTL for report type
     *
     * @param string $reportName
     * @return int
     */
    public static function getTTL(string $reportName): int
    {
        // Heavy reports - cache longer
        $heavyReports = ['hpp', 'profit-loss', 'cash-flow'];
        if (in_array($reportName, $heavyReports)) {
            return self::HEAVY_REPORT_TTL;
        }

        // Quick changing reports - cache shorter
        $quickReports = ['treatments', 'receivables'];
        if (in_array($reportName, $quickReports)) {
            return self::QUICK_REPORT_TTL;
        }

        return self::DEFAULT_TTL;
    }

    /**
     * Warm up cache for common reports
     * Useful untuk background jobs
     *
     * @return void
     */
    public static function warmup(): void
    {
        // TODO: Implement warmup logic
        // Can be called by scheduled command
    }
}
