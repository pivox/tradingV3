<?php

declare(strict_types=1);

namespace App\Trading\Paper\Runtime;

use App\Common\Enum\Exchange;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;

final readonly class PaperRuntimeContext
{
    /**
     * @param list<string> $symbols
     */
    public function __construct(
        public string $executionMode,
        public Exchange $executionExchange,
        public bool $paperExecutionEnabled,
        public bool $mainnetWriteEnabled,
        public bool $demoTestnetWriteEnabled,
        public array $symbols,
        public PaperExecutionCell $cell,
    ) {
    }
}
