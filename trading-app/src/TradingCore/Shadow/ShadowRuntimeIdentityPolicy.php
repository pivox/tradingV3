<?php

declare(strict_types=1);

namespace App\TradingCore\Shadow;

use App\TradingCore\Config\EffectiveTradingConfigRequest;

final readonly class ShadowRuntimeIdentityPolicy
{
    private const IDENTITY_KEYS = ['mode_id', 'mode_version', 'setup_id', 'setup_version', 'side'];

    /**
     * @param list<array{mode_id:string,mode_version:string,setup_id:string,setup_version:string,side:string}> $identities
     */
    public function __construct(
        public string $reasonPrefix,
        public array $identities,
        public bool $requiresCanonicalOrderBook = false,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $reasonPrefix) !== 1) {
            throw new \InvalidArgumentException('shadow_runtime_reason_prefix_invalid');
        }
        if ($identities === []) {
            throw new \InvalidArgumentException('shadow_runtime_identities_empty');
        }

        $seen = [];
        foreach ($identities as $identity) {
            if (!self::hasExactIdentityKeys($identity)) {
                throw new \InvalidArgumentException('shadow_runtime_identity_shape_invalid');
            }
            foreach (self::IDENTITY_KEYS as $key) {
                if (!\is_string($identity[$key]) || $identity[$key] === '') {
                    throw new \InvalidArgumentException('shadow_runtime_identity_value_invalid');
                }
            }
            if (!\in_array($identity['side'], ['long', 'short'], true)) {
                throw new \InvalidArgumentException('shadow_runtime_identity_side_invalid');
            }

            $fingerprint = implode("\0", $identity);
            if (isset($seen[$fingerprint])) {
                throw new \InvalidArgumentException('shadow_runtime_identity_duplicate');
            }
            $seen[$fingerprint] = true;
        }
    }

    public function accepts(EffectiveTradingConfigRequest $request): bool
    {
        $candidate = [
            'mode_id' => $request->modeId,
            'mode_version' => $request->modeVersion,
            'setup_id' => $request->setupId,
            'setup_version' => $request->setupVersion,
            'side' => $request->side,
        ];

        return \in_array($candidate, $this->identities, true);
    }

    public function reason(string $suffix): string
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $suffix) !== 1) {
            throw new \InvalidArgumentException('shadow_runtime_reason_suffix_invalid');
        }

        return $this->reasonPrefix . '_' . $suffix;
    }

    /** @param array<array-key, mixed> $identity */
    private static function hasExactIdentityKeys(array $identity): bool
    {
        return array_keys($identity) === self::IDENTITY_KEYS;
    }
}
