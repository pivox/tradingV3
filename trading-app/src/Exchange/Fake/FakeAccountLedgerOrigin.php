<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Dto\ExchangeBalanceDto;
use Brick\Math\BigDecimal;

final readonly class FakeAccountLedgerOrigin
{
    public const METADATA_KEY = 'fake_account_ledger_origin';
    public const LEDGER_MODEL_VERSION = 'fake-paper-account-ledger-v1';

    private const SCHEMA = 'fake-account-ledger-origin.v1';
    private const PAYLOAD_FIELDS = [
        'schema',
        'currency',
        'opening_balance',
        'ledger_model_version',
    ];

    /** @param array<string,string> $payload */
    private function __construct(
        private array $payload,
        private string $originHash,
    ) {
    }

    public static function create(string $currency, string $openingBalance): self
    {
        try {
            $payload = [
                'schema' => self::SCHEMA,
                'currency' => $currency,
                'opening_balance' => self::positiveDecimal($openingBalance),
                'ledger_model_version' => self::LEDGER_MODEL_VERSION,
            ];

            return self::validated($payload, self::hash($payload));
        } catch (\Throwable $exception) {
            throw self::invalid($exception);
        }
    }

    public static function fromBalance(ExchangeBalanceDto $balance): self
    {
        try {
            if ($balance->exchange !== Exchange::FAKE || $balance->marketType !== MarketType::PERPETUAL) {
                throw new \LogicException();
            }
            $encoded = $balance->metadata[self::METADATA_KEY] ?? null;
            if (!\is_string($encoded)) {
                throw new \LogicException();
            }
            $origin = self::decode($encoded);
            if (!hash_equals($balance->currency, $origin->currency())) {
                throw new \LogicException();
            }

            return $origin;
        } catch (\Throwable $exception) {
            throw self::invalid($exception);
        }
    }

    public static function decode(string $encoded): self
    {
        try {
            $document = json_decode($encoded, true, 32, JSON_THROW_ON_ERROR);
            if (!\is_array($document) || array_is_list($document) || !\is_string($document['origin_hash'] ?? null)) {
                throw new \LogicException();
            }
            $originHash = $document['origin_hash'];
            unset($document['origin_hash']);
            $origin = self::validated($document, $originHash);
            if (!hash_equals($origin->encoded(), $encoded)) {
                throw new \LogicException();
            }

            return $origin;
        } catch (\Throwable $exception) {
            throw self::invalid($exception);
        }
    }

    public function encoded(): string
    {
        return self::encode($this->payload + ['origin_hash' => $this->originHash]);
    }

    public function currency(): string
    {
        return $this->payload['currency'];
    }

    public function openingBalance(): string
    {
        return $this->payload['opening_balance'];
    }

    public function identityHash(): string
    {
        return $this->originHash;
    }

    /** @param array<string,mixed> $payload */
    private static function validated(array $payload, string $originHash): self
    {
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);
        $expected = self::PAYLOAD_FIELDS;
        sort($expected, SORT_STRING);
        if ($keys !== $expected
            || ($payload['schema'] ?? null) !== self::SCHEMA
            || !\is_string($payload['currency'] ?? null)
            || preg_match('/\A[A-Z][A-Z0-9]{2,11}\z/D', $payload['currency']) !== 1
            || !\is_string($payload['opening_balance'] ?? null)
            || !hash_equals(self::positiveDecimal($payload['opening_balance']), $payload['opening_balance'])
            || ($payload['ledger_model_version'] ?? null) !== self::LEDGER_MODEL_VERSION
            || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $originHash) !== 1
            || !hash_equals(self::hash($payload), $originHash)
        ) {
            throw new \LogicException('fake_account_ledger_origin_invalid');
        }

        /** @var array<string,string> $payload */
        return new self($payload, $originHash);
    }

    private static function positiveDecimal(string $value): string
    {
        $decimal = BigDecimal::of($value);
        if (!$decimal->isPositive()) {
            throw new \LogicException('fake_account_ledger_origin_invalid');
        }
        $canonical = $decimal->stripTrailingZeros();

        return $canonical->getScale() < 0
            ? (string) $canonical->toScale(0)
            : (string) $canonical;
    }

    /** @param array<string,mixed> $payload */
    private static function hash(array $payload): string
    {
        return 'sha256:' . hash('sha256', self::encode($payload));
    }

    /** @param array<string,mixed> $payload */
    private static function encode(array $payload): string
    {
        ksort($payload, SORT_STRING);

        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private static function invalid(\Throwable $previous): \LogicException
    {
        if ($previous instanceof \LogicException
            && $previous->getMessage() === 'fake_account_ledger_origin_invalid'
        ) {
            return $previous;
        }

        return new \LogicException('fake_account_ledger_origin_invalid', 0, $previous);
    }
}
