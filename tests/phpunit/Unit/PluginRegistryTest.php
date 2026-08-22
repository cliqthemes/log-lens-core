<?php
declare(strict_types=1);

namespace LogLens\Tests\Unit;

use LogLens\Config;
use LogLens\Plugins\AbstractPlugin;
use LogLens\Plugins\PluginManager;
use LogLens\Plugins\PluginRegistry;
use LogLens\Tests\TestCase;
use PDO;

/**
 * M-7: plugins are a real registration contract, not a closed hardcoded list.
 * These tests cover the built-in catalog staying intact plus the two extension
 * paths (programmatic + config-declared) and the ingest hook.
 */
final class PluginRegistryTest extends TestCase
{
    public function testBuiltInCatalogIsPresentWithExpectedDefaults(): void
    {
        $manager = new PluginManager($this->makeDatabase()->pdo);
        $ids = array_column($manager->catalog(), 'id');
        foreach (['linear', 'alerts', 'http_ingest', 'releases', 'access_analytics'] as $id) {
            self::assertContains($id, $ids);
        }
        // Linear ships on by default; the rest off.
        self::assertTrue($manager->isEnabled('linear'));
        self::assertFalse($manager->isEnabled('alerts'));
    }

    public function testProgrammaticRegistrationAddsAToggleablePlugin(): void
    {
        PluginRegistry::register(new RecordingPlugin());
        $manager = new PluginManager($this->makeDatabase()->pdo);

        $ids = array_column($manager->catalog(), 'id');
        self::assertContains('recording', $ids);
        self::assertFalse($manager->isEnabled('recording'), 'Off by default until toggled.');

        $manager->setEnabled('recording', true);
        self::assertTrue($manager->isEnabled('recording'));
    }

    public function testConfigDeclaredDiscoveryRegistersByClassName(): void
    {
        Config::load(['plugins' => ['register' => [RecordingPlugin::class]]]);
        $manager = new PluginManager($this->makeDatabase()->pdo);
        self::assertContains('recording', array_column($manager->catalog(), 'id'));
    }

    public function testEnabledPluginIngestHookRuns(): void
    {
        RecordingPlugin::$ingested = 0;
        PluginRegistry::register(new RecordingPlugin());
        $manager = new PluginManager($this->makeDatabase()->pdo);
        $manager->setEnabled('recording', true);

        $manager->onIngest();
        self::assertSame(1, RecordingPlugin::$ingested, 'Enabled plugin hook fired.');
    }

    public function testDisabledPluginIngestHookDoesNotRun(): void
    {
        RecordingPlugin::$ingested = 0;
        PluginRegistry::register(new RecordingPlugin()); // left disabled
        (new PluginManager($this->makeDatabase()->pdo))->onIngest();
        self::assertSame(0, RecordingPlugin::$ingested);
    }

    public function testUnknownPluginCannotBeToggled(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PluginManager($this->makeDatabase()->pdo))->setEnabled('no-such-plugin', true);
    }
}

/** A test-only plugin used to exercise the registration + hook paths. */
final class RecordingPlugin extends AbstractPlugin
{
    public static int $ingested = 0;

    public function id(): string
    {
        return 'recording';
    }

    public function name(): string
    {
        return 'Recording';
    }

    public function description(): string
    {
        return 'Test plugin.';
    }

    public function category(): string
    {
        return 'Testing';
    }

    public function onIngest(PDO $db): void
    {
        self::$ingested++;
    }
}
