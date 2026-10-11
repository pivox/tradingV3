<?php

declare(strict_types=1);

namespace App\TradingCore\Backtesting\Research;

use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Execution\Enum\ShadowExecutionCapability;

/**
 * Closed catalogue of research cells. A frame without a ``profile`` key is the frozen
 * day_trading@1.1.0 long cell, so its signals, plans and hashes stay byte-identical.
 */
final readonly class ResearchProfile
{
    public const DAY_TRADING = 'day_trading';
    public const SWING_LONG = 'swing.long';
    public const SWING_SHORT = 'swing.short';
    public const IDS = [self::DAY_TRADING, self::SWING_LONG, self::SWING_SHORT];

    /**
     * @param list<string> $freshTimeframes contexts whose last bar must close at the evaluation instant
     * @param list<string> $mandatoryConfirmations
     */
    private function __construct(
        public string $id,
        public string $modeId,
        public string $modeVersion,
        public string $setupId,
        public string $setupVersion,
        public string $side,
        public int $evaluationStepMs,
        public string $candidateTimeframe,
        public array $freshTimeframes,
        public string $executionTimeframe,
        public array $mandatoryConfirmations,
        public float $dailyLossRate,
        public float $dailyLossAbsoluteQuote,
        public int $maxConcurrentPositions,
        public int $entryTtlSeconds,
        public int $cancelAfterSeconds,
        public int $holdingWindowSeconds,
        public bool $oneActivePlanPerSymbol,
    ) {
    }

    public static function fromId(string $id): self
    {
        return match ($id) {
            self::DAY_TRADING => new self(
                $id, 'day_trading', '1.1.0', 'day_trading.trend_continuation.long', '1.1.0', 'long',
                900_000, '15m', ['1m', '5m', '15m'], '15m', ['5m', '1m'],
                0.06, 30.0, 4, 90, 120, 28_800, false,
            ),
            // swing@1.0.0: 4h decisions, one active plan per pair, fourteen days without a daily close.
            self::SWING_LONG, self::SWING_SHORT => new self(
                $id, 'swing', '1.0.0', $id === self::SWING_LONG ? 'swing.breakout.long' : 'swing.breakout.short', '1.0.0',
                $id === self::SWING_LONG ? 'long' : 'short',
                14_400_000, '4h', ['1m', '5m', '15m', '1h', '4h'], '4h', ['4h'],
                1.0, 100_000.0, 10, 3600, 3660, 1_209_600, true,
            ),
            default => throw new \InvalidArgumentException('research_profile_invalid'),
        };
    }

    /**
     * The ``profile`` key is optional; when present it names a non-default profile.
     *
     * @param array<string, mixed> $frame
     */
    public static function fromFrame(array $frame): self
    {
        if (!array_key_exists('profile', $frame)) {
            return self::fromId(self::DAY_TRADING);
        }
        // An explicit day_trading profile would fork the frozen frame shape and its hashes.
        if (!is_string($frame['profile']) || $frame['profile'] === self::DAY_TRADING || !in_array($frame['profile'], self::IDS, true)) {
            throw new \InvalidArgumentException('research_profile_invalid');
        }

        return self::fromId($frame['profile']);
    }

    public function isDefault(): bool
    {
        return $this->id === self::DAY_TRADING;
    }

    public function request(): EffectiveTradingConfigRequest
    {
        return new EffectiveTradingConfigRequest(
            $this->modeId, $this->modeVersion, $this->setupId, $this->setupVersion,
            'fake', 'local', $this->side, ShadowExecutionCapability::Backtest,
        );
    }

    /** Candidate price source recorded on each plan. */
    public function candidatePriceSource(): string
    {
        return 'last_closed_' . $this->candidateTimeframe . '_close';
    }
}
