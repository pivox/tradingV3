<?php

namespace App\Provider\Bitmart\WebSocket;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Client WebSocket public pour BitMart Futures V2.
 * Gère la construction des messages pour les canaux publics (klines, ticker, depth, trade).
 *
 * Documentation: https://developer-pro.bitmart.com/en/futuresv2/#format
 */
final class BitmartWebsocketPublic extends BitmartWebsocketBase
{
    // Canaux publics Futures V2
    private const CHANNEL_KLINE_PATTERN = 'futures/klineBin%s:%s';
    private const CHANNEL_TICKER_PATTERN = 'futures/ticker:%s';
    private const CHANNEL_DEPTH_PATTERN = 'futures/depth:%s';
    private const CHANNEL_TRADE_PATTERN = 'futures/trade:%s';

    public function __construct(
        #[Autowire(service: 'monolog.logger.bitmart')]
        private readonly LoggerInterface $bitmartLogger,
    ) {
    }

    /**
     * Construit un message de souscription pour les klines d'un symbole et timeframe.
     * Format topic: futures/klineBin{timeframe}:{symbol}
     *
     * @param string $symbol Ex: 'BTCUSDT'
     * @param string $timeframe Ex: '1m', '5m', '1H', etc.
     * @return array{action: string, args: array<string>}
     */
    public function buildSubscribeKline(string $symbol, string $timeframe): array
    {
        $topic = $this->buildKlineTopic($symbol, $timeframe);
        $payload = $this->buildSubscribePayload([$topic]);

        $this->bitmartLogger->info('[Bitmart WS] Build subscribe kline', [
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'topic' => $topic,
        ]);

        return $payload;
    }

    /**
     * Construit un message de souscription pour plusieurs klines d'un même symbole.
     *
     * @param string $symbol Ex: 'BTCUSDT'
     * @param array<string> $timeframes Ex: ['1m', '5m', '1H']
     * @return array{action: string, args: array<string>}
     */
    public function buildSubscribeKlines(string $symbol, array $timeframes): array
    {
        $topics = [];
        foreach ($timeframes as $tf) {
            $topics[] = $this->buildKlineTopic($symbol, $tf);
        }

        $payload = $this->buildSubscribePayload($topics);

        $this->bitmartLogger->info('[Bitmart WS] Build subscribe klines', [
            'symbol' => $symbol,
            'timeframes' => $timeframes,
            'topics' => $topics,
        ]);

        return $payload;
    }

    /**
     * Construit un message de souscription pour le ticker d'un symbole.
     * Format topic: futures/ticker:{symbol}
     *
     * @param string $symbol Ex: 'BTCUSDT'
     * @return array{action: string, args: array<string>}
     */
    public function buildSubscribeTicker(string $symbol): array
    {
        $topic = sprintf(self::CHANNEL_TICKER_PATTERN, $symbol);
        $payload = $this->buildSubscribePayload([$topic]);

        $this->bitmartLogger->info('[Bitmart WS] Build subscribe ticker', [
            'symbol' => $symbol,
            'topic' => $topic,
        ]);

        return $payload;
    }

    /**
     * Construit un message de souscription pour la profondeur (order book) d'un symbole.
     * Format topic: futures/depth:{symbol}
     *
     * @param string $symbol Ex: 'BTCUSDT'
     * @return array{action: string, args: array<string>}
     */
    public function buildSubscribeDepth(string $symbol): array
    {
        $topic = sprintf(self::CHANNEL_DEPTH_PATTERN, $symbol);
        $payload = $this->buildSubscribePayload([$topic]);

        $this->bitmartLogger->info('[Bitmart WS] Build subscribe depth', [
            'symbol' => $symbol,
            'topic' => $topic,
        ]);

        return $payload;
    }

    /**
     * Construit un message de souscription pour les trades d'un symbole.
     * Format topic: futures/trade:{symbol}
     *
     * @param string $symbol Ex: 'BTCUSDT'
     * @return array{action: string, args: array<string>}
     */
    public function buildSubscribeTrade(string $symbol): array
    {
        $topic = sprintf(self::CHANNEL_TRADE_PATTERN, $symbol);
        $payload = $this->buildSubscribePayload([$topic]);

        $this->bitmartLogger->info('[Bitmart WS] Build subscribe trade', [
            'symbol' => $symbol,
            'topic' => $topic,
        ]);

        return $payload;
    }

    /**
     * Construit un message de désabonnement pour plusieurs topics.
     *
     * @param array<string> $topics Liste des topics à désabonner
     * @return array{action: string, args: array<string>}
     */
    public function buildUnsubscribe(array $topics): array
    {
        $payload = $this->buildUnsubscribePayload($topics);

        $this->bitmartLogger->info('[Bitmart WS] Build unsubscribe', [
            'topics' => $topics,
        ]);

        return $payload;
    }

    /**
     * Construit un message ping pour maintenir la connexion active.
     *
     * @return array{action: string}
     */
    public function buildPing(): array
    {
        return $this->buildPingPayload();
    }

    /**
     * Génère le topic pour une kline.
     *
     * @param string $symbol Ex: 'BTCUSDT'
     * @param string $timeframe Ex: '1m', '5m', '1H'
     * @return string Ex: 'futures/klineBin1m:BTCUSDT'
     */
    public function getKlineTopic(string $symbol, string $timeframe): string
    {
        return $this->buildKlineTopic($symbol, $timeframe);
    }

    /**
     * Génère le topic pour un ticker.
     *
     * @param string $symbol Ex: 'BTCUSDT'
     * @return string Ex: 'futures/ticker:BTCUSDT'
     */
    public function getTickerTopic(string $symbol): string
    {
        return sprintf(self::CHANNEL_TICKER_PATTERN, $symbol);
    }

    /**
     * Génère le topic pour la profondeur.
     *
     * @param string $symbol Ex: 'BTCUSDT'
     * @return string Ex: 'futures/depth:BTCUSDT'
     */
    public function getDepthTopic(string $symbol): string
    {
        return sprintf(self::CHANNEL_DEPTH_PATTERN, $symbol);
    }

    /**
     * Génère le topic pour les trades.
     *
     * @param string $symbol Ex: 'BTCUSDT'
     * @return string Ex: 'futures/trade:BTCUSDT'
     */
    public function getTradeTopic(string $symbol): string
    {
        return sprintf(self::CHANNEL_TRADE_PATTERN, $symbol);
    }
}

