<?php
declare(strict_types=1);

namespace LogLens\Tests\Unit;

use LogLens\Config;
use LogLens\Support\SecretBox;
use LogLens\Tests\TestCase;
use RuntimeException;

/**
 * SecretBox: real encryption when a deployment secret is present, a clearly
 * tagged plaintext fallback when it is not.
 */
final class SecretBoxTest extends TestCase
{
    public function testEncryptsAndRoundTripsUnderADeploymentKey(): void
    {
        Config::load(['linear' => ['secret' => 'deployment-secret']]);
        $secured = SecretBox::encrypt('linear-api-key');
        self::assertTrue(SecretBox::secured());
        self::assertStringStartsWith('sb1:', $secured);
        self::assertSame('linear-api-key', SecretBox::decrypt($secured));
    }

    public function testFallsBackToTaggedPlaintextWithoutAKey(): void
    {
        Config::load(['linear' => []]);
        $plain = SecretBox::encrypt('linear-api-key');
        self::assertFalse(SecretBox::secured());
        self::assertStringStartsWith('plain:', $plain);
        self::assertSame('linear-api-key', SecretBox::decrypt($plain));
    }

    public function testDecryptingCiphertextWithoutTheKeyFailsClearly(): void
    {
        Config::load(['linear' => ['secret' => 'deployment-secret']]);
        $secured = SecretBox::encrypt('linear-api-key');
        Config::load(['linear' => []]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/LOG_LENS_SECRET/');
        SecretBox::decrypt($secured);
    }
}
