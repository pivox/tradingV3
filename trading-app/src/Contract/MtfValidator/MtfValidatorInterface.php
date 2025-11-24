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
     * Retourne le nom du service
     */
    public function getServiceName(): string;
}
