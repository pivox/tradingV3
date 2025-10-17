<?php

namespace App\Service\Trading;

use App\Service\Bitmart\Private\AssetsService;
use Psr\Log\LoggerInterface;

class AssetsDetails
{
    public function __construct(
        private readonly AssetsService $assetsService,
        private LoggerInterface $logger
    ) {}


    public function hasEnoughtMoneyInUsdt($symbol, float $amountUsdt = 50): bool
    {
        $resp = $this->assetsService->getAssetsDetail();
        $code = $resp['code'] ?? null;
        if ($code !== 1000) {
            $this->logger->error('Cron 1m: échec récupération assets-detail, on annule l\'envoi', ['response' => $resp]);
            return false;
        }
        $data = $resp['data'] ?? [];
        $usdtAvailable = 0.0;
        foreach ($data as $row) {
            if (($row['currency'] ?? null) === 'USDT') {
                $usdtAvailable = (float)($row['available_balance'] ?? 0);
                break;
            }
        }
        if ($usdtAvailable <= $amountUsdt) {
            $this->logger->error('Cron 1m: solde USDT insuffisant, pas d\'envoi', [
                'available_balance' => $usdtAvailable,
                'threshold' => $amountUsdt
            ]);
        }
        return true;
    }
}
