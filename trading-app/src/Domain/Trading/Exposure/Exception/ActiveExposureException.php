<?php

declare(strict_types=1);

namespace App\Domain\Trading\Exposure\Exception;

use RuntimeException;

final class ActiveExposureException extends RuntimeException
{
    public static function forPosition(string $symbol): self
    {
        return new self(sprintf('Une position est déjà ouverte sur %s', strtoupper($symbol)));
    }

    public static function forOrder(string $symbol): self
    {
        return new self(sprintf('Un ordre est déjà en attente sur %s', strtoupper($symbol)));
    }

    public static function forCooldown(string $symbol, string $until): self
    {
        return new self(sprintf('Le contrat %s est en cooldown jusqu\'à %s', strtoupper($symbol), $until));
    }

    public static function forGlobalLimit(int $max): self
    {
        return new self(sprintf('Nombre maximum de positions ouvertes atteint (%d)', $max));
    }
}

