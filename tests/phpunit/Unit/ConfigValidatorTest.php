<?php
declare(strict_types=1);

namespace LogLens\Tests\Unit;

use LogLens\Config;
use LogLens\Support\ConfigValidator;
use LogLens\Tests\TestCase;

final class ConfigValidatorTest extends TestCase
{
    public function testWellFormedConfigProducesNoWarnings(): void
    {
        Config::load([
            'auth' => ['token' => 'secret'],
            'ingest' => ['rate_max_events' => 100, 'trust_forwarded_for' => true],
            'retention' => ['occurrences_max_age_days' => 30],
        ]);
        self::assertSame([], ConfigValidator::validate());
    }

    public function testUnknownTopLevelKeyIsFlagged(): void
    {
        Config::load(['retenton' => ['occurrences_max_age_days' => 30]]); // typo
        $warnings = ConfigValidator::validate();
        self::assertNotEmpty($warnings);
        self::assertStringContainsString('retenton', implode("\n", $warnings));
    }

    public function testWrongScalarTypeIsFlagged(): void
    {
        Config::load(['ingest' => ['rate_max_events' => 'lots']]);
        $warnings = ConfigValidator::validate();
        self::assertStringContainsString('ingest.rate_max_events', implode("\n", $warnings));
    }

    public function testNumericStringForIntIsAccepted(): void
    {
        // .env values arrive as strings; a numeric string is valid for an int.
        Config::load(['database' => ['busy_timeout' => '5000']]);
        self::assertSame([], ConfigValidator::validate());
    }
}
