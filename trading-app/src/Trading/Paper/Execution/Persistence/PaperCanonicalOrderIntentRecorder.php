<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Persistence;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Entity\OrderIntent;
use App\Entity\OrderProtection;
use App\Entity\TradeLineage;
use App\Provider\Context\ExchangeContext;
use App\Service\OrderIntentManager;
use App\TradeEntry\Dto\ExecutionResult;
use App\Trading\Lineage\LineageContext;
use App\Trading\Lineage\TradeLineageManager;
use App\Trading\Paper\MarketData\PaperMarketEventRedactor;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlan;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanDecimal;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(id: PaperCanonicalOrderIntentRecorderInterface::class)]
final readonly class PaperCanonicalOrderIntentRecorder implements PaperCanonicalOrderIntentRecorderInterface
{
    public function __construct(
        private OrderIntentManager $intents,
        private TradeLineageManager $lineages,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function reserve(
        CanonicalOrderPlan $plan,
        LineageContext $lineage,
        string $decisionKey,
        string $executionTimeframe,
        array $identity,
        array $provenance,
    ): array {
        try {
            $plan = CanonicalOrderPlan::fromArray($plan->toArray());
            $provenance = PaperExecutionProvenance::validate($provenance);
            $clientOrderId = $this->reserveClientOrderId($identity);
            if (array_keys($provenance) !== PaperExecutionProvenance::MODERN_KEYS
                || !\in_array($executionTimeframe, ['1m', '5m', '15m', '1h', '4h'], true)
                || $executionTimeframe !== $this->configuredExecutionTimeframe($lineage)
            ) {
                throw new \InvalidArgumentException();
            }
            $lineage->assertCanonicalIntegrity()->assertExecutableTradeContract()->assertTradeBoundary(
                $plan->symbol,
                $plan->side,
                $plan->exchange,
                $plan->marketType,
            );
            $checks = [
                [$decisionKey, $lineage->decisionKey],
                [$plan->modeId, $lineage->modeId],
                [$plan->modeVersion, $lineage->modeVersion],
                [$plan->setupId, $lineage->setupId],
                [$plan->setupVersion, $lineage->setupVersion],
                [$plan->configHash, $lineage->configHash],
                [$plan->exchange, $lineage->exchange],
                [$plan->environment, $lineage->environment],
                [$plan->modeId, $provenance['mode_id']],
                [$plan->modeVersion, $provenance['mode_version']],
                [$plan->setupId, $provenance['setup_id']],
                [$plan->setupVersion, $provenance['setup_version']],
                [$plan->side, $provenance['side']],
                [$plan->configHash, $provenance['config_hash']],
                [$plan->exchange, $provenance['market_data_venue']],
                [$plan->environment, $provenance['paper_network']],
                [$lineage->conditionCatalogHash, $provenance['condition_catalog_hash']],
                [$lineage->orchestrationRunId, $provenance['run_id']],
                ['fake', $provenance['exchange']],
            ];
            foreach ($checks as [$expected, $actual]) {
                if (!\is_string($expected) || !\is_string($actual) || !hash_equals($expected, $actual)) {
                    throw new \InvalidArgumentException();
                }
            }

            $intentId = $lineage->intentId ?? 'int:' . substr(hash('sha256', $decisionKey), 0, 48);
            $intentLineage = $lineage->withIntent($intentId);
            $lineageWire = $intentLineage->toArray();
            if (($lineageWire['client_order_id'] ?? $clientOrderId) !== $clientOrderId) {
                throw new \InvalidArgumentException();
            }
            $lineageWire['client_order_id'] = $clientOrderId;
            $intentLineage = LineageContext::fromArray($lineageWire);
            PaperMarketEventRedactor::assertSafe($provenance);
        } catch (\Throwable $exception) {
            if ($exception instanceof \InvalidArgumentException
                && $exception->getMessage() === 'paper_canonical_order_intent_invalid'
            ) {
                throw $exception;
            }

            throw new \InvalidArgumentException('paper_canonical_order_intent_invalid', 0, $exception);
        }

        $params = [
            'exchange' => Exchange::FAKE->value,
            'market_type' => $plan->marketType,
            'decision_key' => $decisionKey,
            'symbol' => $plan->symbol,
            'side' => $plan->side === 'long' ? 1 : 4,
            'type' => $plan->orderType,
            'open_type' => OrderIntent::OPEN_TYPE_ISOLATED,
            'position_mode' => OrderIntent::POSITION_MODE_ONE_WAY,
            'leverage' => $plan->finalLeverage,
            'price' => $this->columnDecimal($plan->entryPrice, 65, 30),
            'size' => $this->columnDecimal($plan->quantity, 36, 18),
            'client_order_id' => $clientOrderId,
            'preset_mode' => OrderIntent::PRESET_MODE_NONE,
            'strategy_profile' => $plan->modeId,
            'strategy_version' => $plan->modeVersion,
            'timeframe' => $executionTimeframe,
            'canonical_protections' => $this->protections($plan),
        ];
        if ($this->intents->validateOrderParams($params) !== null) {
            throw new \InvalidArgumentException('paper_canonical_order_intent_invalid');
        }

        $rawInputs = [
            'schema_version' => 'paper-canonical-order-intent.v1',
            'source' => 'paper_execution_coordinator',
            'decision_key' => $decisionKey,
            'execution_timeframe' => $executionTimeframe,
            'plan_hash' => $plan->planHash,
            'plan' => $plan->toArray(),
            'canonical_identity' => $intentLineage->redacted(),
        ];
        PaperMarketEventRedactor::assertSafe($rawInputs);
        return $this->entityManager->getConnection()->transactional(function () use (
            $params,
            $rawInputs,
            $intentLineage,
            $provenance,
            $plan,
            $clientOrderId,
        ): array {
            $intent = $this->intents->createIntent(
                $params,
                [
                    'quantity_step' => $this->decimal($plan->quantityStep),
                    'contract_size' => $this->decimal($plan->contractSize),
                    'tick_size' => $this->decimal($plan->tickSize),
                ],
                $rawInputs,
                $intentLineage,
                $provenance,
            );
            if (!$this->intents->validateIntent($intent)) {
                throw new \LogicException('paper_canonical_order_intent_validation_failed');
            }
            $this->intents->markReadyToSend($intent);
            $this->lineages->ensureForIntent($intent, $intentLineage, $provenance);

            $id = $intent->getId();
            if (!\is_int($id) || $id < 1) {
                throw new \LogicException('paper_canonical_order_intent_id_missing');
            }

            return ['client_order_id' => $clientOrderId, 'order_intent_id' => $id];
        });
    }

    public function acknowledge(array $identity, ExecutionResult $result): void
    {
        [$clientOrderId, $orderIntentId] = $this->acknowledgementIdentity($identity);
        $intent = $this->intents->findIntentById($orderIntentId);
        if (!$intent instanceof OrderIntent
            || $intent->getClientOrderId() !== $clientOrderId
            || $result->clientOrderId !== $clientOrderId
            || $intent->getExchange() !== Exchange::FAKE->value
            || !$intent->hasCompleteCanonicalIdentity()
        ) {
            throw new \LogicException('paper_canonical_order_intent_identity_conflict');
        }
        $lineage = $this->lineages->resolve(
            new ExchangeContext(Exchange::FAKE, MarketType::PERPETUAL),
            clientOrderId: $clientOrderId,
        );
        if (!$lineage instanceof TradeLineage || $lineage->getOrderIntent()?->getId() !== $intent->getId()) {
            throw new \LogicException('paper_canonical_order_intent_lineage_missing');
        }
        $this->lineages->attachExchangeOrderId($lineage, $result->exchangeOrderId);

        if ($result->exchangeOrderId !== null && \in_array($result->status, [
            ExecutionResult::STATUS_SUBMITTED,
            ExecutionResult::STATUS_SUBMITTED_PROTECTED,
            ExecutionResult::STATUS_ENTRY_SUBMITTED,
        ], true)) {
            $this->intents->markAsSent($intent, $result->exchangeOrderId);

            return;
        }

        $this->intents->markAsFailed($intent, (string) ($result->raw['reason'] ?? $result->status));
    }

    /** @param array<string, mixed> $identity */
    private function reserveClientOrderId(array $identity): string
    {
        $clientOrderId = $identity['client_order_id'] ?? null;
        if (array_keys($identity) !== ['client_order_id']
            || !\is_string($clientOrderId)
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,79}\z/D', $clientOrderId) !== 1
        ) {
            throw new \InvalidArgumentException();
        }

        return $clientOrderId;
    }

    /**
     * @param array<string, mixed> $identity
     * @return array{string, int}
     */
    private function acknowledgementIdentity(array $identity): array
    {
        if (array_keys($identity) !== ['client_order_id', 'order_intent_id']
            || !\is_string($identity['client_order_id'] ?? null)
            || !\is_int($identity['order_intent_id'] ?? null)
            || $identity['order_intent_id'] < 1
        ) {
            throw new \InvalidArgumentException('paper_canonical_order_intent_identity_invalid');
        }

        return [$identity['client_order_id'], $identity['order_intent_id']];
    }

    private function configuredExecutionTimeframe(LineageContext $lineage): ?string
    {
        $config = $lineage->effectiveConfigSnapshot?->config();
        $decision = $config['setup']['ast']['execution']['execution_timeframe'] ?? null;

        return \is_array($decision) && ($decision['state'] ?? null) === 'defined'
            && \is_string($decision['value'] ?? null)
            ? $decision['value']
            : null;
    }

    private function decimal(float $value): string
    {
        try {
            return (string) CanonicalOrderPlanDecimal::fromFloat(
                $value,
                'paper_canonical_order_intent_invalid',
            )->stripTrailingZeros();
        } catch (\Throwable $exception) {
            throw new \InvalidArgumentException('paper_canonical_order_intent_invalid', 0, $exception);
        }
    }

    private function columnDecimal(float $value, int $precision, int $scale): string
    {
        try {
            $decimal = CanonicalOrderPlanDecimal::fromFloat(
                $value,
                'paper_canonical_order_intent_invalid',
            )->stripTrailingZeros();
            $digits = ltrim((string) $decimal->getUnscaledValue(), '-');
            $integerDigits = max(0, strlen($digits) - $decimal->getScale());
            if ($decimal->getScale() > $scale || $integerDigits > $precision - $scale) {
                throw new \InvalidArgumentException();
            }

            return (string) $decimal;
        } catch (\Throwable $exception) {
            throw new \InvalidArgumentException('paper_canonical_order_intent_invalid', 0, $exception);
        }
    }

    /** @return list<array{type: string, price: string, metadata: array<string, mixed>}> */
    private function protections(CanonicalOrderPlan $plan): array
    {
        $protections = [[
            'type' => OrderProtection::TYPE_STOP_LOSS,
            'price' => $this->columnDecimal($plan->stopPrice, 65, 30),
            'metadata' => ['kind' => 'canonical_stop', 'plan_hash' => $plan->planHash],
        ]];
        foreach ($plan->targets as $target) {
            $protections[] = [
                'type' => OrderProtection::TYPE_TAKE_PROFIT,
                'price' => $this->columnDecimal($target->price, 65, 30),
                'metadata' => [
                    'kind' => 'canonical_target',
                    'target_id' => $target->id,
                    'risk_multiple' => $target->riskMultiple,
                    'plan_hash' => $plan->planHash,
                ],
            ];
        }

        return $protections;
    }
}
