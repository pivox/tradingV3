<?php

namespace App\Provider\Bitmart\WebSocket;

/**
 * Classe abstraite de base pour les clients WebSocket BitMart Futures V2.
 * Contient les constantes communes pour les actions WebSocket.
 */
abstract class BitmartWebsocketBase
{
    // Actions WebSocket communes
    protected const ACTION_SUBSCRIBE = 'subscribe';
    protected const ACTION_UNSUBSCRIBE = 'unsubscribe';
    protected const ACTION_PING = 'ping';
    protected const ACTION_ACCESS = 'access'; // Pour l'authentification privée

    // Préfixe des canaux Futures V2
    protected const CHANNEL_PREFIX_FUTURES = 'futures/';

    // Mapping des timeframes pour les klines
    protected const TIMEFRAME_MAPPING = [
        '1m' => '1m',
        '5m' => '5m',
        '15m' => '15m',
        '30m' => '30m',
        '1h' => '1H',
        '2h' => '2H',
        '4h' => '4H',
        '1d' => '1D',
        '1w' => '1W',
    ];

    /**
     * Construit un topic de kline pour un symbole et un timeframe donnés.
     * Format: futures/klineBin{timeframe}:{symbol}
     *
     * @param string $symbol Ex: 'BTCUSDT'
     * @param string $timeframe Ex: '1m', '5m', '1H', etc.
     * @return string Ex: 'futures/klineBin1m:BTCUSDT'
     */
    protected function buildKlineTopic(string $symbol, string $timeframe): string
    {
        $normalizedTf = self::TIMEFRAME_MAPPING[$timeframe] ?? $timeframe;
        return self::CHANNEL_PREFIX_FUTURES . "klineBin{$normalizedTf}:{$symbol}";
    }

    /**
     * Construit un payload pour une action subscribe.
     *
     * @param array<string> $topics Liste des topics à souscrire
     * @return array{action: string, args: array<string>}
     */
    protected function buildSubscribePayload(array $topics): array
    {
        return [
            'action' => self::ACTION_SUBSCRIBE,
            'args' => $topics,
        ];
    }

    /**
     * Construit un payload pour une action unsubscribe.
     *
     * @param array<string> $topics Liste des topics à désabonner
     * @return array{action: string, args: array<string>}
     */
    protected function buildUnsubscribePayload(array $topics): array
    {
        return [
            'action' => self::ACTION_UNSUBSCRIBE,
            'args' => $topics,
        ];
    }

    /**
     * Construit un payload pour une action ping (keep-alive).
     *
     * @return array{action: string}
     */
    protected function buildPingPayload(): array
    {
        return [
            'action' => self::ACTION_PING,
        ];
    }
}

