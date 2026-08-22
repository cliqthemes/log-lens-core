<?php
declare(strict_types=1);

namespace LogLens\Services;

use InvalidArgumentException;
use LogLens\Domain\IssueOrigin;
use LogLens\Storage\Connection;
use LogLens\Storage\Dialects;
use LogLens\Storage\PdoConnection;
use PDO;

final class MaintenanceService
{
    public const LOG_DELETION_CONFIRMATION = 'DELETE LOGS';

    private readonly Connection $db;

    public function __construct(Connection|PDO $db)
    {
        $this->db = $db instanceof Connection ? $db : new PdoConnection($db, Dialects::active());
    }

    public function previewLogDeletion(?string $date, ?int $sourceId): array
    {
        [$where, $parameters, $scope] = $this->deletionScope($date, $sourceId);
        $counts = $this->db->selectOne(
            "SELECT COUNT(*) occurrences,COUNT(DISTINCT group_id) issue_groups,
                    COUNT(DISTINCT source_file_id) sources
             FROM occurrences WHERE {$where}",
            $parameters,
        );
        if ($sourceId !== null) {
            $metadata = $this->db->selectOne(
                'SELECT id,path,log_type,channel,size,imported_at FROM source_files WHERE id=?',
                [$sourceId],
            );
            if (!$metadata) {
                throw new InvalidArgumentException("Source {$sourceId} does not exist.");
            }
            $scope['source'] = $metadata;
        }
        return [
            'scope' => $scope,
            'occurrences' => (int) ($counts['occurrences'] ?? 0),
            'issue_groups' => (int) ($counts['issue_groups'] ?? 0),
            'sources' => (int) ($counts['sources'] ?? 0),
            'raw_files_preserved' => true,
            'checkpoints_preserved' => true,
        ];
    }

    public function deleteLogs(
        ?string $date,
        ?int $sourceId,
        string $confirmation,
    ): array {
        if ($confirmation !== self::LOG_DELETION_CONFIRMATION) {
            throw new InvalidArgumentException('Type DELETE LOGS exactly to confirm scoped log deletion.');
        }
        [$where, $parameters] = $this->deletionScope($date, $sourceId);
        $preview = $this->previewLogDeletion($date, $sourceId);
        $groupIds = array_map('intval', array_column(
            $this->db->selectAll("SELECT DISTINCT group_id FROM occurrences WHERE {$where}", $parameters),
            'group_id',
        ));

        [$deleted, $deletedGroups] = $this->db->transaction(function (Connection $db) use ($where, $parameters, $groupIds): array {
            $deleted = $db->execute("DELETE FROM occurrences WHERE {$where}", $parameters);
            $deletedGroups = 0;
            foreach ($groupIds as $groupId) {
                $aggregate = $db->selectOne(
                    'SELECT COUNT(*) count,MIN(occurred_at) first_seen,MAX(occurred_at) last_seen
                     FROM occurrences WHERE group_id=?',
                    [$groupId],
                );
                if ((int) $aggregate['count'] === 0) {
                    $origin = $db->selectValue('SELECT origin FROM error_groups WHERE id=?', [$groupId]);
                    if (IssueOrigin::hasOccurrences((string) $origin)) {
                        $deletedGroups += $db->execute('DELETE FROM error_groups WHERE id=?', [$groupId]);
                    }
                    continue;
                }
                $db->execute(
                    'UPDATE error_groups SET count=?,first_seen=?,last_seen=? WHERE id=?',
                    [(int) $aggregate['count'], $aggregate['first_seen'], $aggregate['last_seen'], $groupId],
                );
            }
            return [$deleted, $deletedGroups];
        });

        return [
            'deleted' => [
                'occurrences' => $deleted,
                'empty_issue_groups' => $deletedGroups,
            ],
            'preview' => $preview,
            'raw_files_preserved' => true,
            'checkpoints_preserved' => true,
        ];
    }

    /** @return array{string,list<int|string>,array<string,mixed>} */
    private function deletionScope(?string $date, ?int $sourceId): array
    {
        $date = $date === null ? null : trim($date);
        $hasDate = $date !== null && $date !== '';
        $hasSource = $sourceId !== null && $sourceId > 0;
        if ($hasDate === $hasSource) {
            throw new InvalidArgumentException('Choose exactly one deletion scope: date or source_id.');
        }
        if ($hasDate) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date) {
                throw new InvalidArgumentException('Deletion date must use YYYY-MM-DD.');
            }
            return ['occurred_day=?', [$date], ['type' => 'date', 'date' => $date]];
        }
        return ['source_file_id=?', [(int) $sourceId], ['type' => 'source', 'source_id' => (int) $sourceId]];
    }
}
