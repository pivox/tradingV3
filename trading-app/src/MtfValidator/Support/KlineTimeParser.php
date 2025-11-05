<?php

declare(strict_types=1);

namespace App\MtfValidator\Support;

final class KlineTimeParser
{
    public function parse(mixed $raw): ?\DateTimeImmutable
    {
        try {
            if ($raw instanceof \DateTimeImmutable) {
                return $raw->setTimezone(new \DateTimeZone('UTC'));
            }
            if ($raw instanceof \DateTimeInterface) {
                return (new \DateTimeImmutable($raw->format('Y-m-d H:i:s'), $raw->getTimezone()))
                    ->setTimezone(new \DateTimeZone('UTC'));
            }
            if (is_int($raw) || is_float($raw) || (is_string($raw) && ctype_digit($raw))) {
                $num = (int) $raw;
                if ($num > 2000000000) {
                    $num = intdiv($num, 1000);
                }
                return (new \DateTimeImmutable('@' . $num))->setTimezone(new \DateTimeZone('UTC'));
            }
            if (is_string($raw) && $raw !== '') {
                try {
                    return (new \DateTimeImmutable($raw, new \DateTimeZone('UTC')))
                        ->setTimezone(new \DateTimeZone('UTC'));
                } catch (\Throwable) {
                    $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw, new \DateTimeZone('UTC'));
                    if ($dt instanceof \DateTimeImmutable) {
                        return $dt->setTimezone(new \DateTimeZone('UTC'));
                    }
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }
}
