<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution;

use App\Tests\Trading\Paper\Execution\Strategy\PaperCanonicalPreparedEffectCodecTest;
use App\Trading\Paper\Execution\Fake\PaperCanonicalFakeInstrumentDescriptor as InstrumentDescriptor;
use App\Trading\Paper\Execution\Fake\PaperCanonicalFakeReservationDescriptor as ReservationDescriptor;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Identity\PaperModernStrategyIdentity;
use App\Trading\Paper\Execution\PaperIdentifierAwareRedaction as Redaction;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalPreparedEffect;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEventRedactor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * #132 decision e: the narrow identifier allowlist in front of PaperMarketEventRedactor.
 *
 * Several tests first check that the redactor alone still refuses a value. Those preconditions
 * document the redactor defect the allowlist works around: once the defect is fixed at the
 * source, they fail and the allowlist can be removed.
 */
#[CoversClass(Redaction::class)]
final class PaperIdentifierAwareRedactionTest extends TestCase
{
    /** portfolio_input_hash of the reservation descriptor of cell paper132-p4-cell-a. */
    private const REPRODUCED = 'sha256:3709f63c6b0b1b0e12e1ba3e00c79c33c27dd157fed1d2a6b8c072553d66dd28';

    private const REFUSED = 'paper_market_sensitive_field_rejected';

    private const SAFE = 'safe';

    private const CORPUS_SIZE = 100_000;

    /** @var array<string, string> the reviewed identifier formats */
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

