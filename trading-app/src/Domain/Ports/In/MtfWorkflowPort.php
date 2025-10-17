<?php

declare(strict_types=1);

namespace App\Domain\Ports\In;

use App\Domain\Common\Dto\KlineDto;
use App\Domain\Common\Dto\SignalDto;
use App\Domain\Common\Dto\ValidationStateDto;
use App\Domain\Common\Enum\Timeframe;

interface MtfWorkflowPort
{
    /**
     * Exécute le workflow MTF pour un symbole donné
     */
    public function executeMtfWorkflow(string $symbol): void;

    /**
     * Remplit les gaps de données pour un timeframe donné
     */
    public function fillGaps(string $symbol, Timeframe $timeframe): void;

    /**
     * Construit les signaux pour un timeframe donné
     */
    public function buildSignals(string $symbol, Timeframe $timeframe): void;

    /**
     * Valide l'alignement MTF
     */
    public function validateMtf(string $symbol): ValidationStateDto;

    /**
     * Traite les données WebSocket en temps réel
     */
    public function processWebSocketData(string $symbol, KlineDto $kline): void;

    /**
     * Planifie un ordre basé sur les signaux validés
     */
    public function planOrder(string $symbol, SignalDto $signal): void;
}




