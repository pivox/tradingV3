<?php

declare(strict_types=1);

namespace App\Provider\Context;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;

/**
 * Résout un {@see ExchangeContext} à partir d'un payload (query string ou JSON).
 *
 * Source de vérité partagée pour le jeu d'exchanges/markets accepté par les
 * endpoints HTTP (`/api/mtf/run`, `/api/mtf/selected-contracts`, ...). Garantit
 * que la sélection exposée et ce que le runner accepte restent strictement
 * alignés. Une entrée fournie mais invalide lève une {@see \InvalidArgumentException}
 * (à traduire en HTTP 400 côté contrôleur) plutôt que de retomber silencieusement
 * sur une valeur par défaut.
 */
final class ExchangeContextResolver
{
    /**
     * @param array<string,mixed> $data
     */
    public function resolve(
        array $data,
        Exchange $defaultExchange = Exchange::BITMART,
        MarketType $defaultMarket = MarketType::PERPETUAL,
    ): ExchangeContext {
        $exchangeInput = $data['exchange'] ?? $data['cex'] ?? null;
        $marketInput = $data['market_type'] ?? $data['type_contract'] ?? null;

        $exchange = $defaultExchange;
        if ($exchangeInput !== null) {
            if (!is_string($exchangeInput) || trim($exchangeInput) === '') {
                throw new \InvalidArgumentException('Invalid exchange parameter.');
            }
            $exchange = self::normalizeExchange($exchangeInput);
        }

        $marketType = $defaultMarket;
        if ($marketInput !== null) {
            if (!is_string($marketInput) || trim($marketInput) === '') {
                throw new \InvalidArgumentException('Invalid market_type parameter.');
            }
            $marketType = self::normalizeMarketType($marketInput);
        }

        return new ExchangeContext($exchange, $marketType);
    }

    public static function normalizeExchange(string $value): Exchange
    {
        return Exchange::tryFrom(strtolower(trim($value)))
            ?? throw new \InvalidArgumentException(sprintf('Unsupported exchange "%s".', $value));
    }

    public static function normalizeMarketType(string $value): MarketType
    {
        return match (strtolower(trim($value))) {
            'perpetual', 'perp', 'future', 'futures' => MarketType::PERPETUAL,
            'spot' => MarketType::SPOT,
            default => throw new \InvalidArgumentException(sprintf('Unsupported market type "%s".', $value)),
        };
    }
}
