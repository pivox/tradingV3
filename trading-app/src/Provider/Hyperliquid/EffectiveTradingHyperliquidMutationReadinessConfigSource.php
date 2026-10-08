<?php

declare(strict_types=1);

namespace App\Provider\Hyperliquid;

use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigResolverInterface;
use Symfony\Component\Yaml\Yaml;

final readonly class EffectiveTradingHyperliquidMutationReadinessConfigSource implements HyperliquidMutationReadinessConfigSourceInterface
{
    public const SCHEMA_VERSION = 'hyperliquid-testnet-readiness-envelope.v1';

    private const ENVELOPE_KEYS = [
        'schema_version', 'envelope_version', 'exchange', 'environment', 'eligible_identities',
        'allowed_symbols', 'allowed_markets', 'max_notional', 'execution', 'envelope_hash',
    ];
    private const IDENTITY_KEYS = ['mode_id', 'mode_version', 'setup_id', 'setup_version', 'side'];
    private const EXECUTION_KEYS = [
        'dry_run', 'live_enabled', 'mainnet_write_enabled', 'demo_testnet_write_enabled',
        'kill_switch_enabled', 'require_stop_loss',
    ];

    public function __construct(
        private EffectiveTradingConfigResolverInterface $resolver,
        private string $envelopePath,
    ) {
    }

    public function forIdentity(EffectiveTradingConfigRequest $identity): HyperliquidMutationReadinessConfig
    {
        if ($identity->exchange !== 'hyperliquid' || $identity->environment !== 'testnet') {
            return HyperliquidMutationReadinessConfig::failClosed();
        }

        try {
            $envelope = $this->envelope();
            if ($envelope === null || !$this->eligible($envelope['eligible_identities'], $identity)) {
                return HyperliquidMutationReadinessConfig::failClosed();
            }
            $snapshot = $this->resolver->resolve($identity);
        } catch (\Throwable) {
            return HyperliquidMutationReadinessConfig::failClosed();
        }
        if (!$snapshot->executable || $snapshot->blockers !== []) {
            return HyperliquidMutationReadinessConfig::failClosed();
        }

        $execution = $envelope['execution'];

        return new HyperliquidMutationReadinessConfig(
            profile: sprintf(
                '%s@%s/%s@%s/%s',
                $identity->modeId,
                $identity->modeVersion,
                $identity->setupId,
                $identity->setupVersion,
                $identity->side,
            ),
            allowedSymbols: $envelope['allowed_symbols'],
            allowedMarkets: $envelope['allowed_markets'],
            maxNotional: (float) $envelope['max_notional'],
            dryRun: $execution['dry_run'],
            liveEnabled: $execution['live_enabled'],
            runtimeCheckRequired: true,
            mainnetWriteEnabled: $execution['mainnet_write_enabled'],
            demoTestnetWriteEnabled: $execution['demo_testnet_write_enabled'],
            killSwitchEnabled: $execution['kill_switch_enabled'],
            requireStopLoss: $execution['require_stop_loss'],
            configHash: hash('sha256', $snapshot->configHash . ':' . $envelope['envelope_hash']),
        );
    }

    /** @param array<string, mixed> $document */
    public static function envelopeHash(array $document): string
    {
        unset($document['envelope_hash']);

        return hash('sha256', json_encode(self::canonical($document), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * @return array{
     *     eligible_identities: list<array<string, string>>,
     *     allowed_symbols: list<string>,
     *     allowed_markets: list<string>,
     *     max_notional: int|float,
     *     execution: array<string, bool>,
     *     envelope_hash: string
     * }|null
     */
    private function envelope(): ?array
    {
        try {
            $document = Yaml::parseFile($this->envelopePath);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($document) || array_is_list($document)) {
            return null;
        }
        $keys = array_keys($document);
        sort($keys);
        $expected = self::ENVELOPE_KEYS;
        sort($expected);
        if ($keys !== $expected
            || $document['schema_version'] !== self::SCHEMA_VERSION
            || !is_string($document['envelope_version'])
            || preg_match('/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/D', $document['envelope_version']) !== 1
            || $document['exchange'] !== 'hyperliquid'
            || $document['environment'] !== 'testnet'
            || !is_string($document['envelope_hash'])
            || preg_match('/^[a-f0-9]{64}$/D', $document['envelope_hash']) !== 1
            || !hash_equals($document['envelope_hash'], self::envelopeHash($document))
        ) {
            return null;
        }
        $maxNotional = $document['max_notional'];
        if (!is_int($maxNotional) && !is_float($maxNotional)
            || !is_finite((float) $maxNotional)
            || $maxNotional <= 0
            || !$this->stringList($document['allowed_symbols'])
            || !$this->stringList($document['allowed_markets'])
            || !$this->identities($document['eligible_identities'])
            || !$this->executionFlags($document['execution'])
        ) {
            return null;
        }

        /** @var array{eligible_identities: list<array<string, string>>, allowed_symbols: list<string>, allowed_markets: list<string>, max_notional: int|float, execution: array<string, bool>, envelope_hash: string} $document */
        return $document;
    }

    private function stringList(mixed $value): bool
    {
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            return false;
        }
        foreach ($value as $item) {
            if (!is_string($item) || trim($item) === '') {
                return false;
            }
        }

        return true;
    }

    private function identities(mixed $value): bool
    {
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            return false;
        }
        foreach ($value as $identity) {
            if (!is_array($identity)) {
                return false;
            }
            $keys = array_keys($identity);
            sort($keys);
            $expected = self::IDENTITY_KEYS;
            sort($expected);
            if ($keys !== $expected) {
                return false;
            }
            foreach ($identity as $field) {
                if (!is_string($field) || $field === '') {
                    return false;
                }
            }
        }

        return true;
    }

    private function executionFlags(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }
        $keys = array_keys($value);
        sort($keys);
        $expected = self::EXECUTION_KEYS;
        sort($expected);
        if ($keys !== $expected) {
            return false;
        }
        foreach ($value as $flag) {
            if (!is_bool($flag)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<array<string, string>> $eligible */
    private function eligible(array $eligible, EffectiveTradingConfigRequest $identity): bool
    {
        $candidate = [
            'mode_id' => $identity->modeId,
            'mode_version' => $identity->modeVersion,
            'setup_id' => $identity->setupId,
            'setup_version' => $identity->setupVersion,
            'side' => $identity->side,
        ];
        foreach ($eligible as $entry) {
            ksort($entry);
            ksort($candidate);
            if ($entry === $candidate) {
                return true;
            }
        }

        return false;
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $value = array_map(self::canonical(...), $value);
        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
