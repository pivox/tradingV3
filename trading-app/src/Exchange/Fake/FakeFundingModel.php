<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

use App\Exchange\Dto\ExchangeFundingDto;
use App\Exchange\Dto\ExchangePositionDto;
use App\Exchange\Enum\ExchangePositionSide;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Psr\Clock\ClockInterface;

final readonly class FakeFundingModel
{
    public function __construct(
        private FakeFundingModelConfig $config,
        private ClockInterface $clock,
    ) {
    }

    public function calculate(
        FakeFundingSchedule $schedule,
        ?ExchangePositionDto $position,
    ): FakeFundingResult {
        if ($schedule->dueAt > $this->clock->now()) {
            throw new \LogicException('fake_funding_deadline_not_reached');
        }
        if ($schedule->fundingRate === null) {
            return new FakeFundingResult('unknown', null);
        }
        if (!$position instanceof ExchangePositionDto) {
            return new FakeFundingResult('no_position', null);
        }
        if (
            $position->symbol !== $schedule->symbol
            || $position->side !== $schedule->side
            || $position->size <= 0.0
        ) {
            return new FakeFundingResult('no_position', null);
        }
        if ($position->markPrice === null || !\is_finite($position->markPrice) || $position->markPrice <= 0.0) {
            throw new \LogicException('fake_funding_mark_price_unknown');
        }

        $contractSize = $position->metadata['margin_contract_size'] ?? '1';
        if (!\is_int($contractSize) && !\is_float($contractSize) && !\is_string($contractSize)) {
            throw new \LogicException('fake_funding_contract_size_unknown');
        }

        try {
            $contractSizeDecimal = BigDecimal::of((string) $contractSize);
            if (!$contractSizeDecimal->isGreaterThan(BigDecimal::zero())) {
                throw new \LogicException('fake_funding_contract_size_unknown');
            }
            $notional = BigDecimal::of((string) abs($position->size))
                ->multipliedBy((string) $position->markPrice)
                ->multipliedBy($contractSizeDecimal)
                ->toScale($this->config->amountScale, RoundingMode::HALF_EVEN);
            $amount = $notional
                ->multipliedBy($schedule->fundingRate)
                ->multipliedBy((string) $schedule->appliedIntervalSeconds)
                ->dividedBy((string) $schedule->rateIntervalSeconds, $this->config->amountScale, RoundingMode::HALF_EVEN);
            if ($position->side === ExchangePositionSide::LONG) {
                $amount = $amount->negated();
            }
            $amount = $amount->toScale($this->config->amountScale, RoundingMode::HALF_EVEN);
        } catch (\LogicException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new \InvalidArgumentException('fake_funding_decimal_invalid', 0, $exception);
        }

        $positionId = $this->positionId($position);
        $currency = strtoupper($schedule->currency);
        $amountValue = (string) $amount;

        return new FakeFundingResult('applied', new ExchangeFundingDto(
            exchange: $position->exchange,
            marketType: $position->marketType,
            symbol: $position->symbol,
            positionSide: $position->side,
            positionId: $positionId,
            internalTradeId: $this->metadataString($position, 'internal_trade_id'),
            internalPositionId: $this->metadataString($position, 'internal_position_id'),
            notional: (string) $notional,
            fundingRate: (string) BigDecimal::of($schedule->fundingRate),
            rateIntervalSeconds: $schedule->rateIntervalSeconds,
            appliedIntervalSeconds: $schedule->appliedIntervalSeconds,
            amount: $amountValue,
            currency: $currency,
            amountUsdt: $this->config->isUsdtCurrency($currency) ? $amountValue : null,
            dueAt: $schedule->dueAt,
            source: 'fake_funding_model',
            modelVersion: $this->config->modelVersion,
            metadata: ['position_opened_at' => $position->openedAt?->format(\DateTimeInterface::ATOM)],
        ));
    }

    public function settle(
        FakeFundingSchedule $schedule,
        ?ExchangePositionDto $position,
        FakeExchangeStateStore $stateStore,
    ): FakeFundingResult {
        $result = $this->calculate($schedule, $position);
        if (!$result->funding instanceof ExchangeFundingDto) {
            return $result;
        }

        $funding = $result->funding;
        $identityHash = hash('sha256', json_encode([
            'position_id' => $funding->positionId,
            'due_at' => $funding->dueAt->format(\DateTimeInterface::ATOM),
            'model_version' => $funding->modelVersion,
        ], \JSON_THROW_ON_ERROR));
        $payload = [
            'exchange' => $funding->exchange->value,
            'market_type' => $funding->marketType->value,
            'position_id' => $funding->positionId,
            'internal_trade_id' => $funding->internalTradeId,
            'internal_position_id' => $funding->internalPositionId,
            'position_side' => $funding->positionSide->value,
            'notional' => $funding->notional,
            'funding_rate' => $funding->fundingRate,
            'rate_interval_seconds' => $funding->rateIntervalSeconds,
            'applied_interval_seconds' => $funding->appliedIntervalSeconds,
            'amount' => $funding->amount,
            'currency' => $funding->currency,
            'amount_usdt' => $funding->amountUsdt,
            'due_at' => $funding->dueAt->format(\DateTimeInterface::ATOM),
            'source' => $funding->source,
            'model_version' => $funding->modelVersion,
            'metadata' => $funding->metadata,
            'funding_idempotency_key' => sprintf(
                '%s:%s:funding:%s',
                $funding->exchange->value,
                $funding->marketType->value,
                $identityHash,
            ),
        ];
        $payload['funding_payload_hash'] = hash('sha256', json_encode($payload, \JSON_THROW_ON_ERROR));
        $inserted = $stateStore->appendFundingEventOnce(new FakeExchangeEvent(
            'funding.accrued',
            $funding->symbol,
            $funding->dueAt,
            $payload,
        ));

        return new FakeFundingResult($result->status, $funding, replayed: !$inserted);
    }

    private function positionId(ExchangePositionDto $position): string
    {
        foreach (['position_id', 'internal_position_id'] as $key) {
            $value = $this->metadataString($position, $key);
            if ($value !== null) {
                return $value;
            }
        }
        if (!$position->openedAt instanceof \DateTimeImmutable) {
            throw new \LogicException('fake_funding_position_identity_unknown');
        }

        return 'fake-position-' . substr(hash('sha256', implode(':', [
            $position->exchange->value,
            $position->marketType->value,
            $position->symbol,
            $position->side->value,
            $position->openedAt->format('U.u'),
        ])), 0, 48);
    }

    private function metadataString(ExchangePositionDto $position, string $key): ?string
    {
        $value = $position->metadata[$key] ?? null;
        if (!\is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
