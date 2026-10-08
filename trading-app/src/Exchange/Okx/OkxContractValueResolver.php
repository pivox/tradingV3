<?php

declare(strict_types=1);

namespace App\Exchange\Okx;

use Brick\Math\BigDecimal;

/**
 * Resolves the OKX contract value (ctVal, base-asset units per contract) of a swap instrument
 * from the public instruments endpoint. One bulk load fills an in-process map; a miss reloads
 * at most once per cooldown. Never throws: an unresolvable value is null, so the fill-cost ledger
 * flags contract_value_missing instead of silently converting with a wrong value.
 */
final class OkxContractValueResolver
{
    public const QUANTITY_UNIT = 'quantity_unit';
    public const CONTRACT_VALUE = 'contract_value';
    private const RELOAD_COOLDOWN_SECONDS = 60;

    /** @var array<string,string>|null */
    private ?array $values = null;
    private ?int $lastAttemptAt = null;

    public function __construct(private readonly OkxRestClientInterface $client)
    {
    }

    /**
     * Fill metadata of an OKX fill: OKX sizes fills in contracts, always; the contract value is
     * added when it can be resolved.
     *
     * @return array{quantity_unit: string, contract_value?: string}
     */
    public static function fillMetadata(?self $resolver, string $instrumentId): array
    {
        $metadata = [self::QUANTITY_UNIT => 'contracts'];
        $value = $resolver?->contractValue($instrumentId);
        if ($value !== null) {
            $metadata[self::CONTRACT_VALUE] = $value;
        }

        return $metadata;
    }

    public function contractValue(string $instrumentId): ?string
    {
        $instrumentId = strtoupper(trim($instrumentId));
        if ($instrumentId === '') {
            return null;
        }
        if (isset($this->values[$instrumentId])) {
            return $this->values[$instrumentId];
        }
        $now = time();
        if ($this->lastAttemptAt !== null && $now - $this->lastAttemptAt < self::RELOAD_COOLDOWN_SECONDS) {
            return null;
        }
        $this->lastAttemptAt = $now;
        $this->load();

        return $this->values[$instrumentId] ?? null;
    }

    private function load(): void
    {
        try {
            $payload = $this->client->publicGet('/api/v5/public/instruments', ['instType' => 'SWAP']);
        } catch (\Throwable) {
            return;
        }
        if ((string) ($payload['code'] ?? '') !== '0' || !\is_array($payload['data'] ?? null)) {
            return;
        }
        $values = $this->values ?? [];
        foreach ($payload['data'] as $row) {
            if (!\is_array($row) || !\is_string($row['instId'] ?? null) || !\is_string($row['ctVal'] ?? null)) {
                continue;
            }
            try {
                $value = BigDecimal::of(trim($row['ctVal']));
            } catch (\Throwable) {
                continue;
            }
            if (!$value->isPositive()) {
                continue;
            }
            $value = $value->stripTrailingZeros();
            $values[strtoupper($row['instId'])] = $value->getScale() < 0
                ? (string) $value->toScale(0)
                : (string) $value;
        }
        $this->values = $values;
    }
}
