<?php

declare(strict_types=1);

namespace App\Trading\Paper\Replay;

use Psr\Clock\ClockInterface;

final class PaperReplayClock implements ClockInterface
{
    private \DateTimeImmutable $current;

    public function __construct(#[\SensitiveParameter] ?\DateTimeImmutable $current = null)
    {
        $this->current = self::toUtc($current ?? new \DateTimeImmutable('@0'));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->current;
    }

    public function advanceTo(#[\SensitiveParameter] \DateTimeImmutable $next): void
    {
        $next = self::toUtc($next);
        $this->assertCanAdvanceTo($next);

        $this->current = $next;
    }

    public function assertCanAdvanceTo(#[\SensitiveParameter] \DateTimeImmutable $next): void
    {
        if (self::toUtc($next) < $this->current) {
            throw new \LogicException('paper_replay_clock_regression');
        }
    }

    private static function toUtc(\DateTimeImmutable $timestamp): \DateTimeImmutable
    {
        return $timestamp->setTimezone(new \DateTimeZone('UTC'));
    }
}
