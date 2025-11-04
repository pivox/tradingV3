<?php

declare(strict_types=1);

namespace App\MtfValidator\Service\Decision;

use App\MtfValidator\Service\Dto\SymbolResultDto;
use App\TradeEntry\Dto\TradeEntryRequest;

/**
 * Représente le résultat de l'évaluation d'une décision de trading.
 */
final class TradingDecisionEvaluation
{
    public const ACTION_NONE = 'none';
    public const ACTION_SKIP = 'skip';
    public const ACTION_PREPARE = 'prepare';

    public function __construct(
        public readonly string $action,
        public readonly SymbolResultDto $result,
        public readonly string $decisionKey,
        public readonly ?TradeEntryRequest $tradeRequest = null,
        public readonly ?string $skipReason = null,
        public readonly ?string $blockReason = null,
        public readonly array $extraContext = [],
    ) {
    }
}

