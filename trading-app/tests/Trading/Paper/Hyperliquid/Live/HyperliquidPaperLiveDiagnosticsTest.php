<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Hyperliquid\Live;

use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperLiveDiagnostics;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HyperliquidPaperLiveDiagnostics::class)]
final class HyperliquidPaperLiveDiagnosticsTest extends TestCase
{
    public function testTextMasksPathsAndSecretsAndStripsControlCharacters(): void
    {
        self::assertNull(HyperliquidPaperLiveDiagnostics::text(null));
        self::assertSame('going away', HyperliquidPaperLiveDiagnostics::text('going away'));
        self::assertSame('a b c', HyperliquidPaperLiveDiagnostics::text("a\x00b\r\nc"));
        self::assertSame(
            'open failed: [path] (errno 13)',
            HyperliquidPaperLiveDiagnostics::text('open failed: /home/trader/.env (errno 13)'),
        );
        self::assertSame(
            'bad [redacted] [redacted] x',
            HyperliquidPaperLiveDiagnostics::text('bad api_key=1 password: x'),
        );
        self::assertSame(
            'Connection to tcp:[path]:443 failed: Connection timed out',
            HyperliquidPaperLiveDiagnostics::text(
                'Connection to tcp://api.hyperliquid.xyz:443 failed: Connection timed out',
            ),
        );
    }

    public function testTextIsBoundedAndAlwaysValidUtf8(): void
    {
        $bounded = HyperliquidPaperLiveDiagnostics::text(str_repeat('é', 400));
        self::assertIsString($bounded);
        self::assertLessThanOrEqual(512 + \strlen('…'), \strlen($bounded));
        self::assertStringEndsWith('…', $bounded);
        self::assertTrue(mb_check_encoding($bounded, 'UTF-8'));

        $scrubbed = HyperliquidPaperLiveDiagnostics::text("\xff\xfe close");
        self::assertIsString($scrubbed);
        self::assertTrue(mb_check_encoding($scrubbed, 'UTF-8'));
        self::assertStringEndsWith(' close', $scrubbed);
    }

    public function testExceptionKeepsClassCodeAndABoundedMaskedCauseChain(): void
    {
        $exception = new \RuntimeException(
            'socket /tmp/hl.sock reset',
            104,
            new \LogicException(
                'level 1',
                0,
                new \DomainException(
                    'level 2 token=abc',
                    0,
                    new \InvalidArgumentException('level 3', 0, new \Exception('level 4')),
                ),
            ),
        );

        self::assertSame([
            'exception_class' => \RuntimeException::class,
            'exception_message' => 'socket [path] reset',
            'exception_code' => 104,
            'exception_previous' => 'LogicException: level 1 <- DomainException: level 2 [redacted]'
                . ' <- InvalidArgumentException: level 3',
        ], HyperliquidPaperLiveDiagnostics::exception($exception));
        self::assertNull(
            HyperliquidPaperLiveDiagnostics::exception(new \RuntimeException('x'))['exception_previous'],
        );
    }
}
