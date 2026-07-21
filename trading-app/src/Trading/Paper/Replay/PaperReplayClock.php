<?php

declare(strict_types=1);

namespace App\Trading\Paper\Replay;

use Psr\Clock\ClockInterface;

final class PaperReplayClock implements ClockInterface
{
    private \DateTimeImmutable $current;

    public function __construct(#[\SensitiveParameter] \DateTimeImmutable $current)
    {
        $this->current = self::toUtc($current);
    }

    public function now(): \DateTimeImmutable
    {
        return $this->current;
    }

    public function advanceTo(#[\SensitiveParameter] \DateTimeImmutable $next): void
    {
        $next = self::toUtc($next);
        if ($next < $this->current) {
            throw new \LogicException('paper_replay_clock_regression');
        }

        $this->current = $next;
    }

    private static function toUtc(\DateTimeImmutable $timestamp): \DateTimeImmutable
    {
        return $timestamp->setTimezone(new \DateTimeZone('UTC'));
    }
}
