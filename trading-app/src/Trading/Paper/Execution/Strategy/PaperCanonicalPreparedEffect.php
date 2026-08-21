<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Strategy;

use App\Trading\Lineage\LineageContext;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Identity\PaperModernStrategyIdentity;
use App\Trading\Paper\Execution\Persistence\PaperExecutionProvenance;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEventRedactor;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlan;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioAdmissionProof;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioPolicy;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioReservation;

final readonly class PaperCanonicalPreparedEffect
{
    /**
     * @param array<string, mixed> $orderIntentIdentity
     * @param array<string, mixed> $provenance
     */
    public function __construct(
        public CanonicalOrderPlan $plan,
        public CanonicalPortfolioAdmissionProof $admissionProof,
        public CanonicalPortfolioReservation $reservation,
        public LineageContext $lineage,
        public string $decisionKey,
        public string $executionTimeframe,
        public array $orderIntentIdentity,
        public array $provenance,
    ) {
        $this->assertValid();
    }

    public function assertValid(): self
    {
        try {
            if ($this->decisionKey === ''
                || !in_array($this->executionTimeframe, ['1m', '5m', '15m', '1h', '4h'], true)
                || array_keys($this->orderIntentIdentity) !== ['client_order_id', 'order_intent_id']
                || !is_string($this->orderIntentIdentity['client_order_id'])
                || $this->orderIntentIdentity['client_order_id'] === ''
                || !is_int($this->orderIntentIdentity['order_intent_id'])
                || $this->orderIntentIdentity['order_intent_id'] < 1
            ) {
                throw new \InvalidArgumentException();
            }
            $provenance = PaperExecutionProvenance::validate($this->provenance);
            if (array_keys($provenance) !== PaperExecutionProvenance::MODERN_KEYS) {
                throw new \InvalidArgumentException();
            }
            $network = PaperMarketDataNetwork::from($provenance['paper_network']);
            $venue = PaperMarketDataVenue::from($provenance['market_data_venue']);
            $cell = PaperExecutionCell::createModern(
                $network,
                $venue,
                $provenance['configuration_snapshot_id'],
                PaperModernStrategyIdentity::fromDurableIdentity(
                    $network,
                    $venue,
                    $provenance['mode_id'],
                    $provenance['mode_version'],
                    $provenance['setup_id'],
                    $provenance['setup_version'],
                    $provenance['side'],
                    $provenance['config_hash'],
                    $provenance['condition_catalog_hash'],
                ),
                $provenance['run_id'],
            );
            $this->lineage->assertCanonicalIntegrity()->assertExecutableTradeContract();
            $snapshot = $this->lineage->effectiveConfigSnapshot;
            if ($snapshot === null
                || !hash_equals($this->plan->expectedPlanHash(), $this->plan->planHash)
                || !hash_equals($this->reservation->expectedStateHash(), $this->reservation->stateHash)
                || $this->executionTimeframe !== self::configuredExecutionTimeframe($this->lineage)
            ) {
                throw new \InvalidArgumentException();
            }
            $policy = CanonicalPortfolioPolicy::fromLineageSnapshot($snapshot);
            $this->admissionProof->verify($this->plan, $this->reservation, $policy);
            $this->reservation->assertCanonicalOpeningState($this->plan);

            $checks = [
                [$this->decisionKey, $this->lineage->decisionKey],
                [$this->decisionKey, $this->reservation->decisionKey],
                [$this->plan->planHash, $this->reservation->planHash],
                [$this->plan->configHash, $this->reservation->configHash],
                [$cell->accountNamespace, $this->reservation->scope->accountId],
                [$cell->network->value, $this->reservation->scope->network],
                [$this->plan->modeId, $this->lineage->modeId],
                [$this->plan->modeVersion, $this->lineage->modeVersion],
                [$this->plan->setupId, $this->lineage->setupId],
                [$this->plan->setupVersion, $this->lineage->setupVersion],
                [strtoupper($this->plan->side), $this->lineage->side],
                [$this->plan->configHash, $this->lineage->configHash],
                [$this->plan->exchange, $this->lineage->exchange],
                [$this->plan->environment, $this->lineage->environment],
                [$this->plan->symbol, $this->lineage->symbol],
                [$this->plan->marketType, $this->lineage->marketType],
                [$this->plan->modeId, $provenance['mode_id']],
                [$this->plan->modeVersion, $provenance['mode_version']],
                [$this->plan->setupId, $provenance['setup_id']],
                [$this->plan->setupVersion, $provenance['setup_version']],
                [$this->plan->side, $provenance['side']],
                [$this->plan->configHash, $provenance['config_hash']],
                [$this->lineage->conditionCatalogHash, $provenance['condition_catalog_hash']],
                [$this->plan->exchange, $provenance['market_data_venue']],
                [$this->plan->environment, $provenance['paper_network']],
                [$this->plan->modeId, $provenance['strategy_profile']],
                [$this->lineage->orchestrationRunId, $provenance['run_id']],
                ['fake', $provenance['exchange']],
            ];
            foreach ($checks as [$expected, $actual]) {
                if (!is_string($expected) || !is_string($actual) || !hash_equals($expected, $actual)) {
                    throw new \InvalidArgumentException();
                }
            }
            PaperMarketEventRedactor::assertSafe($this->orderIntentIdentity);
            PaperMarketEventRedactor::assertSafe($provenance);
        } catch (\Throwable $exception) {
            if ($exception instanceof \InvalidArgumentException
                && $exception->getMessage() === 'paper_canonical_prepared_effect_invalid'
            ) {
                throw $exception;
            }

            throw new \InvalidArgumentException('paper_canonical_prepared_effect_invalid', 0, $exception);
        }

        return $this;
    }

    private static function configuredExecutionTimeframe(LineageContext $lineage): ?string
    {
        $config = $lineage->effectiveConfigSnapshot?->config();
        $decision = $config['setup']['ast']['execution']['execution_timeframe'] ?? null;

        return is_array($decision) && ($decision['state'] ?? null) === 'defined'
            && is_string($decision['value'] ?? null)
            ? $decision['value']
            : null;
    }
}
