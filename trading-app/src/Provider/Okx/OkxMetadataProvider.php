<?php

declare(strict_types=1);

namespace App\Provider\Okx;

use App\Contract\Provider\ContractProviderInterface;
use App\Contract\Provider\Dto\ContractDto;
use App\Exchange\Okx\OkxInstrumentResolver;
use App\Exchange\Okx\OkxRestClientInterface;
use App\Provider\Okx\Dto\OkxInstrumentMetadataDto;

final class OkxMetadataProvider implements ContractProviderInterface
{
    private OkxPublicReadMapper $mapper;

    public function __construct(
        private readonly ?OkxRestClientInterface $client = null,
        private readonly ?OkxInstrumentResolver $instruments = null,
        private readonly ?OkxAccountGateway $account = null,
    ) {
        $this->mapper = new OkxPublicReadMapper($this->resolver());
    }

    /**
     * @return ContractDto[]
     */
    public function getContracts(): array
    {
        $contracts = [];
        foreach ($this->instrumentRows(__METHOD__) as $row) {
            $this->assertSizingMetadata($row, __METHOD__);
            $contracts[] = $this->mapper->contract($row);
        }

        usort(
            $contracts,
            static fn (ContractDto $a, ContractDto $b): int => $a->symbol <=> $b->symbol,
        );

        return $contracts;
    }

    public function getContractDetails(string $symbol): ?ContractDto
    {
        $instId = $this->resolver()->instId($symbol);
        foreach ($this->instrumentRows(__METHOD__) as $row) {
            if ((string) ($row['instId'] ?? '') !== $instId) {
                continue;
            }

            $this->assertSizingMetadata($row, __METHOD__);

            return $this->mapper->contract($row, $this->ticker($instId, __METHOD__));
        }

        return null;
    }

    public function getLastPrice(string $symbol): ?float
    {
        $ticker = $this->ticker($this->resolver()->instId($symbol), __METHOD__);
        $last = $ticker['last'] ?? null;

        return \is_numeric($last) ? (float) $last : null;
    }

