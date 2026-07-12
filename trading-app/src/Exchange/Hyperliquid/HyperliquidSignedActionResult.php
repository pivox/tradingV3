<?php

declare(strict_types=1);

namespace App\Exchange\Hyperliquid;

final readonly class HyperliquidSignedActionResult
{
    private const MAX_STATUSES = 20;
    private const MAX_STATUSES_BYTES = 65_536;
    private const ACTION_TYPES = ['order', 'cancel', 'cancelByCloid', 'updateLeverage'];
    private const OUTCOMES = ['accepted', 'rejected', 'ambiguous'];
    private const REASONS = [
        'broadcast_disabled',
        'agent_address_mismatch',
        'exchange_timeout',
        'exchange_transport_error',
        'exchange_response_too_large',
        'exchange_response_invalid_length',
        'exchange_response_invalid_json',
        'exchange_response_not_object',
        'exchange_redirect_rejected',
        'testnet_endpoint_required',
        'unknown_exchange_response',
        'exchange_error',
        'empty_exchange_statuses',
        'too_many_exchange_statuses',
        'invalid_exchange_statuses',
        'exchange_status_error',
        'mixed_exchange_statuses',
        'unknown_exchange_status',
        'unexpected_exchange_response_type',
        'invalid_exchange_response',
        'signer_auth_failed',
        'signer_response_invalid',
    ];

    /** @param list<array<string, mixed>> $statuses */
    public function __construct(
        public string $actionType,
        public string $outcome,
        public array $statuses,
        public ?string $reason,
        public string $correlationId,
    ) {
        if (!in_array($actionType, self::ACTION_TYPES, true)) {
            throw new \InvalidArgumentException('hyperliquid_signed_action_result_action_type_invalid');
        }
        if (!in_array($outcome, self::OUTCOMES, true)) {
            throw new \InvalidArgumentException('hyperliquid_signed_action_result_outcome_invalid');
        }
        if (!array_is_list($statuses) || count($statuses) > self::MAX_STATUSES) {
            throw new \InvalidArgumentException('hyperliquid_signed_action_result_statuses_invalid');
        }
        foreach ($statuses as $status) {
            if (!is_array($status) || !self::isNormalizedStatus($status)) {
                throw new \InvalidArgumentException('hyperliquid_signed_action_result_statuses_invalid');
            }
        }
        try {
            $encodedStatuses = json_encode($statuses, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('hyperliquid_signed_action_result_statuses_invalid');
        }
        if (strlen($encodedStatuses) > self::MAX_STATUSES_BYTES) {
            throw new \InvalidArgumentException('hyperliquid_signed_action_result_statuses_too_large');
        }
        if ($reason !== null && !in_array($reason, self::REASONS, true)) {
            throw new \InvalidArgumentException('hyperliquid_signed_action_result_reason_invalid');
        }
        if (trim($correlationId) === '' || strlen($correlationId) > 128) {
            throw new \InvalidArgumentException('hyperliquid_signed_action_result_correlation_id_invalid');
        }
        if (!self::isConsistent($actionType, $outcome, $statuses, $reason)) {
            throw new \InvalidArgumentException('hyperliquid_signed_action_result_consistency_invalid');
        }
    }

    /** @param list<array<string, mixed>> $statuses */
    private static function isConsistent(
        string $actionType,
        string $outcome,
        array $statuses,
        ?string $reason,
    ): bool {
        $kinds = array_column($statuses, 'kind');
        if ($actionType === 'updateLeverage' && $statuses !== []) {
            return false;
        }
        if ($actionType === 'order' && !self::containsOnly($kinds, ['resting', 'filled', 'error'])) {
            return false;
        }
        if (in_array($actionType, ['cancel', 'cancelByCloid'], true)
            && !self::containsOnly($kinds, ['success', 'error'])
        ) {
            return false;
        }

        if ($outcome === 'accepted') {
            if ($reason !== null) {
                return false;
            }
            if ($actionType === 'order') {
                return $statuses !== [] && self::containsOnly($kinds, ['resting', 'filled']);
            }
            if (in_array($actionType, ['cancel', 'cancelByCloid'], true)) {
                return $statuses !== [] && self::containsOnly($kinds, ['success']);
            }

            return $statuses === [];
        }

        if ($reason === null) {
            return false;
        }

        return $outcome !== 'rejected' || self::containsOnly($kinds, ['error']);
    }

    /**
     * @param list<string> $values
     * @param list<string> $allowed
     */
    private static function containsOnly(array $values, array $allowed): bool
    {
        foreach ($values as $value) {
            if (!in_array($value, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $status */
    private static function isNormalizedStatus(array $status): bool
    {
        $kind = $status['kind'] ?? null;
        if (!is_string($kind)) {
            return false;
        }
        if ($kind === 'success' || $kind === 'error') {
            return array_keys($status) === ['kind'];
        }
        if ($kind === 'resting') {
            return self::hasExactKeys($status, ['kind', 'oid'])
                && self::isPositiveInt64($status['oid']);
        }
        if ($kind !== 'filled'
            || !isset($status['oid'])
            || !self::hasAllowedKeys($status, ['kind', 'oid', 'total_size', 'average_price'])
            || !self::isPositiveInt64($status['oid'])
        ) {
            return false;
        }

        foreach (['total_size', 'average_price'] as $field) {
            if (array_key_exists($field, $status) && !self::isPositiveDecimalString($status[$field])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $expectedKeys
     */
    private static function hasExactKeys(array $value, array $expectedKeys): bool
    {
        $keys = array_keys($value);
        sort($keys);
        sort($expectedKeys);

        return $keys === $expectedKeys;
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $allowedKeys
     */
    private static function hasAllowedKeys(array $value, array $allowedKeys): bool
    {
        foreach (array_keys($value) as $key) {
            if (!in_array($key, $allowedKeys, true)) {
                return false;
            }
        }

        return true;
    }

    private static function isPositiveInt64(mixed $value): bool
    {
        return is_int($value) && $value > 0;
    }

    private static function isPositiveDecimalString(mixed $value): bool
    {
        if (!is_string($value)
            || preg_match('/^\+?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?$/D', $value) !== 1
        ) {
            return false;
        }

        $mantissa = preg_split('/[eE]/', $value, 2)[0] ?? '';

        return preg_match('/[1-9]/', $mantissa) === 1;
    }
}
