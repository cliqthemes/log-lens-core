<?php
declare(strict_types=1);

namespace LogLens\Services;

use InvalidArgumentException;
use LogLens\Config;
use LogLens\Connectors\ConnectorFactory;
use LogLens\Domain\NotFoundException;
use LogLens\Storage\Connection;
use LogLens\Storage\Dialects;
use LogLens\Storage\PdoConnection;
use LogLens\Storage\UniqueViolation;
use PDO;

final class ConnectorService
{
    public const TYPES = ['local', 'ssh'];

    private readonly Connection $db;

    public function __construct(
        Connection|PDO $db,
        private readonly ConnectorFactory $factory = new ConnectorFactory(),
    ) {
        $this->db = $db instanceof Connection ? $db : new PdoConnection($db, Dialects::active());
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        $rows = $this->db->selectAll(
            'SELECT c.*,m.name module_name,m.slug module_slug,m.color module_color,
                    (SELECT COUNT(*) FROM source_streams s WHERE s.connector_id=c.id) stream_count
             FROM connectors c LEFT JOIN modules m ON m.id=c.module_id
             ORDER BY ' . Dialects::active()->caseInsensitiveOrder('c.name')
        );
        return array_map($this->publicShape(...), $rows);
    }

    /** @return array<string,mixed> */
    public function create(array $input): array
    {
        [$name, $type, $moduleId, $enabled, $config] = $this->validated($input);
        try {
            $id = $this->db->insert(
                'INSERT INTO connectors(name,type,module_id,enabled,config_json) VALUES(?,?,?,?,?)',
                [
                    $name,
                    $type,
                    $moduleId,
                    $enabled ? 1 : 0,
                    json_encode($config, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ],
            );
        } catch (\PDOException $exception) {
            if (UniqueViolation::matches($exception)) {
                throw new InvalidArgumentException('A connector with that name already exists.');
            }
            throw $exception;
        }
        return $this->find($id);
    }

    /** @return array<string,mixed> */
    public function update(int $id, array $input): array
    {
        $this->findRow($id);
        [$name, $type, $moduleId, $enabled, $config] = $this->validated($input);
        $this->db->execute(
            'UPDATE connectors SET name=?,type=?,module_id=?,enabled=?,config_json=?,updated_at=CURRENT_TIMESTAMP
             WHERE id=?',
            [
                $name,
                $type,
                $moduleId,
                $enabled ? 1 : 0,
                json_encode($config, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                $id,
            ],
        );
        return $this->find($id);
    }

    public function delete(int $id): void
    {
        $this->findRow($id);
        $this->db->execute('DELETE FROM connectors WHERE id=?', [$id]);
    }

    /** @return array{ok:bool,message:string} */
    public function test(int $id): array
    {
        return $this->factory->make($this->findRow($id))->test();
    }

    /** @return array<string,mixed> */
    public function row(int $id): array
    {
        return $this->findRow($id);
    }

    /** @return array<string,mixed> */
    private function find(int $id): array
    {
        $connector = $this->db->selectOne(
            'SELECT c.*,m.name module_name,m.slug module_slug,m.color module_color,
                    (SELECT COUNT(*) FROM source_streams s WHERE s.connector_id=c.id) stream_count
             FROM connectors c LEFT JOIN modules m ON m.id=c.module_id WHERE c.id=?',
            [$id],
        );
        if (!$connector) {
            throw new NotFoundException('Connector not found.');
        }
        return $this->publicShape($connector);
    }

    /** @return array<string,mixed> */
    private function findRow(int $id): array
    {
        if ($id < 1) {
            throw new InvalidArgumentException('A valid connector id is required.');
        }
        $connector = $this->db->selectOne('SELECT * FROM connectors WHERE id=?', [$id]);
        if (!$connector) {
            throw new NotFoundException('Connector not found.');
        }
        return $connector;
    }

    /** @return array{string,string,?int,bool,array<string,mixed>} */
    private function validated(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $type = strtolower(trim((string) ($input['type'] ?? '')));
        $moduleId = ($input['module_id'] ?? null) === null || ($input['module_id'] ?? '') === ''
            ? null
            : (int) $input['module_id'];
        $enabled = filter_var($input['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $config = $input['config'] ?? [];
        if ($name === '' || strlen($name) > 100) {
            throw new InvalidArgumentException('Connector name must contain 1–100 characters.');
        }
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Connector type must be local or ssh.');
        }
        if (!is_array($config)) {
            throw new InvalidArgumentException('Connector config must be an object.');
        }
        if ($moduleId !== null) {
            if ($this->db->selectValue('SELECT 1 FROM modules WHERE id=?', [$moduleId]) === null) {
                throw new InvalidArgumentException("Module {$moduleId} does not exist in this application.");
            }
        }
        if ($type === 'local') {
            $directory = trim((string) ($config['directory'] ?? ''));
            if ($directory === '' || !is_dir($directory)) {
                throw new InvalidArgumentException('A readable local directory is required.');
            }
            $config = ['directory' => realpath($directory) ?: $directory, 'recursive' => (bool) ($config['recursive'] ?? true)];
        } else {
            $paths = $config['paths'] ?? [];
            $host = trim((string) ($config['host'] ?? ''));
            $user = trim((string) ($config['user'] ?? ''));
            $port = (int) ($config['port'] ?? Config::int('ssh.default_port', 22));
            if (
                preg_match('/^[a-zA-Z0-9._:-]+$/', $host) !== 1
                || preg_match('/^[a-zA-Z0-9._-]+$/', $user) !== 1
                || $port < 1
                || $port > 65535
                || !is_array($paths)
                || $paths === []
                || count($paths) > 100
                || array_filter($paths, static function (mixed $path): bool {
                    $path = trim((string) $path);
                    return !str_starts_with($path, '/')
                        || preg_match('/[\x00-\x1F\x7F]/', $path) === 1
                        || in_array('..', explode('/', $path), true)
                        || str_contains($path, '**');
                }) !== []
            ) {
                throw new InvalidArgumentException(
                    'SSH host, user, port, and 1–100 safe absolute log paths or single-level glob patterns are required.'
                );
            }
            $keyPath = trim((string) ($config['key_path'] ?? ''));
            $knownHosts = trim((string) ($config['known_hosts_file'] ?? ''));
            if ($keyPath !== '' && !is_file($keyPath)) {
                throw new InvalidArgumentException('The configured SSH private key does not exist.');
            }
            if ($knownHosts !== '' && !is_file($knownHosts)) {
                throw new InvalidArgumentException('The configured known-hosts file does not exist.');
            }
            $config = [
                'host' => $host,
                'port' => $port,
                'user' => $user,
                'key_path' => $keyPath,
                'known_hosts_file' => $knownHosts,
                'paths' => array_values(array_map(static fn (mixed $path): string => trim((string) $path), $paths)),
            ];
        }
        return [$name, $type, $moduleId, $enabled, $config];
    }

    /** @return array<string,mixed> */
    private function publicShape(array $connector): array
    {
        $config = json_decode((string) $connector['config_json'], true);
        $connector['config'] = is_array($config) ? $config : [];
        unset($connector['config_json']);
        $connector['enabled'] = (bool) $connector['enabled'];
        return $connector;
    }
}
