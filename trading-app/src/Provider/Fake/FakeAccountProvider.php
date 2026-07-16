<?php

declare(strict_types=1);

namespace App\Provider\Fake;

use App\Common\Enum\PositionSide;
use App\Contract\Provider\AccountProviderInterface;
use App\Contract\Provider\Dto\AccountDto;
use App\Contract\Provider\Dto\PositionDto;
use App\Exchange\Adapter\FakeExchangeAdapter;
use App\Exchange\Dto\ExchangeBalanceDto;
use App\Exchange\Dto\ExchangePositionDto;
use App\Exchange\Enum\ExchangePositionSide;
use Brick\Math\BigDecimal;

/**
 * Read-only legacy account projection backed by the canonical Fake adapter.
 */
final readonly class FakeAccountProvider implements AccountProviderInterface
{
    public function __construct(private FakeExchangeAdapter $adapter)
    {
    }

    public function getAccountInfo(): ?AccountDto
    {
        $balance = $this->balance('USDT');
        if ($balance === null) {
            return null;
        }

        $usedMargin = $balance->metadata['used_margin_usdt'] ?? 0.0;
        if (!is_numeric($usedMargin)) {
            throw new \LogicException('fake_used_margin_metadata_invalid');
        }

        return new AccountDto(
            currency: $balance->currency,
            availableBalance: BigDecimal::of((string) $balance->available),
            frozenBalance: BigDecimal::of((string) $usedMargin),
            unrealized: BigDecimal::of((string) ($balance->unrealizedPnl ?? 0.0)),
            equity: BigDecimal::of((string) ($balance->equity ?? $balance->total ?? $balance->available)),
            positionDeposit: BigDecimal::of((string) $usedMargin),
            metadata: [
                'source' => 'fake_exchange',
                'margin_model_version' => $balance->metadata['margin_model_version'] ?? null,
            ],
        );
    }

    public function getAccountBalance(string $basicCurrency = 'USDT'): float
    {
        return $this->balance(strtoupper($basicCurrency))?->available ?? 0.0;
    }

    /** @return list<PositionDto> */
    public function getOpenPositions(?string $symbol = null): array
    {
        return array_map($this->position(...), $this->adapter->getOpenPositions($symbol));
    }

    /** @return list<PositionDto> */
    public function getOpenPositionsOrFail(?string $symbol = null): array
    {
        return $this->getOpenPositions($symbol);
    }

    public function getPosition(string $symbol): ?PositionDto
    {
        $positions = $this->getOpenPositions($symbol);
        if (\count($positions) > 1) {
            throw new \LogicException(sprintf('Multiple open Fake positions for %s', $symbol));
        }

        return $positions[0] ?? null;
    }

    /**
     * Fill history remains an explicit read-only gap for Task4.
     *
     * @return array<int, mixed>
     */
    public function getTradeHistory(string $symbol, int $limit = 100): array
    {
        return [];
    }

    /** @return array<int, mixed> */
    public function getTrades(?string $symbol = null, int $limit = 100, ?int $startTime = null, ?int $endTime = null): array
    {
        return [];
    }

    /** @return array<int, mixed> */
    public function getTransactionHistory(?string $symbol = null, ?int $flowType = null, int $limit = 100, ?int $startTime = null, ?int $endTime = null): array
    {
        return [];
    }

    /** @return array{exchange:string,symbol:string,fee_currency:string,fee_model:string,maker:float,taker:float} */
    public function getTradingFees(string $symbol): array
    {
        $model = $this->adapter->runtimeModelMetadata();

        return [
            'exchange' => 'fake',
            'symbol' => strtoupper($symbol),
            'fee_currency' => 'USDT',
            'fee_model' => $model['fee_model'],
            'maker' => $model['fee_rate'],
            'taker' => $model['fee_rate'],
        ];
    }

    public function healthCheck(): bool
    {
        return $this->balance('USDT') !== null;
    }

    public function getProviderName(): string
    {
        return 'Fake';
    }

    private function balance(string $currency): ?ExchangeBalanceDto
    {
        foreach ($this->adapter->getBalances() as $balance) {
            if ($balance->currency === $currency) {
                return $balance;
            }
        }

        return null;
    }

    private function position(ExchangePositionDto $position): PositionDto
    {
        return new PositionDto(
            symbol: $position->symbol,
            side: $position->side === ExchangePositionSide::LONG ? PositionSide::LONG : PositionSide::SHORT,
            size: BigDecimal::of((string) $position->size),
            entryPrice: BigDecimal::of((string) $position->entryPrice),
            markPrice: BigDecimal::of((string) ($position->markPrice ?? 0.0)),
            unrealizedPnl: BigDecimal::of((string) ($position->unrealizedPnl ?? 0.0)),
            realizedPnl: BigDecimal::of((string) ($position->realizedPnl ?? 0.0)),
            margin: BigDecimal::of((string) ($position->margin ?? 0.0)),
            leverage: BigDecimal::of((string) ($position->leverage ?? 1.0)),
            openedAt: $position->openedAt ?? new \DateTimeImmutable('@0', new \DateTimeZone('UTC')),
            metadata: [
                'source' => 'fake_exchange',
                'updated_at' => $position->updatedAt?->format(\DateTimeInterface::ATOM),
            ],
        );
    }
}