    /** The reviewed closed allowlist: widening the helper's list must fail this test. */
    private const SITES = [
        'fake_order_metadata' => [
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
            InstrumentDescriptor::METADATA_KEY => 'fake_instrument_descriptor',
            ReservationDescriptor::METADATA_KEY => 'fake_reservation_descriptor',
        ],
        'order_intent_identity' => [
            'client_order_id' => 'client_order_id',
        ],
        'cell_provenance' => [
            'paper_execution_cell_id' => 'sha256',
            'configuration_snapshot_id' => 'sha256',
            'config_hash' => 'sha256',
            'condition_catalog_hash' => 'sha256',
        ],
        'canonical_intent_raw_inputs' => [
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
        'legacy_lifecycle' => [
            'config_hash' => 'sha256',
            'decision_key' => 'decision_key',
            'trade_id' => 'legacy_trade_id',
            'internal_trade_id' => 'legacy_trade_id',
        ],
        'strategy_observation' => [
            'config_hash' => 'sha256',
            'condition_catalog_hash' => 'sha256',
        ],
    ];

    /** First index of identifiers() whose value of that format the redactor alone refuses. */
    private const FIRST_REFUSED_INDEX = [
        'sha256' => 9,
        'decision_key' => 4,
        'decision_id' => 306,
        'orchestration_set_id' => 175,
        'effective_config_reference' => 56,
        'client_order_id' => 1600,
        'intent_id' => 141,
        'canonical_trade_id' => 194,
        'legacy_trade_id' => 230,
    ];

    public function testTheAllowlistIsExactlyTheReviewedClosedList(): void
    {
        self::assertSame(self::SITES, (new \ReflectionClassConstant(Redaction::class, 'SITES'))->getValue());
        self::assertSame(self::FORMATS, (new \ReflectionClassConstant(Redaction::class, 'FORMATS'))->getValue());
        foreach (array_keys(self::SITES) as $site) {
            self::assertSame($site, \constant(Redaction::class . '::SITE_' . strtoupper($site)));
        }
        foreach (self::SITES as $site => $paths) {
            foreach ($paths as $path => $format) {
                self::assertSame(self::SAFE, self::rawVerdict(self::payloadAt($path, 'paper-identifier')), $site . ' ' . $path);
            }
        }
    }

    public function testTheValueThatBlockedCellAPasses(): void
    {
        $descriptor = self::cellADescriptor();
        self::assertStringContainsString('"portfolio_input_hash":"' . self::REPRODUCED . '"', $descriptor);
        foreach ([[ReservationDescriptor::METADATA_KEY => $descriptor], ['plan_hash' => self::REPRODUCED]] as $metadata) {
            self::assertSame(self::REFUSED, self::rawVerdict($metadata), 'The redactor alone still refuses it.');
            self::assertSame(self::SAFE, self::verdict($metadata, Redaction::SITE_FAKE_ORDER_METADATA));
        }
        foreach (self::SITES as $site => $paths) {
            foreach ($paths as $path => $format) {
                if ($format === 'sha256') {
                    self::assertSame(self::SAFE, self::verdict(self::payloadAt($path, self::REPRODUCED), $site), $site . ' ' . $path);
                }
            }
        }
    }

    public function testEveryListedPathAcceptsAValueOfItsFormatThatTheRedactorRefuses(): void
    {
        foreach (self::SITES as $site => $paths) {
            foreach ($paths as $path => $format) {
                if (!isset(self::FORMATS[$format])) {
                    continue; // the two Fake descriptors: testOnlyStrictlyDecodedDescriptorsAreMasked()
                }
                $payload = self::payloadAt($path, self::refusedIdentifier($format));
                self::assertSame(self::REFUSED, self::rawVerdict($payload), $site . ' ' . $path);
                self::assertSame(self::SAFE, self::verdict($payload, $site), $site . ' ' . $path);
            }
        }
    }

    public function testARandomCorpusOfEveryListedFormatIsNeverRefused(): void
    {
        $refused = [];
        $refusedWithoutAllowlist = 0;
        for ($index = 0; $index < self::CORPUS_SIZE; ++$index) {
            $id = self::identifiers($index);
            $payloads = [
                Redaction::SITE_CANONICAL_INTENT_RAW_INPUTS => [
                    'plan_hash' => $id['sha256'],
                    'canonical_identity' => [
                        'orchestration_set_id' => $id['orchestration_set_id'],
                        'decision_id' => $id['decision_id'],
                        'decision_key' => $id['decision_key'],
                        'intent_id' => $id['intent_id'],
                        'client_order_id' => $id['client_order_id'],
                        'internal_trade_id' => $id['canonical_trade_id'],
                        'effective_config_reference' => $id['effective_config_reference'],
                    ],
                ],
                Redaction::SITE_LEGACY_LIFECYCLE => ['trade_id' => $id['legacy_trade_id']],
            ];
            foreach ($payloads as $site => $payload) {
                $verdict = self::verdict($payload, $site);
                if ($verdict !== self::SAFE) {
                    $refused[] = $site . ' #' . $index . ': ' . $verdict;
                }
                if ($index < 500 && self::rawVerdict($payload) !== self::SAFE) {
                    ++$refusedWithoutAllowlist;
                }
            }
        }

        self::assertCount(0, $refused, implode("\n", \array_slice($refused, 0, 10)));
        self::assertGreaterThan(0, $refusedWithoutAllowlist, 'Without the allowlist the same corpus is refused.');
    }

    public function testTheSameValuesAtAnUnlistedPathAreScannedExactlyAsBefore(): void
    {
        foreach (array_keys(self::FIRST_REFUSED_INDEX) as $format) {
            $value = self::refusedIdentifier($format);
            foreach (array_keys(self::SITES) as $site) {
                foreach ([['note' => $value], ['plan' => ['comment' => $value]], ['comment' => [$value]]] as $payload) {
                    self::assertSame(self::REFUSED, self::verdict($payload, $site), $site . ' ' . $format);
                }
            }
        }

        $sha256 = self::refusedIdentifier('sha256');
        $cases = [
            'listed key nested elsewhere' => [Redaction::SITE_FAKE_ORDER_METADATA, ['plan' => ['plan_hash' => $sha256]]],
            'listed key holding a list' => [Redaction::SITE_FAKE_ORDER_METADATA, ['plan_hash' => [$sha256]]],
            'key listed for another site' => [Redaction::SITE_FAKE_ORDER_METADATA, ['trade_id' => self::refusedIdentifier('legacy_trade_id')]],
            'key listed only when nested' => [Redaction::SITE_FAKE_ORDER_METADATA, ['intent_id' => self::refusedIdentifier('intent_id')]],
            'legacy trade id at the canonical trade id' => [Redaction::SITE_FAKE_ORDER_METADATA, ['internal_trade_id' => self::refusedIdentifier('legacy_trade_id')]],
            'another format at a listed key' => [Redaction::SITE_FAKE_ORDER_METADATA, ['plan_hash' => self::refusedIdentifier('decision_key')]],
            'another identifier at the order id' => [Redaction::SITE_FAKE_ORDER_METADATA, ['client_order_id' => self::refusedIdentifier('intent_id')]],
            'hash beside the order id' => [Redaction::SITE_ORDER_INTENT_IDENTITY, ['plan_hash' => $sha256]],
            'hash at an unlisted provenance key' => [Redaction::SITE_CELL_PROVENANCE, ['plan_hash' => $sha256]],
            'hash at an unlisted identity key' => [Redaction::SITE_CANONICAL_INTENT_RAW_INPUTS, ['canonical_identity' => ['plan_hash' => $sha256]]],
            'map instead of the hash list' => [Redaction::SITE_CANONICAL_INTENT_RAW_INPUTS, ['plan' => ['inputHashes' => ['first' => $sha256]]]],
            'nested list in the hash list' => [Redaction::SITE_CANONICAL_INTENT_RAW_INPUTS, ['plan' => ['inputHashes' => [[$sha256]]]]],
            'identity key at the top level' => [Redaction::SITE_CANONICAL_INTENT_RAW_INPUTS, ['intent_id' => self::refusedIdentifier('intent_id')]],
            'legacy key nested elsewhere' => [Redaction::SITE_LEGACY_LIFECYCLE, ['lifecycle' => ['trade_id' => self::refusedIdentifier('legacy_trade_id')]]],
            'canonical trade id in the legacy lifecycle' => [Redaction::SITE_LEGACY_LIFECYCLE, ['internal_trade_id' => self::refusedIdentifier('canonical_trade_id')]],
            'hash at an unlisted observation key' => [Redaction::SITE_STRATEGY_OBSERVATION, ['plan_hash' => $sha256]],
            'observation key nested elsewhere' => [Redaction::SITE_STRATEGY_OBSERVATION, ['conditions' => ['config_hash' => $sha256]]],
        ];
        foreach ($cases as $label => [$site, $payload]) {
            self::assertSame(self::REFUSED, self::rawVerdict($payload), $label);
            self::assertSame(self::REFUSED, self::verdict($payload, $site), $label);
        }

        $refused = 0;
        for ($index = 0; $index < 200; ++$index) {
            foreach (self::identifiers($index) as $format => $value) {
                $payload = ['note' => $value, 'plan' => ['comment' => $value]];
                $expected = self::rawVerdict($payload);
                foreach ([Redaction::SITE_FAKE_ORDER_METADATA, Redaction::SITE_CANONICAL_INTENT_RAW_INPUTS] as $site) {
                    self::assertSame($expected, self::verdict($payload, $site), $site . ' ' . $format . ' #' . $index);
                }
                $refused += $expected === self::SAFE ? 0 : 1;
            }
        }
        self::assertGreaterThan(0, $refused, 'The sample must contain values the redactor refuses.');
    }

    public function testAListedPathHoldingAnythingButItsExactFormatIsStillScanned(): void
    {
        $hex = substr(self::REPRODUCED, 7);
        $cases = [
            'non hexadecimal payload' => ['plan_hash', 'sha256:' . str_repeat('z', 64), self::SAFE],
            'encoded secret behind the prefix' => ['plan_hash', 'sha256:' . base64_encode('password=hunter2&api_key=0123456789abcdef'), self::REFUSED],
            'upper case hexadecimal' => ['plan_hash', 'sha256:' . strtoupper($hex), self::REFUSED],
            'one digit short' => ['plan_hash', 'sha256:' . substr($hex, 0, 63), self::REFUSED],
            'one digit long' => ['plan_hash', self::REPRODUCED . '0', self::REFUSED],
            'trailing newline' => ['plan_hash', self::REPRODUCED . "\n", self::REFUSED],
            'leading space' => ['plan_hash', ' ' . self::REPRODUCED, self::REFUSED],
            'form data appended' => ['plan_hash', self::REPRODUCED . '&password=hunter2', self::REFUSED],
            'form data' => ['decision_key', 'password=hunter2&api_key=0123456789abcdef', self::REFUSED],
            'bearer token' => ['client_order_id', 'Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJ4In0.c2lnbmF0dXJl', self::REFUSED],
            'private key' => ['config_hash', '-----BEGIN PRIVATE KEY-----MIIEvQIBADANBg-----END PRIVATE KEY-----', self::REFUSED],
            'lower case order id prefix' => ['client_order_id', 'cid' . strtoupper(substr($hex, 0, 29)), self::REFUSED],
        ];
        foreach ($cases as $label => [$key, $value, $expected]) {
            $payload = [$key => $value];
            self::assertSame($expected, self::rawVerdict($payload), $label);
            self::assertSame($expected, self::verdict($payload, Redaction::SITE_FAKE_ORDER_METADATA), $label);
        }
    }

    public function testOnlyStrictlyDecodedDescriptorsAreMasked(): void
    {
        $instrumentEffect = PaperCanonicalPreparedEffectCodecTest::fixture(contractSize: 0.01);
        $reservationEffect = PaperCanonicalPreparedEffectCodecTest::fixture();
        $descriptors = [
            InstrumentDescriptor::METADATA_KEY => InstrumentDescriptor::fromPlan(self::cell($instrumentEffect), $instrumentEffect->plan)->encoded(),
            ReservationDescriptor::METADATA_KEY => ReservationDescriptor::fromEffect(self::cell($reservationEffect), $reservationEffect)->encoded(),
        ];
        foreach ($descriptors as $key => $descriptor) {
            self::assertSame(self::SAFE, self::verdict([$key => $descriptor], Redaction::SITE_FAKE_ORDER_METADATA), $key);
            $forged = [$key => substr_replace($descriptor, '"api_key":"0123456789abcdef",', 1, 0)];
            self::assertSame(self::REFUSED, self::rawVerdict($forged), $key);
            self::assertSame(self::REFUSED, self::verdict($forged, Redaction::SITE_FAKE_ORDER_METADATA), $key);
        }

        $cellA = self::cellADescriptor();
        /** @var array<string, mixed> $document */
        $document = json_decode($cellA, true, 512, JSON_THROW_ON_ERROR);
        $variants = [
            'pretty printed' => [ReservationDescriptor::METADATA_KEY => json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)],
            'members reordered' => [ReservationDescriptor::METADATA_KEY => json_encode(array_reverse($document), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)],
            'trailing newline' => [ReservationDescriptor::METADATA_KEY => $cellA . "\n"],
            'under the instrument key' => [InstrumentDescriptor::METADATA_KEY => $cellA],
            'under an unlisted key' => ['descriptor' => $cellA],
        ];
        foreach ($variants as $label => $payload) {
            self::assertSame(self::REFUSED, self::rawVerdict($payload), $label);
            self::assertSame(self::REFUSED, self::verdict($payload, Redaction::SITE_FAKE_ORDER_METADATA), $label);
        }
        self::assertSame(self::REFUSED, self::verdict([ReservationDescriptor::METADATA_KEY => $cellA], Redaction::SITE_CANONICAL_INTENT_RAW_INPUTS));
    }

