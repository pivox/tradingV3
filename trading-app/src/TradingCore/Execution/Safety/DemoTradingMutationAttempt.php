<?php
declare(strict_types=1);

namespace App\TradingCore\Execution\Safety;

use App\Common\Enum\Exchange;

final readonly class DemoTradingMutationAttempt
{
    /** @var list<string> */
    public array $allowedSymbols;

    /** @var list<string> */
    public array $allowedMarkets;

    public ?string $symbol;

    public ?string $clientOrderId;

    /** @var array<string,string> */
    public array $correlationIds;

    /** @var array<string,mixed> */
    public array $auditContext;

    /**
     * @param list<string> $allowedSymbols
     * @param list<string> $allowedMarkets
     * @param array<mixed,mixed> $correlationIds
     * @param array<string,mixed> $auditContext
     */
    public function __construct(
        public Exchange $exchange,
        public ExchangeRuntimeEnvironment $environment,
        public string $mode,
        public string $profile,
        public string $market,
        ?string $symbol,
        public ?float $notional,
        ?string $clientOrderId,
        public string $action,
        public bool $mainnetWriteEnabled = false,
        public bool $demoTestnetWriteEnabled = false,
        public bool $effectiveKillSwitchEnabled = true,
        public bool $requireStopLoss = true,
        public ?bool $stopLossPresent = null,
        array $allowedSymbols = [],
        array $allowedMarkets = [],
        public ?float $maxNotional = null,
        array $correlationIds = [],
        array $auditContext = [],
    ) {
        $this->assertNonEmpty($mode, 'mode');
        $this->assertNonEmpty($profile, 'profile');
        $this->assertNonEmpty($market, 'market');
        $this->assertNonEmpty($action, 'action');

        if ($notional !== null && (!is_finite($notional) || $notional <= 0.0)) {
            throw new \InvalidArgumentException('notional must be positive and finite when provided.');
        }

        if ($maxNotional !== null && (!is_finite($maxNotional) || $maxNotional <= 0.0)) {
            throw new \InvalidArgumentException('maxNotional must be positive and finite when provided.');
        }

        $this->symbol = $this->normalizeNullableString($symbol);
        $this->clientOrderId = $this->normalizeNullableString($clientOrderId);
        $this->allowedSymbols = $this->normalizeStringList($allowedSymbols, 'allowedSymbols');
        $this->allowedMarkets = $this->normalizeStringList($allowedMarkets, 'allowedMarkets');
        $this->correlationIds = $this->normalizeStringMap($correlationIds, 'correlationIds');
        $this->auditContext = $auditContext;
    }

    private function assertNonEmpty(string $value, string $field): void
    {
        if (trim($value) === '') {
            throw new \InvalidArgumentException(sprintf('%s must not be blank.', $field));
        }
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function normalizeStringList(array $values, string $field): array
    {
        $normalized = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new \InvalidArgumentException(sprintf('%s must contain only strings.', $field));
            }

            $trimmed = trim($value);
            if ($trimmed !== '') {
                $normalized[] = $trimmed;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param array<mixed,mixed> $values
     * @return array<string,string>
     */
    private function normalizeStringMap(array $values, string $field): array
    {
        $normalized = [];
        foreach ($values as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                throw new \InvalidArgumentException(sprintf('%s must be a string map.', $field));
            }

            $trimmedKey = trim($key);
            $trimmedValue = trim($value);
            if ($trimmedKey !== '' && $trimmedValue !== '') {
                $normalized[$trimmedKey] = $trimmedValue;
            }
        }

        return $normalized;
    }

    private function normalizeNullableString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
