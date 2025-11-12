<?php

declare(strict_types=1);

namespace App\Contract\MtfValidator;

use App\Contract\MtfValidator\Dto\MtfRunRequestDto;
use App\Contract\MtfValidator\Dto\MtfRunResponseDto;
use App\Provider\Context\ExchangeContext;

/**
 * Interface principale pour le validateur MTF
 * Inspiré de Symfony Contracts pour l'isolation des modules
 */
interface MtfValidatorInterface
{
    /**
     * Exécute un cycle de validation MTF
     */
    public function run(MtfRunRequestDto $request): MtfRunResponseDto;

    /**
     * Traite le recalcul des TP/SL pour les positions avec exactement 1 ordre TP
     * Cette méthode doit être appelée une seule fois après tous les workers pour éviter
     * les appels API multiples qui causent des erreurs 429.
     */
    public function processTpSlRecalculation(bool $dryRun, ?ExchangeContext $context = null): void;

    /**
     * Vérifie la santé du service
     */
    public function healthCheck(): bool;

    /**
     * Retourne le nom du service
     */
    public function getServiceName(): string;
}
