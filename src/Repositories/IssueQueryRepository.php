<?php
declare(strict_types=1);

namespace LogLens\Repositories;

use LogLens\Config;
use LogLens\Storage\Connection;
use LogLens\Storage\Dialects;
use LogLens\Storage\PdoConnection;
use PDO;

final class IssueQueryRepository
{
    private const SORTS = [
        'occurrences' => 'g.count DESC,g.last_seen DESC',
        'newest' => 'g.last_seen DESC,g.count DESC',
        'oldest' => 'g.first_seen ASC,g.count DESC',
    ];

    private readonly Connection $db;

    public function __construct(Connection|PDO $db)
    {
        $this->db = $db instanceof Connection ? $db : new PdoConnection($db, Dialects::active());
    }

    public function summary(): array
    {
        $total = $this->db->selectOne(
            'SELECT COUNT(*) groups_count,COALESCE(SUM(count),0) occurrences,MAX(last_seen) last_seen
             FROM error_groups'
        );
        $severity = $this->db->selectAll(
            'SELECT severity,COUNT(*) groups_count,SUM(count) occurrences
             FROM error_groups GROUP BY severity
             ORDER BY CASE severity
                WHEN \'EMERGENCY\' THEN 1 WHEN \'ALERT\' THEN 2 WHEN \'CRITICAL\' THEN 3
                WHEN \'ERROR\' THEN 4 WHEN \'WARNING\' THEN 5 WHEN \'NOTICE\' THEN 6
                WHEN \'INFO\' THEN 7 ELSE 8 END'
        );
        $types = $this->db->selectAll(
            'SELECT log_type,COUNT(*) groups_count,SUM(count) occurrences
             FROM error_groups GROUP BY log_type ORDER BY occurrences DESC'
        );
        $status = $this->db->selectAll(
            'SELECT status,COUNT(*) groups_count,SUM(count) occurrences
             FROM error_groups GROUP BY status
             ORDER BY CASE status
                WHEN \'open\' THEN 1 WHEN \'in_progress\' THEN 2 WHEN \'reoccurred\' THEN 3
                WHEN \'fixed\' THEN 4 WHEN \'wont_fix\' THEN 5 ELSE 6 END'
        );
        $trend = $this->db->selectAll(
            'SELECT occurred_day AS day,COUNT(*) count
             FROM occurrences GROUP BY occurred_day ORDER BY occurred_day'
        );
        // The 30-day window is anchored on the newest day in the data, not on
        // today, so an archive imported after the fact still charts. Both the
        // shift and the CURRENT_DATE fallback go through the dialect: `date(x,
        // '-29 days')` is SQLite-only, and Postgres refuses to COALESCE a text
        // column with a bare date.
        $dialect = Dialects::active();
        $anchor = 'COALESCE((SELECT MAX(occurred_day) FROM occurrences),'
            . $dialect->castToText('CURRENT_DATE') . ')';
        $daily_severity = $this->db->selectAll(
            'SELECT occurred_day AS day,severity,COUNT(*) count
             FROM occurrences
             WHERE occurred_day >= ' . $dialect->shiftDays($anchor, -29) . '
             GROUP BY occurred_day,severity ORDER BY occurred_day,severity'
        );
        $files = $this->db->selectAll(
            'SELECT s.id,s.path,s.size,s.imported_at,s.log_type,s.channel,
                    (SELECT COUNT(*) FROM occurrences o WHERE o.source_file_id=s.id) occurrence_count,
                    m.id module_id,m.name module_name,m.slug module_slug,m.color module_color
             FROM source_files s LEFT JOIN modules m ON m.id=s.module_id
             ORDER BY s.imported_at DESC,s.path LIMIT 12'
        );
        $modules = $this->db->selectAll(
            'SELECT m.*,COUNT(g.id) issue_count
             FROM modules m LEFT JOIN error_groups g ON g.module_id=m.id
             GROUP BY m.id ORDER BY ' . Dialects::active()->caseInsensitiveOrder('m.name')
        );
        return compact('total', 'severity', 'status', 'types', 'trend', 'daily_severity', 'files', 'modules');
    }

