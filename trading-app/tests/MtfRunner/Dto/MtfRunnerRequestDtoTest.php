<?php

declare(strict_types=1);

namespace App\Tests\MtfRunner\Dto;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\MtfRunner\Dto\MtfRunnerRequestDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MtfRunnerRequestDto::class)]
final class MtfRunnerRequestDtoTest extends TestCase
{
    public function testAcceptsFakeExchangeContext(): void
    {
        $dto = MtfRunnerRequestDto::fromArray([
            'exchange' => 'fake',
            'market_type' => 'perpetual',
        ]);

        self::assertSame(Exchange::FAKE, $dto->exchange);
        self::assertSame(MarketType::PERPETUAL, $dto->marketType);
        self::assertSame('fake', $dto->toArray()['exchange']);
    }
}
