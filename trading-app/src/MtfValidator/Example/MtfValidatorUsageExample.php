<?php

declare(strict_types=1);

namespace App\MtfValidator\Example;

use App\Contract\MtfValidator\Dto\MtfRunRequestDto;
use App\Contract\MtfValidator\MtfValidatorInterface;
use App\Contract\MtfValidator\TimeframeProcessorInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Exemple d'utilisation des nouveaux contrats MtfValidator
 */
final class MtfValidatorUsageExample
{
    public function __construct(
        private readonly MtfValidatorInterface $mtfValidator,

        #[AutowireIterator('app.mtf.timeframe.processor')]
        private readonly iterable $timeframeProcessors
    ) {
    }

    /**
     * Exemple d'utilisation du validateur MTF
     */
    public function exampleMtfValidation(): void
    {
        // Créer une requête MTF
        $request = new MtfRunRequestDto(
            symbols: ['BTCUSDT', 'ETHUSDT'],
            dryRun: true,
            forceRun: false,
            currentTf: '1h',
            forceTimeframeCheck: false,
            skipContextValidation: false,
            lockPerSymbol: true,
            userId: 'user123',
            ipAddress: '192.168.1.1'
        );

        // Exécuter la validation
        $response = $this->mtfValidator->run($request);

        // Vérifier le résultat
        if ($response->isSuccess()) {
            echo "Validation réussie pour " . $response->getProcessedSymbols() . " symboles\n";
        } elseif ($response->isPartialSuccess()) {
            echo "Validation partiellement réussie\n";
        } else {
            echo "Validation échouée\n";
        }

        // Afficher les détails
        echo "Taux de succès: " . $response->successRate . "%\n";
        echo "Temps d'exécution: " . $response->executionTimeSeconds . "s\n";
        echo "Décisions retournées: " . count($response->results) . " entrées\n";
    }

    /**
     * Exemple d'utilisation du processeur de timeframe
     */
    public function exampleTimeframeProcessing(): void
    {
        foreach ($this->timeframeProcessors as $processor) {
            if (!$processor instanceof TimeframeProcessorInterface) {
                continue;
            }

            $timeframe = $processor->getTimeframeValue();
            echo sprintf("Processeur détecté pour le timeframe %s\n", $timeframe);

            if ($processor->canProcess('1h')) {
                echo " → compatible avec la validation 1h\n";
            }
        }
    }

    /**
     * Exemple de vérification de santé
     */
    public function exampleHealthCheck(): void
    {
        if ($this->mtfValidator->healthCheck()) {
            echo "Service MTF en bonne santé\n";
        } else {
            echo "Service MTF en panne\n";
        }

        echo "Nom du service: " . $this->mtfValidator->getServiceName() . "\n";
    }
}
