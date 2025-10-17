<?php

declare(strict_types=1);

namespace App\Service\Pipeline;

// @tag:mtf-core  Calcul des slots alignés et relations parent/enfant des TF

final class SlotService
{
    public function parentOf(string $tf): ?string
    {
        return match ($tf) {
            '1m' => '15m',
            '5m' => '15m',
            '15m' => '1h',
            '1h' => '4h',
            default => null,
        };
    }

    public function slotLengthMinutes(string $tf): int
    {
        return match ($tf) {
            '1m' => 1,
            '5m' => 5,
            '15m' => 15,
            '1h' => 60,
            '4h' => 240,
            default => throw new \InvalidArgumentException("TF inconnu: $tf"),
        };
    }

    public function currentSlot(string $tf, ?\DateTimeImmutable $now = null): \DateTimeImmutable
    {
        $now = $now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $tfMinutes = $this->slotLengthMinutes($tf);
        $minutes = (int)$now->format('i');
        $minuteAligned = $minutes - ($minutes % $tfMinutes);
        $aligned = $now->setTime((int)$now->format('H'), $minuteAligned, 0);
        if ($tf === '4h') {
            $h = (int)$aligned->format('H');
            $hAligned = $h - ($h % 4);
            $aligned = $aligned->setTime($hAligned, 0, 0);
        } elseif ($tf === '1h') {
            $aligned = $aligned->setTime((int)$aligned->format('H'), 0, 0);
        }
        return $aligned;
    }
}
