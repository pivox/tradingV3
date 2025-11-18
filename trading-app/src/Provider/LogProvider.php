<?php

declare(strict_types=1);

namespace App\Provider;

use App\Logging\Dto\PositionsLogScanResult;
use App\Logging\PositionsLogScanner;

/**
 * Fournit un accès centralisé aux journaux locaux (positions/order journey).
 *
 * Ce provider encapsule la découverte des fichiers de logs et expose des
 * méthodes de lecture/scanning utilisées par les commandes d'investigation.
 */
final readonly class LogProvider
{
    public function __construct(
        private PositionsLogScanner $positionsLogScanner,
    ) {
    }

    /**
     * Retourne les fichiers de log positions-* les plus récents.
     *
     * @return list<string>
     */
    public function getRecentPositionLogFiles(int $maxFiles = 2): array
    {
        return $this->positionsLogScanner->findRecentPositionLogs(max(1, $maxFiles));
    }

    /**
     * Scan les logs positions-* pour un symbole donné.
     *
     * @param list<string>|null $logFiles Liste pré-filtrée à réutiliser (optionnelle)
     */
    public function scanPositionsLogsForSymbol(
        string $symbol,
        \DateTimeImmutable $since,
        ?array $logFiles = null,
        int $maxFiles = 2,
    ): PositionsLogScanResult {
        $files = $logFiles ?? $this->getRecentPositionLogFiles($maxFiles);

        return $this->positionsLogScanner->scanSymbol(
            $symbol,
            $files,
            $since
        );
    }
}
