<?php
declare(strict_types=1);

namespace LogLens\Plugins\Analytics;

use LogLens\Storage\Connection;
use LogLens\Storage\Dialects;
use LogLens\Storage\PdoConnection;

use PDO;

/**
 * Access-log analytics: turn already-parsed nginx access occurrences into a
 * traffic / status-code / top-path view. The per-request fields (method, path,
 * status, user agent) are exposed as generated columns on `occurrences` and
 * covered by partial indexes (schema v7), so these queries index the access
 * rows instead of json_extract-scanning every occurrence (M-9).
 *
 * Each query builds on a common CTE that resolves the generated columns once
 * per row for the nginx rows inside the window, then aggregates from there.
 */
final class AnalyticsService
{
    private readonly Connection $db;

    public function __construct(Connection|PDO $db)
    {
        $this->db = $db instanceof Connection ? $db : new PdoConnection($db, Dialects::active());
    }

    /** The shared access-rows CTE, bounded to the last $days days for the engine. */
    private static function accessCte(int $days): string
    {
        $since = Dialects::active()->now(-$days * 86400);
        return "WITH access AS (
        SELECT o.occurred_day AS day,
               o.access_status AS status,
               o.access_path AS path,
               o.access_method AS method,
               o.access_agent AS agent
        FROM occurrences o
        JOIN source_files s ON s.id = o.source_file_id
        WHERE s.log_type = 'nginx_access'
          AND o.occurred_at >= {$since}
    )";
    }

    public function summary(int $days = 7): array
    {
        $days = max(1, min(90, $days));
        $cte = self::accessCte($days);

        $totals = $this->row(
            $cte . "
             SELECT COUNT(*) total,
                SUM(CASE WHEN status BETWEEN 200 AND 299 THEN 1 ELSE 0 END) c2xx,
                SUM(CASE WHEN status BETWEEN 300 AND 399 THEN 1 ELSE 0 END) c3xx,
                SUM(CASE WHEN status BETWEEN 400 AND 499 THEN 1 ELSE 0 END) c4xx,
                SUM(CASE WHEN status >= 500 THEN 1 ELSE 0 END) c5xx,
                COUNT(DISTINCT path) unique_paths
             FROM access",
        );

        $total = (int) ($totals['total'] ?? 0);
        $errors = (int) ($totals['c4xx'] ?? 0) + (int) ($totals['c5xx'] ?? 0);

        return [
            'window_days' => $days,
            'total' => $total,
            'unique_paths' => (int) ($totals['unique_paths'] ?? 0),
            'error_rate' => $total > 0 ? round($errors / $total, 4) : 0,
            'status_classes' => [
                ['class' => '2xx', 'count' => (int) ($totals['c2xx'] ?? 0)],
                ['class' => '3xx', 'count' => (int) ($totals['c3xx'] ?? 0)],
                ['class' => '4xx', 'count' => (int) ($totals['c4xx'] ?? 0)],
                ['class' => '5xx', 'count' => (int) ($totals['c5xx'] ?? 0)],
            ],
            'by_day' => $this->all(
                $cte . "
                 SELECT day, COUNT(*) count,
                    SUM(CASE WHEN status >= 400 THEN 1 ELSE 0 END) errors
                 FROM access GROUP BY day ORDER BY day",
            ),
            'top_paths' => $this->all(
                $cte . "
                 SELECT path, COUNT(*) count,
                    SUM(CASE WHEN status >= 400 THEN 1 ELSE 0 END) errors
                 FROM access WHERE path IS NOT NULL
                 GROUP BY path ORDER BY count DESC LIMIT 15",
            ),
            'error_paths' => $this->all(
                $cte . "
                 SELECT path, COUNT(*) errors
                 FROM access WHERE status >= 400 AND path IS NOT NULL
                 GROUP BY path ORDER BY errors DESC LIMIT 10",
            ),
            'methods' => $this->all(
                $cte . "
                 SELECT method, COUNT(*) count
                 FROM access WHERE method IS NOT NULL
                 GROUP BY method ORDER BY count DESC",
            ),
            'top_agents' => $this->all(
                $cte . "
                 SELECT agent, COUNT(*) count
                 FROM access WHERE agent IS NOT NULL AND agent <> ''
                 GROUP BY agent ORDER BY count DESC LIMIT 8",
            ),
        ];
    }

    private function row(string $sql, array $params = []): array
    {
        return $this->db->selectOne($sql, $params) ?? [];
    }

    private function all(string $sql, array $params = []): array
    {
        return $this->db->selectAll($sql, $params);
    }
}
