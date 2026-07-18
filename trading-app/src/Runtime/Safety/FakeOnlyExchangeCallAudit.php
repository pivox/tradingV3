<?php

declare(strict_types=1);

namespace App\Runtime\Safety;

use Symfony\Contracts\Service\ResetInterface;

final class FakeOnlyExchangeCallAudit implements ResetInterface
{
    private bool $active = false;

    /** @var array{bitmart: int, hyperliquid: int, okx: int} */
    private array $exchangeCalls = [
        'bitmart' => 0,
        'hyperliquid' => 0,
        'okx' => 0,
    ];

    private int $ambiguousCalls = 0;

    private bool $asyncExchangeCapableDispatchesSuppressed = false;

    public function begin(bool $asyncExchangeCapableDispatchesSuppressed): void
    {
        $this->active = true;
        $this->exchangeCalls = ['bitmart' => 0, 'hyperliquid' => 0, 'okx' => 0];
        $this->ambiguousCalls = 0;
        $this->asyncExchangeCapableDispatchesSuppressed = $asyncExchangeCapableDispatchesSuppressed;
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

    public function hasKnownExchangeAttempts(): bool
    {
        return array_sum($this->exchangeCalls) > 0;
    }

    /**
     * @return array{
     *     ambiguous_calls: int,
     *     async_exchange_capable_dispatches_suppressed: bool,
     *     complete: bool,
     *     exchange_call_proof: array{bitmart: string, hyperliquid: string, okx: string},
     *     exchange_calls: array{bitmart: int, hyperliquid: int, okx: int},
     *     schema_version: string,
     *     source: string
     * }
     */
    public function finish(): array
    {
        $evidence = [
            'ambiguous_calls' => $this->ambiguousCalls,
            'async_exchange_capable_dispatches_suppressed' => $this->asyncExchangeCapableDispatchesSuppressed,
            'complete' => $this->active && $this->asyncExchangeCapableDispatchesSuppressed,
            'exchange_call_proof' => [
                'bitmart' => 'fake_provider_boundary',
                'hyperliquid' => 'http_client_guard',
                'okx' => 'http_client_guard',
            ],
            'exchange_calls' => $this->exchangeCalls,
            'schema_version' => 'fake-only-exchange-safety-v2',
            'source' => 'symfony_fake_provider_boundary_and_http_guards',
        ];
        $this->reset();

        return $evidence;
    }

    public function reset(): void
    {
        $this->active = false;
        $this->exchangeCalls = ['bitmart' => 0, 'hyperliquid' => 0, 'okx' => 0];
        $this->ambiguousCalls = 0;
        $this->asyncExchangeCapableDispatchesSuppressed = false;
    }
}
