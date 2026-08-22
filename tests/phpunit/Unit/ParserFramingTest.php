<?php
declare(strict_types=1);

namespace LogLens\Tests\Unit;

use LogLens\Domain\LogEvent;
use LogLens\Parsing\GenericConsoleLogParser;
use LogLens\Parsing\HorizonLogParser;
use LogLens\Parsing\LaravelLogParser;
use LogLens\Parsing\ParserRegistry;
use LogLens\Services\EventAnalyzer;
use LogLens\Tests\TestCase;

/**
 * Which parser claims a file, and what it makes of the lines inside it.
 *
 * Every case here is a silent-zero-events or wrong-severity failure found by
 * running the shipped parsers over real log framings: the failures produced no
 * error, so only an assertion on the parsed output catches them.
 */
final class ParserFramingTest extends TestCase
{
    /**
     * A bare-level console line ("<time> ERROR <message>") keeps its level.
     *
     * PCRE reports a named group the pattern could have matched but did not as
     * an empty string, so coalescing the level alternatives with `??` always
     * picked the empty first one and graded every line INFO — which the default
     * ERROR/WARNING severities then dropped, ingesting the file to nothing.
     */
    public function testBareLevelConsoleLineKeepsItsSeverity(): void
    {
        $path = $this->writeFile('worker.log', <<<'LOG'
        2026-08-20T10:15:30-07:00 ERROR  redis: connection refused (127.0.0.1:6379)
        2026-08-20T10:16:00-07:00 WARN   queue depth 41200 exceeds soft limit 20000
        2026-08-20T10:17:12-07:00 INFO   drained 41200 jobs in 72s
        LOG);

        $events = $this->events((new GenericConsoleLogParser())->parse($path));

        self::assertSame(['ERROR', 'WARNING', 'INFO'], array_column($events, 'severity'));
        // The offsets are real: -07:00 is 17:xx UTC, not the 10:xx wall clock.
        self::assertSame('2026-08-20 17:15:30', $events[0]['occurred_at']);
    }

    /** A bracketed-level console line still works — the other alternative. */
    public function testBracketedLevelConsoleLineKeepsItsSeverity(): void
    {
        $path = $this->writeFile('supervisor.log', "2026-08-20 10:15:30 [ERROR] worker exited with 137\n");

        $events = $this->events((new GenericConsoleLogParser())->parse($path));

        self::assertSame('ERROR', $events[0]['severity']);
        self::assertSame('worker exited with 137', $events[0]['body']);
    }

    /** `queue:work`'s bracketed framing: the verb is the level. */
    public function testHorizonBracketedFramingGradesFailuresAsErrors(): void
    {
        $path = $this->writeFile('horizon.log', <<<'LOG'
        [2026-08-20 03:00:01][7f3a1c2e] Processing: App\Jobs\SyncCustomer
        [2026-08-20 03:00:02][7f3a1c2e] Failed:     App\Jobs\SyncCustomer
        [2026-08-20 03:00:02][7f3a1c2e] RuntimeException: Customer API returned 503 in /app/app/Jobs/SyncCustomer.php:57
        Stack trace:
        #0 /app/vendor/laravel/framework/src/Illuminate/Queue/CallQueuedHandler.php(51): App\Jobs\SyncCustomer->handle()
        LOG);

        $parser = (new ParserRegistry())->forFile($path);
        $events = $this->events($parser->parse($path));

        self::assertSame('horizon', $parser->type());
        self::assertSame(['INFO', 'ERROR', 'ERROR'], array_column($events, 'severity'));
        self::assertSame('2026-08-20 03:00:01', $events[0]['occurred_at']);
        // The trace lines belong to the exception above them, not to events of
        // their own — otherwise the failure loses what explains it.
        self::assertStringContainsString('CallQueuedHandler.php(51)', $events[2]['body']);
    }

    /** Horizon's modern progress framing: `<time> <job> ....... 33s FAIL`. */
    public function testHorizonProgressFramingGradesFailuresAsErrors(): void
    {
        $path = $this->writeFile('horizon-out.log', <<<'LOG'
        2026-08-20 04:00:00 App\Jobs\SyncCustomer ................................ RUNNING
        2026-08-20 04:00:01 App\Jobs\SyncCustomer .................................. 812ms DONE
        2026-08-20 04:05:33 App\Jobs\RebuildSearchIndex ............................. 33s FAIL
        LOG);

        $parser = (new ParserRegistry())->forFile($path);
        $events = $this->events($parser->parse($path));

        self::assertSame('horizon', $parser->type());
        self::assertSame(['INFO', 'INFO', 'ERROR'], array_column($events, 'severity'));
        self::assertStringContainsString('RebuildSearchIndex', $events[2]['body']);
    }