    public function sources(int $page = 1, int $limit = 12): array
    {
        $page = max(1, $page);
        $limit = min(100, max(1, $limit));
        $total = (int) $this->db->selectValue('SELECT COUNT(*) FROM source_files');
        $data = $this->db->selectAll(
            'SELECT s.id,s.path,s.size,s.imported_at,s.log_type,s.channel,
                    (SELECT COUNT(*) FROM occurrences o WHERE o.source_file_id=s.id) occurrence_count,
                    m.id module_id,m.name module_name,m.slug module_slug,m.color module_color
             FROM source_files s LEFT JOIN modules m ON m.id=s.module_id
             ORDER BY s.imported_at DESC,s.path LIMIT ? OFFSET ?',
            [$limit, ($page - 1) * $limit],
        );
        return [
            'data' => $data,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => (int) ceil($total / $limit),
            ],
        ];
    }

    public function list(
        array $filters,
        int $limit = 200,
        ?int $page = null,
        bool $includeStack = false,
        bool $includeContext = false,
    ): array
    {
        [$where, $parameters] = $this->filters($filters);
        $sqlWhere = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $sort = (string) ($filters['sort'] ?? 'occurrences');
        $order = self::SORTS[$sort] ?? self::SORTS['occurrences'];
        $limit = min(Config::int('pagination.max_limit', 200), max(1, $limit));
        $offset = $page === null ? 0 : (max(1, $page) - 1) * $limit;

        $total = (int) $this->db->selectValue(
            'SELECT COUNT(*) FROM error_groups g LEFT JOIN modules m ON m.id=g.module_id' . $sqlWhere,
            $parameters,
        );

        $fields = <<<'SQL'
g.id,g.fingerprint,g.severity,g.environment,g.title,g.exception_class,g.source_frame,
g.count,g.first_seen,g.last_seen,g.created_at,g.status,g.status_updated_at,
g.log_type,g.channel,g.origin,g.kind,g.module_id,
g.external_source,g.external_id,g.external_url,g.assignee,
g.assigned_to_id,g.assigned_to_label,
m.name module_name,m.slug module_slug,m.color module_color,
(g.sample_stack IS NOT NULL AND trim(g.sample_stack)<>'') has_stack
SQL;
        if ($includeStack) {
            $fields .= ',g.sample_stack';
        }
        if ($includeContext) {
            $fields .= ',g.sample_context,g.sample_message';
        }
        $dialect = Dialects::active();
        $tagsJson = $dialect->jsonArrayAgg($dialect->jsonObject([
            'id' => 't.id',
            'name' => 't.name',
            'color' => 't.color',
            'icon' => 't.icon',
            'source' => 'gt.source',
        ]));
        $items = $this->db->selectAll(
            "SELECT {$fields},
                (SELECT {$tagsJson} FROM error_group_tags gt JOIN tags t ON t.id=gt.tag_id
                    WHERE gt.group_id=g.id) tags
             FROM error_groups g LEFT JOIN modules m ON m.id=g.module_id{$sqlWhere}
             ORDER BY {$order} LIMIT ? OFFSET ?",
            [...$parameters, $limit, $offset],
        );
        foreach ($items as &$item) {
            $item['tags'] = json_decode($item['tags'] ?: '[]', true);
        }
        return ['items' => $items, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
    }

    public function detail(int $groupId, int $limit = 500, int $page = 1): ?array
    {
        $group = $this->db->selectOne(
            'SELECT g.*,m.name module_name,m.slug module_slug,m.color module_color
             FROM error_groups g LEFT JOIN modules m ON m.id=g.module_id WHERE g.id=?',
            [$groupId],
        );
        if (!$group) {
            return null;
        }
        $limit = min(500, max(1, $limit));
        $page = max(1, $page);
        $total = (int) $this->db->selectValue('SELECT COUNT(*) FROM occurrences WHERE group_id=?', [$groupId]);
        $occurrences = $this->db->selectAll(
            'SELECT o.*,s.path,s.log_type,s.channel
             FROM occurrences o JOIN source_files s ON s.id=o.source_file_id
             WHERE group_id=? ORDER BY occurred_at DESC LIMIT ? OFFSET ?',
            [$groupId, $limit, ($page - 1) * $limit],
        );
        $timeline = $this->db->selectAll(
            'SELECT occurred_day AS day,COUNT(*) count
             FROM occurrences WHERE group_id=? GROUP BY occurred_day ORDER BY occurred_day',
            [$groupId],
        );
        $history = $this->db->selectAll(
            'SELECT * FROM issue_status_history WHERE group_id=? ORDER BY created_at DESC,id DESC',
            [$groupId],
        );
        $tags = $this->db->selectAll(
            'SELECT t.*,gt.source FROM error_group_tags gt
             JOIN tags t ON t.id=gt.tag_id WHERE gt.group_id=? ORDER BY t.name',
            [$groupId],
        );
        return [
            'group' => $group,
            'occurrences' => $occurrences,
            'timeline' => $timeline,
            'status_history' => $history,
            'tags' => $tags,
            'meta' => [
                'total_occurrences' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => (int) ceil($total / $limit),
            ],
        ];
    }

    public function rawOccurrence(int $occurrenceId): ?array
    {
        $occurrence = $this->db->selectOne(
            'SELECT o.byte_start,o.byte_end,s.path,s.log_type,s.channel
             FROM occurrences o JOIN source_files s ON s.id=o.source_file_id WHERE o.id=?',
            [$occurrenceId],
        );
        // Defense in depth: source_files.path is meant to be an already-resolved
        // absolute path (connectors/imports) or a synthetic relative key
        // (http-ingest) — never a traversal. Every writer is expected to
        // sanitize its own input (see IngestService::sanitizeChannel), but a
        // stray ".." here would mean reading whatever file it points to, so
        // reject it outright as a last line of defense.
        if (!$occurrence || str_contains($occurrence['path'], '..') || !is_file($occurrence['path'])) {
            return null;
        }
        $handle = fopen($occurrence['path'], 'rb');
        if ($handle === false) {
            return null;
        }
        fseek($handle, (int) $occurrence['byte_start']);
        $content = fread(
            $handle,
            min(4_194_304, (int) $occurrence['byte_end'] - (int) $occurrence['byte_start'])
        );
        fclose($handle);
        return $occurrence + ['content' => $content ?: ''];
    }

    private function filters(array $filters): array
    {
        $dialect = Dialects::active();
        $where = [];
        $parameters = [];
        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $where[] = '(' . $dialect->caseInsensitiveLike('g.title')
                . ' OR ' . $dialect->caseInsensitiveLike('g.sample_message')
                . ' OR ' . $dialect->caseInsensitiveLike('g.exception_class')
                . ' OR ' . $dialect->caseInsensitiveLike('g.source_frame')
                . ' OR ' . $dialect->caseInsensitiveLike('m.name') . ' OR EXISTS(
                    SELECT 1 FROM occurrences os JOIN source_files ss ON ss.id=os.source_file_id
                    WHERE os.group_id=g.id AND (' . $dialect->caseInsensitiveLike('ss.path')
                    . ' OR ' . $dialect->caseInsensitiveLike('ss.channel') . ')
                ))';
            $like = '%' . $search . '%';
            array_push($parameters, $like, $like, $like, $like, $like, $like, $like);
        }
        foreach (['severity', 'status', 'log_type', 'origin', 'kind'] as $field) {
            $value = trim((string) ($filters[$field] ?? ''));
            if ($value !== '') {
                $where[] = "g.{$field}=?";
                $parameters[] = $value;
            }
        }
        $module = trim((string) ($filters['module'] ?? ''));
        if ($module !== '') {
            $where[] = '(' . $dialect->castToText('m.id') . '=? OR '
                . $dialect->caseInsensitiveEquals('m.slug') . ' OR '
                . $dialect->caseInsensitiveEquals('m.name') . ')';
            array_push($parameters, $module, $module, $module);
        }
        $source = trim((string) ($filters['source'] ?? ''));
        if ($source !== '') {
            $where[] = 'EXISTS(
                SELECT 1 FROM occurrences os JOIN source_files ss ON ss.id=os.source_file_id
                WHERE os.group_id=g.id AND (ss.channel=? OR ' . $dialect->caseInsensitiveLike('ss.path') . ')
            )';
            array_push($parameters, $source, '%' . $source . '%');
        }
        $date = trim((string) ($filters['date'] ?? ''));
        if ($date !== '') {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                throw new \InvalidArgumentException('date must use YYYY-MM-DD.');
            }
            $where[] = '(EXISTS(
                SELECT 1 FROM occurrences od WHERE od.group_id=g.id
                AND od.occurred_day=?
            ) OR (g.origin=\'manual\' AND substr(g.created_at,1,10)=?))';
            array_push($parameters, $date, $date);
        }
        $tag = trim((string) ($filters['tag'] ?? ''));
        if ($tag !== '') {
            $where[] = 'EXISTS(
                SELECT 1 FROM error_group_tags egt JOIN tags et ON et.id=egt.tag_id
                WHERE egt.group_id=g.id AND (' . $dialect->castToText('et.id') . '=? OR '
                . $dialect->caseInsensitiveEquals('et.name') . ')
            )';
            array_push($parameters, $tag, $tag);
        }
        $hasStack = $filters['has_stack'] ?? null;
        if (in_array($hasStack, ['true', '1', true, 1], true)) {
            $where[] = "g.sample_stack IS NOT NULL AND trim(g.sample_stack)<>''";
        } elseif (in_array($hasStack, ['false', '0', false, 0], true)) {
            $where[] = "(g.sample_stack IS NULL OR trim(g.sample_stack)='')";
        }
        return [$where, $parameters];
    }
}
