<?php

declare(strict_types=1);

namespace App\Provider\Hyperliquid;

use App\Repository\HyperliquidNonceStateRepository;
use Psr\Clock\ClockInterface;

final readonly class PersistentHyperliquidNonceManager implements HyperliquidNonceManagerInterface
{
    public function __construct(
        private HyperliquidNonceStateRepository $repository,
        private ClockInterface $clock,
    ) {
    }

    public function isReady(HyperliquidNonceScope $scope): bool
    {
        return $this->repository->isReadyForScope($scope);
    }

    public function nextNonce(HyperliquidNonceScope $scope): int
    {
        $now = $this->clock->now();

        return $this->repository->reserveNext($scope, $this->toMilliseconds($now), $now);
    }

    public function recordObservedNonce(HyperliquidNonceScope $scope, int $nonce): void
    {
        $this->repository->recordObserved($scope, $nonce, $this->clock->now());
    }

    private function toMilliseconds(\DateTimeImmutable $now): int
    {
        return ((int) $now->format('U') * 1000) + ((int) floor(((int) $now->format('u')) / 1000));
    }
}