    /** A horizon file written through the logging stack is still Laravel-framed. */
    public function testLaravelFramedHorizonFileKeepsTheHorizonType(): void
    {
        $path = $this->writeFile('horizon.log', "[2026-08-20 03:00:02] production.ERROR: Job failed after 3 tries\n");

        $parser = (new ParserRegistry())->forFile($path);
        $events = $this->events($parser->parse($path));

        self::assertInstanceOf(LaravelLogParser::class, $parser);
        self::assertSame('horizon', $parser->type());
        self::assertSame('ERROR', $events[0]['severity']);
    }

    /**
     * A horizon-named file in neither framing falls through instead of
     * parsing to nothing.
     *
     * `LaravelLogParser('horizon')` used to claim any horizon-named file on its
     * name alone; when the header then matched no line, the import reported a
     * file with zero events and no later parser ever saw it.
     */
    public function testUnframedHorizonFileFallsThroughToTheConsoleParser(): void
    {
        $path = $this->writeFile('horizon.log', "2026-08-20 03:00:02 ERROR supervisor restarted the pool\n");

        $parser = (new ParserRegistry())->forFile($path);
        $events = $this->events($parser->parse($path));

        self::assertInstanceOf(GenericConsoleLogParser::class, $parser);
        self::assertSame('ERROR', $events[0]['severity']);
    }

    public function testHorizonParserDoesNotClaimANonHorizonFile(): void
    {
        $path = $this->writeFile('worker.log', "[2026-08-20 03:00:01][7f3a1c2e] Failed: App\\Jobs\\Thing\n");

        self::assertFalse((new HorizonLogParser())->supports($path, (string) file_get_contents($path)));
    }

    /**
     * The culprit frame is the first non-library frame.
     *
     * The old rule was the first frame containing '/app/', which every
     * framework frame of a container-rooted Laravel app also matches
     * (/app/vendor/laravel/…). Because the frame feeds the fingerprint, the
     * same error entering the framework at two different depths split into two
     * unrelated issues.
     */
    public function testCulpritFrameSkipsVendorFramesUnderAnAppRoot(): void
    {
        $analyzer = new EventAnalyzer();
        $deep = $analyzer->analyze($this->laravelEvent(<<<'BODY'
        SQLSTATE[23000]: Duplicate entry 'inv-1' for key 'invoices_reference_unique'
        [stacktrace]
        #0 /app/vendor/laravel/framework/src/Illuminate/Database/Connection.php(795): run()
        #1 /app/app/Services/InvoiceWriter.php(64): insert()
        BODY));
        $shallow = $analyzer->analyze($this->laravelEvent(<<<'BODY'
        SQLSTATE[23000]: Duplicate entry 'inv-2' for key 'invoices_reference_unique'
        [stacktrace]
        #0 /app/app/Services/InvoiceWriter.php(64): insert()
        BODY));

        self::assertSame('/app/app/Services/InvoiceWriter.php:64', $deep->sourceFrame);
        self::assertSame('/app/app/Services/InvoiceWriter.php:64', $shallow->sourceFrame);
        // Which is what makes the two occurrences one issue: the fingerprint is
        // built from the normalized title and this frame.
        self::assertSame(
            $analyzer->normalize($deep->sourceFrame),
            $analyzer->normalize($shallow->sourceFrame),
        );
        self::assertSame($analyzer->normalize($deep->title), $analyzer->normalize($shallow->title));
    }

    /** With no application frame at all, the first frame is still the culprit. */
    public function testCulpritFrameFallsBackToTheFirstFrameWhenAllAreVendor(): void
    {
        $analysis = (new EventAnalyzer())->analyze($this->laravelEvent(<<<'BODY'
        Something broke inside the framework
        [stacktrace]
        #0 /app/vendor/laravel/framework/src/Illuminate/Foundation/Application.php(1): boot()
        BODY));

        self::assertSame('/app/vendor/laravel/framework/src/Illuminate/Foundation/Application.php:1', $analysis->sourceFrame);
    }

    private function laravelEvent(string $body): LogEvent
    {
        return new LogEvent(0, strlen($body), '2026-08-20 09:14:02', 'ERROR', 'production', $body, 'laravel', 'laravel.log');
    }

    /**
     * @param iterable<LogEvent> $events
     * @return list<array{severity:string,occurred_at:string,body:string}>
     */
    private function events(iterable $events): array
    {
        $rows = [];
        foreach ($events as $event) {
            $rows[] = [
                'severity' => $event->severity,
                'occurred_at' => $event->occurredAt,
                'body' => $event->body,
            ];
        }
        return $rows;
    }
}
