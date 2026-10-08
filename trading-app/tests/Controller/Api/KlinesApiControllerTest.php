<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Common\Enum\Timeframe;
use App\Contract\Provider\Dto\KlineDto;
use App\Contract\Provider\KlineProviderInterface;
use App\Controller\Api\KlinesApiController;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(KlinesApiController::class)]
final class KlinesApiControllerTest extends TestCase
{
    public function testStartAndEndSelectTheWindowAndLimitIsCapped(): void
    {
        $provider = $this->createMock(KlineProviderInterface::class);
        $provider->expects(self::never())->method('getKlines');
        $provider->expects(self::once())->method('getKlinesInWindow')->with(
            'BTCUSDT',
            Timeframe::TF_1H,
            self::callback(static fn (\DateTimeImmutable $d): bool => $d->getTimestamp() === 1_700_000_000),
            self::callback(static fn (\DateTimeImmutable $d): bool => $d->getTimestamp() === 1_700_036_000),
            500,
        )->willReturn([$this->kline()]);

        $response = (new KlinesApiController($provider))->getKlines(new Request([
            'symbol' => 'BTCUSDT',
            'interval' => '1h',
            'limit' => '9999',
            'start' => '1700000000000',
            'end' => '2023-11-15T08:13:20Z',
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, json_decode((string) $response->getContent(), true));
    }

    public function testWithoutStartUsesLatestKlines(): void
    {
        $provider = $this->createMock(KlineProviderInterface::class);
        $provider->expects(self::once())->method('getKlines')->with('BTCUSDT', Timeframe::TF_5M, 100)->willReturn([]);
        $provider->expects(self::never())->method('getKlinesInWindow');

        $response = (new KlinesApiController($provider))->getKlines(new Request(['symbol' => 'BTCUSDT']));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testInvalidOrOrphanRangeIsRejected(): void
    {
        $controller = new KlinesApiController($this->createMock(KlineProviderInterface::class));

        foreach ([
            ['start' => 'garbage'],
            ['start' => '2000', 'end' => '1000'],
            ['end' => '1000'],
        ] as $range) {
            $response = $controller->getKlines(new Request(['symbol' => 'BTCUSDT'] + $range));
            self::assertSame(400, $response->getStatusCode());
        }
    }

    private function kline(): KlineDto
    {
        return new KlineDto(
            'BTCUSDT',
            Timeframe::TF_1H,
            new \DateTimeImmutable('@1700000000'),
            BigDecimal::of('1'),
            BigDecimal::of('2'),
            BigDecimal::of('0.5'),
            BigDecimal::of('1.5'),
            BigDecimal::of('10'),
        );
    }
}
