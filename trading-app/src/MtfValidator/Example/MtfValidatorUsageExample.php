<?php

declare(strict_types=1);

namespace App\MtfValidator\Example;

use App\Contract\MtfValidator\Dto\MtfRunRequestDto;
use App\Contract\MtfValidator\MtfValidatorInterface;
use App\Contract\MtfValidator\TimeframeProcessorInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Exemple d'utilisation des nouveaux contrats MtfValidator
 */
final class MtfValidatorUsageExample
{
    public function __construct(
        #[Autowire(service: 'app.mtf.validator')]
        private readonly MtfValidatorInterface $mtfValidator,

        #[Autowire(service: 'app.mtf.timeframe.processor')]
        private readonly TimeframeProcessorInterface $timeframeProcessor
    ) {}

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
            lockPerSymbol: false,
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
    }

    /**
     * Exemple d'utilisation du processeur de timeframe
     */
    public function exampleTimeframeProcessing(): void
    {
        // Vérifier si le processeur peut traiter un timeframe
        if ($this->timeframeProcessor->canProcess('1h')) {
            echo "Le processeur peut traiter le timeframe 1h\n";
        }

        // Obtenir le timeframe géré
        $timeframe = $this->timeframeProcessor->getTimeframeValue();
        echo "Timeframe géré: " . $timeframe . "\n";
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
