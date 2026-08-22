<?php
declare(strict_types=1);

namespace LogLens\Tests\Unit;

use LogLens\Services\ApplicationRegistry;
use LogLens\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

#[CoversClass(ApplicationRegistry::class)]
final class ApplicationRegistryTest extends TestCase
{
    private function registry(): ApplicationRegistry
    {
        $root = $this->path('registry-root');
        mkdir($root, 0775, true);
        return new ApplicationRegistry($root);
    }

    /** @return array<string,array{0:string}> */
    public static function escapingPaths(): array
    {
        return [
            'parent traversal' => ['../../../../etc/log-lens'],
            'traversal mid-path' => ['applications/x/../../../../tmp'],
            'windows drive' => ['C:/Windows/Temp'],
        ];
    }

    /**
     * The registry is a file on disk, not request input — but its values become
     * mkdir/unlink targets and the SQLite path, so a hand-edited or corrupted
     * entry must not be able to place them outside the project root.
     */
    #[DataProvider('escapingPaths')]
    public function testPathsThatEscapeTheProjectRootAreRejected(string $path): void
    {
        $this->expectException(RuntimeException::class);
        $this->registry()->absolutePath(['id' => 'x', 'logs' => $path], 'logs');
    }

    public function testAnEmptyRegistryPathIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->registry()->absolutePath(['id' => 'x', 'logs' => '   '], 'logs');
    }

    public function testAnOrdinaryRelativePathResolvesUnderTheProjectRoot(): void
    {
        $registry = $this->registry();
        $resolved = $registry->absolutePath(['id' => 'x', 'logs' => '/applications/x/logs'], 'logs');
        self::assertSame($this->path('registry-root') . '/applications/x/logs', $resolved);
    }

    /**
     * create() holds a lock across read-check-write; the observable contract is
     * that a second application does not replace the first.
     */
    public function testCreatingTwoApplicationsKeepsBoth(): void
    {
        $registry = $this->registry();
        $registry->create('Alpha', 'alpha');
        $registry->create('Beta', 'beta');

        $ids = array_map(static fn (array $a): string => (string) $a['id'], $registry->all());
        self::assertSame(['default', 'alpha', 'beta'], $ids);
    }

    public function testDuplicateIdsAreRejected(): void
    {
        $registry = $this->registry();
        $registry->create('Alpha', 'alpha');
        $this->expectExceptionMessage("Application 'alpha' already exists.");
        $registry->create('Alpha again', 'alpha');
    }

    /**
     * Listing must not touch the filesystem beyond reading the registry —
     * directories are prepared by resolve(), for the one application in use.
     */
    public function testListingDoesNotCreateApplicationDirectories(): void
    {
        $registry = $this->registry();
        $registry->create('Alpha', 'alpha');
        $logs = $this->path('registry-root/applications/alpha/logs');
        self::assertDirectoryExists($logs);

        rmdir($logs);
        $registry->all();
        self::assertDirectoryDoesNotExist($logs);

        $registry->resolve('alpha');
        self::assertDirectoryExists($logs);
    }
}
