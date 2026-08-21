<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Fake;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Dto\ExchangeBalanceDto;
use App\Exchange\Dto\ExchangeOrderDto;
use App\Exchange\Enum\ExchangeOrderStatus;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Fake\FakeAccountLedgerOrigin;
use App\Exchange\Fake\FakeExchangeEvent;
use App\Exchange\Fake\FakeExchangePrivateStateSnapshot;
use App\Exchange\Fake\FakeMonetaryLedgerProjector;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanDecimal;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioDecimal;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioPolicy;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioScope;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioSnapshot;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Psr\Clock\ClockInterface;

final readonly class PaperCanonicalFakePortfolioSource
{
    private const SOURCE = 'paper_canonical_fake_private_portfolio';
    private const SOURCE_VERSION = '1.0.0';
    private const SCALE = 12;

    public function __construct(
        private ClockInterface $clock,
        private ?FakeMonetaryLedgerProjector $ledgerProjector = null,
    ) {
    }

    public function snapshot(
        PaperFakeRuntime $runtime,
        CanonicalPortfolioPolicy $policy,
    ): CanonicalPortfolioSnapshot {
        try {
            $cell = $runtime->cell;
            $identity = $cell->modernIdentity;
            if ($identity === null
                || $identity->modeId !== $policy->modeId
                || $identity->modeVersion !== $policy->modeVersion
                || $identity->setupId !== $policy->setupId
                || $identity->setupVersion !== $policy->setupVersion
                || $identity->side !== $policy->side
                || $identity->configHash !== $policy->configHash
                || $cell->marketDataVenue->value !== $policy->exchange
                || $cell->network->value !== $policy->environment
            ) {
                throw new \LogicException();
            }

            $observedAt = \DateTimeImmutable::createFromInterface($this->clock->now());
            [$dayStart, $dayEnd] = $this->policyDay($policy, $observedAt);
            $scope = new CanonicalPortfolioScope(
                $cell->network->value,
                $cell->marketDataVenue->value,
                $cell->network->value,
                $cell->accountNamespace,
                $identity->modeId,
                $policy->quoteCurrency,
            );
            $state = $runtime->stateStore->privateStateSnapshot();
            $origin = FakeAccountLedgerOrigin::fromBalance($this->quoteBalance($state, $policy->quoteCurrency));
            if ($origin->currency() !== $policy->quoteCurrency) {
                throw new \LogicException();
            }

            $projector = $this->ledgerProjector ?? new FakeMonetaryLedgerProjector();
            $this->assertMonetaryScope($state->events, $cell, $policy->quoteCurrency, $projector);
            $lifetime = $projector->project($state->events, $observedAt);
            $daily = $projector->project($state->events, $observedAt, $dayStart, $dayEnd);
            $active = $this->activeState($state, $cell, $policy->quoteCurrency);

            $openingBalance = BigDecimal::of($origin->openingBalance());
            $realizedLifetime = BigDecimal::of($lifetime->netUsdt);
            $realizedDaily = BigDecimal::of($daily->netUsdt);
            $unrealized = $active['unrealized'];
            $equity = $openingBalance
                ->plus($realizedLifetime)
                ->plus($unrealized)
                ->toScale(self::SCALE, RoundingMode::HALF_EVEN);
            if (!$equity->isPositive()) {
                throw new \LogicException();
            }

            $input = [
                'source' => self::SOURCE,
                'source_version' => self::SOURCE_VERSION,
                'scope' => $scope->toArray(),
                'policy_day_start' => self::time($dayStart),
                'policy_day_end' => self::time($dayEnd),
                'observed_at' => self::time($observedAt),
                'state_revision' => $state->stateRevision,
                'account_origin_hash' => $origin->identityHash(),
                'lifetime_ledger_hash' => $lifetime->inputHash,
                'daily_ledger_hash' => $daily->inputHash,
                'equity_quote' => (string) $equity,
                'realized_lifetime_quote' => (string) $realizedLifetime,
                'realized_daily_quote' => (string) $realizedDaily,
                'unrealized_quote' => (string) $unrealized,
                'open_notional_quote' => (string) $active['openNotional'],
                'pending_notional_quote' => (string) $active['pendingNotional'],
                'reserved_risk_quote' => (string) $active['reservedRisk'],
                'active_decision_keys' => $active['decisionKeys'],
                'active_records' => $active['records'],
            ];
            $inputHash = 'sha256:' . hash('sha256', CanonicalJson::encode($input));

            return new CanonicalPortfolioSnapshot(
                scope: $scope,
                source: self::SOURCE,
                sourceVersion: self::SOURCE_VERSION,
                policyDayStart: $dayStart,
                policyDayEnd: $dayEnd,
                observedAt: $observedAt,
                equityQuote: CanonicalPortfolioDecimal::toFiniteFloat($equity, 'paper_canonical_fake_portfolio_snapshot_invalid'),
                realizedNetPnlQuote: CanonicalPortfolioDecimal::toFiniteFloat($realizedDaily, 'paper_canonical_fake_portfolio_snapshot_invalid'),
                unrealizedNetPnlQuote: CanonicalPortfolioDecimal::toFiniteFloat($unrealized, 'paper_canonical_fake_portfolio_snapshot_invalid'),
                openPositions: $active['openPositions'],
                pendingEntries: $active['pendingEntries'],
                openNotionalQuote: CanonicalPortfolioDecimal::toFiniteFloat($active['openNotional'], 'paper_canonical_fake_portfolio_snapshot_invalid'),
                pendingNotionalQuote: CanonicalPortfolioDecimal::toFiniteFloat($active['pendingNotional'], 'paper_canonical_fake_portfolio_snapshot_invalid'),
                reservedRiskQuote: CanonicalPortfolioDecimal::toFiniteFloat($active['reservedRisk'], 'paper_canonical_fake_portfolio_snapshot_invalid'),
                activeDecisionKeys: $active['decisionKeys'],
                stateVersion: $state->stateRevision,
                inputHash: $inputHash,
            );
        } catch (\Throwable $exception) {
            if ($exception instanceof \LogicException
                && $exception->getMessage() === 'paper_canonical_fake_portfolio_snapshot_invalid'
            ) {
                throw $exception;
            }

            throw new \LogicException('paper_canonical_fake_portfolio_snapshot_invalid', 0, $exception);
        }
    }

    private function quoteBalance(
        FakeExchangePrivateStateSnapshot $state,
        string $quoteCurrency,
    ): ExchangeBalanceDto {
        $matches = array_values(array_filter(
            $state->balances,
            static fn (ExchangeBalanceDto $balance): bool => $balance->currency === $quoteCurrency,
        ));
        if (count($matches) !== 1) {
            throw new \LogicException();
        }

        return $matches[0];
    }

    /** @param list<FakeExchangeEvent> $events */
    private function assertMonetaryScope(
        array $events,
        \App\Trading\Paper\Execution\Identity\PaperExecutionCell $cell,
        string $quoteCurrency,
        FakeMonetaryLedgerProjector $projector,
    ): void {
        foreach ($events as $event) {
            if (!$projector->isMonetary($event)) {
                continue;
            }
            $metadata = $event->type === 'funding.accrued'
                ? $event->payload['metadata'] ?? null
                : ($event->payload['order_snapshot']['metadata'] ?? null);
            if (!\is_array($metadata)) {
                throw new \LogicException();
            }
            $encoded = $metadata[PaperCanonicalFakeReservationDescriptor::METADATA_KEY] ?? null;
            $instrumentEncoded = $metadata[PaperCanonicalFakeInstrumentDescriptor::METADATA_KEY] ?? null;
            $decisionKey = $metadata['decision_key'] ?? null;
            if (!\is_string($encoded) || !\is_string($instrumentEncoded) || !\is_string($decisionKey)) {
                throw new \LogicException();
            }
            $descriptor = PaperCanonicalFakeReservationDescriptor::decode($encoded)->assertCell($cell);
            $instrument = PaperCanonicalFakeInstrumentDescriptor::decode($instrumentEncoded);
            if (!hash_equals($descriptor->decisionKey(), $decisionKey)) {
                throw new \LogicException();
            }
            if (!hash_equals($instrument->cellId(), $cell->id)
                || !hash_equals($instrument->symbol(), $event->symbol)
                || !hash_equals($instrument->instrument()->quoteAsset, $quoteCurrency)
            ) {
                throw new \LogicException();
            }
        }
    }

    /**
     * @return array{
     *   openPositions:int,
     *   pendingEntries:int,
     *   openNotional:BigDecimal,
     *   pendingNotional:BigDecimal,
     *   reservedRisk:BigDecimal,
     *   unrealized:BigDecimal,
     *   decisionKeys:list<string>,
     *   records:list<array<string,mixed>>
     * }
     */
    private function activeState(
        FakeExchangePrivateStateSnapshot $state,
        \App\Trading\Paper\Execution\Identity\PaperExecutionCell $cell,
        string $quoteCurrency,
    ): array {
        $openNotional = BigDecimal::zero()->toScale(self::SCALE);
        $pendingNotional = BigDecimal::zero()->toScale(self::SCALE);
        $unrealized = BigDecimal::zero()->toScale(self::SCALE);
        $pendingEntries = 0;
        $openPositions = 0;
        /** @var array<string,array{encoded:string,descriptor:PaperCanonicalFakeReservationDescriptor}> $decisions */
        $decisions = [];
        /** @var array<string,bool> $pendingDecisions */
        $pendingDecisions = [];
        /** @var list<array<string,mixed>> $records */
        $records = [];
        /** @var list<string> $protectionScopes */
        $protectionScopes = [];
        /** @var array<string,bool> $positionScopes */
        $positionScopes = [];

        foreach ($state->orders as $order) {
            if (!$this->isActiveOrder($order)) {
                continue;
            }
            [$reservation, $instrument] = $this->descriptors($order->metadata, $order->symbol, $cell, $quoteCurrency);
            $this->registerDecision($decisions, $reservation);
            $remaining = $this->metadataDecimal($order->metadata, 'remaining_quantity_decimal');
            if (!$remaining->isPositive()
                || !$remaining->isEqualTo($this->decimal($order->remainingQuantity))
            ) {
                throw new \LogicException();
            }
            $record = [
                'kind' => 'order',
                'order_id' => $order->exchangeOrderId,
                'symbol' => $order->symbol,
                'status' => $order->status->value,
                'reduce_only' => $order->reduceOnly,
                'remaining_quantity' => (string) $remaining,
                'reservation_hash' => $reservation->identityHash(),
                'instrument_hash' => $instrument->identityHash(),
            ];
            if (!$order->reduceOnly) {
                if (isset($pendingDecisions[$reservation->decisionKey()])) {
                    throw new \LogicException();
                }
                $pendingDecisions[$reservation->decisionKey()] = true;
                ++$pendingEntries;
                $price = $this->metadataDecimal($order->metadata, 'price_decimal');
                if (!$price->isPositive() || $order->price === null || !$price->isEqualTo($this->decimal($order->price))) {
                    throw new \LogicException();
                }
                $notional = $remaining
                    ->multipliedBy($price)
                    ->multipliedBy($instrument->instrument()->contractSize)
                    ->toScale(self::SCALE, RoundingMode::HALF_EVEN);
                $pendingNotional = $pendingNotional->plus($notional)->toScale(self::SCALE, RoundingMode::HALF_EVEN);
                $record['price'] = (string) $price;
                $record['notional'] = (string) $notional;
            } else {
                if ($order->positionSide === null) {
                    throw new \LogicException();
                }
                $protectionScopes[] = implode(':', [
                    $reservation->decisionKey(),
                    $order->symbol,
                    $order->positionSide->value,
                ]);
            }
            $records[] = $record;
        }

        foreach ($state->positions as $position) {
            if ($position->size <= 0.0) {
                continue;
            }
            [$reservation, $instrument] = $this->descriptors($position->metadata, $position->symbol, $cell, $quoteCurrency);
            $this->registerDecision($decisions, $reservation);
            if ($position->exchange !== Exchange::FAKE
                || $position->marketType !== MarketType::PERPETUAL
                || !\is_finite($position->size)
                || !\is_finite($position->entryPrice)
                || $position->entryPrice <= 0.0
            ) {
                throw new \LogicException();
            }
            $markValue = $state->markPrices[$position->symbol] ?? null;
            if (!\is_string($markValue)) {
                throw new \LogicException();
            }
            $mark = BigDecimal::of($markValue);
            $quantity = $this->decimal($position->size);
            $entry = $this->decimal($position->entryPrice);
            if (!$mark->isPositive() || !$quantity->isPositive()) {
                throw new \LogicException();
            }
            $contractSize = BigDecimal::of($instrument->instrument()->contractSize);
            $notional = $quantity
                ->multipliedBy($mark)
                ->multipliedBy($contractSize)
                ->toScale(self::SCALE, RoundingMode::HALF_EVEN);
            $positionUnrealized = ($position->side === ExchangePositionSide::LONG
                ? $mark->minus($entry)
                : $entry->minus($mark))
                ->multipliedBy($quantity)
                ->multipliedBy($contractSize)
                ->toScale(self::SCALE, RoundingMode::HALF_EVEN);
            $openNotional = $openNotional->plus($notional)->toScale(self::SCALE, RoundingMode::HALF_EVEN);
            $unrealized = $unrealized->plus($positionUnrealized)->toScale(self::SCALE, RoundingMode::HALF_EVEN);
            ++$openPositions;
            $positionScopes[implode(':', [
                $reservation->decisionKey(),
                $position->symbol,
                $position->side->value,
            ])] = true;
            $records[] = [
                'kind' => 'position',
                'position_id' => $position->exchangePositionId,
                'symbol' => $position->symbol,
                'side' => $position->side->value,
                'quantity' => (string) $quantity,
                'entry_price' => (string) $entry,
                'mark_price' => (string) $mark,
                'notional' => (string) $notional,
                'unrealized' => (string) $positionUnrealized,
                'reservation_hash' => $reservation->identityHash(),
                'instrument_hash' => $instrument->identityHash(),
            ];
        }

        foreach ($protectionScopes as $protectionScope) {
            if (!isset($positionScopes[$protectionScope])) {
                throw new \LogicException();
            }
        }
        foreach (array_keys($positionScopes) as $positionScope) {
            if (!\in_array($positionScope, $protectionScopes, true)) {
                throw new \LogicException();
            }
        }

        ksort($decisions, SORT_STRING);
        usort($records, static fn (array $left, array $right): int => CanonicalJson::encode($left) <=> CanonicalJson::encode($right));
        $reservedRisk = BigDecimal::zero()->toScale(self::SCALE);
        foreach ($decisions as $decision) {
            $reservedRisk = $reservedRisk
                ->plus($this->decimal($decision['descriptor']->reservedRiskQuote()))
                ->toScale(self::SCALE, RoundingMode::HALF_EVEN);
        }

        return [
            'openPositions' => $openPositions,
            'pendingEntries' => $pendingEntries,
            'openNotional' => $openNotional,
            'pendingNotional' => $pendingNotional,
            'reservedRisk' => $reservedRisk,
            'unrealized' => $unrealized,
            'decisionKeys' => array_keys($decisions),
            'records' => $records,
        ];
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array{PaperCanonicalFakeReservationDescriptor,PaperCanonicalFakeInstrumentDescriptor}
     */
    private function descriptors(
        array $metadata,
        string $symbol,
        \App\Trading\Paper\Execution\Identity\PaperExecutionCell $cell,
        string $quoteCurrency,
    ): array {
        $reservationEncoded = $metadata[PaperCanonicalFakeReservationDescriptor::METADATA_KEY] ?? null;
        $instrumentEncoded = $metadata[PaperCanonicalFakeInstrumentDescriptor::METADATA_KEY] ?? null;
        if (!\is_string($reservationEncoded) || !\is_string($instrumentEncoded)) {
            throw new \LogicException();
        }
        $reservation = PaperCanonicalFakeReservationDescriptor::decode($reservationEncoded)->assertCell($cell);
        $instrument = PaperCanonicalFakeInstrumentDescriptor::decode($instrumentEncoded);
        if (!hash_equals($instrument->cellId(), $cell->id)
            || !hash_equals($instrument->symbol(), $symbol)
            || !hash_equals($instrument->instrument()->quoteAsset, $quoteCurrency)
        ) {
            throw new \LogicException();
        }
        $decisionKey = $metadata['decision_key'] ?? null;
        if (!\is_string($decisionKey) || !hash_equals($reservation->decisionKey(), $decisionKey)) {
            throw new \LogicException();
        }

        return [$reservation, $instrument];
    }

    /**
     * @param array<string,array{encoded:string,descriptor:PaperCanonicalFakeReservationDescriptor}> $decisions
     */
    private function registerDecision(
        array &$decisions,
        PaperCanonicalFakeReservationDescriptor $descriptor,
    ): void {
        $decisionKey = $descriptor->decisionKey();
        $encoded = $descriptor->encoded();
        if (isset($decisions[$decisionKey])) {
            if (!hash_equals($decisions[$decisionKey]['encoded'], $encoded)) {
                throw new \LogicException();
            }

            return;
        }
        $decisions[$decisionKey] = ['encoded' => $encoded, 'descriptor' => $descriptor];
    }

    private function isActiveOrder(ExchangeOrderDto $order): bool
    {
        if ($order->exchange !== Exchange::FAKE || $order->marketType !== MarketType::PERPETUAL) {
            throw new \LogicException();
        }

        return \in_array($order->status, [
            ExchangeOrderStatus::PENDING,
            ExchangeOrderStatus::OPEN,
            ExchangeOrderStatus::PARTIALLY_FILLED,
        ], true);
    }

    /** @param array<string,mixed> $metadata */
    private function metadataDecimal(array $metadata, string $field): BigDecimal
    {
        $value = $metadata[$field] ?? null;
        if (!\is_string($value)) {
            throw new \LogicException();
        }

        return BigDecimal::of($value);
    }

    private function decimal(float $value): BigDecimal
    {
        return CanonicalOrderPlanDecimal::fromFloat(
            $value,
            'paper_canonical_fake_portfolio_snapshot_invalid',
        );
    }

    /** @return array{\DateTimeImmutable,\DateTimeImmutable} */
    private function policyDay(CanonicalPortfolioPolicy $policy, \DateTimeImmutable $now): array
    {
        $timezone = new \DateTimeZone($policy->dayTimezone);
        $localNow = $now->setTimezone($timezone);
        $candidate = new \DateTimeImmutable(
            $localNow->format('Y-m-d') . 'T' . $policy->dayBoundaryLocal,
            $timezone,
        );
        $start = $localNow < $candidate ? $candidate->modify('-1 day') : $candidate;

        return [$start, $start->modify('+1 day')];
    }

    private static function time(\DateTimeImmutable $time): string
    {
        return $time->format('Y-m-d\TH:i:s.uP');
    }
}
