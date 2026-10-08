<?php

declare(strict_types=1);

namespace App\TradeEntry\Service;

use App\TradeEntry\Dto\TpSlTwoTargetsRequest;

interface TakeProfitPlacerInterface
{
    /**
     * @return array<string,mixed>
     */
    public function __invoke(TpSlTwoTargetsRequest $req, ?string $decisionKey = null, ?string $mode = null): array;
}
