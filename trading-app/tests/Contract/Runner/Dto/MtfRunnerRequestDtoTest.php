<?php

declare(strict_types=1);

namespace App\Tests\Contract\Runner\Dto;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Contract\Runner\Dto\MtfRunnerRequestDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MtfRunnerRequestDto::class)]
final class MtfRunnerRequestDtoTest extends TestCase
{
    public function testAcceptsFakeExchangeContext(): void
    {
        $dto = MtfRunnerRequestDto::fromArray([
            'cex' => 'fake',
            'type_contract' => 'perp',
        ]);

        self::assertSame(Exchange::FAKE, $dto->exchange);
        self::assertSame(MarketType::PERPETUAL, $dto->marketType);
        self::assertSame('fake', $dto->toArray()['exchange']);
    }
}
