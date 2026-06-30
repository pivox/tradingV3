<?php

declare(strict_types=1);

namespace App\Tests\Contract\Runner\Dto;

use App\Common\Enum\Exchange;
use App\Contract\Runner\Dto\MtfRunnerRequestDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MtfRunnerRequestDto::class)]
final class MtfRunnerRequestDtoTest extends TestCase
{
    public function testFromArrayAcceptsOkxExchange(): void
    {
        $request = MtfRunnerRequestDto::fromArray([
            'exchange' => ' OKX ',
            'dry_run' => true,
            'symbols' => ['BTCUSDT'],
        ]);

        self::assertSame(Exchange::OKX, $request->exchange);
        self::assertTrue($request->dryRun);
        self::assertSame(['BTCUSDT'], $request->symbols);
    }
}
