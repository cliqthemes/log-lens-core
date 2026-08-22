<?php
declare(strict_types=1);

namespace LogLens\Services;

use LogLens\Storage\Connection;
use LogLens\Storage\Dialects;
use LogLens\Storage\PdoConnection;
use LogLens\Storage\UniqueViolation;

use PDO;

final class TagService
{
    private readonly Connection $db;

    public function __construct(Connection|PDO $db)
    {
        $this->db = $db instanceof Connection ? $db : new PdoConnection($db, Dialects::active());
    }

    public function all(): array
    {
        $dialect = Dialects::active();
        $rulesJson = $dialect->jsonArrayAgg($dialect->jsonObject([
            'id' => 'r.id',
            'match_word' => 'r.match_word',
            'enabled' => 'r.enabled',
        ]));
        $tags = $this->db->selectAll(
            "SELECT
    t.*,
    (SELECT COUNT(*) FROM error_group_tags gt WHERE gt.tag_id=t.id) issue_count,
    (SELECT {$rulesJson} FROM tag_rules r WHERE r.tag_id=t.id) rules
FROM tags t
ORDER BY " . $dialect->caseInsensitiveOrder('name')
        );
        foreach ($tags as &$tag) {
            $tag['rules'] = json_decode($tag['rules'] ?: '[]', true);
        }
        return $tags;
    }

    public function create(string $name, string $color, string $icon, string $matchWord = '', ?string $actor = null): array
    {
        [$name, $color, $icon, $matchWord] = $this->validate($name, $color, $icon, $matchWord);

        $tagId = null;
        try {
            $this->db->transaction(function (Connection $db) use ($name, $color, $icon, $matchWord, $actor, &$tagId): void {
                $tagId = $db->insert('INSERT INTO tags(name,color,icon,created_by) VALUES(?,?,?,?)', [$name, $color, $icon, $actor]);
                if ($matchWord !== '') {
                    $db->execute('INSERT INTO tag_rules(tag_id,match_word) VALUES(?,?)', [$tagId, $matchWord]);
                }
            });
        } catch (\PDOException $exception) {
            if (UniqueViolation::matches($exception)) {
                throw new \InvalidArgumentException('A tag with this name already exists.');
            }
            throw $exception;
        }
        $this->applyRules();
        return ['id' => $tagId, 'name' => $name, 'color' => $color, 'icon' => $icon, 'match_word' => $matchWord];
    }

    public function update(int $tagId, string $name, string $color, string $icon, string $matchWord = '', ?string $actor = null): array
    {
        if (!$this->exists($tagId)) {
            throw new \LogLens\Domain\NotFoundException('Tag not found.');
        }
        [$name, $color, $icon, $matchWord] = $this->validate($name, $color, $icon, $matchWord);

        try {
            $this->db->transaction(function (Connection $db) use ($name, $color, $icon, $matchWord, $actor, $tagId): void {
                $db->execute('UPDATE tags SET name=?,color=?,icon=?,updated_by=? WHERE id=?', [$name, $color, $icon, $actor, $tagId]);
                $db->execute("DELETE FROM error_group_tags WHERE tag_id=? AND source='rule'", [$tagId]);
                $db->execute('DELETE FROM tag_rules WHERE tag_id=?', [$tagId]);
                if ($matchWord !== '') {
                    $db->execute('INSERT INTO tag_rules(tag_id,match_word) VALUES(?,?)', [$tagId, $matchWord]);
                }
            });
        } catch (\PDOException $exception) {
            if (UniqueViolation::matches($exception)) {
                throw new \InvalidArgumentException('A tag with this name already exists.');
            }
            throw $exception;
        }
        $this->applyRules();
        return ['id' => $tagId, 'name' => $name, 'color' => $color, 'icon' => $icon, 'match_word' => $matchWord];
    }

    public function delete(int $tagId): void
    {
        if (!$this->exists($tagId)) {
            throw new \LogLens\Domain\NotFoundException('Tag not found.');
        }
        $this->db->execute('DELETE FROM tags WHERE id=?', [$tagId]);
    }

    public function assign(int $groupId, int $tagId): void
    {
        $this->db->execute(Dialects::active()->upsert(
            'error_group_tags',
            'group_id,tag_id,source',
            "?,?,'manual'",
            ['group_id', 'tag_id'],
            ["source='manual'"],
        ), [$groupId, $tagId]);
    }

    public function remove(int $groupId, int $tagId): void
    {
        $this->db->execute('DELETE FROM error_group_tags WHERE group_id=? AND tag_id=?', [$groupId, $tagId]);
    }

    public function applyRules(): void
    {
        $rules = $this->db->selectAll('SELECT tag_id,match_word FROM tag_rules WHERE enabled=1');
        $dialect = Dialects::active();
        $sql = $dialect->insertIgnoreSelect(
            'error_group_tags',
            'group_id,tag_id,source',
            "SELECT id,?,'rule' FROM error_groups WHERE "
            . $dialect->caseInsensitiveLike('title') . ' OR '
            . $dialect->caseInsensitiveLike('sample_message') . ' OR '
            . $dialect->caseInsensitiveLike('exception_class'),
        );
        foreach ($rules as $rule) {
            $like = '%' . $rule['match_word'] . '%';
            $this->db->execute($sql, [(int) $rule['tag_id'], $like, $like, $like]);
        }
    }

    private function exists(int $tagId): bool
    {
        return $this->db->selectValue('SELECT 1 FROM tags WHERE id=?', [$tagId]) !== null;
    }

    private function validate(string $name, string $color, string $icon, string $matchWord): array
    {
        $name = trim($name);
        $matchWord = trim($matchWord);
        if ($name === '') {
            throw new \InvalidArgumentException('Tag name is required.');
        }
        if (strlen($name) > 80) {
            throw new \InvalidArgumentException('Tag name cannot exceed 80 characters.');
        }
        if (preg_match('/^#[0-9a-f]{6}$/i', $color) !== 1) {
            throw new \InvalidArgumentException('Tag color must be a six-digit hex value.');
        }
        if (preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $icon) !== 1) {
            throw new \InvalidArgumentException('Tag icon is invalid.');
        }
        if (strlen($matchWord) > 200) {
            throw new \InvalidArgumentException('Tag match rule cannot exceed 200 characters.');
        }
        return [$name, strtolower($color), $icon, $matchWord];
    }
}
