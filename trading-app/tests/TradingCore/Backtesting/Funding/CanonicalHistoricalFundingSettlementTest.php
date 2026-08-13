<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Backtesting\Funding;

use App\TradingCore\Backtesting\Funding\CanonicalHistoricalFundingSettlement;
use App\TradingCore\Backtesting\Funding\CanonicalHistoricalFundingSettlementException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CanonicalHistoricalFundingSettlement::class)]
final class CanonicalHistoricalFundingSettlementTest extends TestCase
{
    public function testLongPaysAndShortReceivesEveryCrossedInstant(): void
    {
        $authority = new CanonicalHistoricalFundingSettlement();

        $long = $authority->settle($this->request('long'));
        $short = $authority->settle($this->request('short'));

        self::assertSame('-0.015', $long['funding_cashflow_quote']);
        self::assertSame('0.015', $short['funding_cashflow_quote']);
        self::assertSame(['funding-1', 'funding-2'], $long['applied_source_record_ids']);
        self::assertSame(2, $long['applied_record_count']);
        self::assertMatchesRegularExpression('/\Asha256:[a-f0-9]{64}\z/D', $long['request_hash']);
        self::assertMatchesRegularExpression('/\Asha256:[a-f0-9]{64}\z/D', $long['result_hash']);
    }

    public function testEntryBoundaryIsExcludedAndExitBoundaryIncluded(): void
    {
        $request = $this->request('long');
        $request['entry_at'] = '2026-08-10T16:00:00.000000Z';
        $request['exit_at'] = '2026-08-11T00:00:00.000000Z';

        $result = (new CanonicalHistoricalFundingSettlement())->settle($request);

        self::assertSame(['funding-3'], $result['applied_source_record_ids']);
        self::assertSame('-0.01', $result['funding_cashflow_quote']);
    }

    public function testNegativeRateCreditsLong(): void
    {
        $request = $this->request('long');
        $request['records'][1]['funding_rate'] = '-0.00005';

        self::assertSame(
            '-0.0075',
            (new CanonicalHistoricalFundingSettlement())->settle($request)['funding_cashflow_quote'],
        );
    }

    public function testHashesUseUnescapedUtf8ForCrossLanguageParity(): void
    {
        $request = $this->request('long');
        $request['records'][0]['source_record_id'] = 'funding-é';

        $result = (new CanonicalHistoricalFundingSettlement())->settle($request);
        $encoded = json_encode(
            $request,
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        self::assertSame('sha256:' . hash('sha256', $encoded), $result['request_hash']);
    }

    public function testIncompleteOrLateScheduleFailsClosed(): void
    {
        foreach (['gap', 'late'] as $case) {
            $request = $this->request('long');
            if ($case === 'gap') {
                unset($request['records'][1]);
                $request['records'] = array_values($request['records']);
            } else {
                $request['records'][0]['available_at'] = '2026-08-10T08:00:01.000000Z';
            }
            try {
                (new CanonicalHistoricalFundingSettlement())->settle($request);
                self::fail('Expected fail-closed settlement.');
            } catch (CanonicalHistoricalFundingSettlementException $exception) {
                self::assertStringStartsWith('canonical_historical_funding_', $exception->getMessage());
            }
        }
    }

    public function testRecordCountAndByteSizedFieldsAreBounded(): void
    {
        $request = $this->request('long');
        $request['records'] = array_fill(0, 10001, $request['records'][0]);
        try {
            (new CanonicalHistoricalFundingSettlement())->settle($request);
            self::fail('Expected record bound rejection.');
        } catch (CanonicalHistoricalFundingSettlementException $exception) {
            self::assertSame('canonical_historical_funding_records_invalid', $exception->getMessage());
        }

        foreach (['source_record_id' => str_repeat('é', 65), 'funding_rate' => '0.' . str_repeat('1', 127)] as $field => $value) {
            $request = $this->request('long');
            $request['records'][0][$field] = $value;
            $this->expectRecordRejection($request);
        }
    }

    /** @param array<string, mixed> $request */
    private function expectRecordRejection(array $request): void
    {
        try {
            (new CanonicalHistoricalFundingSettlement())->settle($request);
            self::fail('Expected record rejection.');
        } catch (CanonicalHistoricalFundingSettlementException $exception) {
            self::assertStringStartsWith('canonical_historical_funding_', $exception->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function request(string $side): array
    {
        $records = [];
        foreach ([8, 16, 24] as $index => $hour) {
            $day = $hour === 24 ? 11 : 10;
            $normalizedHour = $hour === 24 ? 0 : $hour;
            $at = sprintf('2026-08-%02dT%02d:00:00.000000Z', $day, $normalizedHour);
            $records[] = [
                'source_record_id' => 'funding-' . ($index + 1),
                'funding_at' => $at,
                'available_at' => $at,
                'funding_rate' => '0.0001',
                'mark_price' => $index === 1 ? '50' : '100',
                'interval_seconds' => 28800,
            ];
        }

        return [
            'schema_version' => 'canonical-historical-funding-request.v1',
            'dataset_id' => 'backtest-dataset-' . str_repeat('a', 64),
            'dataset_checksum' => 'sha256:' . str_repeat('a', 64),
            'schedule_checksum' => 'sha256:' . str_repeat('b', 64),
            'plan_hash' => 'sha256:' . str_repeat('c', 64),
            'config_hash' => 'sha256:' . str_repeat('d', 64),
            'cost_input_hash' => 'sha256:' . str_repeat('e', 64),
            'symbol' => 'BTCUSDT',
            'side' => $side,
            'quantity' => '1',
            'contract_size' => '1',
            'entry_at' => '2026-08-10T07:00:00.000000Z',
            'exit_at' => '2026-08-10T16:00:00.000000Z',
            'coverage_start' => '2026-08-10T00:00:00.000000Z',
            'coverage_end' => '2026-08-11T00:00:00.000000Z',
            'records' => $records,
        ];
    }
}
