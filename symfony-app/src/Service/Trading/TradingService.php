<?php
namespace App\Service\Trading;

use App\Repository\KlineRepository;
use App\Service\Config\TradingParameters;
use App\Service\Signals\Timeframe\Signal4hService;

class TradingService
{
    public function __construct(
        private TradingParameters $params,
        private KlineRepository $klineRepository,
        private Signal4hService $signal4h,
    ) {}

    public function getSignal(string $symbol, string $timeframe, int $limit = 300): array
    {
        // 1) Config YAML
        $config = $this->params->getConfig();

        // 2) Bougies selon timeframe
        $candles = $this->klineRepository->findRecentBySymbolAndTimeframe($symbol, $timeframe, $limit);

        // 3) Exécuter le signal (ici spécifique 4h, mais on peut généraliser après)
        $result = $this->signal4h->evaluate($candles);

        // 4) Retourner un tableau métier
        return [
            'symbol'    => $symbol,
            'timeframe' => $timeframe,
            'limit'     => $limit,
            'config'    => [
                'validation_order' => $config['validation_order'] ?? [],
                'risk'             => $config['risk'] ?? [],
            ],
            'result'    => $result,
        ];
    }
}
