<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Okx\Live;

use App\Trading\Paper\Okx\Live\OkxPaperLiveDiagnostics;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OkxPaperLiveDiagnostics::class)]
final class OkxPaperLiveDiagnosticsTest extends TestCase
{
    public function testFramesAreMaskedLikeHyperliquidAndBoundedToThreeKibibytes(): void
    {
        $frame = OkxPaperLiveDiagnostics::frame(
            "{\"event\":\"mystery\"}\n /srv/app/var/checkpoint.json api_key=abc " . str_repeat('a', 5_000),
        );

        self::assertIsString($frame);
        self::assertStringStartsWith('{"event":"mystery"}  [path] [redacted] ', $frame);
        self::assertStringNotContainsString('/srv/app', $frame);
        self::assertStringNotContainsString('abc', $frame);
        self::assertStringEndsWith('…', $frame);
        self::assertSame(3_072 + \strlen('…'), \strlen($frame));
        self::assertNull(OkxPaperLiveDiagnostics::frame(null));
    }

    public function testExceptionsKeepTheirRaiseSiteAndBoundedCauseChain(): void
    {
        $causeLine = __LINE__ + 1;
        $cause = new \RuntimeException('cause at /srv/app/private.php');
        $exceptionLine = __LINE__ + 1;
        $exception = new \LogicException('okx_paper_public_message_invalid', 7, $cause);

        $diagnostics = OkxPaperLiveDiagnostics::exception($exception);

        self::assertSame(\LogicException::class, $diagnostics['exception_class']);
        self::assertSame('okx_paper_public_message_invalid', $diagnostics['exception_message']);
        self::assertSame(7, $diagnostics['exception_code']);
        self::assertSame('OkxPaperLiveDiagnosticsTest.php:' . $exceptionLine, $diagnostics['exception_site']);
        self::assertSame(
            \RuntimeException::class . ': cause at [path] @OkxPaperLiveDiagnosticsTest.php:' . $causeLine,
            $diagnostics['exception_previous'],
        );
    }
}