    public function getInstrumentMetadata(string $symbol): ?OkxInstrumentMetadataDto
    {
        $instId = $this->resolver()->instId($symbol);
        foreach ($this->instrumentRows(__METHOD__) as $row) {
            if ((string) ($row['instId'] ?? '') !== $instId) {
                continue;
            }

            $this->assertSizingMetadata($row, __METHOD__);

            return $this->metadataFromRow($row);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getOrderBook(string $symbol, int $limit = 50): array
    {
        $instId = $this->resolver()->instId($symbol);
        $payload = $this->publicGet('/api/v5/market/books', [
            'instId' => $instId,
            'sz' => max(1, min($limit, 400)),
        ], __METHOD__);
        $row = $this->firstRow($payload, __METHOD__);

        return [
            'symbol' => strtoupper($symbol),
            'instrument_id' => $instId,
            'timestamp' => $this->mapper->time($row['ts'] ?? null),
            'bids' => $this->mapper->orderBookLevels(\is_array($row['bids'] ?? null) ? $row['bids'] : []),
            'asks' => $this->mapper->orderBookLevels(\is_array($row['asks'] ?? null) ? $row['asks'] : []),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    public function getRecentTrades(string $symbol, int $limit = 100): array
    {
        $payload = $this->publicGet('/api/v5/market/trades', [
            'instId' => $this->resolver()->instId($symbol),
            'limit' => max(1, min($limit, 500)),
        ], __METHOD__);

        return $this->dataRows($payload, __METHOD__);
    }

    /**
     * @return array<int, mixed>
     */
    public function getMarkPriceKline(
        string $symbol,
        int $step = 1,
        int $limit = 1,
        ?int $startTime = null,
        ?int $endTime = null,
    ): array {
        $query = [
            'instId' => $this->resolver()->instId($symbol),
            'bar' => $this->barFromStep($step),
            'limit' => max(1, min($limit, 100)),
        ];
        if ($startTime !== null) {
            $query['before'] = (string) ($startTime * 1000);
        }
        if ($endTime !== null) {
            $query['after'] = (string) ($endTime * 1000);
        }

        $payload = $this->publicGet('/api/v5/market/mark-price-candles', $query, __METHOD__);

        return $this->dataRows($payload, __METHOD__);
    }

    /**
     * @return array<int|string, mixed>
     */
    public function getLeverageBrackets(string $symbol): array
    {
        $instId = $this->resolver()->instId($symbol);
        foreach ($this->instrumentRows(__METHOD__) as $row) {
            if ((string) ($row['instId'] ?? '') !== $instId) {
                continue;
            }

            $this->assertSizingMetadata($row, __METHOD__);

            return [[
                'symbol' => $this->resolver()->symbol($instId),
                'instrument_id' => $instId,
                'min_leverage' => '1',
                'max_leverage' => $this->string($row['lever'] ?? ''),
                'source' => 'okx_public_instruments',
            ]];
        }

        return [];
    }

    /**
     * @param array<string>|null $symbols
     * @return array{upserted: int, total_fetched: int, errors: array<string>}
     */
    public function syncContracts(?array $symbols = null): array
    {
        $contracts = $this->getContracts();
        $requested = array_map(static fn (string $symbol): string => strtoupper($symbol), $symbols ?? []);
        $errors = ['okx_contract_sync_read_only_not_persisted'];
        $totalFetched = count($contracts);

        if ($requested !== []) {
            $available = array_fill_keys(array_map(static fn (ContractDto $contract): string => $contract->symbol, $contracts), true);
            foreach ($requested as $symbol) {
                if (!isset($available[$symbol])) {
                    $errors[] = sprintf('Contract not found for symbol %s', $symbol);
                }
            }
        }

        return [
            'upserted' => 0,
            'total_fetched' => $totalFetched,
            'errors' => $errors,
        ];
    }

    public function healthCheck(): bool
    {
        if (!$this->client instanceof OkxRestClientInterface) {
            return false;
        }

        try {
            $this->instrumentRows(__METHOD__);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function getProviderName(): string
    {
        return 'OKX';
    }

    private function notImplemented(string $operation): OkxProviderNotReadyException
    {
        return new OkxProviderNotReadyException('okx_metadata_not_implemented', $operation);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function instrumentRows(string $operation): array
    {
        $payload = $this->publicGet('/api/v5/public/instruments', ['instType' => 'SWAP'], $operation);

        return array_values(array_filter(
            $this->dataRows($payload, $operation),
            static fn (mixed $row): bool => \is_array($row) && ($row['instType'] ?? 'SWAP') === 'SWAP',
        ));
    }

    /**
     * @param array<string,mixed> $row
     */
    private function metadataFromRow(array $row): OkxInstrumentMetadataDto
    {
        $instId = $this->string($row['instId'] ?? '');
        $feeGroupId = $this->string($row['groupId'] ?? '');
        $funding = $this->optionalFundingRate($instId);
        $fees = $this->tradingFees($this->resolver()->symbol($instId), $feeGroupId);
        $qualityFlags = [];
        $selectedFees = $this->selectFeeGroup($fees, $feeGroupId);
        $makerFee = $this->nullableNumericString($selectedFees['maker'] ?? null);
        $takerFee = $this->nullableNumericString($selectedFees['taker'] ?? null);
        $fundingRate = $this->nullableNumericString($funding['fundingRate'] ?? null);

        if ($makerFee === null) {
            $qualityFlags[] = 'maker_fee_unknown';
        }
        if ($takerFee === null) {
            $qualityFlags[] = 'taker_fee_unknown';
        }
        if ($fundingRate === null) {
            $qualityFlags[] = 'funding_rate_unknown';
        }

        return new OkxInstrumentMetadataDto(
            symbol: $this->resolver()->symbol($instId),
            instrumentId: $instId,
            priceTick: $this->string($row['tickSz'] ?? ''),
            quantityStep: $this->string($row['lotSz'] ?? $row['minSz'] ?? ''),
            minSize: $this->string($row['minSz'] ?? ''),
            maxSize: $this->firstNonEmpty($row['maxMktSz'] ?? null, $row['maxLmtSz'] ?? null),
            contractValue: $this->string($row['ctVal'] ?? ''),
            contractType: strtolower($this->string($row['ctType'] ?? '')),
            contractValueCurrency: strtoupper($this->string($row['ctValCcy'] ?? '')),
            settleCurrency: strtoupper($this->string($row['settleCcy'] ?? '')),
            maxLeverage: $this->string($row['lever'] ?? ''),
            feeGroupId: $feeGroupId === '' ? null : $feeGroupId,
            makerFeeRate: $makerFee,
            takerFeeRate: $takerFee,
            fundingRate: $fundingRate,
            nextFundingTime: $this->fundingTime($funding['nextFundingTime'] ?? null),
            qualityFlags: array_values(array_unique($qualityFlags)),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function fundingRate(string $instId): array
    {
        return $this->firstRow(
            $this->publicGet('/api/v5/public/funding-rate', ['instId' => $instId], __METHOD__),
            __METHOD__,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function optionalFundingRate(string $instId): array
    {
        try {
            return $this->fundingRate($instId);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function tradingFees(string $symbol, string $feeGroupId): array
    {
        if (!$this->account instanceof OkxAccountGateway) {
            return [];
        }

        try {
            return $this->account->getTradingFees($symbol, $feeGroupId === '' ? null : $feeGroupId);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string,mixed> $row
     */
    private function assertSizingMetadata(array $row, string $operation): void
    {
        $flags = $this->sizingMetadataFlags($row);
        if ($flags === []) {
            return;
        }

        throw new OkxProviderUnavailableException('okx_metadata_incomplete', $operation);
    }

    /**
     * @param array<string,mixed> $row
     * @return list<string>
     */
    private function sizingMetadataFlags(array $row): array
    {
        $checks = [
            'instrument_id' => $this->string($row['instId'] ?? ''),
            'price_tick' => $this->string($row['tickSz'] ?? ''),
            'quantity_step' => $this->string($row['lotSz'] ?? $row['minSz'] ?? ''),
            'min_size' => $this->string($row['minSz'] ?? ''),
            'max_size' => $this->firstNonEmpty($row['maxMktSz'] ?? null, $row['maxLmtSz'] ?? null),
            'contract_value' => $this->string($row['ctVal'] ?? ''),
            'contract_type' => $this->string($row['ctType'] ?? ''),
            'contract_value_currency' => $this->string($row['ctValCcy'] ?? ''),
            'settle_currency' => $this->string($row['settleCcy'] ?? ''),
            'max_leverage' => $this->string($row['lever'] ?? ''),
        ];

        $flags = [];
        foreach ($checks as $field => $value) {
            if ($value === '') {
                $flags[] = 'missing_' . $field;
                continue;
            }

            if ($field !== 'instrument_id' && $field !== 'settle_currency' && (!$this->isPositiveNumber($value))) {
                if ($field === 'contract_type' || $field === 'contract_value_currency') {
                    continue;
                }

                $flags[] = 'invalid_' . $field;
            }
        }

        $contractType = strtolower($this->string($row['ctType'] ?? ''));
        if ($contractType !== 'linear') {
            $flags[] = $contractType === 'inverse' ? 'unsupported_inverse_contract' : 'invalid_contract_type';
        }

        return $flags;
    }

    /**
     * @return array<string,mixed>
     */
    private function ticker(string $instId, string $operation): array
    {
        return $this->firstRow(
            $this->publicGet('/api/v5/market/ticker', ['instId' => $instId], $operation),
            $operation,
        );
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private function publicGet(string $path, array $query, string $operation): array
    {
        if (!$this->client instanceof OkxRestClientInterface) {
            throw $this->notImplemented($operation);
        }

        try {
            return $this->client->publicGet($path, $query);
        } catch (\Throwable $exception) {
            throw new OkxProviderUnavailableException($this->reason($exception), $operation, $exception);
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<mixed>
     */
    private function dataRows(array $payload, string $operation): array
    {
        $code = (string) ($payload['code'] ?? '');
        if ($code !== '0') {
            $reason = $code === '50011' ? 'okx_public_rate_limited' : 'okx_public_api_error';

            throw new OkxProviderUnavailableException($reason, $operation);
        }

        $data = $payload['data'] ?? [];

        return \is_array($data) ? array_values($data) : [];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function firstRow(array $payload, string $operation): array
    {
        $row = $this->dataRows($payload, $operation)[0] ?? [];

        return \is_array($row) ? $row : [];
    }

    private function resolver(): OkxInstrumentResolver
    {
        return $this->instruments ?? new OkxInstrumentResolver();
    }

    private function reason(\Throwable $exception): string
    {
        return str_contains($exception->getMessage(), '429')
            || str_contains(strtolower($exception->getMessage()), 'rate')
            ? 'okx_public_rate_limited'
            : 'okx_public_network_error';
    }

    private function fundingTime(mixed $value): ?\DateTimeImmutable
    {
        $raw = $this->string($value);
        if ($raw === '' || !is_numeric($raw) || (int) $raw <= 0) {
            return null;
        }

        return $this->mapper->time($raw);
    }

    private function nullableNumericString(mixed $value): ?string
    {
        $raw = $this->string($value);

        return $raw !== '' && is_numeric($raw) ? $raw : null;
    }

    private function isPositiveNumber(string $value): bool
    {
        return is_numeric($value) && (float) $value > 0.0;
    }

    private function string(mixed $value): string
    {
        return \is_scalar($value) ? trim((string) $value) : '';
    }

    private function firstNonEmpty(mixed ...$values): string
    {
        foreach ($values as $value) {
            $string = $this->string($value);
            if ($string !== '') {
                return $string;
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $fees
     * @return array<string,mixed>
     */
    private function selectFeeGroup(array $fees, string $feeGroupId): array
    {
        if ($feeGroupId === '') {
            return $fees;
        }

        $groups = $fees['feeGroup'] ?? null;
        if (!\is_array($groups)) {
            return [];
        }

        foreach ($groups as $group) {
            if (!\is_array($group) || $this->string($group['groupId'] ?? '') !== $feeGroupId) {
                continue;
            }

            return $group;
        }

        return [];
    }

    private function barFromStep(int $step): string
    {
        return match ($step) {
            1 => '1m',
            5 => '5m',
            15 => '15m',
            30 => '30m',
            60 => '1H',
            240 => '4H',
            1440 => '1Dutc',
            default => throw new \InvalidArgumentException(sprintf('Unsupported OKX mark price kline step: %d', $step)),
        };
    }
}
