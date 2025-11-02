<?php

declare(strict_types=1);

namespace App\Balance;

final class BalanceSignalFactory
{
    /**
     * Construit un signal à partir d'un évènement d'asset Bitmart reçu via WS.
     *
     * @param array<string,mixed> $assetData
     * @return BalanceSignal|null Retourne null si l'asset n'est pas USDT
     */
    public function createFromBitmartEvent(array $assetData): ?BalanceSignal
    {
        $asset = strtoupper((string)($assetData['currency'] ?? ''));
        
        // Filtrer uniquement USDT
        if ($asset !== 'USDT') {
            return null;
        }

        $timestampIso = $this->resolveTimestampIso($assetData);
        
        $payload = [
            'asset' => $asset,
            'available_balance' => (string)($assetData['available_balance'] ?? '0'),
            'frozen_balance' => (string)($assetData['frozen_balance'] ?? '0'),
            'equity' => (string)($assetData['equity'] ?? '0'),
            'unrealized_pnl' => (string)($assetData['unrealized_value'] ?? '0'),
            'position_deposit' => (string)($assetData['position_deposit'] ?? '0'),
            'bonus' => (string)($assetData['bonus'] ?? '0'),
            'timestamp' => $timestampIso,
            'context' => [
                'source' => 'bitmart_ws_worker',
                'raw_data' => $assetData,
            ],
        ];

        return BalanceSignal::fromArray($payload);
    }

    private function resolveTimestampIso(array $assetData): string
    {
        $timestamp = $assetData['timestamp'] ?? $assetData['update_time'] ?? null;
        
        if ($timestamp !== null) {
            $timestamp = (int) $timestamp;
            // Convertir les millisecondes en secondes si nécessaire
            if ($timestamp > 9_999_999_999) {
                $timestamp = (int) floor($timestamp / 1000);
            }
            return (new \DateTimeImmutable('@' . $timestamp))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format(\DateTimeInterface::ATOM);
        }

        // Si pas de timestamp fourni, utiliser l'heure actuelle
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
    }
}

