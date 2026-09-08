<?php
declare(strict_types=1);

namespace LogLens\Tests\Unit;

use LogLens\Plugins\AbstractPlugin;
use LogLens\Plugins\PluginManager;
use LogLens\Plugins\PluginRegistry;
use LogLens\Tests\TestCase;
use PDO;
use RuntimeException;

/**
 * A plugin hook that throws is caught so it can never break ingestion or a
 * status change. That isolation is right, but it used to make the failure
 * invisible: the only trace was a line in the PHP error log. These tests cover
 * the recorded-failure surface that puts it back in front of the operator.
 */
final class PluginFailureTest extends TestCase
{
    /** Where recordFailure()'s error_log() output goes, so the suite stays quiet. */
    private string $previousErrorLog = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousErrorLog = (string) ini_get('error_log');
        ini_set('error_log', $this->path('php-errors.log'));
        // Statics are process-wide; reset them so test order never matters.
        FailingPlugin::$fail = false;
        FailingPlugin::$message = 'Linear token rejected (401)';
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog);
        parent::tearDown();
    }

    public function testHealthyPluginHasNoRecordedFailure(): void
    {
        PluginRegistry::register(new FailingPlugin());
        $manager = new PluginManager($this->makeDatabase()->pdo);
        $manager->setEnabled('failing', true);

        FailingPlugin::$fail = false;
        $manager->onIngest();

        self::assertSame([], $manager->failures());
        self::assertNull($this->catalogEntry($manager, 'failing')['last_error']);
    }

    public function testThrowingIngestHookIsRecordedRatherThanPropagated(): void
    {
        PluginRegistry::register(new FailingPlugin());
        $manager = new PluginManager($this->makeDatabase()->pdo);
        $manager->setEnabled('failing', true);

        FailingPlugin::$fail = true;
        $manager->onIngest(); // must not throw

        $failure = $manager->failures()['failing'] ?? null;
        self::assertIsArray($failure);
        self::assertSame('onIngest', $failure['hook']);
        self::assertStringContainsString('Linear token rejected', $failure['message']);
        self::assertNotSame('', $failure['at']);
    }

    public function testThrowingStatusHookIsRecordedAndOtherPluginsStillRun(): void
    {
        PluginRegistry::register(new FailingPlugin());
        $manager = new PluginManager($this->makeDatabase()->pdo);
        $manager->setEnabled('failing', true);

        FailingPlugin::$fail = true;
        $results = $manager->onIssueStatusChanged(1, 'resolved', 'note');

        self::assertArrayNotHasKey('failing', $results, 'A failed hook contributes no result.');
        self::assertSame('onIssueStatusChanged', $manager->failures()['failing']['hook'] ?? '');
    }

    public function testCatalogCarriesTheFailureForTheSettingsPanel(): void
    {
        PluginRegistry::register(new FailingPlugin());
        $manager = new PluginManager($this->makeDatabase()->pdo);
        $manager->setEnabled('failing', true);

        FailingPlugin::$fail = true;
        $manager->onIngest();

        $entry = $this->catalogEntry($manager, 'failing');
        self::assertSame('onIngest', $entry['last_error']['hook']);
        self::assertStringContainsString('Linear token rejected', $entry['last_error']['message']);
        // Every other plugin stays clean — a failure is scoped to its own id.
        self::assertNull($this->catalogEntry($manager, 'alerts')['last_error']);
    }

    public function testASucceedingHookClearsTheEarlierFailure(): void
    {
        PluginRegistry::register(new FailingPlugin());
        $manager = new PluginManager($this->makeDatabase()->pdo);
        $manager->setEnabled('failing', true);

        FailingPlugin::$fail = true;
        $manager->onIngest();
        self::assertArrayHasKey('failing', $manager->failures());

        FailingPlugin::$fail = false;
        $manager->onIngest();
        self::assertSame([], $manager->failures(), 'A fixed fault stops being reported.');
    }

    public function testAControlCharacterMessageIsFlattenedAndCapped(): void
    {
        PluginRegistry::register(new FailingPlugin());
        $manager = new PluginManager($this->makeDatabase()->pdo);
        $manager->setEnabled('failing', true);

        FailingPlugin::$fail = true;
        FailingPlugin::$message = "bad\n\tinput\x00" . str_repeat('x', 400);
        $manager->onIngest();

        $message = $manager->failures()['failing']['message'];
        self::assertStringStartsWith('bad input', $message);
        self::assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $message);
        self::assertLessThanOrEqual(301, mb_strlen($message), 'Capped, with an ellipsis.');
    }

    /** @return array<string,mixed> */
    private function catalogEntry(PluginManager $manager, string $id): array
    {
        foreach ($manager->catalog() as $entry) {
            if ($entry['id'] === $id) {
                return $entry;
            }
        }
        self::fail("Plugin '{$id}' is not in the catalog.");
    }
}

/** A test-only plugin whose hooks throw on demand. */
final class FailingPlugin extends AbstractPlugin
{
    public static bool $fail = false;

    public static string $message = 'Linear token rejected (401)';

    public function id(): string
    {
        return 'failing';
    }

    public function name(): string
    {
        return 'Failing';
    }

    public function description(): string
    {
        return 'Test plugin that throws on demand.';
    }

    public function category(): string
    {
        return 'Testing';
    }

    public function onIngest(PDO $db): void
    {
        if (self::$fail) {
            throw new RuntimeException(self::$message);
        }
    }

    public function onIssueStatusChanged(PDO $db, int $groupId, string $status, string $note, bool $comment = true): ?array
    {
        if (self::$fail) {
            throw new RuntimeException(self::$message);
        }
        return null;
    }
}
