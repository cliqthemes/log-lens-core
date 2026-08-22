<?php
declare(strict_types=1);

namespace LogLens\Services;

use InvalidArgumentException;
use LogLens\Domain\NotFoundException;
use LogLens\Storage\Connection;
use LogLens\Storage\Dialects;
use LogLens\Storage\PdoConnection;
use LogLens\Storage\UniqueViolation;
use PDO;

/**
 * Modules CRUD, routed through the {@see Connection} storage seam (C-1) so its
 * SQL no longer talks to PDO directly. Accepts either a Connection (preferred)
 * or a raw PDO (wrapped transparently) so existing call sites keep working while
 * the codebase migrates onto the seam.
 */
final class ModuleService
{
    private readonly Connection $db;

    public function __construct(Connection|PDO $db)
    {
        $this->db = $db instanceof Connection ? $db : new PdoConnection($db, Dialects::active());
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->db->selectAll(
            'SELECT m.*,COUNT(g.id) issue_count
             FROM modules m LEFT JOIN error_groups g ON g.module_id=m.id
             GROUP BY m.id ORDER BY ' . $this->db->dialect()->caseInsensitiveOrder('m.name')
        );
    }

    /** @return array<string,mixed> */
    public function create(string $name, string $requestedSlug = '', string $color = '#6366f1'): array
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 100) {
            throw new InvalidArgumentException('Module name must contain 1–100 characters.');
        }
        $slug = $this->slug($requestedSlug !== '' ? $requestedSlug : $name);
        if (!preg_match('/^#[0-9a-f]{6}$/i', $color)) {
            throw new InvalidArgumentException('Module color must be a six-digit hex value.');
        }
        try {
            $id = $this->db->insert('INSERT INTO modules(name,slug,color) VALUES(?,?,?)', [$name, $slug, strtolower($color)]);
        } catch (\PDOException $exception) {
            if (UniqueViolation::matches($exception)) {
                throw new InvalidArgumentException('A module with that name or slug already exists.');
            }
            throw $exception;
        }
        return $this->find($id);
    }

    /** @return array<string,mixed> */
    public function findOrCreateDirectory(string $directory): array
    {
        $slug = $this->slug($directory);
        $name = ucwords(str_replace(['-', '_'], ' ', $directory));
        $module = $this->db->selectOne(
            'SELECT * FROM modules WHERE slug=? OR ' . $this->db->dialect()->caseInsensitiveEquals('name'),
            [$slug, $name],
        );
        if ($module !== null) {
            return $module;
        }
        return $this->create($name, $slug);
    }

    /** @return array<string,mixed> */
    private function find(int $id): array
    {
        $module = $this->db->selectOne('SELECT * FROM modules WHERE id=?', [$id]);
        if ($module === null) {
            throw new NotFoundException('Created module was not found.');
        }
        return $module;
    }

    private function slug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        if ($slug === '' || strlen($slug) > 64) {
            throw new InvalidArgumentException('Module slug must contain 1–64 URL-safe characters.');
        }
        return $slug;
    }
}
