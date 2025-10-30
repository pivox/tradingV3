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

            if (isset($response['data']['positions'])) {
                return array_map(fn($position) => PositionDto::fromArray($position), $response['data']['positions']);
            }

            return [];
        } catch (\Exception $e) {
            $this->logger->error("Erreur lors de la récupération des positions ouvertes", [
                'symbol' => $symbol,
                'error' => $e->getMessage()
            ]);
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
