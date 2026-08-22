<?php
declare(strict_types=1);

namespace LogLens\Services;

use InvalidArgumentException;
use RuntimeException;

/**
 * The list of applications and where each one's files live.
 *
 * The registry is a JSON file under storage/, so every value read out of it is
 * treated as untrusted input on the way to a filesystem path — see
 * {@see absolutePath()}. Mutations take an exclusive file lock, because the
 * registry is a read-modify-write on a shared file and two concurrent requests
 * would otherwise each write a list missing the other's application.
 */
final class ApplicationRegistry
{
    private const DEFAULT_ID = 'default';

    /** Re-entrancy depth, so a locked section may call another one. */
    private int $lockDepth = 0;

    public function __construct(private readonly string $projectRoot)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        $applications = $this->read();
        return array_map(fn (array $application): array => $this->publicShape($application), $applications);
    }

    /**
     * The application a request targets, with its directories in place.
     *
     * Only the resolved application is prepared. read() used to call
     * ensureDirectories() for *every* registered application, which put four
     * stats and up to four mkdir attempts per application on the hot path of
     * every single request — work that scaled with the number of applications
     * and was wasted for all but one of them.
     *
     * @return array<string,mixed>
     */
    public function resolve(?string $id): array
    {
        $applications = $this->read();
        $requested = trim((string) $id);
        if ($requested === '') {
            $this->ensureDirectories($applications[0]);
            return $applications[0];
        }
        foreach ($applications as $application) {
            if ($application['id'] === $requested) {
                $this->ensureDirectories($application);
                return $application;
            }
        }
        throw new InvalidArgumentException("Application '{$requested}' was not found.");
    }

    /** @return array<string,mixed> */
    public function create(string $name, string $requestedId = ''): array
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 100) {
            throw new InvalidArgumentException('Application name must contain 1–100 characters.');
        }
        $id = $this->slug($requestedId !== '' ? $requestedId : $name);
        if ($id === self::DEFAULT_ID) {
            throw new InvalidArgumentException('The application id "default" is reserved.');
        }
        // The duplicate-id check and the write have to be one atomic section:
        // two requests creating different applications at the same moment would
        // otherwise both read the old list and the second write would drop the
        // first application entirely.
        return $this->locked(function () use ($id, $name): array {
            $applications = $this->read();
            foreach ($applications as $application) {
                if ($application['id'] === $id) {
                    throw new InvalidArgumentException("Application '{$id}' already exists.");
                }
            }

            $relativeRoot = 'applications/' . $id;
            $application = [
                'id' => $id,
                'name' => $name,
                'root' => $relativeRoot,
                'database' => $relativeRoot . '/log-lens.sqlite',
                'logs' => $relativeRoot . '/logs',
                'processed' => $relativeRoot . '/processed',
                'sources' => $relativeRoot . '/sources',
                'created_at' => gmdate('Y-m-d H:i:s'),
            ];
            $this->ensureDirectories($application);
            $applications[] = $application;
            $this->write($applications);
            return $this->publicShape($application);
        });
    }

    /** @return array<string,mixed> */
    public function publicShape(array $application): array
    {
        return [
            'id' => $application['id'],
            'name' => $application['name'],
            'database' => $application['database'],
            'logs' => $application['logs'],
            'processed' => $application['processed'],
            'sources' => $application['sources'] ?? $this->derivedSourcesPath($application),
            'created_at' => $application['created_at'],
        ];
    }

    /**
     * Resolve one of an application's paths against the project root.
     *
     * The relative part comes out of storage/applications.json, which is a file
     * on disk rather than request input — but it is still input to path
     * construction, and this method's result is handed straight to mkdir(),
     * unlink(), and the SQLite driver. A registry entry containing `..`, an
     * absolute path, or a NUL byte would place an application's database and
     * its delete-logs/prune targets anywhere the PHP process can write.
     * Validating here means the guarantee holds for every consumer rather than
     * being restated at each call site.
     */
    public function absolutePath(array $application, string $key): string
    {
        if ($key === 'sources' && !isset($application[$key])) {
            return $this->projectRoot . '/' . $this->derivedSourcesPath($application);
        }
        if (!isset($application[$key])) {
            throw new RuntimeException("Application path '{$key}' is unavailable.");
        }
        return $this->projectRoot . '/' . $this->relativePath((string) $application[$key], $key);
    }

    /**
     * A registry path value, confirmed to stay inside the project root.
     *
     * Checked on the string rather than with realpath() because the directory
     * usually does not exist yet — realpath() returns false for a path that has
     * not been created, which is precisely when ensureDirectories() needs it.
     */
    private function relativePath(string $value, string $key): string
    {
        $path = ltrim(trim($value), '/');
        if ($path === '') {
            throw new RuntimeException("Application path '{$key}' is empty in the registry.");
        }
        if (str_contains($path, "\0")) {
            throw new RuntimeException("Application path '{$key}' contains a null byte.");
        }
        if (preg_match('#^[A-Za-z]:#', $path) === 1) {
            throw new RuntimeException("Application path '{$key}' must be relative to the project root.");
        }
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '..') {
                throw new RuntimeException("Application path '{$key}' must not escape the project root.");
            }
        }
        return $path;
    }

    /** @return list<array<string,mixed>> */
    private function read(): array
    {
        $path = $this->registryPath();
        if (!is_file($path)) {
            $applications = [$this->legacyDefault()];
            $this->write($applications);
            return $applications;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded) || !isset($decoded['applications']) || !is_array($decoded['applications'])) {
            throw new RuntimeException('The application registry is invalid.');
        }
        $applications = array_values(array_filter($decoded['applications'], 'is_array'));
        if ($applications === []) {
            throw new RuntimeException('The application registry must contain at least one application.');
        }
        // Deliberately does not create directories: resolve() prepares the one
        // application a request actually uses, and all()/health only need names.
        return $applications;
    }

    /**
     * Run $work holding an exclusive lock on the registry.
     *
     * Re-entrant: a locked section may call read(), which bootstraps the
     * registry with write() on first run, without deadlocking on itself.
     *
     * @template T
     * @param  callable():T  $work
     * @return T
     */
    private function locked(callable $work): mixed
    {
        if ($this->lockDepth > 0) {
            $this->lockDepth++;
            try {
                return $work();
            } finally {
                $this->lockDepth--;
            }
        }

        $directory = dirname($this->registryPath());
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create the application registry directory.');
        }
        $handle = fopen($this->registryPath() . '.lock', 'c');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('Cannot lock the application registry.');
        }
        $this->lockDepth = 1;
        try {
            return $work();
        } finally {
            $this->lockDepth = 0;
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @param list<array<string,mixed>> $applications */
    private function write(array $applications): void
    {
        $directory = dirname($this->registryPath());
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create the application registry directory.');
        }
        $temporary = tempnam($directory, 'applications-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot create a temporary application registry.');
        }
        try {
            $json = json_encode(['applications' => $applications], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (file_put_contents($temporary, $json . "\n", LOCK_EX) === false || !rename($temporary, $this->registryPath())) {
                throw new RuntimeException('Cannot save the application registry.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /** @return array<string,mixed> */
    private function legacyDefault(): array
    {
        return [
            'id' => self::DEFAULT_ID,
            'name' => 'Primary application',
            'root' => '.',
            'database' => 'storage/log-analyzer.sqlite',
            'logs' => 'logs',
            'processed' => 'processed',
            'sources' => 'sources',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    private function ensureDirectories(array $application): void
    {
        foreach (['logs', 'processed', 'sources'] as $key) {
            $directory = $this->absolutePath($application, $key);
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException("Cannot create application {$key} directory.");
            }
        }
        $databaseDirectory = dirname($this->absolutePath($application, 'database'));
        if (!is_dir($databaseDirectory) && !mkdir($databaseDirectory, 0775, true) && !is_dir($databaseDirectory)) {
            throw new RuntimeException('Cannot create application database directory.');
        }
    }

    private function registryPath(): string
    {
        return $this->projectRoot . '/storage/applications.json';
    }

    private function derivedSourcesPath(array $application): string
    {
        $root = trim((string) ($application['root'] ?? '.'), '/');
        return $root === '' || $root === '.' ? 'sources' : $root . '/sources';
    }

    private function slug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        if ($slug === '' || strlen($slug) > 64) {
            throw new InvalidArgumentException('Application id must contain 1–64 URL-safe characters.');
        }
        return $slug;
    }
}
