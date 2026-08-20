<?php

declare(strict_types=1);

namespace App\TradingCore\Microstructure;

use App\Trading\Lineage\LineageContext;
use App\TradingCore\Rules\Evaluation\RuleMarketIdentity;

final readonly class CanonicalMicrostructureRuntimeInputResolver
{
    public function __construct(
        private ?CanonicalMicrostructureSnapshotProviderInterface $provider = null,
        private ?CanonicalMicrostructureRuleInputAdapter $adapter = null,
    ) {
    }

    public function resolve(
        LineageContext $identity,
        \DateTimeImmutable $evaluatedAt,
    ): CanonicalMicrostructureRuntimeInput {
        if ($identity->modeId !== 'micro_scalping') {
            return $this->result('not_required');
        }

        $marketIdentity = $this->marketIdentity($identity);
        if ($marketIdentity === null) {
            return $this->result('identity_unavailable');
        }
        if ($this->provider === null) {
            return $this->result('provider_unavailable', $marketIdentity);
        }

        try {
            $snapshot = $this->provider->snapshotFor($identity, $evaluatedAt);
            if ($snapshot === null) {
                return $this->result('input_unavailable', $marketIdentity);
            }
            $snapshot->verify();
            if (!$marketIdentity->matches(
                $snapshot->sourceNetwork,
                $snapshot->marketDataVenue,
                $snapshot->marketType,
                $snapshot->symbol,
                $snapshot->quantityUnit,
            )) {
                return $this->result('identity_mismatch', $marketIdentity, [
                    'observed_market_identity' => $this->snapshotIdentity($snapshot),
                ]);
            }
            $ruleInput = ($this->adapter ?? new CanonicalMicrostructureRuleInputAdapter())->adapt($snapshot);
            if (!$ruleInput->isValidAt($evaluatedAt)) {
                return $this->result('input_stale', $marketIdentity, [
                    'input_hash' => $snapshot->inputHash,
                    'observed_at' => $ruleInput->observedAt->format(DATE_ATOM),
                    'valid_until' => $ruleInput->validUntil->format(DATE_ATOM),
                ]);
            }

            return new CanonicalMicrostructureRuntimeInput('ready', $ruleInput, $marketIdentity, [
                'schema_version' => 'canonical-microstructure-runtime-input.v1',
                'status' => 'ready',
                'input_hash' => $snapshot->inputHash,
                'observed_at' => $ruleInput->observedAt->format(DATE_ATOM),
                'valid_until' => $ruleInput->validUntil->format(DATE_ATOM),
                'best_bid' => (float) $snapshot->bestBid,
                'best_ask' => (float) $snapshot->bestAsk,
                'spread_bps' => (float) $snapshot->spreadBps,
                'book_observed_at' => $snapshot->bookHappenedAt,
                'expected_market_identity' => $marketIdentity->toArray(),
            ]);
        } catch (\Throwable $exception) {
            return $this->result('input_rejected', $marketIdentity, [
                'exception_class' => $exception::class,
            ]);
        }
    }

    /** @param array<string, mixed> $extra */
    private function result(
        string $status,
        ?RuleMarketIdentity $marketIdentity = null,
        array $extra = [],
    ): CanonicalMicrostructureRuntimeInput {
        $trace = [
            'schema_version' => 'canonical-microstructure-runtime-input.v1',
            'status' => $status,
            'expected_market_identity' => $marketIdentity?->toArray(),
        ] + $extra;

        return new CanonicalMicrostructureRuntimeInput($status, null, $marketIdentity, $trace);
    }

    private function marketIdentity(LineageContext $identity): ?RuleMarketIdentity
    {
        if (!\in_array($identity->environment, ['mainnet', 'testnet'], true)
            || !\in_array($identity->exchange, ['okx', 'hyperliquid'], true)
            || $identity->marketType !== 'perpetual'
            || $identity->symbol === null
        ) {
            return null;
        }

        return new RuleMarketIdentity(
            $identity->environment,
            $identity->exchange,
            $identity->marketType,
            $identity->symbol,
            $identity->exchange === 'okx' ? 'contracts' : 'base_asset',
        );
    }

    /** @return array{source_network:string,market_data_venue:string,market_type:string,symbol:string,quantity_unit:string} */
    private function snapshotIdentity(CanonicalMicrostructureSnapshot $snapshot): array
    {
        return [
            'source_network' => $snapshot->sourceNetwork,
            'market_data_venue' => $snapshot->marketDataVenue,
            'market_type' => $snapshot->marketType,
            'symbol' => $snapshot->symbol,
            'quantity_unit' => $snapshot->quantityUnit,
        ];
    }
}
