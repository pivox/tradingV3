<?php

declare(strict_types=1);

namespace App\MtfValidator\Service\Dto;

/**
 * DTO pour le résultat d'un symbole
 * Status normalisé: READY | INVALID | ERROR | COMPLETED
 */
final class SymbolResultDto
{
    public function __construct(
        public readonly string $symbol,
        public readonly string $status,
        public readonly ?string $executionTf = null,
        public readonly ?string $blockingTf = null,
        public readonly ?string $signalSide = null,
        public readonly ?array $tradingDecision = null,
        public readonly ?array $error = null,
        public readonly ?array $context = null,
        public readonly ?float $currentPrice = null,
        public readonly ?float $atr = null,
        public readonly ?string $validationModeUsed = null,
        public readonly ?string $tradeEntryModeUsed = null
    ) {}

    public function isSuccess(): bool
    {
        return strtoupper($this->status) === 'SUCCESS' || strtoupper($this->status) === 'READY' || strtoupper($this->status) === 'COMPLETED';
    }

    public function isError(): bool
    {
        return strtoupper($this->status) === 'ERROR';
    }

    public function isSkipped(): bool
    {
        return strtoupper($this->status) === 'SKIPPED';
    }

    public function hasTradingDecision(): bool
    {
        return $this->tradingDecision !== null;
    }

    /**
     * Normalise le trading_decision en enrichissant avec reason, message, entry depuis raw
     */
    private function normalizeTradingDecision(?array $tradingDecision): ?array
    {
        if ($tradingDecision === null) {
            return null;
        }

        $normalized = $tradingDecision;
        $raw = $tradingDecision['raw'] ?? [];

        // Remonter reason depuis raw si absent au niveau principal
        if (!isset($normalized['reason']) && isset($raw['reason'])) {
            $normalized['reason'] = $raw['reason'];
        }

        // Remonter message depuis raw si absent
        if (!isset($normalized['message']) && isset($raw['message'])) {
            $normalized['message'] = $raw['message'];
        }

        // Remonter entry depuis raw si absent (pour skipped_out_of_zone par exemple)
        if (!isset($normalized['entry']) && isset($raw['context'])) {
            $context = $raw['context'];
            $entry = [];
            if (isset($context['candidate'])) {
                $entry['price_candidate'] = $context['candidate'];
            }
            if (isset($context['zone_min'])) {
                $entry['zone_min'] = $context['zone_min'];
            }
            if (isset($context['zone_max'])) {
                $entry['zone_max'] = $context['zone_max'];
            }
            if (isset($context['zone_dev_pct'])) {
                $entry['zone_dev_pct'] = $context['zone_dev_pct'];
            }
            if (isset($context['zone_max_dev_pct'])) {
                $entry['zone_max_dev_pct'] = $context['zone_max_dev_pct'];
            }
            if ($entry !== []) {
                $normalized['entry'] = $entry;
            }
        }

        // Remonter failed_checks depuis raw si absent
        if (!isset($normalized['failed_checks']) && isset($raw['failed_checks'])) {
            $normalized['failed_checks'] = $raw['failed_checks'];
        }

        // Garder raw pour compatibilité mais ne plus le rendre nécessaire
        if (!isset($normalized['raw'])) {
            $normalized['raw'] = $raw;
        }

        return $normalized;
    }

    public function toArray(): array
    {
        // Normaliser le status (READY | INVALID | ERROR | COMPLETED)
        $normalizedStatus = $this->normalizeStatus($this->status);

        return [
            'symbol' => $this->symbol,
            'status' => $normalizedStatus,
            'execution_tf' => $this->executionTf,
            'blocking_tf' => $this->blockingTf,
            'signal_side' => $this->signalSide ?? 'NONE',
            'trading_decision' => $this->normalizeTradingDecision($this->tradingDecision),
            'error' => $this->error,
            'context' => $this->context,
            'current_price' => $this->currentPrice,
            'atr' => $this->atr,
            'validation_mode_used' => $this->validationModeUsed,
            'trade_entry_mode_used' => $this->tradeEntryModeUsed,
        ];
    }

    /**
     * Normalise le status vers l'enum attendu: READY | INVALID | ERROR | COMPLETED
     */
    private function normalizeStatus(string $status): string
    {
        $upper = strtoupper($status);
        // SUCCESS est mappé vers COMPLETED pour cohérence
        if ($upper === 'SUCCESS') {
            return 'COMPLETED';
        }
        // SKIPPED n'est pas un status de symbole, mais de trading_decision
        // On le mappe vers READY car le symbole est prêt mais la décision a été skippée
        if ($upper === 'SKIPPED') {
            return 'READY';
        }
        // Autres status valides
        if (in_array($upper, ['READY', 'INVALID', 'ERROR', 'COMPLETED'], true)) {
            return $upper;
        }
        // Par défaut, si inconnu, on retourne INVALID
        return 'INVALID';
    }
}
