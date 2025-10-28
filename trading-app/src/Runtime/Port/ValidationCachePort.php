<?php

declare(strict_types=1);

namespace App\Runtime\Port;

/**
 * Port pour le cache de validation
 * Interface temporaire pour permettre au système de fonctionner
 */
interface ValidationCachePort
{
    public function get(string $key): ?string;
    public function set(string $key, string $value, int $ttl = 3600): void;
    public function delete(string $key): void;
}
