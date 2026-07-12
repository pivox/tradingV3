<?php

declare(strict_types=1);

namespace App\Exchange\Hyperliquid;

interface HyperliquidReadinessInfoClientInterface
{
    /**
     * @param array<string, mixed> $request
     * @return array<mixed>
     */
    public function readinessInfo(array $request): array;
}
