<?php

declare(strict_types=1);

namespace App\Provider\Okx;

use App\Contract\Provider\Dto\PositionDto;
use App\Exchange\Okx\OkxInstrumentResolver;
use App\Exchange\Okx\OkxRestClientInterface;

final class OkxPositionGateway
{
    private OkxPrivateReadMapper $mapper;

    public function __construct(
        private readonly ?OkxRestClientInterface $client = null,
        private readonly ?OkxInstrumentResolver $instruments = null,
    ) {
        $this->mapper = new OkxPrivateReadMapper($this->resolver());
    }

    /**
     * @return PositionDto[]
     */
    public function getOpenPositions(?string $symbol = null): array
    {
        return $this->fetchOpenPositions($symbol, __METHOD__);
    }

    /**
     * @return PositionDto[]
     */
    public function getOpenPositionsOrFail(?string $symbol = null): array
    {
        return $this->fetchOpenPositions($symbol, __METHOD__);
    }

    public function getPosition(string $symbol): ?PositionDto
    {
        return $this->fetchOpenPositions($symbol, __METHOD__)[0] ?? null;
    }

    private function notImplemented(string $operation): OkxProviderNotReadyException
    {
        return new OkxProviderNotReadyException('okx_position_not_implemented', $operation);
    }

    /**
     * @return PositionDto[]
     */
    private function fetchOpenPositions(?string $symbol, string $operation): array
    {
        if (!$this->client instanceof OkxRestClientInterface) {
            throw $this->notImplemented($operation);
        }

        $query = ['instType' => 'SWAP'];
        if ($symbol !== null) {
            $query['instId'] = $this->resolver()->instId($symbol);
        }

        try {
            $payload = $this->client->privateGet('/api/v5/account/positions', $query);
        } catch (\Throwable $exception) {
            throw new OkxProviderUnavailableException($this->reason($exception), $operation, $exception);
        }

        $positions = [];
        foreach ($this->dataRows($payload, $operation) as $row) {
            $position = $this->mapper->position($row);
            if ($position instanceof PositionDto) {
                $positions[] = $position;
            }
        }

        return $positions;
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<array<string,mixed>>
     */
    private function dataRows(array $payload, string $operation): array
    {
        $code = (string) ($payload['code'] ?? '');
        if ($code !== '0') {
            $reason = $code === '50011' ? 'okx_private_rate_limited' : 'okx_private_api_error';

            throw new OkxProviderUnavailableException($reason, $operation);
        }

        $data = $payload['data'] ?? [];
        if (!\is_array($data)) {
            return [];
        }

        return array_values(array_filter($data, \is_array(...)));
    }

    private function resolver(): OkxInstrumentResolver
    {
        return $this->instruments ?? new OkxInstrumentResolver();
    }

    private function reason(\Throwable $exception): string
    {
        return str_contains($exception->getMessage(), '429')
            || str_contains(strtolower($exception->getMessage()), 'rate')
            ? 'okx_private_rate_limited'
            : 'okx_private_network_error';
    }
}
