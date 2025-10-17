<?php

declare(strict_types=1);

namespace App\Bitmart\Ws;

use Psr\Log\LoggerInterface;

/**
 * Écoute un flux Kline BitMart en construisant le topic correct
 * puis en déléguant l’ouverture WS à PrivateWsClient.
 *
 * Usage (ex. via ta commande) :
 *   $klineStream->listen('BTCUSDT', '1m');       // Futures -> futures/klineBin1m:BTCUSDT
 *   $klineStream->listen('BTC_USDT', '1m');      // Spot    -> spot/kline1m:BTC_USDT
 */
final class KlineStream
{
    /** Intervalles supportés côté Futures (topics klineBin*) */
    private const FUTURES_INTERVAL_MAP = [
        '1m'  => 'klineBin1m',
        '3m'  => 'klineBin3m',
        '5m'  => 'klineBin5m',
        '15m' => 'klineBin15m',
        '30m' => 'klineBin30m',
        '1h'  => 'klineBin1h',
        '2h'  => 'klineBin2h',
        '4h'  => 'klineBin4h',
        '1d'  => 'klineBin1d',
        '1w'  => 'klineBin1w',
    ];

    /** Intervalles supportés côté Spot (topics kline*) */
    private const SPOT_INTERVAL_MAP = [
        '1m'  => 'kline1m',
        '3m'  => 'kline3m',
        '5m'  => 'kline5m',
        '15m' => 'kline15m',
        '30m' => 'kline30m',
        '1h'  => 'kline1h',
        '2h'  => 'kline2h',
        '4h'  => 'kline4h',
        '1d'  => 'kline1d',
        '1w'  => 'kline1w',
    ];

    public function __construct(
        private readonly PrivateWsClient $wsClient,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param string $contract  Futures: "BTCUSDT" | Spot: "BTC_USDT"
     * @param string $timeframe Ex: 1m, 5m, 15m, 30m, 1h, 4h, 1d, 1w
     */
    public function listen(string $contract, string $timeframe): void
    {
        $timeframe = strtolower(trim($timeframe));
        $isSpot = str_contains($contract, '_'); // Heuristique : underscore => Spot

        if ($isSpot) {
            $topic = $this->buildSpotTopic($contract, $timeframe);
        } else {
            $topic = $this->buildFuturesTopic($contract, $timeframe);
        }

        $this->logger->info('[BitMart KlineStream] Subscription topic', ['topic' => $topic]);
        // Démarre/maintient la connexion + ping/pong + backoff via PrivateWsClient
        $this->wsClient->run([$topic]);
    }

    private function buildFuturesTopic(string $contract, string $timeframe): string
    {
        $symbol = strtoupper(str_replace('_', '', $contract)); // "BTC_USDT" -> "BTCUSDT"
        $chan   = self::FUTURES_INTERVAL_MAP[$timeframe] ?? null;

        if ($chan === null) {
            throw new \InvalidArgumentException(sprintf(
                'Timeframe Futures non supporté: "%s". Intervalles valides: %s',
                $timeframe, implode(', ', array_keys(self::FUTURES_INTERVAL_MAP))
            ));
        }

        // Format attendu : futures/klineBin<tf>:<SYMBOL>
        return sprintf('futures/%s:%s', $chan, $symbol);
    }

    private function buildSpotTopic(string $contract, string $timeframe): string
    {
        // Spot attend "BTC_USDT" (avec underscore)
        $symbol = strtoupper($contract);
        if (!str_contains($symbol, '_')) {
            throw new \InvalidArgumentException('Symbole Spot invalide : attendu "BASE_QUOTE" (ex: BTC_USDT).');
        }

        $chan = self::SPOT_INTERVAL_MAP[$timeframe] ?? null;
        if ($chan === null) {
            throw new \InvalidArgumentException(sprintf(
                'Timeframe Spot non supporté: "%s". Intervalles valides: %s',
                $timeframe, implode(', ', array_keys(self::SPOT_INTERVAL_MAP))
            ));
        }

        // Format attendu : spot/kline<tf>:<BASE_QUOTE>
        return sprintf('spot/%s:%s', $chan, $symbol);
    }
}
