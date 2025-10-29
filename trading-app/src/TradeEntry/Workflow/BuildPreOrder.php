<?php
declare(strict_types=1);

namespace App\TradeEntry\Workflow;

use App\TradeEntry\Dto\{PreflightReport, TradeEntryRequest};
use App\TradeEntry\Policy\PreTradeChecks;

final class BuildPreOrder
{
    public function __construct(private readonly PreTradeChecks $checks) {}

    public function __invoke(TradeEntryRequest $req): PreflightReport
    {
        return $this->checks->run($req);
    }
}
