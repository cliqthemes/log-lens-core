<?php
declare(strict_types=1);

namespace LogLens\Services;

use LogLens\Storage\Dialects;

use InvalidArgumentException;
use LogLens\Config;
use LogLens\Storage\Connection;
use LogLens\Storage\PdoConnection;
use PDO;

final class IngestionSettingsService
{
    public const LEVELS = [
        'EMERGENCY',
        'ALERT',
        'CRITICAL',
        'ERROR',
        'WARNING',
        'NOTICE',
        'INFO',
        'DEBUG',
    ];
    public const DEFAULT_LEVELS = ['ERROR', 'WARNING'];
    private const SETTING_KEY = 'ingestion.severities';

    private readonly Connection $db;

    public function __construct(Connection|PDO $db)
    {
        $this->db = $db instanceof Connection ? $db : new PdoConnection($db, Dialects::active());
    }

    /**
     * Default severities for a brand-new workspace, from config.php, restricted
     * to known levels and ordered by severity. Falls back to ERROR + WARNING.
     *
     * @return list<string>
     */
    public function defaultLevels(): array
    {
        $configured = array_map('strtoupper', Config::stringList('ingestion.default_severities', self::DEFAULT_LEVELS));
        $levels = array_values(array_intersect(self::LEVELS, $configured));
        return $levels !== [] ? $levels : self::DEFAULT_LEVELS;
    }

    /** @return list<string> */
    public function severities(): array
    {
        $stored = $this->db->selectValue(Dialects::active()->keyValueLookup(), [self::SETTING_KEY]);
        if (!is_string($stored)) {
            return $this->defaultLevels();
        }

        $decoded = json_decode($stored, true);
        if (!is_array($decoded)) {
            return $this->defaultLevels();
        }

        $levels = array_values(array_intersect(self::LEVELS, array_map('strval', $decoded)));
        return $levels !== [] ? $levels : $this->defaultLevels();
    }

    /** @param array<mixed> $severities */
    public function update(array $severities): array
    {
        $normalized = array_values(array_unique(array_map(
            static fn (mixed $level): string => strtoupper(trim((string) $level)),
            $severities,
        )));
        $unknown = array_values(array_diff($normalized, self::LEVELS));
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown severities: ' . implode(', ', $unknown) . '.');
        }
        if ($normalized === []) {
            throw new InvalidArgumentException('Select at least one severity to ingest.');
        }

        $ordered = array_values(array_intersect(self::LEVELS, $normalized));
        $this->db->execute(Dialects::active()->keyValueUpsert(), [self::SETTING_KEY, json_encode($ordered, JSON_THROW_ON_ERROR)]);

        return $this->configuration();
    }

    public function configuration(): array
    {
        return [
            'available_severities' => self::LEVELS,
            'ingested_severities' => $this->severities(),
            'defaults' => $this->defaultLevels(),
        ];
    }

    public function importSignature(): int
    {
        $mask = 0;
        foreach ($this->severities() as $severity) {
            $position = array_search($severity, self::LEVELS, true);
            if ($position !== false) {
                $mask |= 1 << $position;
            }
        }
        return $mask;
    }
}
