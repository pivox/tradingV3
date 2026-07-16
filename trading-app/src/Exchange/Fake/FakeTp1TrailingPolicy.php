<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

final readonly class FakeTp1TrailingPolicy
{
    public const VERSION = 'fake-tp1-trailing-v1';
    public const VERSION_KEY = 'fake_tp1_trailing_version';
    public const ENABLED_KEY = 'fake_tp1_trailing_enabled';
    public const TP1_QUANTITY_KEY = 'fake_tp1_quantity';
    public const TRAILING_OFFSET_KEY = 'fake_trailing_offset';

    /** @var list<string> */
    public const METADATA_KEYS = [
        self::VERSION_KEY,
        self::ENABLED_KEY,
        self::TP1_QUANTITY_KEY,
        self::TRAILING_OFFSET_KEY,
    ];

    public function __construct(
        public string $tp1Quantity,
        public string $trailingOffset,
    ) {
        self::assertPositiveDecimal($tp1Quantity);
        self::assertPositiveDecimal($trailingOffset);
    }

    /** @return array<string,bool|string> */
    public function toMetadata(): array
    {
        return [
            self::VERSION_KEY => self::VERSION,
            self::ENABLED_KEY => true,
            self::TP1_QUANTITY_KEY => $this->tp1Quantity,
            self::TRAILING_OFFSET_KEY => $this->trailingOffset,
        ];
    }

    /**
     * @param array<string,mixed> $metadata
     */
    public static function fromMetadata(array $metadata): ?self
    {
        $requested = false;
        foreach (self::METADATA_KEYS as $key) {
            if (\array_key_exists($key, $metadata)) {
                $requested = true;
                break;
            }
        }
        if (!$requested) {
            return null;
        }

        if (($metadata[self::VERSION_KEY] ?? null) !== self::VERSION) {
            throw new \InvalidArgumentException('fake_tp1_trailing_policy_invalid');
        }
        if (($metadata[self::ENABLED_KEY] ?? null) === false) {
            throw new \InvalidArgumentException('fake_tp1_trailing_policy_disabled');
        }
        if (($metadata[self::ENABLED_KEY] ?? null) !== true) {
            throw new \InvalidArgumentException('fake_tp1_trailing_policy_invalid');
        }

        $tp1Quantity = $metadata[self::TP1_QUANTITY_KEY] ?? null;
        $trailingOffset = $metadata[self::TRAILING_OFFSET_KEY] ?? null;
        if (!\is_string($tp1Quantity) || !\is_string($trailingOffset)) {
            throw new \InvalidArgumentException('fake_tp1_trailing_policy_invalid');
        }

        return new self($tp1Quantity, $trailingOffset);
    }

    public function tp1QuantityFloat(): float
    {
        return (float) $this->tp1Quantity;
    }

    public function trailingOffsetFloat(): float
    {
        return (float) $this->trailingOffset;
    }

    private static function assertPositiveDecimal(string $value): void
    {
        if (preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value) !== 1) {
            throw new \InvalidArgumentException('fake_tp1_trailing_policy_invalid');
        }

        try {
            $decimal = BigDecimal::of($value);
        } catch (MathException) {
            throw new \InvalidArgumentException('fake_tp1_trailing_policy_invalid');
        }
        if ($decimal->isLessThanOrEqualTo(BigDecimal::zero()) || !\is_finite((float) $value)) {
            throw new \InvalidArgumentException('fake_tp1_trailing_policy_invalid');
        }
    }
}
