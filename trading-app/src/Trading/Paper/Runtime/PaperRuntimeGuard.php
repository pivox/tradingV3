<?php

declare(strict_types=1);

namespace App\Trading\Paper\Runtime;

use App\Common\Enum\Exchange;

final class PaperRuntimeGuard
{
    private const ALLOWED_SYMBOLS = ['BTCUSDT', 'ETHUSDT'];

    public function assertSafe(#[\SensitiveParameter] PaperRuntimeContext $context): void
    {
        if ($context->executionMode !== 'paper') {
            throw new \LogicException('paper_execution_mode_required');
        }

        if ($context->executionExchange !== Exchange::FAKE) {
            throw new \LogicException('paper_execution_exchange_must_be_fake');
        }

        if (!$context->paperExecutionEnabled) {
            throw new \LogicException('paper_execution_disabled');
        }

        if ($context->mainnetWriteEnabled || $context->demoTestnetWriteEnabled) {
            throw new \LogicException('paper_exchange_writes_must_be_disabled');
        }

        if ($context->symbols === []) {
            throw new \LogicException('paper_symbol_not_allowed');
        }

        foreach ($context->symbols as $symbol) {
            if (!in_array($symbol, self::ALLOWED_SYMBOLS, true)) {
                throw new \LogicException('paper_symbol_not_allowed');
            }
        }
    }
}
