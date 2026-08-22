<?php
declare(strict_types=1);

namespace LogLens\Tests\Integration;

use InvalidArgumentException;
use LogLens\Client\LogLensClient;
use LogLens\Config;
use LogLens\Database;
use LogLens\Http\ApiController;
use LogLens\Http\LogLensRequest;
use LogLens\Plugins\Alerts\AlertService;
use LogLens\Plugins\Alerts\Notifier;
use LogLens\Plugins\Ingest\IngestService;
use LogLens\Plugins\PluginManager;
use LogLens\Plugins\Releases\ReleaseService;
use LogLens\Plugins\Releases\SourceMapService;
use LogLens\Tests\TestCase;
use PDO;
use RuntimeException;

/**
 * The optional feature plugins: per-application gating, alerting, HTTP ingest,
 * release tracking, and the generic PHP capture client. Each scenario runs on
 * its own fresh database.
 */
final class PluginsTest extends TestCase
{
    private function controller(PDO $pdo): ApiController
    {
        return new ApiController($pdo, $this->workspace, $this->workspace, $this->workspace);
    }

    public function testPluginGatingAndDefaults(): void
    {
        $pdo = $this->makeDatabase()->pdo;
        $manager = new PluginManager($pdo);
        self::assertTrue($manager->isEnabled('linear'), 'Linear is enabled by default.');
        self::assertFalse($manager->isEnabled('alerts'), 'Other plugins are off by default.');

        $manager->setEnabled('linear', false);
        self::assertFalse((new PluginManager($pdo))->isEnabled('linear'), 'Disabling must persist per application.');

        Config::load(['auth' => ['token' => '']]);
        $response = $this->controller($pdo)->handle(new LogLensRequest('GET', ['api' => 'linear-settings'], [], ['host' => 'log-lens.test']));
        self::assertSame(404, $response->status, 'A disabled plugin route must 404.');

        $this->expectException(InvalidArgumentException::class);
        $manager->setEnabled('no-such-plugin', true);
    }

    public function testAlertingLifecycle(): void
    {
        $pdo = $this->makeDatabase()->pdo;
        $sent = [];
        $transport = static function (string $url, array $headers, string $body) use (&$sent): array {
            $sent[] = ['url' => $url, 'body' => $body];
            return ['status' => 200, 'body' => 'ok'];
        };
        $alerts = new AlertService($pdo, new Notifier($transport));
        $channel = $alerts->createChannel(['name' => 'Eng Slack', 'type' => 'slack', 'url' => 'https://hooks.slack.com/services/T00/B00/secret']);
        self::assertSame('hooks.slack.com', $channel['target_hint']);
        self::assertArrayNotHasKey('url', $channel, 'The secret webhook URL must never be exposed.');
        $alerts->createRule(['name' => 'All new errors', 'channel_id' => $channel['id'], 'trigger_type' => 'new_error', 'severities' => ['ERROR'], 'cooldown_minutes' => 0]);

        $first = $alerts->scan();
        self::assertTrue($first['initialized'] ?? false, 'First scan initializes the cursor without replaying history.');
        self::assertSame(0, $first['sent']);

        $pdo->exec("INSERT INTO error_groups(fingerprint,severity,environment,title,count,first_seen,last_seen,origin,status) VALUES('a-err','ERROR','production','Payment gateway exploded',7,'2026-08-14 10:00:00','2026-08-14 10:00:00','ingested','open')");
        $pdo->exec("INSERT INTO error_groups(fingerprint,severity,environment,title,count,first_seen,last_seen,origin,status) VALUES('a-warn','WARNING','production','Cache latency elevated',3,'2026-08-14 10:05:00','2026-08-14 10:05:00','ingested','open')");

        $triggered = $alerts->scan();
        self::assertSame(1, $triggered['sent'], 'Only the new ERROR group is delivered.');
        self::assertStringContainsString('Payment gateway exploded', $sent[0]['body']);
        self::assertStringNotContainsString('Cache latency elevated', $sent[0]['body']);
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM alert_events WHERE status='sent'")->fetchColumn());

        self::assertSame(0, $alerts->scan()['sent'], 'Re-scan without new activity resends nothing.');

        $alerts->testChannel((int) $channel['id']);
        self::assertCount(2, $sent);
        self::assertStringContainsString('Test', $sent[1]['body']);

        // Route gating.
        Config::load(['auth' => ['token' => '']]);
        self::assertSame(404, $this->controller($pdo)->handle(new LogLensRequest('GET', ['api' => 'alert-rules'], [], ['host' => 'log-lens.test']))->status);
        (new PluginManager($pdo))->setEnabled('alerts', true);
        self::assertSame(200, $this->controller($pdo)->handle(new LogLensRequest('GET', ['api' => 'alert-rules'], [], ['host' => 'log-lens.test']))->status);
    }

