<?php

declare(strict_types=1);

namespace App\Runtime\Safety;

final class FakeOnlyExchangeCallAudit
{
    private bool $active = false;

    /** @var array{bitmart: int, hyperliquid: int, okx: int} */
    private array $exchangeCalls = [
        'bitmart' => 0,
        'hyperliquid' => 0,
        'okx' => 0,
    ];

    private int $ambiguousCalls = 0;

    public function begin(): void
    {
        $this->active = true;
        $this->exchangeCalls = ['bitmart' => 0, 'hyperliquid' => 0, 'okx' => 0];
        $this->ambiguousCalls = 0;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function recordAttempt(string $exchange): void
    {
        if (!$this->active) {
            return;
        }
        if (!\array_key_exists($exchange, $this->exchangeCalls)) {
            ++$this->ambiguousCalls;

            return;
        }

        ++$this->exchangeCalls[$exchange];
    }

    public function recordAmbiguousAttempt(): void
    {
        if ($this->active) {
            ++$this->ambiguousCalls;
        }
    }

    /**
     * @return array{
     *     ambiguous_calls: int,
     *     complete: true,
     *     exchange_calls: array{bitmart: int, hyperliquid: int, okx: int},
     *     schema_version: string,
     *     source: string
     * }
     */
    public function finish(): array
    {
        $evidence = [
            'ambiguous_calls' => $this->ambiguousCalls,
            'complete' => true,
            'exchange_calls' => $this->exchangeCalls,
            'schema_version' => 'fake-only-exchange-safety-v1',
            'source' => 'symfony_http_client_guard',
        ];
        $this->active = false;

        return $evidence;
    }
}
