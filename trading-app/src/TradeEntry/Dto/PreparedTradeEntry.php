<?php

declare(strict_types=1);

namespace App\TradeEntry\Dto;

use App\Logging\Dto\LifecycleContextBuilder;
use App\TradeEntry\OrderPlan\OrderPlanModel;

final readonly class PreparedTradeEntry
{
    public function __construct(
        public ?OrderPlanModel $plan,
        public ?ExecutionResult $terminalResult,
        public string $decisionKey,
        public string $internalTradeId,
        public LifecycleContextBuilder $lifecycle,
        public string $mode,
        public string $executionTimeframe,
        public ?PreflightReport $preflight = null,
    ) {
        if (($plan === null) === ($terminalResult === null)) {
            throw new \InvalidArgumentException('prepared_trade_entry_state_invalid');
        }
        if ($decisionKey === '' || $internalTradeId === '' || $mode === '') {
            throw new \InvalidArgumentException('prepared_trade_entry_identity_invalid');
        }
    }

    /** @return array{symbol:string,side:string,entry:float,stop:float,take_profit:float,size:int,leverage:int} */
    public function stablePlanPayload(): array
    {
        if (!$this->plan instanceof OrderPlanModel) {
            throw new \LogicException('prepared_trade_entry_terminal');
        }

        return [
            'symbol' => $this->plan->symbol,
            'side' => $this->plan->side->value,
            'entry' => $this->plan->entry,
            'stop' => $this->plan->stop,
            'take_profit' => $this->plan->takeProfit,
            'size' => $this->plan->size,
            'leverage' => $this->plan->leverage,
        ];
    }
}
