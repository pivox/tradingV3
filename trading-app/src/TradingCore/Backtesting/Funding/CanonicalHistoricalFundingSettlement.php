<?php

declare(strict_types=1);

namespace App\TradingCore\Backtesting\Funding;

use Brick\Math\BigDecimal;

final class CanonicalHistoricalFundingSettlement
{
    private const MAX_RECORDS = 10000;
    private const MAX_TEXT_BYTES = 128;
    private const HASH = '/\Asha256:[0-9a-f]{64}\z/D';
    private const DECIMAL = '/\A(?:0|[1-9][0-9]*)(?:\.[0-9]*[1-9])?\z/D';
    private const SIGNED_DECIMAL = '/\A-?(?:0|[1-9][0-9]*)(?:\.[0-9]*[1-9])?\z/D';
    private const TIME = 'Y-m-d\TH:i:s.u\Z';

    /** @param array<string, mixed> $request
     *  @return array<string, mixed>
     */
    public function settle(array $request): array
    {
        try {
            $this->assertShape($request);
            $entryAt = $this->time($request['entry_at']);
            $exitAt = $this->time($request['exit_at']);
            $coverageStart = $this->time($request['coverage_start']);
            $coverageEnd = $this->time($request['coverage_end']);
            if ($exitAt < $entryAt || $entryAt < $coverageStart || $exitAt > $coverageEnd) {
                throw new CanonicalHistoricalFundingSettlementException('canonical_historical_funding_coverage_invalid');
            }
            $quantity = $this->positiveDecimal($request['quantity']);
            $contractSize = $this->positiveDecimal($request['contract_size']);
            $sideFactor = $request['side'] === 'long' ? BigDecimal::of('-1') : BigDecimal::one();
            $records = $this->records($request['records'], $coverageStart, $coverageEnd);
            $cashflow = BigDecimal::zero();
            $applied = [];
            foreach ($records as $record) {
                if ($record['funding_at_value'] <= $entryAt || $record['funding_at_value'] > $exitAt) {
                    continue;
                }
                $cashflow = $cashflow->plus(
                    $quantity
                        ->multipliedBy($contractSize)
                        ->multipliedBy($record['mark_price_value'])
                        ->multipliedBy($record['funding_rate_value'])
                        ->multipliedBy($sideFactor),
                );
                $applied[] = $record['source_record_id'];
            }

            $requestHash = 'sha256:' . hash('sha256', $this->canonicalJson($request));
            $result = [
                'schema_version' => 'canonical-historical-funding-result.v1',
                'dataset_id' => $request['dataset_id'],
                'dataset_checksum' => $request['dataset_checksum'],
                'schedule_checksum' => $request['schedule_checksum'],
                'plan_hash' => $request['plan_hash'],
                'config_hash' => $request['config_hash'],
                'cost_input_hash' => $request['cost_input_hash'],
                'symbol' => $request['symbol'],
                'side' => $request['side'],
                'quantity' => $request['quantity'],
                'contract_size' => $request['contract_size'],
                'entry_at' => $request['entry_at'],
                'exit_at' => $request['exit_at'],
                'applied_source_record_ids' => $applied,
                'applied_record_count' => count($applied),
                'funding_cashflow_quote' => $this->canonicalDecimal($cashflow),
                'request_hash' => $requestHash,
            ];
            $result['result_hash'] = 'sha256:' . hash('sha256', $this->canonicalJson($result));

            return $result;
        } catch (CanonicalHistoricalFundingSettlementException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new CanonicalHistoricalFundingSettlementException(
                'canonical_historical_funding_request_invalid',
                0,
                $exception,
            );
        }
    }

    /** @param array<string, mixed> $request */
    private function assertShape(array $request): void
    {
        $expected = [
            'schema_version', 'dataset_id', 'dataset_checksum', 'schedule_checksum',
            'plan_hash', 'config_hash', 'cost_input_hash', 'symbol', 'side',
            'quantity', 'contract_size', 'entry_at', 'exit_at', 'coverage_start',
            'coverage_end', 'records',
        ];
        $keys = array_keys($request);
        sort($expected);
        sort($keys);
        if ($keys !== $expected
            || $request['schema_version'] !== 'canonical-historical-funding-request.v1'
            || !\is_string($request['dataset_id'])
            || preg_match('/\Abacktest-dataset-[0-9a-f]{64}\z/D', $request['dataset_id']) !== 1
            || !\is_string($request['dataset_checksum'])
            || $request['dataset_id'] !== 'backtest-dataset-' . substr($request['dataset_checksum'], 7)
            || !\is_string($request['symbol'])
            || preg_match('/\A[A-Z0-9]{2,32}\z/D', $request['symbol']) !== 1
            || !\in_array($request['side'], ['long', 'short'], true)
            || !\is_array($request['records'])
        ) {
            throw new CanonicalHistoricalFundingSettlementException('canonical_historical_funding_request_invalid');
        }
        foreach (['dataset_checksum', 'schedule_checksum', 'plan_hash', 'config_hash', 'cost_input_hash'] as $key) {
            if (!\is_string($request[$key]) || preg_match(self::HASH, $request[$key]) !== 1) {
                throw new CanonicalHistoricalFundingSettlementException('canonical_historical_funding_request_invalid');
            }
        }
    }

