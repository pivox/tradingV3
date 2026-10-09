<?php

declare(strict_types=1);

namespace App\TradingCore\Backtesting\Research;

use App\TradingCore\Backtesting\CanonicalBacktestRuleEvaluator;

/** Versioned, explicitly non-historical instrument assumptions for research only. */
final readonly class ResearchInstrumentAssumptions
{
    public const SYMBOLS = ['BTCUSDT', 'ETHUSDT', 'BNBUSDT', 'XRPUSDT', 'ADAUSDT', 'DOGEUSDT', 'SOLUSDT', 'LTCUSDT', 'LINKUSDT', 'AVAXUSDT'];
    private const SOURCE_URL = 'https://fapi.binance.com/fapi/v1/exchangeInfo';
    private const FIELDS = ['symbol', 'tick_size', 'quantity_step', 'min_quantity', 'max_quantity', 'min_notional', 'contract_size', 'leverage_cap', 'mmr_proxy_rate', 'liquidation_fee_rate'];

    /** @param array<string, array<string, float|string>> $symbols */
    private function __construct(
        public array $symbols,
        public string $hash,
        public string $rawSha256,
        public string $retrievedAt,
        public string $status,
        public string $validityStatement,
    ) {
    }

    /** @param array<string, mixed> $manifest */
    public static function fromArray(array $manifest): self
    {
        self::exact($manifest, ['schema_version', 'source_url', 'retrieved_at', 'raw_sha256', 'validity_statement', 'assumption_status', 'symbols', 'manifest_hash']);
        if (($manifest['schema_version'] ?? null) !== 'research-instrument-assumptions.v1'
            || ($manifest['source_url'] ?? null) !== self::SOURCE_URL
            || ($manifest['assumption_status'] ?? null) !== 'current_metadata_not_historical'
            || !is_string($manifest['validity_statement']) || strlen(trim($manifest['validity_statement'])) < 40
            || !str_contains(strtolower($manifest['validity_statement']), 'not attested')
            || !self::instant($manifest['retrieved_at'])
            || !is_string($manifest['raw_sha256']) || preg_match('/\A[a-f0-9]{64}\z/D', $manifest['raw_sha256']) !== 1
            || !is_array($manifest['symbols']) || !array_is_list($manifest['symbols']) || count($manifest['symbols']) !== 10
        ) {
            throw new \InvalidArgumentException('research_instrument_manifest_invalid');
        }
        $symbols = [];
        foreach ($manifest['symbols'] as $index => $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new \InvalidArgumentException('research_instrument_manifest_invalid');
            }
            self::exact($row, self::FIELDS);
            if (($row['symbol'] ?? null) !== self::SYMBOLS[$index]) {
                throw new \InvalidArgumentException('research_instrument_manifest_invalid');
            }
            foreach (array_diff(self::FIELDS, ['symbol']) as $key) {
                if ((!is_float($row[$key]) && !is_int($row[$key])) || !is_finite((float) $row[$key])) {
                    throw new \InvalidArgumentException('research_instrument_manifest_invalid');
                }
                $row[$key] = (float) $row[$key];
            }
            if ($row['tick_size'] <= 0.0 || $row['quantity_step'] < 1.0e-12
                || $row['min_quantity'] < $row['quantity_step'] || $row['max_quantity'] < $row['min_quantity']
                || $row['min_notional'] <= 0.0 || $row['contract_size'] <= 0.0 || $row['leverage_cap'] < 1.0
                || $row['mmr_proxy_rate'] <= 0.0 || $row['mmr_proxy_rate'] >= 1.0
                || $row['liquidation_fee_rate'] < 0.0 || $row['liquidation_fee_rate'] >= 1.0
            ) {
                throw new \InvalidArgumentException('research_instrument_manifest_invalid');
            }
            $symbols[$row['symbol']] = $row;
        }
        $payload = $manifest;
        unset($payload['manifest_hash']);
        if (!is_string($manifest['manifest_hash']) || !hash_equals(CanonicalBacktestRuleEvaluator::canonicalHash($payload), $manifest['manifest_hash'])) {
            throw new \InvalidArgumentException('research_instrument_hash_invalid');
        }

        return new self($symbols, $manifest['manifest_hash'], $manifest['raw_sha256'], $manifest['retrieved_at'], $manifest['assumption_status'], $manifest['validity_statement']);
    }

    /** @return array<string, float|string> */
    public function forSymbol(string $symbol): array
    {
        return $this->symbols[$symbol] ?? throw new \InvalidArgumentException('research_instrument_symbol_invalid');
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $expected
     */
    private static function exact(array $value, array $expected): void
    {
        $keys = array_keys($value);
        sort($keys);
        sort($expected);
        if ($keys !== $expected) {
            throw new \InvalidArgumentException('research_instrument_manifest_invalid');
        }
    }

    private static function instant(mixed $value): bool
    {
        if (!is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $value) !== 1) {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        return $date instanceof \DateTimeImmutable
            && $date->format('Y-m-d\TH:i:s\Z') === $value
            && $date <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
