<?php

declare(strict_types=1);

namespace App\TradingCore\Execution\Hyperliquid;

interface HyperliquidQuarantineFilesystemInterface
{
    public function markerExists(string $path): bool;

    public function persistMarker(string $path, string $content): void;

    public function removeMarker(string $path): void;
}