    /** @param list<mixed> $records
     *  @return list<array{source_record_id: string, funding_at_value: \DateTimeImmutable, funding_rate_value: BigDecimal, mark_price_value: BigDecimal}>
     */
    private function records(array $records, \DateTimeImmutable $coverageStart, \DateTimeImmutable $coverageEnd): array
    {
        if (!array_is_list($records) || $records === [] || count($records) > self::MAX_RECORDS) {
            throw new CanonicalHistoricalFundingSettlementException('canonical_historical_funding_records_invalid');
        }
        $normalized = [];
        $previousAt = null;
        $interval = null;
        $ids = [];
        foreach ($records as $record) {
            if (!\is_array($record) || array_keys($record) !== [
                'source_record_id', 'funding_at', 'available_at', 'funding_rate',
                'mark_price', 'interval_seconds',
            ] || !\is_string($record['source_record_id']) || $record['source_record_id'] === ''
                || strlen($record['source_record_id']) > self::MAX_TEXT_BYTES
                || isset($ids[$record['source_record_id']]) || !\is_int($record['interval_seconds'])
                || $record['interval_seconds'] < 1 || $record['interval_seconds'] > 604800
            ) {
                throw new CanonicalHistoricalFundingSettlementException('canonical_historical_funding_records_invalid');
            }
            $ids[$record['source_record_id']] = true;
            $at = $this->time($record['funding_at']);
            $availableAt = $this->time($record['available_at']);
            if ($availableAt > $at) {
                throw new CanonicalHistoricalFundingSettlementException('canonical_historical_funding_record_late');
            }
            $interval ??= $record['interval_seconds'];
            if ($record['interval_seconds'] !== $interval
                || ($previousAt !== null && $at != $previousAt->modify('+' . $interval . ' seconds'))
            ) {
                throw new CanonicalHistoricalFundingSettlementException('canonical_historical_funding_coverage_invalid');
            }
            $previousAt = $at;
            $normalized[] = [
                'source_record_id' => $record['source_record_id'],
                'funding_at_value' => $at,
                'funding_rate_value' => $this->signedDecimal($record['funding_rate']),
                'mark_price_value' => $this->positiveDecimal($record['mark_price']),
            ];
        }
        if ($normalized[0]['funding_at_value'] != $coverageStart->modify('+' . $interval . ' seconds')
            || $normalized[array_key_last($normalized)]['funding_at_value'] != $coverageEnd
        ) {
            throw new CanonicalHistoricalFundingSettlementException('canonical_historical_funding_coverage_invalid');
        }

        return $normalized;
    }

    private function time(mixed $value): \DateTimeImmutable
    {
        if (!\is_string($value)) {
            throw new CanonicalHistoricalFundingSettlementException('canonical_historical_funding_time_invalid');
        }
        $time = \DateTimeImmutable::createFromFormat('!' . self::TIME, $value, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$time instanceof \DateTimeImmutable || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))
            || $time->format(self::TIME) !== $value
        ) {
            throw new CanonicalHistoricalFundingSettlementException('canonical_historical_funding_time_invalid');
        }
        return $time;
    }

    private function positiveDecimal(mixed $value): BigDecimal
    {
        if (!\is_string($value) || strlen($value) > self::MAX_TEXT_BYTES
            || preg_match(self::DECIMAL, $value) !== 1 || !BigDecimal::of($value)->isPositive()) {
            throw new CanonicalHistoricalFundingSettlementException('canonical_historical_funding_decimal_invalid');
        }
        return BigDecimal::of($value);
    }

    private function signedDecimal(mixed $value): BigDecimal
    {
        if (!\is_string($value) || strlen($value) > self::MAX_TEXT_BYTES
            || preg_match(self::SIGNED_DECIMAL, $value) !== 1
            || (str_starts_with($value, '-') && BigDecimal::of($value)->isZero())
        ) {
            throw new CanonicalHistoricalFundingSettlementException('canonical_historical_funding_decimal_invalid');
        }
        return BigDecimal::of($value);
    }

    private function canonicalDecimal(BigDecimal $value): string
    {
        $canonical = (string) $value->stripTrailingZeros();
        return $canonical === '-0' ? '0' : $canonical;
    }

    private function canonicalJson(mixed $value): string
    {
        try {
            return json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (\JsonException $exception) {
            throw new CanonicalHistoricalFundingSettlementException(
                'canonical_historical_funding_encoding_invalid',
                0,
                $exception,
            );
        }
    }
}
