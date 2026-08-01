<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Persistence;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Entity\OrderIntent;
use App\Entity\TradeLineage;
use App\Provider\Context\ExchangeContext;
use App\Service\OrderIntentManager;
use App\TradeEntry\Dto\ExecutionResult;
use App\TradeEntry\Dto\PreparedTradeEntry;
use App\TradeEntry\OrderPlan\OrderPlanModel;
use App\TradeEntry\Types\Side;
use App\Trading\Lineage\TradeLineageManager;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(id: PaperOrderIntentRecorderInterface::class)]
final readonly class PaperOrderIntentRecorder implements PaperOrderIntentRecorderInterface
{
    public function __construct(
        private OrderIntentManager $intents,
        private TradeLineageManager $lineages,
    ) {
    }

    public function reserve(PreparedTradeEntry $prepared, array $identity, array $provenance): array
    {
        $plan = $prepared->plan;
        if (!$plan instanceof OrderPlanModel) {
            throw new \LogicException('paper_order_intent_plan_missing');
        }
        $provenance = PaperExecutionProvenance::validate($provenance);
        $clientOrderId = $identity['client_order_id'];
        if (!is_string($clientOrderId) || trim($clientOrderId) === '') {
            throw new \InvalidArgumentException('paper_order_intent_identity_invalid');
        }

        $params = $provenance + [
            'market_type' => MarketType::PERPETUAL->value,
            'symbol' => $plan->symbol,
            'side' => $this->intentSide($plan->side),
            'type' => strtolower($plan->orderType),
            'open_type' => strtolower($plan->openType),
            'position_mode' => OrderIntent::POSITION_MODE_ONE_WAY,
            'leverage' => $plan->leverage,
            'price' => (string) $plan->entry,
            'size' => $plan->size,
            'client_order_id' => $clientOrderId,
            'preset_mode' => OrderIntent::PRESET_MODE_NONE,
            'strategy_profile' => $prepared->mode,
            'timeframe' => $prepared->executionTimeframe,
        ];
        if (($errors = $this->intents->validateOrderParams($params)) !== null) {
            throw new \InvalidArgumentException('paper_order_intent_plan_invalid');
        }

        $intent = $this->intents->createIntent(
            $params,
            ['price_precision' => $plan->pricePrecision, 'contract_size' => $plan->contractSize],
            [
                'source' => 'paper_execution_coordinator',
                'decision_key' => $prepared->decisionKey,
                'plan' => $prepared->stablePlanPayload(),
            ],
        );
        if (!$this->intents->validateIntent($intent)) {
            throw new \LogicException('paper_order_intent_validation_failed');
        }
        $this->intents->markReadyToSend($intent);
        $this->lineages->ensureForIntent($intent, $provenance + [
            'internal_trade_id' => $prepared->internalTradeId,
            'profile' => $prepared->mode,
            'run_id' => $provenance['run_id'],
            'origin' => 'paper',
            'config_hash' => $provenance['configuration_snapshot_id'],
        ]);

        $id = $intent->getId();
        if (!is_int($id) || $id < 1) {
            throw new \LogicException('paper_order_intent_id_missing');
        }

        return ['client_order_id' => $clientOrderId, 'order_intent_id' => $id];
    }

    public function acknowledge(array $identity, ExecutionResult $result): void
    {
        $intent = $this->intents->findIntentById($identity['order_intent_id']);
        if (!$intent instanceof OrderIntent || $intent->getClientOrderId() !== $identity['client_order_id']) {
            throw new \LogicException('paper_order_intent_identity_conflict');
        }
        $lineage = $this->lineages->resolve(
            new ExchangeContext(Exchange::FAKE, MarketType::PERPETUAL),
            clientOrderId: $identity['client_order_id'],
        );
        if (!$lineage instanceof TradeLineage || $lineage->getOrderIntent()?->getId() !== $intent->getId()) {
            throw new \LogicException('paper_order_intent_lineage_missing');
        }
        $this->lineages->attachExchangeOrderId($lineage, $result->exchangeOrderId);

        if ($result->exchangeOrderId !== null && in_array($result->status, [
            ExecutionResult::STATUS_SUBMITTED,
            ExecutionResult::STATUS_SUBMITTED_PROTECTED,
            ExecutionResult::STATUS_ENTRY_SUBMITTED,
        ], true)) {
            $this->intents->markAsSent($intent, $result->exchangeOrderId);

            return;
        }

        $this->intents->markAsFailed($intent, (string) ($result->raw['reason'] ?? $result->status));
    }

    private function intentSide(Side $side): int
    {
        return match ($side) {
            Side::Long => 1,
            Side::Short => 4,
        };
    }
}
