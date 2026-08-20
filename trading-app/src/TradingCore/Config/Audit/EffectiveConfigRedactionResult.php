<?php

declare(strict_types=1);

namespace App\TradingCore\Config\Audit;

final readonly class EffectiveConfigRedactionResult
{
    /**
     * @param array<string,mixed> $document
     * @param list<string>        $redactedPaths
     */
    public function __construct(public array $document, public array $redactedPaths)
    {
    }
}
