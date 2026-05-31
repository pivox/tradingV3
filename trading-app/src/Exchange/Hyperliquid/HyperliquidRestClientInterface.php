<?php

declare(strict_types=1);

namespace App\Exchange\Hyperliquid;

interface HyperliquidRestClientInterface
{
    /**
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public function info(array $request): array;

    /**
     * @param array<string,mixed> $action
     * @return array<string,mixed>
     */
    public function exchange(array $action): array;
}
