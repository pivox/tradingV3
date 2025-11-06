<?php

declare(strict_types=1);

namespace App\Provider\Bitmart;

use App\Contract\Provider\Dto\AccountDto;
use App\Contract\Provider\Dto\PositionDto;
use App\Contract\Provider\AccountProviderInterface;
use App\Provider\Bitmart\Http\BitmartHttpClientPrivate;
use Psr\Log\LoggerInterface;

/**
 * Provider Bitmart pour les comptes
 */
#[\Symfony\Component\DependencyInjection\Attribute\Autoconfigure(
    bind: [
        AccountProviderInterface::class => '@app.provider.bitmart.account'
    ]
)]
final class BitmartAccountProvider implements AccountProviderInterface
{
    public function __construct(
        private readonly BitmartHttpClientPrivate $bitmartClient,
        private readonly LoggerInterface $logger
    ) {}

    public function getAccountInfo(string $basicCurrency = 'USDT'): ?AccountDto
    {
        try {
            $response = $this->bitmartClient->getAccount();

            if (isset($response['data'])) {
                foreach ($response['data'] as $balance) {
                    if ($balance['currency'] === $basicCurrency) {
                        return AccountDto::fromArray($balance);
                    }
                }
                return null;
            }

            return null;
        } catch (\Exception $e) {
            $this->logger->error("Erreur lors de la récupération des informations du compte", [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    public function getAccountBalance(string $basicCurrency = 'USDT'): float
    {
        try {
            $accountInfo = $this->getAccountInfo();
            return $accountInfo ? $accountInfo->availableBalance->toScale(8, 3)->toFloat() : 0.0;
        } catch (\Exception $e) {
            $this->logger->error("Erreur lors de la récupération du solde du compte", [
                'error' => $e->getMessage()
            ]);
            return 0.0;
        }
    }

    public function getOpenPositions(?string $symbol = null): array
    {
        try {
            $response = $this->bitmartClient->getPositions($symbol);

            // Log pour debug : structure de la réponse
            $this->logger->debug("BitMart positions response structure", [
                'symbol' => $symbol,
                'has_data' => isset($response['data']),
                'data_keys' => isset($response['data']) ? array_keys($response['data']) : [],
                'code' => $response['code'] ?? null,
                'message' => $response['message'] ?? null,
            ]);

            // Pour position-v2, la structure peut être directement dans 'data' ou dans 'data.positions'
            $positions = [];
            if (isset($response['data']['positions'])) {
                $positions = $response['data']['positions'];
            } elseif (isset($response['data']) && is_array($response['data']) && !empty($response['data'])) {
                // Fallback : parfois position-v2 retourne directement un tableau dans 'data'
                $firstItem = reset($response['data']);
                if (is_array($firstItem) && isset($firstItem['symbol'])) {
                    $positions = $response['data'];
                }
            }
            
            // Filtrer les positions avec amount > 0 (BitMart retourne toujours long+short même si vides)
            $positions = array_filter($positions, function($position) {
                $amount = $position['current_amount'] ?? $position['size'] ?? 0;
                return (float)$amount > 0;
            });
            
            if (empty($positions)) {
                return [];
            }
            
            return array_map(fn($position) => PositionDto::fromArray($position), $positions);
        } catch (\Exception $e) {
            $msg = $e->getMessage();
            $isRateLimited = (stripos($msg, '429') !== false) || (stripos($msg, '30013') !== false) || str_contains($msg, 'Too Many Requests');
            if ($isRateLimited) {
                $this->logger->warning("Erreur lors de la récupération des positions ouvertes (rate limited)", [
                    'symbol' => $symbol,
                    'error' => $msg,
                ]);
            } else {
                $this->logger->error("Erreur lors de la récupération des positions ouvertes", [
                    'symbol' => $symbol,
                    'error' => $msg,
                    'trace' => $e->getTraceAsString(),
                ]);
            }
            return [];
        }
    }

    public function getPosition(string $symbol): ?PositionDto
    {
        try {
            $positions = $this->getOpenPositions($symbol);
            return !empty($positions) ? $positions[0] : null;
        } catch (\Exception $e) {
            $this->logger->error("Erreur lors de la récupération de la position", [
                'symbol' => $symbol,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    public function getTradeHistory(string $symbol, int $limit = 100): array
    {
        try {
            $response = $this->bitmartClient->getOrderHistory($symbol, $limit);

            if (isset($response['data']['trades'])) {
                return $response['data']['trades'];
            }

            return [];
        } catch (\Exception $e) {
            $this->logger->error("Erreur lors de la récupération de l'historique des trades", [
                'symbol' => $symbol,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Récupère l'historique des trades pour tous les symboles avec positions
     * @return array<string, array> Tableau associatif [symbol => trades]
     */
    public function getAllTradeHistory(int $limit = 100): array
    {
        try {
            // Récupérer toutes les positions pour obtenir les symboles actifs
            $positions = $this->getOpenPositions();
            $allTrades = [];

            foreach ($positions as $position) {
                $symbol = $position->symbol;
                $trades = $this->getTradeHistory($symbol, $limit);
                if (!empty($trades)) {
                    $allTrades[$symbol] = $trades;
                }
            }

            return $allTrades;
        } catch (\Exception $e) {
            $this->logger->error("Erreur lors de la récupération de l'historique complet des trades", [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    public function getTradingFees(string $symbol): array
    {
        try {
            $response = $this->bitmartClient->getFeeRate($symbol);

            if (isset($response['data'])) {
                return $response['data'];
            }

            return [];
        } catch (\Exception $e) {
            $this->logger->error("Erreur lors de la récupération des frais de trading", [
                'symbol' => $symbol,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    public function healthCheck(): bool
    {
        try {
            $this->bitmartClient->getAccount();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getProviderName(): string
    {
        return 'Bitmart';
    }
}
