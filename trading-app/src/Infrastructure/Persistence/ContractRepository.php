<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Repository\ContractRepository as BaseContractRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class ContractRepository
{
    public function __construct(
        private readonly BaseContractRepository $baseRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * UPSERT des contrats - évite les doublons
     */
    public function upsertContracts(array $contracts): int
    {
        if (empty($contracts)) {
            return 0;
        }

        $this->logger->info('Starting contracts upsert', [
            'count' => count($contracts)
        ]);

        $upsertedCount = $this->baseRepository->upsertContracts($contracts);

        $this->logger->info('Contracts upsert completed', [
            'total_upserted' => $upsertedCount,
            'total_input' => count($contracts)
        ]);

        return $upsertedCount;
    }

    /**
     * Récupère tous les contrats actifs
     */
    public function getActiveContracts(): array
    {
        return $this->baseRepository->findActiveContracts();
    }

    /**
     * Récupère un contrat par symbole
     */
    public function getContractBySymbol(string $symbol): ?\App\Entity\Contract
    {
        return $this->baseRepository->findBySymbol($symbol);
    }

    /**
     * Compte les contrats actifs
     */
    public function countActiveContracts(): int
    {
        return $this->baseRepository->countActiveContracts();
    }

    /**
     * Récupère les statistiques des contrats
     */
    public function getContractStats(): array
    {
        return $this->baseRepository->getContractStats();
    }

    /**
     * Filtre les contrats actifs selon les critères de trading
     */
    public function filterActiveContracts(array $contracts): array
    {
        $activeContracts = [];
        $blacklistedSymbols = $this->getBlacklistedSymbols();

        foreach ($contracts as $contract) {
            // Vérifier les critères de base
            if (!$this->isContractActive($contract)) {
                continue;
            }

            // Vérifier si le symbole n'est pas blacklisté
            $symbol = $contract['symbol'] ?? '';
            if (in_array($symbol, $blacklistedSymbols)) {
                continue;
            }

            $activeContracts[] = $contract;
        }

        return $activeContracts;
    }

    /**
     * Vérifie si un contrat est actif selon les critères
     */
    private function isContractActive(array $contract): bool
    {
        // 1. Devise de quote doit être USDT
        $quoteCurrency = $contract['quote_currency'] ?? '';
        if ($quoteCurrency !== 'USDT') {
            return false;
        }

        // 2. Statut doit être "Trading"
        $status = $contract['status'] ?? '';
        if ($status !== 'Trading') {
            return false;
        }

        // 3. Volume 24h minimum de 500,000 USDT
        $volume24h = floatval($contract['volume_24h'] ?? 0);
        if ($volume24h < 500_000) {
            return false;
        }

        // 4. Vérifier que le contrat n'est pas trop récent (moins de 45 jours)
        $openTimestamp = intval($contract['open_timestamp'] ?? 0);
        if ($openTimestamp > 0) {
            $openDate = new \DateTimeImmutable('@' . ($openTimestamp / 1000));
            $minDate = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->modify('-45 days');
            
            if ($openDate > $minDate) {
                return false;
            }
        }

        return true;
    }

    /**
     * Retourne la liste des symboles blacklistés
     */
    private function getBlacklistedSymbols(): array
    {
        return [
            // Ajouter ici les symboles à blacklister
            // Exemples de symboles souvent problématiques :
            // 'LUNAUSDT',
            // 'USTCUSDT',
        ];
    }
}




