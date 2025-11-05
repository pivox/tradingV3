<?php

declare(strict_types=1);

namespace App\Logging;

final class TraceIdProvider
{
    /** @var array<string,string> */
    private array $bySymbol = [];

    public function new(string $symbol): string
    {
        $traceId = $this->generateTraceId($symbol);
        $this->bySymbol[$symbol] = $traceId;
        return $traceId;
    }

    public function getTraceId(string $symbol): ?string
    {
        return $this->bySymbol[$symbol] ?? null;
    }

    public function getOrCreate(string $symbol): string
    {
        if (!isset($this->bySymbol[$symbol])) {
            $this->bySymbol[$symbol] = $this->generateTraceId($symbol);
        }
        return $this->bySymbol[$symbol];
    }

    private function generateTraceId(string $symbol): string
    {
        // Génère un ID court basé sur le symbol et le timestamp (format: symbol-HHiiss)
        $now = new \DateTimeImmutable();
        return $symbol . '-' . $now->format('His');
    }
}

