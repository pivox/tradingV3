<?php

namespace App\Provider\Bitmart\WebSocket;

use App\Provider\Bitmart\Http\BitmartConfig;
use App\Provider\Bitmart\Http\BitmartRequestSigner;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Client WebSocket privé pour BitMart Futures V2.
 * Gère la construction des messages pour les canaux privés (orders, positions, asset/balance)
 * avec authentification.
 *
 * Documentation: https://developer-pro.bitmart.com/en/futuresv2/#format
 */
final class BitmartWebsocketPrivate extends BitmartWebsocketBase
{
    // Canaux privés Futures V2
    private const CHANNEL_ORDER = 'futures/order';
    private const CHANNEL_POSITION = 'futures/position';
    private const CHANNEL_ASSET_PATTERN = 'futures/asset:%s';

    // Device pour l'authentification (fixe selon BitMart)
    private const AUTH_DEVICE = 'web';

    public function __construct(
        private readonly BitmartConfig $config,
        private readonly BitmartRequestSigner $signer,
        #[Autowire(service: 'monolog.logger.bitmart')]
        private readonly LoggerInterface $bitmartLogger,
    ) {
    }

    /**
     * Construit un message d'authentification (login) pour les canaux privés.
     * Format: {"action":"access","args":[apiKey, timestamp, signature, "web"]}
     * Signature: HMAC_SHA256(secret, timestamp#memo#bitmart.WebSocket)
     *
     * @return array{action: string, args: array<string>}
     */
    public function buildLogin(): array
    {
        $timestamp = (string) (int) (microtime(true) * 1000);
        $signature = $this->signer->signWebSocket($timestamp);

        $payload = [
            'action' => self::ACTION_ACCESS,
            'args' => [
                $this->config->getApiKey(),
                $timestamp,
                $signature,
                self::AUTH_DEVICE,
            ],
        ];

        $this->bitmartLogger->info('[Bitmart WS] Build login', [
            'api_key' => $this->config->getApiKey(),
            'timestamp' => $timestamp,
        ]);

        return $payload;
    }

    /**
     * Construit un message de souscription pour les ordres.
     * Format topic: futures/order
     *
     * @return array{action: string, args: array<string>}
     */
    public function buildSubscribeOrder(): array
    {
        $payload = $this->buildSubscribePayload([self::CHANNEL_ORDER]);

        $this->bitmartLogger->info('[Bitmart WS] Build subscribe order', [
            'topic' => self::CHANNEL_ORDER,
        ]);

        return $payload;
    }

    /**
     * Construit un message de souscription pour les positions.
     * Format topic: futures/position
     *
     * @return array{action: string, args: array<string>}
     */
    public function buildSubscribePosition(): array
    {
        $payload = $this->buildSubscribePayload([self::CHANNEL_POSITION]);

        $this->bitmartLogger->info('[Bitmart WS] Build subscribe position', [
            'topic' => self::CHANNEL_POSITION,
        ]);

        return $payload;
    }

    /**
     * Construit un message de souscription pour l'asset (balance) d'une devise.
     * Format topic: futures/asset:{currency}
     *
     * @param string $currency Ex: 'USDT'
     * @return array{action: string, args: array<string>}
     */
    public function buildSubscribeAsset(string $currency): array
    {
        $topic = sprintf(self::CHANNEL_ASSET_PATTERN, $currency);
        $payload = $this->buildSubscribePayload([$topic]);

        $this->bitmartLogger->info('[Bitmart WS] Build subscribe asset', [
            'currency' => $currency,
            'topic' => $topic,
        ]);

        return $payload;
    }

    /**
     * Construit un message de souscription pour plusieurs canaux privés.
     *
     * @param array<string> $channels Liste des channels ('order', 'position', ou 'asset:USDT')
     * @return array{action: string, args: array<string>}
     */
    public function buildSubscribeMultiple(array $channels): array
    {
        $topics = [];
        foreach ($channels as $channel) {
            $topics[] = $this->normalizeChannel($channel);
        }

        $payload = $this->buildSubscribePayload($topics);

        $this->bitmartLogger->info('[Bitmart WS] Build subscribe multiple', [
            'channels' => $channels,
            'topics' => $topics,
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
     * Récupère le topic pour les ordres.
     *
     * @return string 'futures/order'
     */
    public function getOrderTopic(): string
    {
        return self::CHANNEL_ORDER;
    }

    /**
     * Récupère le topic pour les positions.
     *
     * @return string 'futures/position'
     */
    public function getPositionTopic(): string
    {
        return self::CHANNEL_POSITION;
    }

    /**
     * Récupère le topic pour un asset.
     *
     * @param string $currency Ex: 'USDT'
     * @return string Ex: 'futures/asset:USDT'
     */
    public function getAssetTopic(string $currency): string
    {
        return sprintf(self::CHANNEL_ASSET_PATTERN, $currency);
    }

    /**
     * Normalise un channel en topic complet.
     * Accepte 'order', 'position', ou 'asset:USDT' et retourne le topic complet.
     *
     * @param string $channel
     * @return string
     */
    private function normalizeChannel(string $channel): string
    {
        return match ($channel) {
            'order' => self::CHANNEL_ORDER,
            'position' => self::CHANNEL_POSITION,
            default => str_starts_with($channel, 'asset:')
                ? sprintf(self::CHANNEL_ASSET_PATTERN, substr($channel, 6))
                : $channel,
        };
    }
}

