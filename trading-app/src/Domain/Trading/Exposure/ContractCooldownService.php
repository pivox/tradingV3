<?php

declare(strict_types=1);

namespace App\Domain\Trading\Exposure;

use App\Entity\ContractCooldown;
use App\Repository\ContractCooldownRepository;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final class ContractCooldownService
{
    private const DEFAULT_DURATION = 'PT4H';

    public function __construct(
        private readonly ContractCooldownRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isCoolingDown(string $symbol): bool
    {
        $cooldown = $this->getActiveCooldown($symbol);
        if ($cooldown !== null) {
            $this->logger->debug('[Cooldown] Symbol locked', [
                'symbol' => strtoupper($symbol),
                'until' => $cooldown->getActiveUntil()->format(DATE_ATOM),
                'reason' => $cooldown->getReason(),
            ]);
        }

        return $cooldown !== null;
    }

    public function getActiveCooldown(string $symbol): ?ContractCooldown
    {
        $now = $this->utcNow();
        return $this->repository->findActive($symbol, $now);
    }

    public function startCooldown(string $symbol, ?DateInterval $duration = null, string $reason = 'position_closed'): void
    {
        $duration ??= new DateInterval(self::DEFAULT_DURATION);
        $now = $this->utcNow();
        $until = $now->add($duration);

        $existing = $this->repository->findActive($symbol, $now);
        if ($existing instanceof ContractCooldown) {
            $existing->extendUntil($until)->updateReason($reason);
            $this->entityManager->persist($existing);
        } else {
            $cooldown = new ContractCooldown($symbol, $until, $reason);
            $this->entityManager->persist($cooldown);
        }

        $this->entityManager->flush();

        $this->logger->info('[Cooldown] Symbol cooldown started', [
            'symbol' => strtoupper($symbol),
            'reason' => $reason,
            'until' => $until->format(DATE_ATOM),
        ]);
    }

    public function clear(string $symbol): void
    {
        $existing = $this->repository->findOneBy(['symbol' => strtoupper($symbol)]);
        if ($existing === null) {
            return;
        }

        $this->entityManager->remove($existing);
        $this->entityManager->flush();

        $this->logger->info('[Cooldown] Symbol cooldown cleared', [
            'symbol' => strtoupper($symbol),
        ]);
    }

    private function utcNow(): DateTimeImmutable
    {
        $now = $this->clock->now();
        return DateTimeImmutable::createFromInterface($now)->setTimezone(new DateTimeZone('UTC'));
    }
}
