<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Fake;

use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Identity\PaperModernStrategyIdentity;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalPreparedEffect;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanDecimal;
use Brick\Math\BigDecimal;

final readonly class PaperCanonicalFakeReservationDescriptor
{
    public const METADATA_KEY = 'paper_canonical_reservation_descriptor';

    private const SCHEMA = 'paper-canonical-fake-reservation.v1';

    private const PAYLOAD_FIELDS = [
        'schema',
        'paper_cell_id',
        'paper_network',
        'public_venue',
        'account_namespace',
        'mode_id',
        'mode_version',
        'setup_id',
        'setup_version',
        'side',
        'config_hash',
        'condition_catalog_hash',
        'decision_key',
        'plan_hash',
        'quote_currency',
        'reserved_risk_quote',
        'reserved_notional_quote',
        'portfolio_input_hash',
        'portfolio_snapshot_identity_hash',
        'admission_hash',
        'reservation_state_hash',
        'reservation_version',
        'observed_at',
    ];

    /** @param array<string, mixed> $payload */
    private function __construct(
        private array $payload,
        private string $descriptorHash,
    ) {
    }

    public static function fromEffect(
        PaperExecutionCell $cell,
        PaperCanonicalPreparedEffect $effect,
    ): self {
        try {
            $identity = $cell->modernIdentity;
            $effect->assertValid();
            $effect->reservation->assertCanonicalOpeningState($effect->plan);
            if ($identity === null) {
                throw new \LogicException();
            }

            $reservation = $effect->reservation;
            $scope = $reservation->scope;
            if ($scope->network !== $cell->network->value
                || $scope->exchange !== $cell->marketDataVenue->value
                || $scope->environment !== $cell->network->value
                || $scope->accountId !== $cell->accountNamespace
                || $scope->modeId !== $identity->modeId
                || $scope->quoteCurrency !== $effect->plan->quoteCurrency
                || $effect->decisionKey !== $reservation->decisionKey
                || $effect->plan->planHash !== $reservation->planHash
                || $effect->plan->configHash !== $reservation->configHash
            ) {
                throw new \LogicException();
            }

            $payload = [
                'schema' => self::SCHEMA,
                'paper_cell_id' => $cell->id,
                'paper_network' => $cell->network->value,
                'public_venue' => $cell->marketDataVenue->value,
                'account_namespace' => $cell->accountNamespace,
                'mode_id' => $identity->modeId,
                'mode_version' => $identity->modeVersion,
                'setup_id' => $identity->setupId,
                'setup_version' => $identity->setupVersion,
                'side' => $identity->side,
                'config_hash' => $identity->configHash,
                'condition_catalog_hash' => $identity->conditionCatalogHash,
                'decision_key' => $effect->decisionKey,
                'plan_hash' => $effect->plan->planHash,
                'quote_currency' => $effect->plan->quoteCurrency,
                'reserved_risk_quote' => self::decimal($reservation->reservedRiskQuote),
                'reserved_notional_quote' => self::decimal($reservation->reservedNotionalQuote),
                'portfolio_input_hash' => $reservation->portfolioInputHash,
                'portfolio_snapshot_identity_hash' => $reservation->portfolioSnapshotIdentityHash,
                'admission_hash' => $reservation->admissionHash,
                'reservation_state_hash' => $reservation->stateHash,
                'reservation_version' => $reservation->version,
                'observed_at' => self::time($reservation->observedAt),
            ];

            return self::create($payload, self::hash($payload));
        } catch (\Throwable $exception) {
            throw self::invalid($exception);
        }
    }

    public static function decode(string $encoded): self
    {
        try {
            $document = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($document) || array_is_list($document)) {
                throw new \LogicException();
            }
            $keys = array_keys($document);
            sort($keys, SORT_STRING);
            $expectedKeys = [...self::PAYLOAD_FIELDS, 'descriptor_hash'];
            sort($expectedKeys, SORT_STRING);
            if ($keys !== $expectedKeys || !is_string($document['descriptor_hash'])) {
                throw new \LogicException();
            }
            $descriptorHash = $document['descriptor_hash'];
            unset($document['descriptor_hash']);
            if (!hash_equals(self::hash($document), $descriptorHash)) {
                throw new \LogicException();
            }
            $descriptor = self::create($document, $descriptorHash);
            if (!hash_equals($descriptor->encoded(), $encoded)) {
                throw new \LogicException();
            }

            return $descriptor;
        } catch (\Throwable $exception) {
            throw self::invalid($exception);
        }
    }

    public function encoded(): string
    {
        return CanonicalJson::encode($this->payload + ['descriptor_hash' => $this->descriptorHash]);
    }

    public function identityHash(): string
    {
        return $this->descriptorHash;
    }

    public function cellId(): string
    {
        return $this->string('paper_cell_id');
    }

    public function decisionKey(): string
    {
        return $this->string('decision_key');
    }

    public function reservedRiskQuote(): float
    {
        return self::float($this->string('reserved_risk_quote'));
    }

    public function reservedNotionalQuote(): float
    {
        return self::float($this->string('reserved_notional_quote'));
    }

    public function assertCell(PaperExecutionCell $cell): self
    {
        try {
            $identity = $cell->modernIdentity;
            if ($identity === null) {
                throw new \LogicException();
            }
            $checks = [
                [$cell->id, $this->string('paper_cell_id')],
                [$cell->network->value, $this->string('paper_network')],
                [$cell->marketDataVenue->value, $this->string('public_venue')],
                [$cell->accountNamespace, $this->string('account_namespace')],
                [$identity->modeId, $this->string('mode_id')],
                [$identity->modeVersion, $this->string('mode_version')],
                [$identity->setupId, $this->string('setup_id')],
                [$identity->setupVersion, $this->string('setup_version')],
                [$identity->side, $this->string('side')],
                [$identity->configHash, $this->string('config_hash')],
                [$identity->conditionCatalogHash, $this->string('condition_catalog_hash')],
            ];
            self::assertChecks($checks);

            return $this;
        } catch (\Throwable $exception) {
            throw self::invalid($exception);
        }
    }

    public function assertEffect(PaperCanonicalPreparedEffect $effect): self
    {
        try {
            $effect->assertValid();
            $reservation = $effect->reservation->assertCanonicalOpeningState($effect->plan);
            $checks = [
                [$effect->decisionKey, $this->string('decision_key')],
                [$effect->plan->modeId, $this->string('mode_id')],
                [$effect->plan->modeVersion, $this->string('mode_version')],
                [$effect->plan->setupId, $this->string('setup_id')],
                [$effect->plan->setupVersion, $this->string('setup_version')],
                [$effect->plan->side, $this->string('side')],
                [$effect->plan->configHash, $this->string('config_hash')],
                [(string) $effect->lineage->conditionCatalogHash, $this->string('condition_catalog_hash')],
                [$effect->plan->planHash, $this->string('plan_hash')],
                [$effect->plan->quoteCurrency, $this->string('quote_currency')],
                [$reservation->portfolioInputHash, $this->string('portfolio_input_hash')],
                [$reservation->portfolioSnapshotIdentityHash, $this->string('portfolio_snapshot_identity_hash')],
                [$reservation->admissionHash, $this->string('admission_hash')],
                [$reservation->stateHash, $this->string('reservation_state_hash')],
                [self::decimal($reservation->reservedRiskQuote), $this->string('reserved_risk_quote')],
                [self::decimal($reservation->reservedNotionalQuote), $this->string('reserved_notional_quote')],
                [self::time($reservation->observedAt), $this->string('observed_at')],
            ];
            self::assertChecks($checks);
            if ($reservation->version !== $this->integer('reservation_version')) {
                throw new \LogicException();
            }

            return $this;
        } catch (\Throwable $exception) {
            throw self::invalid($exception);
        }
    }

    /** @param array<string, mixed> $payload */
    private static function create(array $payload, string $descriptorHash): self
    {
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);
        $expectedKeys = self::PAYLOAD_FIELDS;
        sort($expectedKeys, SORT_STRING);
        if ($keys !== $expectedKeys
            || ($payload['schema'] ?? null) !== self::SCHEMA
            || !is_string($payload['paper_cell_id'] ?? null)
            || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $payload['paper_cell_id']) !== 1
            || !is_string($payload['paper_network'] ?? null)
            || !($network = PaperMarketDataNetwork::tryFrom($payload['paper_network'])) instanceof PaperMarketDataNetwork
            || $network === PaperMarketDataNetwork::LEGACY_UNKNOWN
            || !is_string($payload['public_venue'] ?? null)
            || !($venue = PaperMarketDataVenue::tryFrom($payload['public_venue'])) instanceof PaperMarketDataVenue
            || !is_string($payload['account_namespace'] ?? null)
            || $payload['account_namespace'] !== 'paper:cell:v2:' . substr($payload['paper_cell_id'], 7)
            || !is_string($payload['mode_id'] ?? null)
            || !is_string($payload['mode_version'] ?? null)
            || !is_string($payload['setup_id'] ?? null)
            || !is_string($payload['setup_version'] ?? null)
            || !is_string($payload['side'] ?? null)
            || !is_string($payload['config_hash'] ?? null)
            || !is_string($payload['condition_catalog_hash'] ?? null)
            || !is_string($payload['decision_key'] ?? null)
            || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,127}\z/D', $payload['decision_key']) !== 1
            || !is_string($payload['plan_hash'] ?? null)
            || !is_string($payload['quote_currency'] ?? null)
            || preg_match('/\A[A-Z][A-Z0-9]{2,11}\z/D', $payload['quote_currency']) !== 1
            || !is_int($payload['reservation_version'] ?? null)
            || $payload['reservation_version'] !== 1
            || !is_string($payload['observed_at'] ?? null)
            || !self::validTime($payload['observed_at'])
            || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $descriptorHash) !== 1
        ) {
            throw new \LogicException('paper_canonical_fake_reservation_descriptor_invalid');
        }

        PaperModernStrategyIdentity::fromDurableIdentity(
            $network,
            $venue,
            $payload['mode_id'],
            $payload['mode_version'],
            $payload['setup_id'],
            $payload['setup_version'],
            $payload['side'],
            $payload['config_hash'],
            $payload['condition_catalog_hash'],
        );
        foreach ([
            'config_hash',
            'condition_catalog_hash',
            'plan_hash',
            'portfolio_input_hash',
            'portfolio_snapshot_identity_hash',
            'admission_hash',
            'reservation_state_hash',
        ] as $field) {
            if (!is_string($payload[$field] ?? null)
                || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $payload[$field]) !== 1
            ) {
                throw new \LogicException('paper_canonical_fake_reservation_descriptor_invalid');
            }
        }
        self::positiveDecimal($payload, 'reserved_risk_quote');
        self::positiveDecimal($payload, 'reserved_notional_quote');

        return new self($payload, $descriptorHash);
    }

    /** @param list<array{string, string}> $checks */
    private static function assertChecks(array $checks): void
    {
        foreach ($checks as [$expected, $actual]) {
            if (!hash_equals($expected, $actual)) {
                throw new \LogicException();
            }
        }
    }

    private static function decimal(float $value): string
    {
        $decimal = CanonicalOrderPlanDecimal::fromFloat(
            $value,
            'paper_canonical_fake_reservation_descriptor_invalid',
        )->stripTrailingZeros();

        return $decimal->getScale() < 0 ? (string) $decimal->toScale(0) : (string) $decimal;
    }

    /** @param array<string, mixed> $payload */
    private static function positiveDecimal(array $payload, string $field): void
    {
        $value = $payload[$field] ?? null;
        if (!is_string($value)) {
            throw new \LogicException('paper_canonical_fake_reservation_descriptor_invalid');
        }
        $decimal = BigDecimal::of($value);
        $canonical = $decimal->stripTrailingZeros();
        $encoded = $canonical->getScale() < 0 ? (string) $canonical->toScale(0) : (string) $canonical;
        if (!$decimal->isPositive() || !hash_equals($encoded, $value)) {
            throw new \LogicException('paper_canonical_fake_reservation_descriptor_invalid');
        }
    }

    private static function float(string $value): float
    {
        $float = (float) $value;
        if (!is_finite($float)) {
            throw new \LogicException('paper_canonical_fake_reservation_descriptor_invalid');
        }

        return $float;
    }

    private static function time(\DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d\\TH:i:s.uP');
    }

    private static function validTime(string $value): bool
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}[+-]\d{2}:\d{2}\z/D', $value) !== 1) {
            return false;
        }
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s.uP', $value);
        $errors = \DateTimeImmutable::getLastErrors();

        return $time instanceof \DateTimeImmutable
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && hash_equals(self::time($time), $value);
    }

    /** @param array<string, mixed> $payload */
    private static function hash(array $payload): string
    {
        return 'sha256:' . hash('sha256', CanonicalJson::encode($payload));
    }

    private function string(string $field): string
    {
        $value = $this->payload[$field] ?? null;
        if (!is_string($value)) {
            throw new \LogicException('paper_canonical_fake_reservation_descriptor_invalid');
        }

        return $value;
    }

    private function integer(string $field): int
    {
        $value = $this->payload[$field] ?? null;
        if (!is_int($value)) {
            throw new \LogicException('paper_canonical_fake_reservation_descriptor_invalid');
        }

        return $value;
    }

    private static function invalid(\Throwable $exception): \LogicException
    {
        if ($exception instanceof \LogicException
            && $exception->getMessage() === 'paper_canonical_fake_reservation_descriptor_invalid'
        ) {
            return $exception;
        }

        return new \LogicException('paper_canonical_fake_reservation_descriptor_invalid', 0, $exception);
    }
}
