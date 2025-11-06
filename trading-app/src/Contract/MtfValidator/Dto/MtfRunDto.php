<?php

namespace App\Contract\MtfValidator\Dto;

class MtfRunDto
{
    public function __construct(
        public array $symbols = [],
        public bool $dryRun = false,
        public bool $forceRun = false,
        public ?string $currentTf = null,
        public bool $forceTimeframeCheck = false,
        public bool $skipContextValidation = false,
        public bool $lockPerSymbol = false,
        // When true, orchestrator will skip fetching open orders/positions to prefilter symbols
        public bool $skipOpenStateFilter = false,
    )
    {
    }

    public function getArrayAsLowerSnakeCase(): array
    {
        return [
            'symbols' => $this->symbols,
            'dry_run' => $this->dryRun,
            'force_run' => $this->forceRun,
            'current_tf' => $this->currentTf,
            'force_timeframe_check' => $this->forceTimeframeCheck,
            'skip_context' => $this->skipContextValidation,
            'lock_per_symbol' => $this->lockPerSymbol,
            'skip_open_state_filter' => $this->skipOpenStateFilter,
        ];
    }




}