    public function testHttpIngestGroupingStructuredContextAndFingerprint(): void
    {
        $pdo = $this->makeDatabase()->pdo;
        (new PluginManager($pdo))->setEnabled('http_ingest', true);
        $ingest = new IngestService($pdo);
        $config = $ingest->settings('default');
        self::assertStringStartsWith('llk_', $config['key']);
        self::assertTrue($ingest->verify($config['key']));
        self::assertFalse($ingest->verify('wrong-key'));

        $result = $ingest->ingest(['events' => [
            ['message' => 'Redis connection timeout', 'severity' => 'ERROR', 'exception_class' => 'RedisException', 'module' => 'cache'],
            ['message' => 'Redis connection timeout', 'severity' => 'ERROR', 'exception_class' => 'RedisException', 'module' => 'cache'],
            ['message' => 'Disk almost full', 'severity' => 'WARNING'],
        ]]);
        self::assertSame(3, $result['accepted']);

        $redis = $pdo->query("SELECT * FROM error_groups WHERE exception_class='RedisException'")->fetch();
        self::assertSame(2, (int) $redis['count'], 'Identical pushed events group into one issue.');
        self::assertSame('http', $redis['log_type']);
        self::assertSame('ingested', $redis['origin']);
        self::assertNotNull($redis['module_id']);
        self::assertSame(3, (int) $pdo->query("SELECT COUNT(*) FROM occurrences o JOIN source_files s ON s.id=o.source_file_id WHERE s.log_type='http'")->fetchColumn());

        $ingest->ingest(['events' => [[
            'message' => 'Null pointer in checkout', 'severity' => 'ERROR', 'exception_class' => 'TypeError',
            'fingerprint' => 'checkout-null-v1', 'tags' => ['checkout', 'payments'],
            'request' => ['method' => 'POST', 'url' => 'https://app.test/checkout', 'route' => 'checkout.store'],
            'user' => ['id' => 42, 'email' => 'buyer@example.test'],
            'breadcrumbs' => [['category' => 'nav', 'message' => '/cart'], ['category' => 'http', 'message' => 'POST /checkout']],
        ]]]);
        $rich = $pdo->query("SELECT * FROM error_groups WHERE exception_class='TypeError'")->fetch();
        $context = json_decode((string) $rich['sample_context'], true);
        self::assertSame('checkout.store', $context['request']['route'] ?? null);
        self::assertSame(42, (int) ($context['user']['id'] ?? 0));
        self::assertCount(2, $context['breadcrumbs'] ?? []);
        self::assertSame(2, (int) $pdo->query(
            "SELECT COUNT(*) FROM error_group_tags gt JOIN tags t ON t.id=gt.tag_id WHERE gt.group_id={$rich['id']} AND gt.source='ingest' AND t.name IN ('checkout','payments')"
        )->fetchColumn());

        // A custom fingerprint groups two differently-worded events together.
        $ingest->ingest(['events' => [['message' => 'A completely different symptom', 'severity' => 'ERROR', 'fingerprint' => 'checkout-null-v1']]]);
        self::assertSame(2, (int) $pdo->query("SELECT count FROM error_groups WHERE id={$rich['id']}")->fetchColumn());
    }

