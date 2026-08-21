<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Fake;

use App\Exchange\Dto\ExchangeOrderDto;
use App\Exchange\Dto\ExchangePositionDto;
use App\Exchange\Fake\FakeExchangeStateStore;
use App\Exchange\Fake\FakeInstrument;
use App\Exchange\Fake\FakeInstrumentProviderInterface;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlan;

final class PaperCanonicalFakeInstrumentRegistry implements FakeInstrumentProviderInterface
{
    private const DISPATCH_SOURCE = 'paper_canonical_fake_dispatcher';

    /** @var array<string, PaperCanonicalFakeInstrumentDescriptor> */
    private array $descriptors = [];

    public function __construct(
        private readonly PaperExecutionCell $cell,
        private readonly FakeExchangeStateStore $state,
    ) {
        if (!$cell->isModern()) {
            throw new \LogicException('paper_canonical_fake_instrument_cell_invalid');
        }

        try {
            $this->restoreActiveState();
        } catch (\Throwable $exception) {
            if ($exception instanceof \LogicException
                && $exception->getMessage() === 'paper_canonical_fake_instrument_state_invalid'
            ) {
                throw $exception;
            }

            throw new \LogicException('paper_canonical_fake_instrument_state_invalid', 0, $exception);
        }
    }

    public function bind(CanonicalOrderPlan $plan): string
    {
        $descriptor = PaperCanonicalFakeInstrumentDescriptor::fromPlan($this->cell, $plan);
        $symbol = $descriptor->symbol();
        $current = $this->descriptors[$symbol] ?? null;
        if ($current instanceof PaperCanonicalFakeInstrumentDescriptor) {
            if (hash_equals($current->identityHash(), $descriptor->identityHash())) {
                return $current->encoded();
            }
            if ($this->hasActiveCanonicalState($symbol)) {
                throw new \LogicException('paper_canonical_fake_instrument_drift');
            }
        }

        $this->descriptors[$symbol] = $descriptor;

        return $descriptor->encoded();
    }

    public function find(string $symbol): ?FakeInstrument
    {
        if ($symbol === '' || trim($symbol) !== $symbol || strtoupper($symbol) !== $symbol) {
            return null;
        }

        return ($this->descriptors[$symbol] ?? null)?->instrument();
    }

    private function restoreActiveState(): void
    {
        foreach ($this->activeCanonicalState() as $record) {
            $encoded = $record->metadata[PaperCanonicalFakeInstrumentDescriptor::METADATA_KEY] ?? null;
            if (!is_string($encoded) || $encoded === '') {
                throw new \LogicException('paper_canonical_fake_instrument_state_invalid');
            }
            $descriptor = PaperCanonicalFakeInstrumentDescriptor::decode($encoded);
            if (!hash_equals($this->cell->id, $descriptor->cellId())
                || !hash_equals($record->symbol, $descriptor->symbol())
            ) {
                throw new \LogicException('paper_canonical_fake_instrument_state_invalid');
            }
            $current = $this->descriptors[$descriptor->symbol()] ?? null;
            if ($current instanceof PaperCanonicalFakeInstrumentDescriptor
                && !hash_equals($current->identityHash(), $descriptor->identityHash())
            ) {
                throw new \LogicException('paper_canonical_fake_instrument_state_invalid');
            }
            $this->descriptors[$descriptor->symbol()] = $descriptor;
        }
    }

    private function hasActiveCanonicalState(string $symbol): bool
    {
        foreach ($this->activeCanonicalState() as $record) {
            if ($record->symbol === $symbol) {
                return true;
            }
        }

        return false;
    }

    /** @return list<ExchangeOrderDto|ExchangePositionDto> */
    private function activeCanonicalState(): array
    {
        return array_values(array_filter(
            [...$this->state->getOpenOrders(), ...$this->state->getOpenPositions()],
            static fn (ExchangeOrderDto|ExchangePositionDto $record): bool =>
                ($record->metadata['canonical_dispatch_source'] ?? null) === self::DISPATCH_SOURCE,
        ));
    }
}