    public function testEveryKeyNameIsStillScanned(): void
    {
        $sha256 = self::refusedIdentifier('sha256');
        foreach (['api_key', 'password', 'private_key', 'passphrase', 'access_token', 'signature', 'authorization', 'client_secret'] as $key) {
            $payloads = [
                Redaction::SITE_FAKE_ORDER_METADATA => ['plan_hash' => $sha256, $key => 'value'],
                Redaction::SITE_CANONICAL_INTENT_RAW_INPUTS => [
                    'plan_hash' => $sha256,
                    'canonical_identity' => [$key => 'value', 'intent_id' => self::refusedIdentifier('intent_id')],
                ],
            ];
            foreach ($payloads as $site => $payload) {
                self::assertSame(self::REFUSED, self::verdict($payload, $site), $site . ' ' . $key);
            }
        }
    }

    public function testAnUnknownSiteIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_identifier_redaction_site_unknown');

        Redaction::assertSafe(['plan_hash' => self::REPRODUCED], 'everything');
    }

    /** @return array<string, string> one value of every listed identifier format, derived from $index */
    private static function identifiers(int $index): array
    {
        $hash = static fn (string $label): string => hash('sha256', 'paper-identifier-corpus|' . $label . '|' . $index);
        $uuid = $hash('decision_id');

        return [
            'sha256' => 'sha256:' . $hash('sha256'),
            'decision_key' => 'paper:' . $hash('decision_key'),
            'decision_id' => \sprintf(
                '%s-%s-5%s-%s%s-%s',
                substr($uuid, 0, 8),
                substr($uuid, 8, 4),
                substr($uuid, 13, 3),
                '89ab'[hexdec($uuid[16]) % 4],
                substr($uuid, 17, 3),
                substr($uuid, 20, 12),
            ),
            'orchestration_set_id' => 'paper-set:' . substr($hash('orchestration_set_id'), 0, 48),
            'effective_config_reference' => 'effective-config-snapshot:sha256:' . $hash('effective_config_reference'),
            'client_order_id' => 'CID' . strtoupper(substr($hash('client_order_id'), 0, 29)),
            'intent_id' => 'int:' . substr($hash('intent_id'), 0, 48),
            'canonical_trade_id' => 'itd:' . substr($hash('canonical_trade_id'), 0, 32),
            'legacy_trade_id' => 'ptrd:' . $hash('legacy_trade_id'),
        ];
    }

    private static function refusedIdentifier(string $format): string
    {
        return self::identifiers(self::FIRST_REFUSED_INDEX[$format])[$format];
    }

    /** @return array<string, mixed> $value placed at a path of the allowlist syntax */
    private static function payloadAt(string $path, string $value): array
    {
        $isList = str_ends_with($path, '[]');
        $node = $isList ? [$value] : $value;
        foreach (array_reverse(explode('.', $isList ? substr($path, 0, -2) : $path)) as $segment) {
            $node = [$segment => $node];
        }

        return $node;
    }

    private static function cellADescriptor(): string
    {
        $descriptor = file_get_contents(\dirname(__DIR__, 3) . '/Fixtures/PaperExecution/reservation-descriptor-redactor-false-positive.json');
        self::assertIsString($descriptor);
        self::assertSame($descriptor, ReservationDescriptor::decode($descriptor)->encoded());

        return $descriptor;
    }

    private static function cell(PaperCanonicalPreparedEffect $effect): PaperExecutionCell
    {
        $provenance = $effect->provenance;
        $network = PaperMarketDataNetwork::from($provenance['paper_network']);
        $venue = PaperMarketDataVenue::from($provenance['market_data_venue']);

        return PaperExecutionCell::createModern(
            $network,
            $venue,
            $provenance['configuration_snapshot_id'],
            PaperModernStrategyIdentity::fromDurableIdentity(
                $network,
                $venue,
                $provenance['mode_id'],
                $provenance['mode_version'],
                $provenance['setup_id'],
                $provenance['setup_version'],
                $provenance['side'],
                $provenance['config_hash'],
                $provenance['condition_catalog_hash'],
            ),
            $provenance['run_id'],
        );
    }

    /** @param array<array-key, mixed> $payload */
    private static function verdict(array $payload, string $site): string
    {
        try {
            Redaction::assertSafe($payload, $site);

            return self::SAFE;
        } catch (\InvalidArgumentException $exception) {
            return $exception->getMessage();
        }
    }

    /** @param array<array-key, mixed> $payload */
    private static function rawVerdict(array $payload): string
    {
        try {
            PaperMarketEventRedactor::assertSafe($payload);

            return self::SAFE;
        } catch (\InvalidArgumentException $exception) {
            return $exception->getMessage();
        }
    }
}