    public function testReleaseAttributionDeploysAndSourceLinks(): void
    {
        $pdo = $this->makeDatabase()->pdo;
        $manager = new PluginManager($pdo);
        $manager->setEnabled('http_ingest', true);
        $manager->setEnabled('releases', true);
        $ingest = new IngestService($pdo);
        $ingest->ingest(['events' => [
            ['message' => 'Checkout total mismatch', 'severity' => 'ERROR', 'exception_class' => 'CheckoutException', 'release' => 'v2.3.0', 'module' => 'billing'],
            ['message' => 'Checkout total mismatch', 'severity' => 'ERROR', 'exception_class' => 'CheckoutException', 'release' => 'v2.4.0', 'module' => 'billing'],
        ]]);

        $releases = new ReleaseService($pdo);
        $groupId = (int) $pdo->query("SELECT id FROM error_groups WHERE exception_class='CheckoutException'")->fetchColumn();
        $forGroup = $releases->forGroup($groupId);
        self::assertSame('v2.3.0', $forGroup['first_release']);
        self::assertSame('v2.4.0', $forGroup['last_release']);

        $deploy = $releases->createDeploy(['version' => 'v2.4.0', 'environment' => 'production', 'ref' => 'abc123']);
        self::assertSame('v2.4.0', $deploy['version']);
        $row = array_values(array_filter($releases->deploys(), static fn (array $r): bool => $r['version'] === 'v2.4.0'))[0] ?? null;
        self::assertSame(1, (int) $row['issue_count']);

        try {
            $releases->createDeploy(['version' => 'v2.4.0', 'environment' => 'production']);
            self::fail('A duplicate deploy was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('already exists', $exception->getMessage());
        }

        $releases->updateSettings(['repo_url_template' => 'https://github.com/acme/app/blob/{ref}/{path}#L{line}', 'default_ref' => 'main']);
        self::assertSame('https://github.com/acme/app/blob/main/app/Services/Checkout.php#L42', $releases->sourceLink('app/Services/Checkout.php:42'));
        self::assertNull($releases->sourceLink(''));

        // Route gating.
        Config::load(['auth' => ['token' => '']]);
        self::assertSame(200, $this->controller($pdo)->handle(new LogLensRequest('GET', ['api' => 'deploys'], [], ['host' => 'log-lens.test']))->status);
        $manager->setEnabled('releases', false);
        self::assertSame(404, $this->controller($pdo)->handle(new LogLensRequest('GET', ['api' => 'deploys'], [], ['host' => 'log-lens.test']))->status);
    }

    public function testIngestTimeSourceMapResolution(): void
    {
        $pdo = $this->makeDatabase()->pdo;
        $manager = new PluginManager($pdo);
        $manager->setEnabled('http_ingest', true);
        $manager->setEnabled('releases', true);
        (new SourceMapService($pdo))->upload([
            'release' => 'v9.0.0',
            'file' => 'https://app.test/assets/app.4f2a.js',
            'map' => '{"version":3,"sources":["Checkout.tsx"],"names":["submitOrder"],"mappings":"AASIA"}',
        ]);
        (new IngestService($pdo))->ingest(['events' => [[
            'message' => 'Minified browser crash', 'severity' => 'ERROR', 'channel' => 'browser',
            'release' => 'v9.0.0', 'stack' => 'at submitOrder (https://app.test/assets/app.4f2a.js:1:1)',
        ]]]);
        $stack = $pdo->query("SELECT sample_stack FROM error_groups WHERE title='Minified browser crash'")->fetchColumn();
        self::assertStringContainsString('Checkout.tsx:10:5', (string) $stack, 'Ingest did not de-minify the browser stack.');
    }

    public function testGenericPhpClientBatchesEvents(): void
    {
        $captured = [];
        $client = new LogLensClient(
            'https://logs.example.test/?api=ingest&app=api',
            'llk_test_key',
            ['environment' => 'staging', 'release' => 'v9.0.1', 'channel' => 'worker'],
            static function (string $url, string $body) use (&$captured): void { $captured[] = json_decode($body, true); },
        );
        $client->captureException(new RuntimeException('Boom in the queue worker'), ['tags' => ['queue'], 'fingerprint' => 'boom-1']);
        $client->captureMessage('Backlog is high', 'WARNING');
        $client->flush();
        self::assertCount(1, $captured, 'Buffered events batch into one request.');

        $events = $captured[0]['events'] ?? [];
        self::assertCount(2, $events);
        self::assertSame('RuntimeException', $events[0]['exception_class']);
        self::assertSame('staging', $events[0]['environment']);
        self::assertSame(['queue'], $events[0]['tags']);
        self::assertSame('boom-1', $events[0]['fingerprint']);
        self::assertSame('WARNING', $events[1]['severity']);

        $client->flush();
        self::assertCount(1, $captured, 'Flushing an empty buffer sends nothing.');
    }
}
