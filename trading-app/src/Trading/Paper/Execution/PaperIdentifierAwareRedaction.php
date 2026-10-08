<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution;

use App\Trading\Paper\Execution\Fake\PaperCanonicalFakeInstrumentDescriptor;
use App\Trading\Paper\Execution\Fake\PaperCanonicalFakeReservationDescriptor;
use App\Trading\Paper\MarketData\PaperMarketEventRedactor;

/**
 * PaperMarketEventRedactor::assertSafe() for the identifiers of Paper execution payloads
 * (#132 decision e, narrow allowlist chosen by the user).
 *
 * The redactor decodes text that looks encoded and reads what it decodes as form data. A random
 * hexadecimal identifier sometimes decodes to bytes containing "=" whose non-ASCII "key" is then
 * refused as a sensitive field: 0.10 to 0.85 % of the random values of every identifier format
 * below (2,000 values each). A canonical Paper order carries about twenty-five distinct such
 * identifiers, so roughly 15 % of canonical orders were refused at random, depending only on the
 * identifiers of their cell. Campaign run ids are not listed: their 16 hexadecimal digits were
 * never refused (0 of 100,000).
 *
 * Only a closed list is relaxed, per call site: a value is replaced by a fixed mask before the
 * scan when its EXACT path is listed for that site AND it matches that path's EXACT identifier
 * format (anchored regex); the two Fake descriptors are masked only when their strict codec
 * decodes them and re-encodes them byte for byte. Every key name is still scanned, every other
 * value is scanned unchanged, and PaperMarketEventRedactor itself is not modified.
 */
final class PaperIdentifierAwareRedaction
{
    public const SITE_FAKE_ORDER_METADATA = 'fake_order_metadata';
    public const SITE_ORDER_INTENT_IDENTITY = 'order_intent_identity';
    public const SITE_CELL_PROVENANCE = 'cell_provenance';
    public const SITE_CANONICAL_INTENT_RAW_INPUTS = 'canonical_intent_raw_inputs';
    public const SITE_LEGACY_LIFECYCLE = 'legacy_lifecycle';
    public const SITE_STRATEGY_OBSERVATION = 'strategy_observation';

    private const MASK = 'paper-identifier';

    /** @var array<string, string> identifier format => anchored pattern */
    private const FORMATS = [
        'sha256' => '/\Asha256:[0-9a-f]{64}\z/D',
        'decision_key' => '/\Apaper:[0-9a-f]{64}\z/D',
        'decision_id' => '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
        'orchestration_set_id' => '/\Apaper-set:[0-9a-f]{48}\z/D',
        'effective_config_reference' => '/\Aeffective-config-snapshot:sha256:[0-9a-f]{64}\z/D',
        'client_order_id' => '/\ACID[0-9A-F]{29}\z/D',
        'intent_id' => '/\Aint:[0-9a-f]{48}\z/D',
        'canonical_trade_id' => '/\Aitd:[0-9a-f]{32}\z/D',
        'legacy_trade_id' => '/\Aptrd:[0-9a-f]{64}\z/D',
    ];

    /** Paths of a site; "a.b" is a nested key, "a[]" every element of the list under "a". */
    private const SITES = [
        self::SITE_FAKE_ORDER_METADATA => [
            'orchestration_set_id' => 'orchestration_set_id',
            'config_hash' => 'sha256',
            'condition_catalog_hash' => 'sha256',
            'decision_id' => 'decision_id',
            'decision_key' => 'decision_key',
            'effective_config_reference' => 'effective_config_reference',
            'paper_execution_cell_id' => 'sha256',
            'configuration_snapshot_id' => 'sha256',
            'client_order_id' => 'client_order_id',
            'internal_trade_id' => 'canonical_trade_id',
            'plan_hash' => 'sha256',
            PaperCanonicalFakeInstrumentDescriptor::METADATA_KEY => 'fake_instrument_descriptor',
            PaperCanonicalFakeReservationDescriptor::METADATA_KEY => 'fake_reservation_descriptor',
        ],
        self::SITE_ORDER_INTENT_IDENTITY => [
            'client_order_id' => 'client_order_id',
        ],
        self::SITE_CELL_PROVENANCE => [
            'paper_execution_cell_id' => 'sha256',
            'configuration_snapshot_id' => 'sha256',
            'config_hash' => 'sha256',
            'condition_catalog_hash' => 'sha256',
        ],
        self::SITE_CANONICAL_INTENT_RAW_INPUTS => [
            'decision_key' => 'decision_key',
            'plan_hash' => 'sha256',
            'plan.planHash' => 'sha256',
            'plan.configHash' => 'sha256',
            'plan.costInputHash' => 'sha256',
            'plan.orderBookInputHash' => 'sha256',
            'plan.inputHashes[]' => 'sha256',
            'canonical_identity.orchestration_set_id' => 'orchestration_set_id',
            'canonical_identity.config_hash' => 'sha256',
            'canonical_identity.condition_catalog_hash' => 'sha256',
            'canonical_identity.decision_id' => 'decision_id',
            'canonical_identity.decision_key' => 'decision_key',
            'canonical_identity.intent_id' => 'intent_id',
            'canonical_identity.client_order_id' => 'client_order_id',
            'canonical_identity.internal_trade_id' => 'canonical_trade_id',
            'canonical_identity.effective_config_reference' => 'effective_config_reference',
        ],
        self::SITE_LEGACY_LIFECYCLE => [
            'config_hash' => 'sha256',
            'decision_key' => 'decision_key',
            'trade_id' => 'legacy_trade_id',
            'internal_trade_id' => 'legacy_trade_id',
        ],
        self::SITE_STRATEGY_OBSERVATION => [
            'config_hash' => 'sha256',
            'condition_catalog_hash' => 'sha256',
        ],
    ];

    /** @param array<array-key, mixed> $payload */
    public static function assertSafe(#[\SensitiveParameter] array $payload, string $site): void
    {
        $paths = self::SITES[$site] ?? throw new \InvalidArgumentException('paper_identifier_redaction_site_unknown');
        PaperMarketEventRedactor::assertSafe(self::masked($payload, $paths, ''));
    }

    /**
     * @param array<array-key, mixed> $node
     * @param array<string, string> $paths
     * @return array<array-key, mixed>
     */
    private static function masked(#[\SensitiveParameter] array $node, array $paths, string $prefix): array
    {
        $isList = array_is_list($node);
        foreach ($node as $key => $value) {
            $path = $isList ? $prefix . '[]' : ($prefix === '' ? (string) $key : $prefix . '.' . $key);
            if (\is_array($value)) {
                $node[$key] = self::masked($value, $paths, $path);
            } elseif (\is_string($value) && isset($paths[$path]) && self::isIdentifier($paths[$path], $value)) {
                $node[$key] = self::MASK;
            }
        }

        return $node;
    }

    private static function isIdentifier(string $format, #[\SensitiveParameter] string $value): bool
    {
        try {
            return match ($format) {
                'fake_instrument_descriptor' => PaperCanonicalFakeInstrumentDescriptor::decode($value)->encoded() === $value,
                'fake_reservation_descriptor' => PaperCanonicalFakeReservationDescriptor::decode($value)->encoded() === $value,
                default => preg_match(self::FORMATS[$format], $value) === 1,
            };
        } catch (\Throwable) {
            return false;
        }
    }
}
