<?php

declare(strict_types=1);

namespace App\Provider\Fake;

use App\Contract\Provider\SystemProviderInterface;

/**
 * Minimal fake system provider.
 *
 * Returns the current wall-clock time in milliseconds. No external calls.
 * Used by the FAKE exchange context so the orchestrator demo (exchange=fake)
 * resolves a provider bundle without hitting any real exchange.
 */
final class FakeSystemProvider implements SystemProviderInterface
{
    public function getSystemTimeMs(): int
    {
        return (int) (microtime(true) * 1000);
    }
}
