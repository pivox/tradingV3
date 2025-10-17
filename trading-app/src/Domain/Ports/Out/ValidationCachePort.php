<?php

declare(strict_types=1);

namespace App\Domain\Ports\Out;

use App\Domain\Common\Dto\ValidationStateDto;

interface ValidationCachePort
{
    /**
     * Met en cache un état de validation
     */
    public function cacheValidationState(ValidationStateDto $state): void;

    /**
     * Récupère un état de validation depuis le cache
     */
    public function getValidationState(string $cacheKey): ?ValidationStateDto;

    /**
     * Vérifie si un état de validation est en cache et valide
     */
    public function isValidationCached(string $cacheKey): bool;

    /**
     * Invalide un état de validation
     */
    public function invalidateValidation(string $cacheKey): void;

    /**
     * Purge le cache de validation expiré
     */
    public function purgeExpiredValidations(): int;

    /**
     * Récupère tous les états de validation pour un symbole
     */
    public function getValidationStates(string $symbol): array;
}




