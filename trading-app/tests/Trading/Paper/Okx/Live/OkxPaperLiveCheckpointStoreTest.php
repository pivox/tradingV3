<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Okx\Live;

use App\Trading\Paper\Dataset\PaperDatasetRecorderFilesystem;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\Okx\Live\OkxPaperLiveCheckpoint;
use App\Trading\Paper\Okx\Live\OkxPaperLiveCheckpointStore;
use App\Trading\Paper\Okx\Live\OkxPaperLiveIntegrityException;
use App\Trading\Paper\Okx\Live\OkxPaperStreamFrontier;
use App\Trading\Paper\Okx\Normalization\OkxPaperSourceOrdinal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(OkxPaperStreamFrontier::class)]
#[CoversClass(OkxPaperLiveCheckpoint::class)]
#[CoversClass(OkxPaperLiveCheckpointStore::class)]
final class OkxPaperLiveCheckpointStoreTest extends TestCase
{
    private const DATASET_ID = 'okx-live-checkpoint-001';
    private const CONFIGURATION_SHA256 = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private string $testRoot;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'okx-live-checkpoint-test-');
        if ($path === false || !unlink($path) || !mkdir($path, 0700)) {
            self::fail('Unable to create test directory.');
        }
        $resolved = realpath($path);
        self::assertIsString($resolved);
        $this->testRoot = $resolved;
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->testRoot);
    }

    public function testRestAndWebSocketCopiesOfTheSameCandleHaveTheSameStrictFrontier(): void
    {
        $rest = $this->candleEvent('rest_warmup', '2026-07-22T10:00:01.000000Z');
        $webSocket = $this->candleEvent('ws_candle', '2026-07-22T10:00:02.000000Z');

        $restFrontier = OkxPaperStreamFrontier::fromEvent($rest);
        $webSocketFrontier = OkxPaperStreamFrontier::fromEvent($webSocket);

        self::assertSame($restFrontier->toArray(), $webSocketFrontier->toArray());
        self::assertSame([
            'source_identity' => '1m|1784714400000',
            'natural_identity' => 'okx|BTC-USDT-SWAP|candle_1m|1m|1784714400000',
            'canonical_digest' => $restFrontier->canonicalDigest,
        ], $restFrontier->toArray());
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $restFrontier->canonicalDigest);
    }

    public function testFrontierIsClosedAndEveryIdentityIsBounded(): void
    {
        $valid = [
            'source_identity' => '242720721',
            'natural_identity' => 'okx|BTC-USDT-SWAP|public_trade|242720721',
            'canonical_digest' => str_repeat('b', 64),
        ];

        self::assertSame($valid, OkxPaperStreamFrontier::fromArray($valid)->toArray());

        foreach ([
            $valid + ['unexpected' => true],
            array_diff_key($valid, ['source_identity' => true]),
            array_replace($valid, ['source_identity' => '']),
            array_replace($valid, ['natural_identity' => str_repeat('x', 1025)]),
            array_replace($valid, ['canonical_digest' => str_repeat('A', 64)]),
        ] as $invalid) {
            try {
                OkxPaperStreamFrontier::fromArray($invalid);
                self::fail('An invalid frontier must be rejected.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
            }
        }
    }

    public function testFreshCheckpointHasTheCompleteClosedVersionTwoSchema(): void
    {
        $checkpoint = OkxPaperLiveCheckpoint::fresh(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $state = $checkpoint->toArray();

        self::assertSame([
            'configuration_sha256',
            'connection_epoch',
            'dataset_id',
            'failure_reason',
            'healthy_stop',
            'last_acknowledged_event_id',
            'ordinal_state',
            'overlap_pagination_by_stream',
            'pending_event',
            'pending_frontier',
            'pending_transition',
            'phase',
            'reconnect',
            'remaining_boundaries',
            'remaining_symbols',
            'resync_by_symbol',
            'schema_version',
            'source_epochs',
            'stream_frontiers',
        ], array_keys($state));
        self::assertSame(2, $state['schema_version']);
        self::assertSame(self::DATASET_ID, $state['dataset_id']);
        self::assertSame(self::CONFIGURATION_SHA256, $state['configuration_sha256']);
        self::assertSame('warming', $state['phase']);
        self::assertSame(['BTCUSDT', 'ETHUSDT'], $state['remaining_symbols']);
        self::assertSame([
            ['symbol' => 'BTCUSDT', 'reason' => 'initial'],
            ['symbol' => 'ETHUSDT', 'reason' => 'initial'],
        ], $state['remaining_boundaries']);
        self::assertCount(28, $state['stream_frontiers']);
        self::assertCount(20, $state['overlap_pagination_by_stream']);
        self::assertSame($state, OkxPaperLiveCheckpoint::fromArray($state)->toArray());

        $state['unexpected'] = true;
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('okx_paper_live_checkpoint_invalid');
        OkxPaperLiveCheckpoint::fromArray($state);
    }

    public function testEveryTransitionKindHasOnlyItsExactRepresentableStagesAndArguments(): void
    {
        $validTransitions = [
            ['warming', ['kind' => 'rest_fetch', 'symbol' => 'BTCUSDT', 'stream' => 'BTCUSDT/rest/candle_1m', 'stage' => 'current_candles']],
            ['warming', ['kind' => 'rest_fetch', 'symbol' => 'BTCUSDT', 'stream' => 'BTCUSDT/rest/public_trade', 'stage' => 'recent_trades']],
            ['reconnecting', ['kind' => 'rest_fetch', 'symbol' => 'BTCUSDT', 'stream' => 'BTCUSDT/ws/candle_1m', 'stage' => 'history_candles']],
            ['reconnecting', ['kind' => 'rest_fetch', 'symbol' => 'BTCUSDT', 'stream' => 'BTCUSDT/ws/public_trade', 'stage' => 'history_trades']],
            ['warming', ['kind' => 'rest_fetch', 'symbol' => 'BTCUSDT', 'stream' => 'BTCUSDT/rest/top_of_book', 'stage' => 'order_book']],
            ['connecting', ['kind' => 'transport_connect', 'symbol' => null, 'stream' => 'public', 'stage' => 'connect']],
            ['reconnecting', ['kind' => 'transport_close', 'symbol' => null, 'stream' => 'business', 'stage' => 'close']],
            ['subscribing', ['kind' => 'subscription_send', 'symbol' => null, 'stream' => 'public', 'stage' => 'subscribe']],
            ['reconnecting', ['kind' => 'timer_schedule', 'symbol' => null, 'stream' => null, 'stage' => 'reconnect_delay']],
            ['resyncing', ['kind' => 'timer_schedule', 'symbol' => 'BTCUSDT', 'stream' => 'BTCUSDT/ws/top_of_book', 'stage' => 'resync_timeout']],
            ['reconnecting', ['kind' => 'timer_cancel', 'symbol' => null, 'stream' => null, 'stage' => 'cancel_reconnect_timer']],
            ['resyncing', ['kind' => 'timer_cancel', 'symbol' => 'BTCUSDT', 'stream' => 'BTCUSDT/ws/top_of_book', 'stage' => 'cancel_resync_timer']],
            ['warming', ['kind' => 'emit_boundary', 'symbol' => 'BTCUSDT', 'stream' => 'BTCUSDT/control/snapshot_boundary', 'stage' => 'initial']],
            ['reconnecting', ['kind' => 'emit_boundary', 'symbol' => 'BTCUSDT', 'stream' => 'BTCUSDT/control/snapshot_boundary', 'stage' => 'reconnect']],
            ['resyncing', ['kind' => 'emit_boundary', 'symbol' => 'BTCUSDT', 'stream' => 'BTCUSDT/control/snapshot_boundary', 'stage' => 'sequence_gap']],
            ['stopping', ['kind' => 'healthy_stop', 'symbol' => 'BTCUSDT', 'stream' => 'BTCUSDT/control/connection_state', 'stage' => 'emit_stopped']],
            ['stopping', ['kind' => 'healthy_stop', 'symbol' => null, 'stream' => null, 'stage' => 'finalize']],
            ['stopping', ['kind' => 'loop_stop', 'symbol' => null, 'stream' => null, 'stage' => 'stop_loop']],
        ];

        foreach ($validTransitions as [$phase, $transition]) {
            $state = OkxPaperLiveCheckpoint::fresh(self::DATASET_ID, self::CONFIGURATION_SHA256)->toArray();
            $state['phase'] = $phase;
            if ($phase === 'stopping') {
                $state['healthy_stop'] = ['requested' => true, 'remaining_symbols' => ['BTCUSDT', 'ETHUSDT']];
            }
            $state['pending_transition'] = $transition;
            if ($transition['stage'] === 'reconnect_delay') {
                $state['reconnect'] = [
                    'attempt' => 1,
                    'deadline_at' => '2026-07-22T10:00:01.000000Z',
                    'stable_since' => null,
                    'accepted_events' => 0,
                ];
            }
            if (\in_array($transition['stage'], [
                'resync_timeout', 'cancel_resync_timer', 'sequence_gap',
            ], true)) {
                $bookFrontier = OkxPaperStreamFrontier::fromEvent($this->bookEvent())->toArray();
                $state['resync_by_symbol']['BTCUSDT'] = [
                    'attempt' => 1,
                    'frontier' => $bookFrontier,
                    'source_sequence' => '9001',
                    'deadline_at' => '2026-07-22T10:00:10.000000Z',
                    'policy' => 'book_seq_overlap_v1',
                ];
                $state['stream_frontiers']['BTCUSDT/ws/top_of_book'] = $bookFrontier;
            }
            if (\in_array($transition['stage'], ['history_candles', 'history_trades'], true)) {
                $isTrade = $transition['stage'] === 'history_trades';
                $target = OkxPaperStreamFrontier::fromEvent(
                    $isTrade
                        ? $this->tradeEvent('rest_recovery')
                        : $this->candleEvent('rest_warmup', '2026-07-22T10:00:01.000000Z'),
                )->toArray();
                $state['resync_by_symbol']['BTCUSDT'] = [
                    'attempt' => 1,
                    'frontier' => $target,
                    'source_sequence' => null,
                    'deadline_at' => '2026-07-22T10:00:10.000000Z',
                    'policy' => 'frontier_overlap_v1',
                ];
                $state['stream_frontiers'][$transition['stream']] = $target;
                $state['overlap_pagination_by_stream'][$transition['stream']] = [
                    'endpoint' => $isTrade ? 'history_trades' : 'history_candles',
                    'pagination_type' => $isTrade ? 2 : null,
                    'next_cursor' => $isTrade ? '1784714400000' : '1784714399000',
                    'pages_consumed' => 0,
                    'pages_remaining' => 10,
                    'target_frontier' => $target,
                    'deadline_at' => '2026-07-22T10:00:10.000000Z',
                ];
            }
            self::assertSame($transition, OkxPaperLiveCheckpoint::fromArray($state)->pendingTransition);
        }

        foreach ([
            ['kind' => 'rest_fetch', 'symbol' => null, 'stream' => 'BTCUSDT/rest/candle_1m', 'stage' => 'current_candles'],
            ['kind' => 'rest_fetch', 'symbol' => 'BTCUSDT', 'stream' => 'BTCUSDT/rest/public_trade', 'stage' => 'current_candles'],
            ['kind' => 'transport_connect', 'symbol' => null, 'stream' => null, 'stage' => 'connect'],
            ['kind' => 'subscription_send', 'symbol' => null, 'stream' => 'private', 'stage' => 'subscribe'],
            ['kind' => 'timer_schedule', 'symbol' => 'BTCUSDT', 'stream' => null, 'stage' => 'reconnect_delay'],
            ['kind' => 'timer_cancel', 'symbol' => null, 'stream' => null, 'stage' => 'resync_timeout'],
            ['kind' => 'emit_boundary', 'symbol' => 'ETHUSDT', 'stream' => 'BTCUSDT/control/snapshot_boundary', 'stage' => 'initial'],
            ['kind' => 'healthy_stop', 'symbol' => null, 'stream' => null, 'stage' => 'emit_stopped'],
            ['kind' => 'loop_stop', 'symbol' => null, 'stream' => null, 'stage' => 'finalize'],
            ['kind' => 'rest_fetch', 'symbol' => 'BTCUSDT', 'stream' => 'BTCUSDT/rest/candle_1m', 'stage' => 'unknown'],
        ] as $transition) {
            $state = OkxPaperLiveCheckpoint::fresh(self::DATASET_ID, self::CONFIGURATION_SHA256)->toArray();
            $state['pending_transition'] = $transition;
            $this->assertCheckpointInvalid($state);
        }
    }

    public function testFullRecoveryStateRoundTripsWithoutResettingAnyBudgetOrDeadline(): void
    {
        $state = OkxPaperLiveCheckpoint::fresh(self::DATASET_ID, self::CONFIGURATION_SHA256)->toArray();
        $tradeFrontier = OkxPaperStreamFrontier::fromEvent($this->tradeEvent('rest_recovery'))->toArray();
        $bookFrontier = OkxPaperStreamFrontier::fromEvent($this->bookEvent())->toArray();
        $state['phase'] = 'reconnecting';
        $state['pending_transition'] = [
            'kind' => 'rest_fetch',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/ws/public_trade',
            'stage' => 'history_trades',
        ];
        $state['remaining_symbols'] = ['ETHUSDT'];
        $state['remaining_boundaries'] = [['symbol' => 'ETHUSDT', 'reason' => 'sequence_gap']];
        $state['connection_epoch'] = 3;
        $state['source_epochs'] = ['BTCUSDT' => 2, 'ETHUSDT' => 4];
        $state['stream_frontiers']['BTCUSDT/rest/top_of_book'] = $bookFrontier;
        $state['stream_frontiers']['BTCUSDT/ws/top_of_book'] = $bookFrontier;
        $state['stream_frontiers']['BTCUSDT/rest/public_trade'] = $tradeFrontier;
        $state['stream_frontiers']['BTCUSDT/ws/public_trade'] = $tradeFrontier;
        $state['last_acknowledged_event_id'] = str_repeat('c', 64);
        $state['reconnect'] = [
            'attempt' => 4,
            'deadline_at' => null,
            'stable_since' => '2026-07-22T10:00:00.000000Z',
            'accepted_events' => 7,
        ];
        $state['resync_by_symbol']['BTCUSDT'] = [
            'attempt' => 2,
            'frontier' => $tradeFrontier,
            'source_sequence' => null,
            'deadline_at' => '2026-07-22T10:00:10.000000Z',
            'policy' => 'frontier_overlap_v1',
        ];
        $state['overlap_pagination_by_stream']['BTCUSDT/ws/public_trade'] = [
            'endpoint' => 'history_trades',
            'pagination_type' => 1,
            'next_cursor' => '242720700',
            'pages_consumed' => 3,
            'pages_remaining' => 7,
            'target_frontier' => $tradeFrontier,
            'deadline_at' => '2026-07-22T10:00:10.000000Z',
        ];

        self::assertSame($state, OkxPaperLiveCheckpoint::fromArray($state)->toArray());
    }

    public function testClosedSchemaRejectsInvalidFiniteStateAndBounds(): void
    {
        $valid = OkxPaperLiveCheckpoint::fresh(self::DATASET_ID, self::CONFIGURATION_SHA256)->toArray();
        $tradeFrontier = OkxPaperStreamFrontier::fromEvent($this->tradeEvent('rest_recovery'))->toArray();
        $bookFrontier = OkxPaperStreamFrontier::fromEvent($this->bookEvent())->toArray();

        $invalidStates = [];
        $invalidStates[] = array_replace($valid, ['phase' => 'unknown']);
        $invalidStates[] = array_replace($valid, ['phase' => 'failed', 'failure_reason' => null]);
        $invalidStates[] = array_replace($valid, ['phase' => 'failed', 'failure_reason' => 'raw secret failure']);
        $invalidStates[] = array_replace($valid, ['phase' => 'complete', 'pending_transition' => [
            'kind' => 'loop_stop', 'symbol' => null, 'stream' => null, 'stage' => 'stop_loop',
        ]]);
        $invalidStates[] = array_replace($valid, ['remaining_symbols' => ['ETHUSDT', 'BTCUSDT']]);
        $invalidStates[] = array_replace($valid, ['remaining_symbols' => ['BTCUSDT', 'BTCUSDT']]);
        $invalidStates[] = array_replace($valid, ['remaining_boundaries' => [
            ['symbol' => 'ETHUSDT', 'reason' => 'initial'],
            ['symbol' => 'BTCUSDT', 'reason' => 'reconnect'],
        ]]);
        $invalidStates[] = array_replace($valid, ['remaining_boundaries' => [
            ['symbol' => 'BTCUSDT', 'reason' => 'initial', 'unexpected' => true],
        ]]);
        $invalidStates[] = array_replace($valid, ['connection_epoch' => 0]);
        $invalidStates[] = array_replace($valid, ['source_epochs' => ['BTCUSDT' => 1, 'ETHUSDT' => 0]]);
        $frontiers = $valid['stream_frontiers'];
        $frontiers['unknown'] = null;
        $invalidStates[] = array_replace($valid, ['stream_frontiers' => $frontiers]);
        $frontiers = $valid['stream_frontiers'];
        $frontiers['ETHUSDT/rest/public_trade'] = $tradeFrontier;
        $invalidStates[] = array_replace($valid, ['stream_frontiers' => $frontiers]);
        $invalidStates[] = array_replace($valid, ['ordinal_state' => [
            'schema_version' => 1,
            'scopes' => ['okx/BTCUSDT/private_orders' => []],
        ]]);
        $invalidStates[] = array_replace($valid, ['reconnect' => [
            'attempt' => 7, 'deadline_at' => null, 'stable_since' => null, 'accepted_events' => 0,
        ]]);
        $invalidStates[] = array_replace($valid, ['reconnect' => [
            'attempt' => 1, 'deadline_at' => '2026-07-22T12:00:00+02:00', 'stable_since' => null, 'accepted_events' => 0,
        ]]);
        $resync = $valid['resync_by_symbol'];
        $resync['BTCUSDT'] = [
            'attempt' => 4,
            'frontier' => $bookFrontier,
            'source_sequence' => '9001',
            'deadline_at' => '2026-07-22T10:00:10.000000Z',
            'policy' => 'book_seq_overlap_v1',
        ];
        $invalidStates[] = array_replace($valid, ['resync_by_symbol' => $resync]);
        $resync['BTCUSDT'] = [
            'attempt' => 1,
            'frontier' => $tradeFrontier,
            'source_sequence' => '242720721',
            'deadline_at' => '2026-07-22T10:00:10.000000Z',
            'policy' => 'frontier_overlap_v1',
        ];
        $invalidStates[] = array_replace($valid, ['resync_by_symbol' => $resync]);
        $pagination = $valid['overlap_pagination_by_stream'];
        $pagination['BTCUSDT/ws/public_trade'] = [
            'endpoint' => 'history_candles',
            'pagination_type' => 2,
            'next_cursor' => null,
            'pages_consumed' => 0,
            'pages_remaining' => 10,
            'target_frontier' => $tradeFrontier,
            'deadline_at' => '2026-07-22T10:00:10.000000Z',
        ];
        $invalidStates[] = array_replace($valid, ['overlap_pagination_by_stream' => $pagination]);
        $pagination['BTCUSDT/ws/public_trade'] = [
            'endpoint' => 'history_trades',
            'pagination_type' => 1,
            'next_cursor' => null,
            'pages_consumed' => 3,
            'pages_remaining' => 8,
            'target_frontier' => $tradeFrontier,
            'deadline_at' => '2026-07-22T10:00:10.000000Z',
        ];
        $invalidStates[] = array_replace($valid, ['overlap_pagination_by_stream' => $pagination]);

        foreach ($invalidStates as $invalid) {
            $this->assertCheckpointInvalid($invalid);
        }
    }

    public function testRestAndWebSocketCopiesOfTheSameTradeExcludeTransportAggregationFromFrontier(): void
    {
        $rest = OkxPaperStreamFrontier::fromEvent($this->tradeEvent('rest_recovery'));
        $webSocket = OkxPaperStreamFrontier::fromEvent($this->tradeEvent('ws_aggregated', true));

        self::assertSame($rest->toArray(), $webSocket->toArray());
        self::assertSame('242720721', $rest->sourceIdentity);
        self::assertSame(
            'okx|BTC-USDT-SWAP|public_trade|242720721',
            $rest->naturalIdentity,
        );
    }

    public function testLoadOrCreatePublishesCanonicalPrivateCheckpointAndStrictlyResumesIdentity(): void
    {
        $directory = $this->datasetDirectory('create');
        $store = new OkxPaperLiveCheckpointStore($directory);
        $checkpoint = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $path = $this->checkpointPath($directory);
        $contents = file_get_contents($path);

        self::assertIsString($contents);
        self::assertStringEndsWith("\n", $contents);
        self::assertSame(CanonicalJson::encode($checkpoint->toArray()) . "\n", $contents);
        self::assertSame(0600, fileperms($path) & 0777);
        self::assertSame(1, lstat($path)['nlink'] ?? null);
        self::assertSame(0700, fileperms($directory . '/checkpoints') & 0777);
        self::assertSame(0700, fileperms(dirname($path)) & 0777);
        unset($store);

        $resumed = new OkxPaperLiveCheckpointStore($directory);
        self::assertSame($checkpoint->toArray(), $resumed->loadOrCreate(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        )->toArray());
        unset($resumed);

        $before = file_get_contents($path);
        try {
            (new OkxPaperLiveCheckpointStore($directory))->loadOrCreate(
                self::DATASET_ID,
                str_repeat('b', 64),
            );
            self::fail('A configuration mismatch must fail closed.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }
        self::assertSame($before, file_get_contents($path));
    }

    public function testTransitionIsWriteAheadAndRestartPreservesTheExactNextAction(): void
    {
        $directory = $this->datasetDirectory('transition');
        $store = new OkxPaperLiveCheckpointStore($directory);
        $checkpoint = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $checkpoint = $this->completeInitialWarmup($store, $checkpoint);
        $transition = [
            'kind' => 'transport_connect',
            'symbol' => null,
            'stream' => 'public',
            'stage' => 'connect',
        ];

        $saved = $store->saveTransition($checkpoint, 'connecting', $transition);
        $bytes = file_get_contents($this->checkpointPath($directory));
        unset($store);

        $resumedStore = new OkxPaperLiveCheckpointStore($directory);
        $resumed = $resumedStore->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        self::assertSame('connecting', $resumed->phase);
        self::assertSame($transition, $resumed->pendingTransition);
        self::assertSame($saved->reconnect, $resumed->reconnect);
        self::assertSame($saved->remainingBoundaries, $resumed->remainingBoundaries);
        self::assertSame($bytes, file_get_contents($this->checkpointPath($directory)));
    }

    public function testPhaseSkipCannotBypassTheClosedTaskSevenPhaseGraph(): void
    {
        $directory = $this->datasetDirectory('phase-skip');
        $store = new OkxPaperLiveCheckpointStore($directory);
        $fresh = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $path = $this->checkpointPath($directory);
        $before = file_get_contents($path);

        try {
            $store->saveTransition($fresh, 'streaming', null);
            self::fail('PHASE_SKIP: a fresh checkpoint must not enter streaming.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }

        self::assertSame($before, file_get_contents($path));
    }

    public function testPublicSubscriptionCannotSkipTheCompleteBusinessChannelSequence(): void
    {
        $directory = $this->datasetDirectory('business-channel-skip');
        $store = new OkxPaperLiveCheckpointStore($directory);
        $checkpoint = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $checkpoint = $this->completeInitialWarmup($store, $checkpoint);
        $checkpoint = $store->saveTransition($checkpoint, 'connecting', [
            'kind' => 'transport_connect',
            'symbol' => null,
            'stream' => 'public',
            'stage' => 'connect',
        ]);
        $checkpoint = $store->saveTransition($checkpoint, 'subscribing', [
            'kind' => 'subscription_send',
            'symbol' => null,
            'stream' => 'public',
            'stage' => 'subscribe',
        ]);
        $path = $this->checkpointPath($directory);
        $before = file_get_contents($path);

        try {
            $store->saveTransition($checkpoint, 'streaming', null);
            self::fail('PHASE_SKIP_ACCEPTED: Business connect and subscribe must not be skipped.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }

        self::assertSame($before, file_get_contents($path));
    }

    public function testPendingWriteAheadActionCannotBeReplacedByALaterAction(): void
    {
        $directory = $this->datasetDirectory('pending-action-replacement');
        $store = new OkxPaperLiveCheckpointStore($directory);
        $checkpoint = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $checkpoint = $this->completeInitialWarmup($store, $checkpoint);
        $checkpoint = $store->saveTransition($checkpoint, 'connecting', [
            'kind' => 'transport_connect',
            'symbol' => null,
            'stream' => 'public',
            'stage' => 'connect',
        ]);
        $path = $this->checkpointPath($directory);
        $before = file_get_contents($path);

        try {
            $store->saveTransition($checkpoint, 'connecting', [
                'kind' => 'transport_connect',
                'symbol' => null,
                'stream' => 'business',
                'stage' => 'connect',
            ]);
            self::fail('PENDING_ACTION_REPLACED: Public connect must advance only to Public subscribe.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }

        self::assertSame($before, file_get_contents($path));
    }

    public function testReconnectPendingActionCannotBeReplacedByBusinessSubscribe(): void
    {
        $directory = $this->datasetDirectory('reconnect-pending-action-replacement');
        $store = new OkxPaperLiveCheckpointStore($directory);
        $checkpoint = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $checkpoint = $this->completeInitialWarmupAndEnterStreaming($store, $checkpoint);
        $checkpoint = $this->startReconnectAttempt($store, $checkpoint);
        $path = $this->checkpointPath($directory);
        $before = file_get_contents($path);

        try {
            $store->saveTransition($checkpoint, 'reconnecting', [
                'kind' => 'subscription_send',
                'symbol' => null,
                'stream' => 'business',
                'stage' => 'subscribe',
            ]);
            self::fail(
                'RECONNECT_PENDING_ACTION_REPLACED_ACCEPTED: reconnect delay must remain authoritative.',
            );
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }

        self::assertSame($before, file_get_contents($path));
    }

    public function testTaskSevenPhaseGraphAcceptsTheExactWarmConnectSubscribeStreamPath(): void
    {
        $directory = $this->datasetDirectory('task-seven-phase-path');
        $store = new OkxPaperLiveCheckpointStore($directory);
        $checkpoint = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $checkpoint = $this->completeInitialWarmup($store, $checkpoint);

        $actions = [
            ['connecting', ['kind' => 'transport_connect', 'symbol' => null, 'stream' => 'public', 'stage' => 'connect']],
            ['subscribing', ['kind' => 'subscription_send', 'symbol' => null, 'stream' => 'public', 'stage' => 'subscribe']],
            ['connecting', ['kind' => 'transport_connect', 'symbol' => null, 'stream' => 'business', 'stage' => 'connect']],
            ['subscribing', ['kind' => 'subscription_send', 'symbol' => null, 'stream' => 'business', 'stage' => 'subscribe']],
        ];
        foreach ($actions as [$phase, $transition]) {
            $checkpoint = $store->saveTransition($checkpoint, $phase, $transition);
            self::assertSame($phase, $checkpoint->phase);
            self::assertSame($transition, $checkpoint->pendingTransition);
        }

        $streaming = $store->saveTransition($checkpoint, 'streaming', null);

        self::assertSame('streaming', $streaming->phase);
        self::assertNull($streaming->pendingTransition);
        self::assertSame([], $streaming->remainingSymbols);
        self::assertSame([], $streaming->remainingBoundaries);
    }

    public function testReconnectCannotConnectWithoutItsExactWriteAheadBudgetAndReseed(): void
    {
        $directory = $this->datasetDirectory('reconnect-without-budget');
        $store = new OkxPaperLiveCheckpointStore($directory);
        $fresh = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $path = $this->checkpointPath($directory);
        $before = file_get_contents($path);

        try {
            $store->saveTransition($fresh, 'reconnecting', [
                'kind' => 'transport_connect',
                'symbol' => null,
                'stream' => 'public',
                'stage' => 'connect',
            ]);
            self::fail('RECONNECT_WITHOUT_BUDGET: connect must require attempt/deadline/reseed/epoch write-ahead.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }

        self::assertSame($before, file_get_contents($path));
    }

    public function testReconnectCannotExitToStreamingWithoutBudgetEpochAndRecoveryReseed(): void
    {
        $directory = $this->datasetDirectory('reconnect-exit-without-budget');
        $store = new OkxPaperLiveCheckpointStore($directory);
        $checkpoint = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $checkpoint = $this->completeInitialWarmupAndEnterStreaming($store, $checkpoint);
        $checkpoint = $store->saveTransition($checkpoint, 'reconnecting', [
            'kind' => 'transport_close',
            'symbol' => null,
            'stream' => 'public',
            'stage' => 'close',
        ]);
        $path = $this->checkpointPath($directory);
        $before = file_get_contents($path);

        try {
            $store->saveTransition($checkpoint, 'streaming', null);
            self::fail(
                'RECONNECT_WITHOUT_BUDGET_CAN_EXIT_TO_STREAMING: reconnect must reserve budget, epoch, and recovery work.',
            );
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }

        self::assertSame($before, file_get_contents($path));
    }

    public function testInitialBoundaryCannotBeAcknowledgedBeforeAllExactWarmRestWork(): void
    {
        $directory = $this->datasetDirectory('early-boundary');
        $store = new OkxPaperLiveCheckpointStore($directory);
        $fresh = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $boundaryTransition = [
            'kind' => 'emit_boundary',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/control/snapshot_boundary',
            'stage' => 'initial',
        ];
        $path = $this->checkpointPath($directory);
        $before = file_get_contents($path);

        try {
            $writeAhead = $store->saveTransition($fresh, 'warming', $boundaryTransition);
            $boundary = $this->initialBoundaryEvent();
            $pending = $store->savePending(
                $writeAhead,
                $boundary,
                $this->advanceOrdinal(
                    $writeAhead->ordinalState,
                    $boundary,
                    'boundary|1|9001|initial',
                ),
                null,
            );
            $store->acknowledge($pending, $boundary->eventId);
            self::fail('EARLY_BOUNDARY: all four candles, trades, and book must be acknowledged first.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }

        self::assertSame($before, file_get_contents($path));
    }

    public function testReconnectBoundaryCannotReuseARestBookFrontierFromAnEarlierSourceEpoch(): void
    {
        $directory = $this->datasetDirectory('inherited-reconnect-boundary');
        $store = new OkxPaperLiveCheckpointStore($directory);
        $checkpoint = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $checkpoint = $this->completeInitialWarmupAndEnterStreaming($store, $checkpoint);
        $wsBook = $this->bookEvent('ws_books', '9001', sourceEpoch: 1, sequence: '2');
        $checkpoint = $this->acknowledgeEventForTest(
            $store,
            $checkpoint,
            $wsBook,
            'book|9001',
            'BTCUSDT/ws/top_of_book',
        );
        $checkpoint = $this->startReconnectAttempt($store, $checkpoint);
        $checkpoint = $this->advanceReconnectToBusinessSubscription($store, $checkpoint);
        $orderBook = [
            'kind' => 'rest_fetch',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/rest/top_of_book',
            'stage' => 'order_book',
        ];
        $recoveryState = $checkpoint->toArray();
        $recoveryState['pending_transition'] = $orderBook;
        $recoveryState['source_epochs']['BTCUSDT'] = 2;
        $recoveryState['resync_by_symbol']['BTCUSDT'] = [
            'attempt' => 1,
            'frontier' => OkxPaperStreamFrontier::fromEvent($wsBook)->toArray(),
            'source_sequence' => '9001',
            'deadline_at' => '2026-07-22T10:00:10.000000Z',
            'policy' => 'book_seq_overlap_v1',
        ];
        $checkpoint = $store->saveTransition(
            OkxPaperLiveCheckpoint::fromArray($recoveryState),
            'reconnecting',
            $orderBook,
        );
        $boundary = [
            'kind' => 'emit_boundary',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/control/snapshot_boundary',
            'stage' => 'reconnect',
        ];
        $path = $this->checkpointPath($directory);
        $before = file_get_contents($path);

        try {
            $store->saveTransition($checkpoint, 'reconnecting', $boundary);
            self::fail('EARLY_BOUNDARY: an inherited REST book frontier must not prove a new source epoch.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }

        self::assertSame($before, file_get_contents($path));

        $snapshot = $this->bookEvent(
            'rest_resync_snapshot',
            '9002',
            sourceEpoch: 2,
            sequence: '3',
        );
        $pendingSnapshot = $store->savePending(
            $checkpoint,
            $snapshot,
            $this->advanceOrdinal($checkpoint->ordinalState, $snapshot, 'book|9002'),
            [
                'stream' => 'BTCUSDT/rest/top_of_book',
                'frontier' => OkxPaperStreamFrontier::fromEvent($snapshot)->toArray(),
            ],
        );
        $checkpoint = $store->acknowledge($pendingSnapshot, $snapshot->eventId);
        $checkpoint = $store->saveTransition($checkpoint, 'reconnecting', $boundary);
        $boundaryEvent = PaperMarketEvent::create(
            venue: PaperMarketDataVenue::OKX,
            symbol: 'BTCUSDT',
            channel: PaperMarketDataChannel::SNAPSHOT_BOUNDARY,
            exchangeTimestamp: new \DateTimeImmutable('2026-07-22T10:00:10.000000Z'),
            receivedTimestamp: new \DateTimeImmutable('2026-07-22T10:00:10.000000Z'),
            sequence: '2',
            payload: [
                'native_symbol' => 'BTC-USDT-SWAP',
                'reason' => 'reconnect',
                'source_epoch' => 2,
                'source_seq_id' => '9002',
            ],
        );
        $pendingBoundary = $store->savePending(
            $checkpoint,
            $boundaryEvent,
            $this->advanceOrdinal(
                $checkpoint->ordinalState,
                $boundaryEvent,
                'boundary|2|9002|reconnect',
            ),
            null,
        );
        $acknowledged = $store->acknowledge($pendingBoundary, $boundaryEvent->eventId);

        self::assertNull($acknowledged->resyncBySymbol['BTCUSDT']);
        self::assertSame(['ETHUSDT'], $acknowledged->remainingSymbols);
        self::assertSame(
            [['symbol' => 'ETHUSDT', 'reason' => 'reconnect']],
            $acknowledged->remainingBoundaries,
        );
    }

    public function testSequenceGapBoundaryRejectsARestBookFrontierInheritedFromThePreviousEpoch(): void
    {
        $directory = $this->datasetDirectory('inherited-sequence-gap-boundary');
        $store = new OkxPaperLiveCheckpointStore($directory);
        $checkpoint = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $checkpoint = $this->completeInitialWarmupAndEnterStreaming($store, $checkpoint);
        $wsBook = $this->bookEvent('ws_books', '9001', sourceEpoch: 1, sequence: '2');
        $checkpoint = $this->acknowledgeEventForTest(
            $store,
            $checkpoint,
            $wsBook,
            'book|9001',
            'BTCUSDT/ws/top_of_book',
        );
        $ordinals = OkxPaperSourceOrdinal::restore($checkpoint->ordinalState);
        $ordinals->reserveGap('okx/BTCUSDT/top_of_book');
        $orderBook = [
            'kind' => 'rest_fetch',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/rest/top_of_book',
            'stage' => 'order_book',
        ];
        $recoveryState = $checkpoint->toArray();
        $recoveryState['phase'] = 'resyncing';
        $recoveryState['pending_transition'] = $orderBook;
        $recoveryState['ordinal_state'] = $ordinals->snapshot();
        $recoveryState['remaining_symbols'] = ['BTCUSDT'];
        $recoveryState['remaining_boundaries'] = [
            ['symbol' => 'BTCUSDT', 'reason' => 'sequence_gap'],
        ];
        $recoveryState['source_epochs']['BTCUSDT'] = 2;
        $recoveryState['resync_by_symbol']['BTCUSDT'] = [
            'attempt' => 1,
            'frontier' => OkxPaperStreamFrontier::fromEvent($wsBook)->toArray(),
            'source_sequence' => '9001',
            'deadline_at' => '2026-07-22T10:00:10.000000Z',
            'policy' => 'book_seq_overlap_v1',
        ];
        $checkpoint = $store->saveTransition(
            OkxPaperLiveCheckpoint::fromArray($recoveryState),
            'resyncing',
            $orderBook,
        );
        $boundary = [
            'kind' => 'emit_boundary',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/control/snapshot_boundary',
            'stage' => 'sequence_gap',
        ];
        $path = $this->checkpointPath($directory);
        $before = file_get_contents($path);

        try {
            $store->saveTransition($checkpoint, 'resyncing', $boundary);
            self::fail(
                'STALE_SEQUENCE_GAP_BOUNDARY_ACCEPTED: epoch 1 REST seqId 8001 must not prove epoch 2 recovery target 9001.',
            );
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }

        self::assertSame($before, file_get_contents($path));
    }

    public function testWarmingRestartCanPersistBoundedHistoricalTradeRecovery(): void
    {
        $directory = $this->datasetDirectory('warming-history');
        $store = new OkxPaperLiveCheckpointStore($directory);
        $checkpoint = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $checkpoint = $this->acknowledgeInitialWarmupRestPrefix($store, $checkpoint, 'BTCUSDT', 5);
        $target = $checkpoint->streamFrontiers['BTCUSDT/rest/public_trade'];
        self::assertInstanceOf(OkxPaperStreamFrontier::class, $target);
        $transition = [
            'kind' => 'rest_fetch',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/rest/public_trade',
            'stage' => 'history_trades',
        ];
        $deadline = '2026-07-22T10:00:10.000000Z';
        $state = $checkpoint->toArray();
        $state['pending_transition'] = $transition;
        $state['resync_by_symbol']['BTCUSDT'] = [
            'attempt' => 1,
            'frontier' => $target->toArray(),
            'source_sequence' => null,
            'deadline_at' => $deadline,
            'policy' => 'frontier_overlap_v1',
        ];
        $state['overlap_pagination_by_stream']['BTCUSDT/rest/public_trade'] = [
            'endpoint' => 'history_trades',
            'pagination_type' => 2,
            'next_cursor' => '1784714400000',
            'pages_consumed' => 0,
            'pages_remaining' => 10,
            'target_frontier' => $target->toArray(),
            'deadline_at' => $deadline,
        ];

        try {
            $saved = $store->saveTransition(
                OkxPaperLiveCheckpoint::fromArray($state),
                'warming',
                $transition,
            );
        } catch (OkxPaperLiveIntegrityException|\InvalidArgumentException $exception) {
            self::fail('WARMING_HISTORY: bounded restart history must be representable: ' . $exception->getMessage());
        }

        self::assertSame('warming', $saved->phase);
        self::assertSame($transition, $saved->pendingTransition);
        self::assertSame(10, $saved->overlapPaginationByStream[
            'BTCUSDT/rest/public_trade'
        ]['pages_remaining'] ?? null);
    }

    public function testWarmingHistoryPendingActionCannotBeReplacedByRecentTrades(): void
    {
        $directory = $this->datasetDirectory('warming-history-pending-action-replacement');
        $store = new OkxPaperLiveCheckpointStore($directory);
        $checkpoint = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $checkpoint = $this->acknowledgeInitialWarmupRestPrefix($store, $checkpoint, 'BTCUSDT', 5);
        $target = $checkpoint->streamFrontiers['BTCUSDT/rest/public_trade'];
        self::assertInstanceOf(OkxPaperStreamFrontier::class, $target);
        $history = [
            'kind' => 'rest_fetch',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/rest/public_trade',
            'stage' => 'history_trades',
        ];
        $deadline = '2026-07-22T10:00:10.000000Z';
        $state = $checkpoint->toArray();
        $state['pending_transition'] = $history;
        $state['resync_by_symbol']['BTCUSDT'] = [
            'attempt' => 1,
            'frontier' => $target->toArray(),
            'source_sequence' => null,
            'deadline_at' => $deadline,
            'policy' => 'frontier_overlap_v1',
        ];
        $state['overlap_pagination_by_stream']['BTCUSDT/rest/public_trade'] = [
            'endpoint' => 'history_trades',
            'pagination_type' => 2,
            'next_cursor' => '1784714400000',
            'pages_consumed' => 0,
            'pages_remaining' => 10,
            'target_frontier' => $target->toArray(),
            'deadline_at' => $deadline,
        ];
        $active = $store->saveTransition(
            OkxPaperLiveCheckpoint::fromArray($state),
            'warming',
            $history,
        );
        $pagination = $active->overlapPaginationByStream['BTCUSDT/rest/public_trade'];
        self::assertIsArray($pagination);
        $path = $this->checkpointPath($directory);
        $before = file_get_contents($path);

        try {
            $store->saveTransition($active, 'warming', [
                'kind' => 'rest_fetch',
                'symbol' => 'BTCUSDT',
                'stream' => 'BTCUSDT/rest/public_trade',
                'stage' => 'recent_trades',
            ]);
            self::fail(
                'WARMING_HISTORY_PENDING_ACTION_REPLACED_ACCEPTED: historical pagination must remain authoritative.',
            );
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }

        self::assertSame($before, file_get_contents($path));
        self::assertSame('1784714400000', $pagination['next_cursor']);
        self::assertSame(0, $pagination['pages_consumed']);
        self::assertSame(10, $pagination['pages_remaining']);
    }

    public function testWarmingExactOverlapWithoutLaterRowClosesOnlyIntoTheExactNextWorkUnit(): void
    {
        $directory = $this->datasetDirectory('warming-overlap-without-later-row');
        $store = new OkxPaperLiveCheckpointStore($directory);
        $checkpoint = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $checkpoint = $this->acknowledgeInitialWarmupRestPrefix($store, $checkpoint, 'BTCUSDT', 5);
        $target = $checkpoint->streamFrontiers['BTCUSDT/rest/public_trade'];
        self::assertInstanceOf(OkxPaperStreamFrontier::class, $target);
        $currentTransition = [
            'kind' => 'rest_fetch',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/rest/public_trade',
            'stage' => 'recent_trades',
        ];
        $activeState = $checkpoint->toArray();
        $activeState['pending_transition'] = $currentTransition;
        $activeState['resync_by_symbol']['BTCUSDT'] = [
            'attempt' => 1,
            'frontier' => $target->toArray(),
            'source_sequence' => null,
            'deadline_at' => '2026-07-22T10:00:10.000000Z',
            'policy' => 'frontier_overlap_v1',
        ];
        $active = $store->saveTransition(
            OkxPaperLiveCheckpoint::fromArray($activeState),
            'warming',
            $currentTransition,
        );

        $wrongNext = [
            'kind' => 'rest_fetch',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/rest/candle_1m',
            'stage' => 'current_candles',
        ];
        $wrongState = $active->toArray();
        $wrongState['pending_transition'] = $wrongNext;
        $wrongState['resync_by_symbol']['BTCUSDT'] = null;
        $path = $this->checkpointPath($directory);
        $beforeWrongNext = file_get_contents($path);
        try {
            $store->saveTransition(
                OkxPaperLiveCheckpoint::fromArray($wrongState),
                'warming',
                $wrongNext,
            );
            self::fail('WARMING_REST_OVERLAP_NO_LATER: closure must not skip back to another work unit.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }
        self::assertSame($beforeWrongNext, file_get_contents($path));

        $exactNext = [
            'kind' => 'rest_fetch',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/rest/top_of_book',
            'stage' => 'order_book',
        ];
        $closedState = $active->toArray();
        $closedState['pending_transition'] = $exactNext;
        $closedState['resync_by_symbol']['BTCUSDT'] = null;
        try {
            $closed = $store->saveTransition(
                OkxPaperLiveCheckpoint::fromArray($closedState),
                'warming',
                $exactNext,
            );
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::fail('WARMING_REST_OVERLAP_NO_LATER: exact next work must close recovery: ' . $exception->getMessage());
        }

        self::assertNull($closed->resyncBySymbol['BTCUSDT']);
        self::assertSame($exactNext, $closed->pendingTransition);
    }

    public function testPendingEventResumesExactlyAndAcknowledgementAdvancesOneFrontierAtomically(): void
    {
        $directory = $this->datasetDirectory('pending');
        $store = new OkxPaperLiveCheckpointStore($directory);
        $checkpoint = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $event = $this->tradeEvent(
            'ws_aggregated',
            true,
            receivedTimestamp: '2026-07-22T10:00:09.876543Z',
        );
        $ordinalState = $this->ordinalStateFor($event, 'trade|242720721');
        $frontier = OkxPaperStreamFrontier::fromEvent($event);

        $pending = $store->savePending($checkpoint, $event, $ordinalState, [
            'stream' => 'BTCUSDT/ws/public_trade',
            'frontier' => $frontier->toArray(),
        ]);
        self::assertSame('2026-07-22T10:00:09.876543Z', $pending->pendingEvent?->toArray()['received_timestamp']);
        unset($store);

        $resumedStore = new OkxPaperLiveCheckpointStore($directory);
        $resumed = $resumedStore->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        self::assertSame(
            CanonicalJson::encode($event->toArray()),
            CanonicalJson::encode($resumed->pendingEvent?->toArray()),
        );
        self::assertSame(
            CanonicalJson::encode($ordinalState),
            CanonicalJson::encode($resumed->ordinalState),
        );

        $acknowledged = $resumedStore->acknowledge($resumed, $event->eventId);
        self::assertNull($acknowledged->pendingEvent);
        self::assertNull($acknowledged->pendingFrontier);
        self::assertSame($event->eventId, $acknowledged->lastAcknowledgedEventId);
        self::assertSame(
            $frontier->toArray(),
            $acknowledged->streamFrontiers['BTCUSDT/ws/public_trade']?->toArray(),
        );
        self::assertSame(
            CanonicalJson::encode($ordinalState),
            CanonicalJson::encode($acknowledged->ordinalState),
        );
    }

    public function testAcknowledgementHasExactlyTheCanonicalTwoArgumentContract(): void
    {
        $method = new \ReflectionMethod(OkxPaperLiveCheckpointStore::class, 'acknowledge');

        self::assertCount(2, $method->getParameters());
        self::assertSame(2, $method->getNumberOfRequiredParameters());
    }

    public function testTwoArgumentBoundaryAcknowledgementConsumesOnlyTheExactWorkHeads(): void
    {
        $directory = $this->datasetDirectory('canonical-boundary-acknowledgement');
        $store = new OkxPaperLiveCheckpointStore($directory);
        $checkpoint = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $checkpoint = $this->acknowledgeInitialWarmupRestPrefix($store, $checkpoint, 'BTCUSDT', 6);
        $btcTransition = [
            'kind' => 'emit_boundary',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/control/snapshot_boundary',
            'stage' => 'initial',
        ];
        $writeAhead = $store->saveTransition($checkpoint, 'warming', $btcTransition);
        $btc = $this->initialBoundaryEvent();
        $pending = $store->savePending(
            $writeAhead,
            $btc,
            $this->advanceOrdinal($writeAhead->ordinalState, $btc, 'boundary|1|0|initial'),
            null,
        );

        $acknowledged = $store->acknowledge($pending, $btc->eventId);

        self::assertSame(['ETHUSDT'], $acknowledged->remainingSymbols);
        self::assertSame([
            ['symbol' => 'ETHUSDT', 'reason' => 'initial'],
        ], $acknowledged->remainingBoundaries);
        unset($store);

        $resumedStore = new OkxPaperLiveCheckpointStore($directory);
        $resumed = $resumedStore->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        self::assertSame(
            CanonicalJson::encode($acknowledged->toArray()),
            CanonicalJson::encode($resumed->toArray()),
        );
        $resumed = $this->acknowledgeInitialWarmupRestPrefix(
            $resumedStore,
            $resumed,
            'ETHUSDT',
            6,
        );
        $ethTransition = [
            'kind' => 'emit_boundary',
            'symbol' => 'ETHUSDT',
            'stream' => 'ETHUSDT/control/snapshot_boundary',
            'stage' => 'initial',
        ];
        self::assertSame(
            $ethTransition,
            $resumedStore->saveTransition($resumed, 'warming', $ethTransition)->pendingTransition,
        );
    }

    public function testStoppedAcknowledgementsConsumeHeadsButRemainStoppingUntilCleanup(): void
    {
        $directory = $this->datasetDirectory('canonical-stopped-acknowledgements');
        $initialStore = new OkxPaperLiveCheckpointStore($directory);
        $fresh = $initialStore->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        unset($initialStore);
        $state = $fresh->toArray();
        $state['phase'] = 'stopping';
        $state['remaining_boundaries'] = [];
        $state['healthy_stop'] = [
            'requested' => true,
            'remaining_symbols' => ['BTCUSDT', 'ETHUSDT'],
        ];
        $this->replaceCheckpointState($directory, $state);

        $store = new OkxPaperLiveCheckpointStore($directory);
        $checkpoint = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        foreach (['BTCUSDT', 'ETHUSDT'] as $symbol) {
            $transition = [
                'kind' => 'healthy_stop',
                'symbol' => $symbol,
                'stream' => $symbol . '/control/connection_state',
                'stage' => 'emit_stopped',
            ];
            $writeAhead = $store->saveTransition($checkpoint, 'stopping', $transition);
            $event = $this->stoppedConnectionEvent($symbol);
            $pending = $store->savePending(
                $writeAhead,
                $event,
                $this->advanceOrdinal(
                    $writeAhead->ordinalState,
                    $event,
                    'connection|2|stopped',
                ),
                null,
            );
            $checkpoint = $store->acknowledge($pending, $event->eventId);
            self::assertSame('stopping', $checkpoint->phase);
        }

        self::assertSame([], $checkpoint->remainingSymbols);
        self::assertSame([], $checkpoint->healthyStop['remaining_symbols']);
    }

    public function testOnlyBoundaryAndHealthyStopHeadsAreActionable(): void
    {
        $boundaryDirectory = $this->datasetDirectory('non-head-boundary');
        $boundaryStore = new OkxPaperLiveCheckpointStore($boundaryDirectory);
        $fresh = $boundaryStore->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $path = $this->checkpointPath($boundaryDirectory);
        $before = file_get_contents($path);
        try {
            $boundaryStore->saveTransition($fresh, 'warming', [
                'kind' => 'emit_boundary',
                'symbol' => 'ETHUSDT',
                'stream' => 'ETHUSDT/control/snapshot_boundary',
                'stage' => 'initial',
            ]);
            self::fail('A non-head boundary must not be actionable.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }
        self::assertSame($before, file_get_contents($path));
        unset($boundaryStore);

        $stopDirectory = $this->datasetDirectory('non-head-stopped');
        $initialStore = new OkxPaperLiveCheckpointStore($stopDirectory);
        $fresh = $initialStore->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        unset($initialStore);
        $state = $fresh->toArray();
        $state['phase'] = 'stopping';
        $state['remaining_boundaries'] = [];
        $state['healthy_stop'] = [
            'requested' => true,
            'remaining_symbols' => ['BTCUSDT', 'ETHUSDT'],
        ];
        $this->replaceCheckpointState($stopDirectory, $state);
        $stopStore = new OkxPaperLiveCheckpointStore($stopDirectory);
        $stopping = $stopStore->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $path = $this->checkpointPath($stopDirectory);
        $before = file_get_contents($path);
        try {
            $stopStore->saveTransition($stopping, 'stopping', [
                'kind' => 'healthy_stop',
                'symbol' => 'ETHUSDT',
                'stream' => 'ETHUSDT/control/connection_state',
                'stage' => 'emit_stopped',
            ]);
            self::fail('A non-head healthy-stop symbol must not be actionable.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }
        self::assertSame($before, file_get_contents($path));
    }

    public function testOnlyTheRemainingSymbolHeadCanStartSymbolicWork(): void
    {
        $directory = $this->datasetDirectory('non-head-symbol-work');
        $store = new OkxPaperLiveCheckpointStore($directory);
        $fresh = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $path = $this->checkpointPath($directory);
        $before = file_get_contents($path);

        try {
            $store->saveTransition($fresh, 'warming', [
                'kind' => 'rest_fetch',
                'symbol' => 'ETHUSDT',
                'stream' => 'ETHUSDT/rest/public_trade',
                'stage' => 'recent_trades',
            ]);
            self::fail('A non-head symbol must not start REST work.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }
        self::assertSame($before, file_get_contents($path));
    }

    public function testSaveTransitionReseedsOnlyTheExactReconnectWorkAndBudget(): void
    {
        $directory = $this->datasetDirectory('reconnect-reseed');
        $initialStore = new OkxPaperLiveCheckpointStore($directory);
        $fresh = $initialStore->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        unset($initialStore);
        $streamingState = $fresh->toArray();
        $streamingState['phase'] = 'streaming';
        $streamingState['remaining_symbols'] = [];
        $streamingState['remaining_boundaries'] = [];
        $this->replaceCheckpointState($directory, $streamingState);

        $store = new OkxPaperLiveCheckpointStore($directory);
        $streaming = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);

        try {
            $saved = $this->startReconnectAttempt(
                $store,
                $streaming,
                '2026-07-22T10:00:01.000000Z',
            );
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::fail('The exact reconnect reseed must be write-ahead persistable: ' . $exception->getMessage());
        }

        self::assertSame(['BTCUSDT', 'ETHUSDT'], $saved->remainingSymbols);
        self::assertSame([
            ['symbol' => 'BTCUSDT', 'reason' => 'reconnect'],
            ['symbol' => 'ETHUSDT', 'reason' => 'reconnect'],
        ], $saved->remainingBoundaries);
        self::assertSame(2, $saved->connectionEpoch);
        self::assertSame([
            'attempt' => 1,
            'deadline_at' => '2026-07-22T10:00:01.000000Z',
            'stable_since' => null,
            'accepted_events' => 0,
        ], $saved->reconnect);
    }

    public function testSaveTransitionPersistsOneSequenceGapReservationAndItsExactRecoveryWork(): void
    {
        $directory = $this->datasetDirectory('sequence-gap-reservation');
        $store = new OkxPaperLiveCheckpointStore($directory);
        $fresh = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $book = $this->bookEvent();
        $acknowledged = $this->acknowledgeEventForTest(
            $store,
            $fresh,
            $book,
            'book|9001',
            'BTCUSDT/ws/top_of_book',
        );
        unset($store);
        $streamingState = $acknowledged->toArray();
        $streamingState['phase'] = 'streaming';
        $streamingState['remaining_symbols'] = [];
        $streamingState['remaining_boundaries'] = [];
        $this->replaceCheckpointState($directory, $streamingState);

        $store = new OkxPaperLiveCheckpointStore($directory);
        $streaming = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $ordinals = OkxPaperSourceOrdinal::restore($streaming->ordinalState);
        $ordinals->reserveGap('okx/BTCUSDT/top_of_book');
        $transition = [
            'kind' => 'rest_fetch',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/rest/top_of_book',
            'stage' => 'order_book',
        ];
        $candidateState = $streaming->toArray();
        $candidateState['phase'] = 'resyncing';
        $candidateState['pending_transition'] = $transition;
        $candidateState['ordinal_state'] = $ordinals->snapshot();
        $candidateState['remaining_symbols'] = ['BTCUSDT'];
        $candidateState['remaining_boundaries'] = [
            ['symbol' => 'BTCUSDT', 'reason' => 'sequence_gap'],
        ];
        $candidateState['source_epochs']['BTCUSDT'] = 2;
        $candidateState['resync_by_symbol']['BTCUSDT'] = [
            'attempt' => 1,
            'frontier' => OkxPaperStreamFrontier::fromEvent($book)->toArray(),
            'source_sequence' => '9001',
            'deadline_at' => '2026-07-22T10:00:10.000000Z',
            'policy' => 'book_seq_overlap_v1',
        ];
        $unrelatedRecovery = $candidateState;
        $unrelatedRecovery['resync_by_symbol']['ETHUSDT'] = [
            'attempt' => 1,
            'frontier' => OkxPaperStreamFrontier::fromEvent($this->ethBookEvent())->toArray(),
            'source_sequence' => '9001',
            'deadline_at' => '2026-07-22T10:00:10.000000Z',
            'policy' => 'book_seq_overlap_v1',
        ];
        $path = $this->checkpointPath($directory);
        $beforeUnrelatedRecovery = file_get_contents($path);
        try {
            $store->saveTransition(
                OkxPaperLiveCheckpoint::fromArray($unrelatedRecovery),
                'resyncing',
                $transition,
            );
            self::fail('A sequence-gap transition must not mutate the other symbol recovery.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }
        self::assertSame($beforeUnrelatedRecovery, file_get_contents($path));
        $candidate = OkxPaperLiveCheckpoint::fromArray($candidateState);

        try {
            $saved = $store->saveTransition($candidate, 'resyncing', $transition);
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::fail('The exact sequence-gap reservation must be write-ahead persistable: ' . $exception->getMessage());
        }
        self::assertTrue($saved->ordinalState['scopes']['okx/BTCUSDT/top_of_book']['gap_pending']);
        self::assertSame(2, $saved->sourceEpochs['BTCUSDT']);
        self::assertSame($candidateState['resync_by_symbol'], $saved->toArray()['resync_by_symbol']);

        $retried = $store->saveTransition($saved, 'resyncing', $transition);
        self::assertSame($saved->ordinalState, $retried->ordinalState);
        self::assertSame(2, $retried->sourceEpochs['BTCUSDT']);
    }

    public function testReconnectBookRecoveryIncrementsOnlyItsEpochWithoutReservingAGap(): void
    {
        $directory = $this->datasetDirectory('reconnect-book-recovery');
        $store = new OkxPaperLiveCheckpointStore($directory);
        $fresh = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $book = $this->bookEvent();
        $acknowledged = $this->acknowledgeEventForTest(
            $store,
            $fresh,
            $book,
            'book|9001',
            'BTCUSDT/ws/top_of_book',
        );
        unset($store);
        $streamingState = $acknowledged->toArray();
        $streamingState['phase'] = 'streaming';
        $streamingState['remaining_symbols'] = [];
        $streamingState['remaining_boundaries'] = [];
        $this->replaceCheckpointState($directory, $streamingState);

        $store = new OkxPaperLiveCheckpointStore($directory);
        $streaming = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $reconnecting = $this->startReconnectAttempt(
            $store,
            $streaming,
            '2026-07-22T10:00:01.000000Z',
        );
        $reconnecting = $this->advanceReconnectToBusinessSubscription($store, $reconnecting);

        $orderBook = [
            'kind' => 'rest_fetch',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/rest/top_of_book',
            'stage' => 'order_book',
        ];
        $bookRecoveryState = $reconnecting->toArray();
        $bookRecoveryState['pending_transition'] = $orderBook;
        $bookRecoveryState['source_epochs']['BTCUSDT'] = 2;
        $bookRecoveryState['resync_by_symbol']['BTCUSDT'] = [
            'attempt' => 1,
            'frontier' => OkxPaperStreamFrontier::fromEvent($book)->toArray(),
            'source_sequence' => '9001',
            'deadline_at' => '2026-07-22T10:00:10.000000Z',
            'policy' => 'book_seq_overlap_v1',
        ];
        try {
            $bookRecovery = $store->saveTransition(
                OkxPaperLiveCheckpoint::fromArray($bookRecoveryState),
                'reconnecting',
                $orderBook,
            );
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::fail('Reconnect book recovery must persist its exact epoch and state: ' . $exception->getMessage());
        }

        self::assertSame(2, $bookRecovery->sourceEpochs['BTCUSDT']);
        self::assertSame(1, $bookRecovery->sourceEpochs['ETHUSDT']);
        self::assertFalse(
            $bookRecovery->ordinalState['scopes']['okx/BTCUSDT/top_of_book']['gap_pending'],
        );
        self::assertSame(
            'book_seq_overlap_v1',
            $bookRecovery->resyncBySymbol['BTCUSDT']['policy'] ?? null,
        );
    }

    public function testSaveTransitionReseedsTheExactOrderedHealthyStopWork(): void
    {
        $directory = $this->datasetDirectory('healthy-stop-reseed');
        $initialStore = new OkxPaperLiveCheckpointStore($directory);
        $fresh = $initialStore->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        unset($initialStore);
        $streamingState = $fresh->toArray();
        $streamingState['phase'] = 'streaming';
        $streamingState['remaining_symbols'] = [];
        $streamingState['remaining_boundaries'] = [];
        $this->replaceCheckpointState($directory, $streamingState);

        $store = new OkxPaperLiveCheckpointStore($directory);
        $streaming = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $transition = [
            'kind' => 'healthy_stop',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/control/connection_state',
            'stage' => 'emit_stopped',
        ];
        $candidateState = $streaming->toArray();
        $candidateState['phase'] = 'stopping';
        $candidateState['pending_transition'] = $transition;
        $candidateState['remaining_symbols'] = ['BTCUSDT', 'ETHUSDT'];
        $candidateState['healthy_stop'] = [
            'requested' => true,
            'remaining_symbols' => ['BTCUSDT', 'ETHUSDT'],
        ];

        try {
            $saved = $store->saveTransition(
                OkxPaperLiveCheckpoint::fromArray($candidateState),
                'stopping',
                $transition,
            );
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::fail('The exact healthy-stop reseed must be write-ahead persistable: ' . $exception->getMessage());
        }

        self::assertSame(['BTCUSDT', 'ETHUSDT'], $saved->remainingSymbols);
        self::assertSame($candidateState['healthy_stop'], $saved->healthyStop);
    }

    public function testSaveTransitionRejectsMutationsUnrelatedToItsKindAndStage(): void
    {
        $directory = $this->datasetDirectory('unrelated-transition-mutation');
        $store = new OkxPaperLiveCheckpointStore($directory);
        $fresh = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $transition = [
            'kind' => 'transport_connect',
            'symbol' => null,
            'stream' => 'public',
            'stage' => 'connect',
        ];
        $candidateState = $fresh->toArray();
        $candidateState['phase'] = 'connecting';
        $candidateState['pending_transition'] = $transition;
        $candidateState['source_epochs']['BTCUSDT'] = 2;
        $candidate = OkxPaperLiveCheckpoint::fromArray($candidateState);
        $path = $this->checkpointPath($directory);
        $before = file_get_contents($path);

        try {
            $store->saveTransition($candidate, 'connecting', $transition);
            self::fail('A transport connect must not mutate a source epoch.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }
        self::assertSame($before, file_get_contents($path));

        $reseedDirectory = $this->datasetDirectory('reconnect-reseed-without-epoch');
        $initialStore = new OkxPaperLiveCheckpointStore($reseedDirectory);
        $fresh = $initialStore->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        unset($initialStore);
        $streamingState = $fresh->toArray();
        $streamingState['phase'] = 'streaming';
        $streamingState['remaining_symbols'] = [];
        $streamingState['remaining_boundaries'] = [];
        $this->replaceCheckpointState($reseedDirectory, $streamingState);
        $reseedStore = new OkxPaperLiveCheckpointStore($reseedDirectory);
        $streaming = $reseedStore->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $reconnectTransition = [
            'kind' => 'timer_schedule',
            'symbol' => null,
            'stream' => null,
            'stage' => 'reconnect_delay',
        ];
        $budgetWithoutWork = $streaming->toArray();
        $budgetWithoutWork['phase'] = 'reconnecting';
        $budgetWithoutWork['pending_transition'] = $reconnectTransition;
        $budgetWithoutWork['reconnect'] = [
            'attempt' => 1,
            'deadline_at' => '2026-07-22T10:00:01.000000Z',
            'stable_since' => null,
            'accepted_events' => 0,
        ];
        $path = $this->checkpointPath($reseedDirectory);
        $before = file_get_contents($path);
        try {
            $reseedStore->saveTransition(
                OkxPaperLiveCheckpoint::fromArray($budgetWithoutWork),
                'reconnecting',
                $reconnectTransition,
            );
            self::fail('A reconnect attempt must atomically reseed its exact work lists.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }
        self::assertSame($before, file_get_contents($path));

        $invalidReseed = $streaming->toArray();
        $invalidReseed['phase'] = 'reconnecting';
        $invalidReseed['pending_transition'] = $reconnectTransition;
        $invalidReseed['remaining_symbols'] = ['BTCUSDT', 'ETHUSDT'];
        $invalidReseed['remaining_boundaries'] = [
            ['symbol' => 'BTCUSDT', 'reason' => 'reconnect'],
            ['symbol' => 'ETHUSDT', 'reason' => 'reconnect'],
        ];
        $invalidReseed['reconnect'] = [
            'attempt' => 1,
            'deadline_at' => '2026-07-22T10:00:01.000000Z',
            'stable_since' => null,
            'accepted_events' => 0,
        ];
        $before = file_get_contents($path);
        try {
            $reseedStore->saveTransition(
                OkxPaperLiveCheckpoint::fromArray($invalidReseed),
                'reconnecting',
                $reconnectTransition,
            );
            self::fail('Reconnect work must not be reseeded without its connection epoch.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }
        self::assertSame($before, file_get_contents($path));

        $recoveryDirectory = $this->datasetDirectory('unrelated-recovery-map-mutation');
        $recoveryStore = new OkxPaperLiveCheckpointStore($recoveryDirectory);
        $fresh = $recoveryStore->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $targetEvent = $this->tradeEvent('rest_recovery');
        $acknowledged = $this->acknowledgeEventForTest(
            $recoveryStore,
            $fresh,
            $targetEvent,
            'trade|242720721',
            'BTCUSDT/rest/public_trade',
        );
        $target = OkxPaperStreamFrontier::fromEvent($targetEvent)->toArray();
        $deadline = '2026-07-22T10:00:10.000000Z';
        $history = [
            'kind' => 'rest_fetch',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/rest/public_trade',
            'stage' => 'history_trades',
        ];
        $unrelatedRecovery = $acknowledged->toArray();
        $unrelatedRecovery['phase'] = 'reconnecting';
        $unrelatedRecovery['pending_transition'] = $history;
        $unrelatedRecovery['resync_by_symbol']['BTCUSDT'] = [
            'attempt' => 1,
            'frontier' => $target,
            'source_sequence' => null,
            'deadline_at' => $deadline,
            'policy' => 'frontier_overlap_v1',
        ];
        $unrelatedRecovery['resync_by_symbol']['ETHUSDT'] = [
            'attempt' => 1,
            'frontier' => OkxPaperStreamFrontier::fromEvent($this->tradeEvent(
                'rest_recovery',
                symbol: 'ETHUSDT',
            ))->toArray(),
            'source_sequence' => null,
            'deadline_at' => $deadline,
            'policy' => 'frontier_overlap_v1',
        ];
        $unrelatedRecovery['overlap_pagination_by_stream']['BTCUSDT/rest/public_trade'] = [
            'endpoint' => 'history_trades',
            'pagination_type' => 2,
            'next_cursor' => '1784714400000',
            'pages_consumed' => 0,
            'pages_remaining' => 10,
            'target_frontier' => $target,
            'deadline_at' => $deadline,
        ];
        $unrelatedRecovery['overlap_pagination_by_stream']['BTCUSDT/ws/public_trade'] =
            $unrelatedRecovery['overlap_pagination_by_stream']['BTCUSDT/rest/public_trade'];
        $path = $this->checkpointPath($recoveryDirectory);
        $before = file_get_contents($path);
        try {
            $recoveryStore->saveTransition(
                OkxPaperLiveCheckpoint::fromArray($unrelatedRecovery),
                'reconnecting',
                $history,
            );
            self::fail('One history transition must not mutate another recovery or pagination map entry.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }
        self::assertSame($before, file_get_contents($path));
    }

    public function testAcknowledgementDerivesOnlyTheExactPendingStoppedEffect(): void
    {
        $directory = $this->datasetDirectory('acknowledgement-effects');
        $initialStore = new OkxPaperLiveCheckpointStore($directory);
        $fresh = $initialStore->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        unset($initialStore);
        $activeState = $fresh->toArray();
        $activeState['phase'] = 'stopping';
        $activeState['healthy_stop'] = [
            'requested' => true,
            'remaining_symbols' => ['BTCUSDT'],
        ];
        $activeState['pending_transition'] = [
            'kind' => 'healthy_stop',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/control/connection_state',
            'stage' => 'emit_stopped',
        ];
        $this->replaceCheckpointState($directory, $activeState);
        $store = new OkxPaperLiveCheckpointStore($directory);
        $active = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $event = $this->stoppedConnectionEvent();
        $pending = $store->savePending(
            $active,
            $event,
            $this->ordinalStateFor($event, 'connection|2|stopped'),
            [
                'stream' => 'BTCUSDT/control/connection_state',
                'frontier' => OkxPaperStreamFrontier::fromEvent($event)->toArray(),
            ],
        );
        $acknowledged = $store->acknowledge($pending, $event->eventId);

        self::assertSame(['ETHUSDT'], $acknowledged->remainingSymbols);
        self::assertSame([], $acknowledged->healthyStop['remaining_symbols']);
        self::assertSame($fresh->remainingBoundaries, $acknowledged->remainingBoundaries);
    }

    public function testBoundaryAcknowledgementCannotRemoveASymbolWithoutItsExactRemainingBoundary(): void
    {
        $directory = $this->datasetDirectory('acknowledgement-boundary-unit');
        $initialStore = new OkxPaperLiveCheckpointStore($directory);
        $fresh = $initialStore->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        unset($initialStore);
        $state = $fresh->toArray();
        $state['remaining_boundaries'] = [['symbol' => 'ETHUSDT', 'reason' => 'initial']];
        $this->replaceCheckpointState($directory, $state);

        $store = new OkxPaperLiveCheckpointStore($directory);
        $current = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $event = $this->initialBoundaryEvent();
        $path = $this->checkpointPath($directory);
        $before = file_get_contents($path);

        try {
            $store->savePending(
                $current,
                $event,
                $this->advanceOrdinal($current->ordinalState, $event, 'boundary|1|0|initial'),
                null,
            );
            self::fail('A non-head boundary must not become pending without its write-ahead action.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }
        self::assertSame($before, file_get_contents($path));
    }

    public function testTwelfthStableAcceptedEventResetsReconnectBudgetInItsAcknowledgement(): void
    {
        $clock = new MockClock('2026-07-22T10:00:31.000000Z');
        $directory = $this->datasetDirectory('acknowledgement-stability-threshold');
        $initialStore = new OkxPaperLiveCheckpointStore($directory, clock: $clock);
        $fresh = $initialStore->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        unset($initialStore);
        $stableState = $fresh->toArray();
        $stableState['phase'] = 'streaming';
        $stableState['reconnect'] = [
            'attempt' => 1,
            'deadline_at' => null,
            'stable_since' => '2026-07-22T10:00:00.000000Z',
            'accepted_events' => 11,
        ];
        $this->replaceCheckpointState($directory, $stableState);
        $store = new OkxPaperLiveCheckpointStore($directory, clock: $clock);
        $stable = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $event = $this->tradeEvent(
            'ws_aggregated',
            true,
            receivedTimestamp: '2026-07-22T10:00:31.000000Z',
        );
        $pending = $store->savePending(
            $stable,
            $event,
            $this->ordinalStateFor($event, 'trade|242720721'),
            [
                'stream' => 'BTCUSDT/ws/public_trade',
                'frontier' => OkxPaperStreamFrontier::fromEvent($event)->toArray(),
            ],
        );
        $acknowledged = $store->acknowledge($pending, $event->eventId);

        self::assertSame([
            'attempt' => 0,
            'deadline_at' => null,
            'stable_since' => null,
            'accepted_events' => 0,
        ], $acknowledged->reconnect);
    }

    public function testFutureReceivedTimestampCannotResetStabilityBeforeTheInjectedClockThreshold(): void
    {
        $clock = new MockClock('2026-07-22T10:00:00.000000Z');
        $directory = $this->datasetDirectory('stability-after-future-ack');
        $initialStore = new OkxPaperLiveCheckpointStore($directory, clock: $clock);
        $fresh = $initialStore->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        unset($initialStore);
        $stableState = $fresh->toArray();
        $stableState['phase'] = 'streaming';
        $stableState['reconnect'] = [
            'attempt' => 1,
            'deadline_at' => null,
            'stable_since' => '2026-07-22T10:00:00.000000Z',
            'accepted_events' => 11,
        ];
        $this->replaceCheckpointState($directory, $stableState);

        $store = new OkxPaperLiveCheckpointStore($directory, clock: $clock);
        $stable = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $futureEvent = $this->tradeEvent(
            'ws_aggregated',
            true,
            receivedTimestamp: '2026-07-22T10:00:31.000000Z',
        );
        $pending = $store->savePending(
            $stable,
            $futureEvent,
            $this->ordinalStateFor($futureEvent, 'trade|242720721'),
            [
                'stream' => 'BTCUSDT/ws/public_trade',
                'frontier' => OkxPaperStreamFrontier::fromEvent($futureEvent)->toArray(),
            ],
        );

        $acknowledged = $store->acknowledge($pending, $futureEvent->eventId);

        self::assertSame('2026-07-22T10:00:31.000000Z', $acknowledged->lastAcknowledgedEventId === null
            ? null
            : $futureEvent->receivedTimestamp->format('Y-m-d\TH:i:s.u\Z'));
        self::assertSame([
            'attempt' => 1,
            'deadline_at' => null,
            'stable_since' => '2026-07-22T10:00:00.000000Z',
            'accepted_events' => 12,
        ], $acknowledged->reconnect, 'STABILITY_AFTER_FUTURE_ACK');

        $clock->sleep(30);
        $reset = $store->saveTransition($acknowledged, 'streaming', null);
        self::assertSame([
            'attempt' => 0,
            'deadline_at' => null,
            'stable_since' => null,
            'accepted_events' => 0,
        ], $reset->reconnect);
    }

    public function testReconnectStabilityStartsAtVerifiedStreamingAndDelayedTimerResetIsDurable(): void
    {
        $constructor = new \ReflectionMethod(OkxPaperLiveCheckpointStore::class, '__construct');
        self::assertContains(
            'clock',
            array_map(
                static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
                $constructor->getParameters(),
            ),
            'The store needs a deterministic clock to reject premature stability resets.',
        );
        $clock = new MockClock('2026-07-22T10:00:10.000000Z');
        $directory = $this->datasetDirectory('delayed-stability-reset');
        $initialStore = new OkxPaperLiveCheckpointStore($directory, clock: $clock);
        $fresh = $initialStore->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        unset($initialStore);
        $reconnectingState = $fresh->toArray();
        $reconnectingState['phase'] = 'reconnecting';
        $reconnectingState['remaining_symbols'] = [];
        $reconnectingState['remaining_boundaries'] = [];
        $reconnectingState['connection_epoch'] = 2;
        foreach (['BTCUSDT', 'ETHUSDT'] as $symbol) {
            $reconnectingState['source_epochs'][$symbol] = 2;
            $reconnectingState['stream_frontiers'][$symbol . '/control/snapshot_boundary'] =
                OkxPaperStreamFrontier::fromEvent($this->reconnectBoundaryEvent($symbol))->toArray();
        }
        $reconnectingState['reconnect'] = [
            'attempt' => 1,
            'deadline_at' => '2026-07-22T10:00:05.000000Z',
            'stable_since' => null,
            'accepted_events' => 0,
        ];
        $this->replaceCheckpointState($directory, $reconnectingState);

        $store = new OkxPaperLiveCheckpointStore($directory, clock: $clock);
        $reconnecting = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        try {
            $checkpoint = $store->saveTransition(
                $reconnecting,
                'streaming',
                null,
            );
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::fail('Verified streaming must durably start the stability window: ' . $exception->getMessage());
        }
        self::assertSame('2026-07-22T10:00:10.000000Z', $checkpoint->reconnect['stable_since']);

        for ($offset = 1; $offset <= 12; ++$offset) {
            $event = $this->tradeEvent(
                'ws_aggregated',
                true,
                (string) (242720720 + $offset),
                '65000.' . $offset,
                (string) $offset,
                sprintf('2026-07-22T10:00:%02d.000000Z', 10 + $offset),
            );
            $pending = $store->savePending(
                $checkpoint,
                $event,
                $this->advanceOrdinal(
                    $checkpoint->ordinalState,
                    $event,
                    'trade|' . (242720720 + $offset),
                ),
                [
                    'stream' => 'BTCUSDT/ws/public_trade',
                    'frontier' => OkxPaperStreamFrontier::fromEvent($event)->toArray(),
                ],
            );
            $checkpoint = $store->acknowledge($pending, $event->eventId);
            self::assertSame(1, $checkpoint->reconnect['attempt']);
            self::assertSame($offset, $checkpoint->reconnect['accepted_events']);
        }
        self::assertSame('2026-07-22T10:00:10.000000Z', $checkpoint->reconnect['stable_since']);

        $path = $this->checkpointPath($directory);
        $beforePrematureReset = file_get_contents($path);
        $notReset = $store->saveTransition($checkpoint, 'streaming', null);
        self::assertSame($checkpoint->reconnect, $notReset->reconnect);
        self::assertSame($beforePrematureReset, file_get_contents($path));

        $clock->sleep(30);
        try {
            $reset = $store->saveTransition(
                $notReset,
                'streaming',
                null,
            );
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::fail('The stability timer must durably reset a saturated budget: ' . $exception->getMessage());
        }

        self::assertSame([
            'attempt' => 0,
            'deadline_at' => null,
            'stable_since' => null,
            'accepted_events' => 0,
        ], $reset->reconnect);
    }

    public function testControlAcknowledgementDoesNotAdvanceReconnectStability(): void
    {
        $directory = $this->datasetDirectory('acknowledgement-control-stability');
        $initialStore = new OkxPaperLiveCheckpointStore($directory);
        $fresh = $initialStore->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        unset($initialStore);

        $stableState = $fresh->toArray();
        $stableState['phase'] = 'stopping';
        $stableState['healthy_stop'] = [
            'requested' => true,
            'remaining_symbols' => ['BTCUSDT', 'ETHUSDT'],
        ];
        $stableState['pending_transition'] = [
            'kind' => 'healthy_stop',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/control/connection_state',
            'stage' => 'emit_stopped',
        ];
        $stableState['reconnect'] = [
            'attempt' => 1,
            'deadline_at' => null,
            'stable_since' => '2026-07-22T10:00:00.000000Z',
            'accepted_events' => 11,
        ];
        $this->replaceCheckpointState($directory, $stableState);

        $store = new OkxPaperLiveCheckpointStore($directory);
        $stable = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $event = $this->stoppedConnectionEvent();
        $pending = $store->savePending(
            $stable,
            $event,
            $this->advanceOrdinal($stable->ordinalState, $event, 'connection|2|stopped'),
            null,
        );
        $acknowledged = $store->acknowledge($pending, $event->eventId);

        self::assertSame($stable->reconnect, $acknowledged->reconnect);
    }

    public function testAcknowledgementLeavesAnotherStreamsResyncAndPaginationUnchanged(): void
    {
        $directory = $this->datasetDirectory('acknowledgement-wrong-recovery');
        $initialStore = new OkxPaperLiveCheckpointStore($directory);
        $fresh = $initialStore->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        unset($initialStore);

        $target = OkxPaperStreamFrontier::fromEvent($this->tradeEvent('rest_recovery'))->toArray();
        $recoveryState = $fresh->toArray();
        $recoveryState['phase'] = 'streaming';
        $recoveryState['stream_frontiers']['BTCUSDT/rest/public_trade'] = $target;
        $recoveryState['resync_by_symbol']['BTCUSDT'] = [
            'attempt' => 1,
            'frontier' => $target,
            'source_sequence' => null,
            'deadline_at' => '2026-07-22T10:00:10.000000Z',
            'policy' => 'frontier_overlap_v1',
        ];
        $recoveryState['overlap_pagination_by_stream']['BTCUSDT/rest/public_trade'] = [
            'endpoint' => 'history_trades',
            'pagination_type' => 2,
            'next_cursor' => '1784714400000',
            'pages_consumed' => 0,
            'pages_remaining' => 10,
            'target_frontier' => $target,
            'deadline_at' => '2026-07-22T10:00:10.000000Z',
        ];
        $this->replaceCheckpointState($directory, $recoveryState);

        $store = new OkxPaperLiveCheckpointStore($directory);
        $recovery = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $event = $this->tradeEvent(
            'rest_recovery',
            tradeId: '342720721',
            symbol: 'ETHUSDT',
        );
        $pending = $store->savePending(
            $recovery,
            $event,
            $this->advanceOrdinal($recovery->ordinalState, $event, 'trade|342720721'),
            [
                'stream' => 'ETHUSDT/rest/public_trade',
                'frontier' => OkxPaperStreamFrontier::fromEvent($event)->toArray(),
            ],
        );
        $acknowledged = $store->acknowledge($pending, $event->eventId);

        self::assertSame(
            $recovery->toArray()['resync_by_symbol']['BTCUSDT'],
            $acknowledged->toArray()['resync_by_symbol']['BTCUSDT'],
        );
        self::assertSame(
            $recovery->toArray()['overlap_pagination_by_stream']['BTCUSDT/rest/public_trade'],
            $acknowledged->toArray()['overlap_pagination_by_stream']['BTCUSDT/rest/public_trade'],
        );
    }

    public function testSeveralTradesAdvanceExactlyOneRowPerAcknowledgement(): void
    {
        $directory = $this->datasetDirectory('multi-row');
        $store = new OkxPaperLiveCheckpointStore($directory);
        $checkpoint = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $first = $this->tradeEvent('ws_aggregated', true);
        $ordinal = $this->ordinalStateFor($first, 'trade|242720721');
        $firstPending = $store->savePending($checkpoint, $first, $ordinal, [
            'stream' => 'BTCUSDT/ws/public_trade',
            'frontier' => OkxPaperStreamFrontier::fromEvent($first)->toArray(),
        ]);
        $firstAcknowledged = $store->acknowledge($firstPending, $first->eventId);
        self::assertSame('242720721', $firstAcknowledged->streamFrontiers[
            'BTCUSDT/ws/public_trade'
        ]?->sourceIdentity);

        $second = $this->tradeEvent('ws_aggregated', true, '242720722', '65000.2', '2');
        $ordinal = $this->advanceOrdinal($ordinal, $second, 'trade|242720722');
        $secondPending = $store->savePending($firstAcknowledged, $second, $ordinal, [
            'stream' => 'BTCUSDT/ws/public_trade',
            'frontier' => OkxPaperStreamFrontier::fromEvent($second)->toArray(),
        ]);
        self::assertSame('242720721', $secondPending->streamFrontiers[
            'BTCUSDT/ws/public_trade'
        ]?->sourceIdentity);

        $secondAcknowledged = $store->acknowledge($secondPending, $second->eventId);
        self::assertSame('242720722', $secondAcknowledged->streamFrontiers[
            'BTCUSDT/ws/public_trade'
        ]?->sourceIdentity);
    }

    public function testWrongAcknowledgementAndNonMonotonicFrontierLeaveBytesUnchanged(): void
    {
        $directory = $this->datasetDirectory('ack-invalid');
        $store = new OkxPaperLiveCheckpointStore($directory);
        $checkpoint = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $event = $this->tradeEvent('rest_recovery');
        $ordinal = $this->ordinalStateFor($event, 'trade|242720721');
        $pending = $store->savePending($checkpoint, $event, $ordinal, [
            'stream' => 'BTCUSDT/rest/public_trade',
            'frontier' => OkxPaperStreamFrontier::fromEvent($event)->toArray(),
        ]);
        $beforeWrongId = file_get_contents($this->checkpointPath($directory));
        try {
            $store->acknowledge($pending, str_repeat('f', 64));
            self::fail('A wrong event identifier must be rejected.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_acknowledgement_invalid', $exception->getMessage());
        }
        self::assertSame($beforeWrongId, file_get_contents($this->checkpointPath($directory)));

        $acknowledged = $store->acknowledge($pending, $event->eventId);
        $older = $this->tradeEvent('rest_recovery', false, '242720720', '64999.9', '2');
        $olderOrdinal = $this->advanceOrdinal($ordinal, $older, 'trade|242720720');
        $olderPending = $store->savePending($acknowledged, $older, $olderOrdinal, [
            'stream' => 'BTCUSDT/rest/public_trade',
            'frontier' => OkxPaperStreamFrontier::fromEvent($older)->toArray(),
        ]);
        $beforeConflict = file_get_contents($this->checkpointPath($directory));
        try {
            $store->acknowledge($olderPending, $older->eventId);
            self::fail('A non-monotonic frontier must be rejected.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('market_event_identity_conflict', $exception->getMessage());
        }
        self::assertSame($beforeConflict, file_get_contents($this->checkpointPath($directory)));
    }

    public function testConflictingPendingAcknowledgementFailsClosedOnlyThroughExplicitFailure(): void
    {
        $directory = $this->datasetDirectory('pending-conflict-failure');
        [$failed, $pendingOrdinalState] = $this->persistExplicitFailureAfterPendingConflict($directory);

        self::assertSame('failed', $failed->phase);
        self::assertNull($failed->pendingEvent);
        self::assertSame(
            CanonicalJson::encode($pendingOrdinalState),
            CanonicalJson::encode($failed->ordinalState),
        );
        self::assertSame(
            CanonicalJson::encode($failed->toArray()) . "\n",
            file_get_contents($this->checkpointPath($directory)),
        );
    }

    public function testFullRestartStateAndCanonicalBytesSurviveSaveAndReopen(): void
    {
        $directory = $this->datasetDirectory('durable-state');
        $store = new OkxPaperLiveCheckpointStore($directory);
        $current = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $current = $this->completeInitialWarmupAndEnterStreaming($store, $current);
        $tradeEvent = $this->tradeEvent('rest_recovery', sequence: '2');
        $current = $this->acknowledgeEventForTest(
            $store,
            $current,
            $tradeEvent,
            'trade|242720721',
            'BTCUSDT/rest/public_trade',
        );
        $attempt = $this->startReconnectAttempt(
            $store,
            $current,
            '2026-07-22T10:00:01.000000Z',
        );
        $attempt = $this->advanceReconnectToBusinessSubscription($store, $attempt);

        $tradeFrontier = OkxPaperStreamFrontier::fromEvent($tradeEvent)->toArray();
        $resync = [
            'attempt' => 1,
            'frontier' => $tradeFrontier,
            'source_sequence' => null,
            'deadline_at' => '2026-07-22T10:00:10.000000Z',
            'policy' => 'frontier_overlap_v1',
        ];
        $pagination = [
            'endpoint' => 'history_trades',
            'pagination_type' => 2,
            'next_cursor' => '1784714400000',
            'pages_consumed' => 0,
            'pages_remaining' => 10,
            'target_frontier' => $tradeFrontier,
            'deadline_at' => '2026-07-22T10:00:10.000000Z',
        ];
        $checkpoint = $this->startReconnectFrontierRecovery(
            $store,
            $attempt,
            'BTCUSDT/rest/public_trade',
            $resync,
            $pagination,
        );
        $state = $checkpoint->toArray();
        $bytes = file_get_contents($this->checkpointPath($directory));
        unset($store);

        $resumedStore = new OkxPaperLiveCheckpointStore($directory);
        $resumed = $resumedStore->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        self::assertSame(
            CanonicalJson::encode($state),
            CanonicalJson::encode($resumed->toArray()),
        );
        $resumedStore->save($resumed);
        self::assertSame($bytes, file_get_contents($this->checkpointPath($directory)));
    }

    public function testWriterLockIsExclusiveAndContentionDoesNotMutateCheckpoint(): void
    {
        $directory = $this->datasetDirectory('lock');
        $first = new OkxPaperLiveCheckpointStore($directory);
        $first->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $path = $this->checkpointPath($directory);
        $before = file_get_contents($path);

        try {
            new OkxPaperLiveCheckpointStore($directory);
            self::fail('A second writer must fail immediately.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_lock_unavailable', $exception->getMessage());
        }
        self::assertSame($before, file_get_contents($path));
    }

    public function testInvalidTransitionIsValidatedBeforeWriteAndLeavesBytesUnchanged(): void
    {
        $directory = $this->datasetDirectory('validate-before-write');
        $store = new OkxPaperLiveCheckpointStore($directory);
        $checkpoint = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $path = $this->checkpointPath($directory);
        $before = file_get_contents($path);

        try {
            $store->saveTransition($checkpoint, 'streaming', [
                'kind' => 'transport_connect',
                'symbol' => 'BTCUSDT',
                'stream' => 'public',
                'stage' => 'connect',
            ]);
            self::fail('An invalid transition must be rejected before publication.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }

        self::assertSame($before, file_get_contents($path));
        self::assertSame([], glob(dirname($path) . '/.okx-live-*') ?: []);
    }

    public function testCheckpointSymlinkAndHardlinkAreRejectedWithoutWritingThroughThem(): void
    {
        $symlinkDirectory = $this->datasetDirectory('checkpoint-symlink');
        $managed = $symlinkDirectory . '/checkpoints/okx-live';
        self::assertTrue(mkdir($symlinkDirectory . '/checkpoints', 0700));
        self::assertTrue(mkdir($managed, 0700));
        $outside = $this->testRoot . '/outside-checkpoint';
        self::assertSame(7, file_put_contents($outside, 'outside'));
        self::assertTrue(symlink($outside, $managed . '/checkpoint.json'));

        try {
            (new OkxPaperLiveCheckpointStore($symlinkDirectory))->loadOrCreate(
                self::DATASET_ID,
                self::CONFIGURATION_SHA256,
            );
            self::fail('A checkpoint symlink must be rejected.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }
        self::assertSame('outside', file_get_contents($outside));

        $hardlinkDirectory = $this->datasetDirectory('checkpoint-hardlink');
        $store = new OkxPaperLiveCheckpointStore($hardlinkDirectory);
        $checkpoint = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $path = $this->checkpointPath($hardlinkDirectory);
        $alias = $this->testRoot . '/checkpoint-hardlink-alias';
        self::assertTrue(link($path, $alias));
        $before = file_get_contents($path);
        try {
            $store->saveTransition($checkpoint, 'connecting', [
                'kind' => 'transport_connect', 'symbol' => null, 'stream' => 'public', 'stage' => 'connect',
            ]);
            self::fail('A checkpoint hardlink must be rejected.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }
        self::assertSame($before, file_get_contents($path));
        self::assertSame($before, file_get_contents($alias));
    }

    public function testManagedDirectoryAndWriterLockReplacementAreDetectedBeforePublication(): void
    {
        $directory = $this->datasetDirectory('pinned-replacement');
        $store = new OkxPaperLiveCheckpointStore($directory);
        $checkpoint = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $managed = dirname($this->checkpointPath($directory));
        $displaced = $managed . '-displaced';
        self::assertTrue(rename($managed, $displaced));
        self::assertTrue(mkdir($managed, 0700));

        try {
            $store->save($checkpoint);
            self::fail('A replaced pinned directory must be rejected.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }
        self::assertFileDoesNotExist($managed . '/checkpoint.json');
        unset($store);

        $lockDirectory = $this->datasetDirectory('lock-replacement');
        $lockStore = new OkxPaperLiveCheckpointStore($lockDirectory);
        $lockCheckpoint = $lockStore->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $path = $this->checkpointPath($lockDirectory);
        $lock = dirname($path) . '/.writer.lock';
        $before = file_get_contents($path);
        self::assertTrue(rename($lock, $lock . '.displaced'));
        self::assertSame(0, file_put_contents($lock, ''));
        self::assertTrue(chmod($lock, 0600));
        try {
            $lockStore->save($lockCheckpoint);
            self::fail('A replaced writer lock must be rejected.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }
        self::assertSame($before, file_get_contents($path));
    }

    public function testDirectoryReplacementAfterTemporaryOpenIsRejectedBeforeWritingCheckpointBytes(): void
    {
        $directory = $this->datasetDirectory('temporary-open-replacement');
        $initialStore = new OkxPaperLiveCheckpointStore($directory);
        $checkpoint = $initialStore->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        unset($initialStore);

        $filesystem = new ReplacingDirectoryAfterTemporaryOpenFilesystem();
        $store = new OkxPaperLiveCheckpointStore($directory, $filesystem);
        $checkpoint = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $filesystem->replaceDirectory = true;

        try {
            $store->saveTransition($checkpoint, 'connecting', [
                'kind' => 'transport_connect', 'symbol' => null, 'stream' => 'public', 'stage' => 'connect',
            ]);
            self::fail('A directory replacement after temporary open must be rejected.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }

        self::assertFalse($filesystem->wroteCheckpointAfterReplacement);
    }

    public function testOversizedOrNonCanonicalCheckpointFailsClosed(): void
    {
        $oversizedDirectory = $this->datasetDirectory('oversized');
        $managed = $oversizedDirectory . '/checkpoints/okx-live';
        self::assertTrue(mkdir($oversizedDirectory . '/checkpoints', 0700));
        self::assertTrue(mkdir($managed, 0700));
        $path = $managed . '/checkpoint.json';
        self::assertSame(1_048_577, file_put_contents($path, str_repeat('x', 1_048_577)));
        self::assertTrue(chmod($path, 0600));
        try {
            (new OkxPaperLiveCheckpointStore($oversizedDirectory))->loadOrCreate(
                self::DATASET_ID,
                self::CONFIGURATION_SHA256,
            );
            self::fail('An oversized checkpoint must be rejected.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }

        $canonicalDirectory = $this->datasetDirectory('non-canonical');
        $canonicalStore = new OkxPaperLiveCheckpointStore($canonicalDirectory);
        $canonicalStore->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $canonicalPath = $this->checkpointPath($canonicalDirectory);
        unset($canonicalStore);
        $state = json_decode((string) file_get_contents($canonicalPath), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($state);
        self::assertNotFalse(file_put_contents($canonicalPath, json_encode($state, \JSON_THROW_ON_ERROR)));
        self::assertTrue(chmod($canonicalPath, 0600));
        try {
            (new OkxPaperLiveCheckpointStore($canonicalDirectory))->loadOrCreate(
                self::DATASET_ID,
                self::CONFIGURATION_SHA256,
            );
            self::fail('A checkpoint without its canonical final newline must be rejected.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }
    }

    public function testFileSyncFailureBeforeRenamePreservesPriorCheckpointBytes(): void
    {
        $directory = $this->datasetDirectory('sync-failure');
        $initial = new OkxPaperLiveCheckpointStore($directory);
        $checkpoint = $initial->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $path = $this->checkpointPath($directory);
        $before = file_get_contents($path);
        unset($initial);

        $filesystem = new FailingOkxPaperLiveCheckpointFilesystem();
        $store = new OkxPaperLiveCheckpointStore($directory, $filesystem);
        $checkpoint = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $filesystem->failCheckpointSync = true;
        try {
            $store->saveTransition($checkpoint, 'warming', [
                'kind' => 'rest_fetch',
                'symbol' => 'BTCUSDT',
                'stream' => 'BTCUSDT/rest/candle_1m',
                'stage' => 'current_candles',
            ]);
            self::fail('A failed file fsync must abort before rename.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_write_failed', $exception->getMessage());
        }
        self::assertSame($before, file_get_contents($path));
        self::assertSame([], glob(dirname($path) . '/.okx-live-*') ?: []);
    }

    public function testAtomicPublicationSyncsFileThenRenamesInSameDirectoryThenSyncsDirectory(): void
    {
        $directory = $this->datasetDirectory('atomic-order');
        $filesystem = new RecordingOkxPaperLiveCheckpointFilesystem();
        (new OkxPaperLiveCheckpointStore($directory, $filesystem))->loadOrCreate(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        );

        $fileSync = array_search('sync:okx_paper_live_checkpoint_sync', $filesystem->operations, true);
        $move = array_search('move:checkpoint.json', $filesystem->operations, true);
        $directorySync = array_search(
            'sync:okx_paper_live_checkpoint_directory_sync',
            $filesystem->operations,
            true,
        );
        self::assertIsInt($fileSync);
        self::assertIsInt($move);
        self::assertIsInt($directorySync);
        self::assertLessThan($move, $fileSync);
        self::assertLessThan($directorySync, $move);
        self::assertSame($filesystem->moveSourceDirectory, $filesystem->moveDestinationDirectory);
    }

    public function testPendingFrontierMustMatchEventAndPendingEventMustBeOkx(): void
    {
        $state = OkxPaperLiveCheckpoint::fresh(self::DATASET_ID, self::CONFIGURATION_SHA256)->toArray();
        $event = $this->tradeEvent('rest_recovery');
        $state['pending_event'] = $event->toArray();
        $state['ordinal_state'] = $this->ordinalStateFor($event, 'trade|242720721');
        $frontier = OkxPaperStreamFrontier::fromEvent($event)->toArray();
        $frontier['canonical_digest'] = str_repeat('d', 64);
        $state['pending_frontier'] = [
            'stream' => 'BTCUSDT/rest/public_trade',
            'frontier' => $frontier,
        ];
        $this->assertCheckpointInvalid($state);

        $state = OkxPaperLiveCheckpoint::fresh(self::DATASET_ID, self::CONFIGURATION_SHA256)->toArray();
        $state['pending_frontier'] = [
            'stream' => 'BTCUSDT/rest/public_trade',
            'frontier' => OkxPaperStreamFrontier::fromEvent($event)->toArray(),
        ];
        $this->assertCheckpointInvalid($state);
    }

    public function testPhaseActionPairingNativeFrontierShapeAndCandleCursorAreStrict(): void
    {
        $state = OkxPaperLiveCheckpoint::fresh(self::DATASET_ID, self::CONFIGURATION_SHA256)->toArray();
        $state['pending_transition'] = [
            'kind' => 'loop_stop', 'symbol' => null, 'stream' => null, 'stage' => 'stop_loop',
        ];
        $this->assertCheckpointInvalid($state);

        $state = OkxPaperLiveCheckpoint::fresh(self::DATASET_ID, self::CONFIGURATION_SHA256)->toArray();
        $state['phase'] = 'failed';
        $state['failure_reason'] = 'market_data_gap_unresolved';
        $state['pending_transition'] = [
            'kind' => 'transport_close', 'symbol' => null, 'stream' => 'public', 'stage' => 'close',
        ];
        self::assertSame($state, OkxPaperLiveCheckpoint::fromArray($state)->toArray());

        $state = OkxPaperLiveCheckpoint::fresh(self::DATASET_ID, self::CONFIGURATION_SHA256)->toArray();
        $state['stream_frontiers']['BTCUSDT/rest/public_trade'] = [
            'source_identity' => 'not-a-trade-id',
            'natural_identity' => 'okx|BTC-USDT-SWAP|public_trade|not-a-trade-id',
            'canonical_digest' => str_repeat('e', 64),
        ];
        $this->assertCheckpointInvalid($state);

        $state = OkxPaperLiveCheckpoint::fresh(self::DATASET_ID, self::CONFIGURATION_SHA256)->toArray();
        $frontier = OkxPaperStreamFrontier::fromEvent($this->candleEvent(
            'rest_warmup',
            '2026-07-22T10:00:01.000000Z',
        ))->toArray();
        $state['overlap_pagination_by_stream']['BTCUSDT/rest/candle_1m'] = [
            'endpoint' => 'history_candles',
            'pagination_type' => null,
            'next_cursor' => '123',
            'pages_consumed' => 1,
            'pages_remaining' => 9,
            'target_frontier' => $frontier,
            'deadline_at' => '2026-07-22T10:00:10.000000Z',
        ];
        $this->assertCheckpointInvalid($state);
    }

    public function testResyncingPhaseAndSnapshotActionsRequireTheExactActiveResync(): void
    {
        $base = OkxPaperLiveCheckpoint::fresh(self::DATASET_ID, self::CONFIGURATION_SHA256)->toArray();
        $base['phase'] = 'resyncing';
        $this->assertCheckpointInvalid($base);

        foreach ([
            [
                'kind' => 'emit_boundary',
                'symbol' => 'BTCUSDT',
                'stream' => 'BTCUSDT/control/snapshot_boundary',
                'stage' => 'sequence_gap',
            ],
            [
                'kind' => 'rest_fetch',
                'symbol' => 'BTCUSDT',
                'stream' => 'BTCUSDT/rest/top_of_book',
                'stage' => 'order_book',
            ],
        ] as $transition) {
            $state = $base;
            $state['pending_transition'] = $transition;
            $this->assertCheckpointInvalid($state);
        }

        $state = $base;
        $state['pending_transition'] = [
            'kind' => 'emit_boundary',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/control/snapshot_boundary',
            'stage' => 'sequence_gap',
        ];
        $state['resync_by_symbol']['ETHUSDT'] = [
            'attempt' => 1,
            'frontier' => OkxPaperStreamFrontier::fromEvent($this->ethBookEvent())->toArray(),
            'source_sequence' => '9001',
            'deadline_at' => '2026-07-22T10:00:10.000000Z',
            'policy' => 'book_seq_overlap_v1',
        ];
        $this->assertCheckpointInvalid($state);

        $pendingState = $base;
        $pendingState['resync_by_symbol']['ETHUSDT'] = $state['resync_by_symbol']['ETHUSDT'];
        $boundary = $this->sequenceGapBoundaryEvent();
        $pendingState['pending_event'] = $boundary->toArray();
        $pendingState['ordinal_state'] = $this->ordinalStateFor(
            $boundary,
            'boundary|2|9001|sequence_gap',
        );
        $this->assertCheckpointInvalid($pendingState);

        $state['resync_by_symbol']['BTCUSDT'] = [
            'attempt' => 1,
            'frontier' => OkxPaperStreamFrontier::fromEvent($this->bookEvent())->toArray(),
            'source_sequence' => '9001',
            'deadline_at' => '2026-07-22T10:00:10.000000Z',
            'policy' => 'book_seq_overlap_v1',
        ];
        $state['stream_frontiers']['BTCUSDT/ws/top_of_book'] =
            OkxPaperStreamFrontier::fromEvent($this->bookEvent())->toArray();
        self::assertSame($state, OkxPaperLiveCheckpoint::fromArray($state)->toArray());
    }

    public function testResyncContinuationMatchesTheExactStreamPolicyFrontierAndRestAction(): void
    {
        $bookState = OkxPaperLiveCheckpoint::fresh(self::DATASET_ID, self::CONFIGURATION_SHA256)->toArray();
        $bookState['phase'] = 'resyncing';
        $bookState['resync_by_symbol']['BTCUSDT'] = [
            'attempt' => 1,
            'frontier' => OkxPaperStreamFrontier::fromEvent($this->bookEvent())->toArray(),
            'source_sequence' => '9001',
            'deadline_at' => '2026-07-22T10:00:10.000000Z',
            'policy' => 'book_seq_overlap_v1',
        ];

        $wrongChannelAction = $bookState;
        $wrongChannelAction['pending_transition'] = [
            'kind' => 'rest_fetch',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/rest/candle_1m',
            'stage' => 'current_candles',
        ];
        $this->assertCheckpointInvalid($wrongChannelAction);

        $wrongBookTarget = $bookState;
        $wrongBookTarget['pending_transition'] = [
            'kind' => 'rest_fetch',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/ws/top_of_book',
            'stage' => 'order_book',
        ];
        $this->assertCheckpointInvalid($wrongBookTarget);

        $pendingCandle = $this->candleEvent('rest_warmup', '2026-07-22T10:00:01.000000Z');
        $wrongPendingEvent = $bookState;
        $wrongPendingEvent['pending_event'] = $pendingCandle->toArray();
        $wrongPendingEvent['ordinal_state'] = $this->ordinalStateFor(
            $pendingCandle,
            'candle|1m|1784714400000',
        );
        $wrongPendingEvent['pending_frontier'] = [
            'stream' => 'BTCUSDT/rest/candle_1m',
            'frontier' => OkxPaperStreamFrontier::fromEvent($pendingCandle)->toArray(),
        ];
        $this->assertCheckpointInvalid($wrongPendingEvent);

        $tradeState = OkxPaperLiveCheckpoint::fresh(self::DATASET_ID, self::CONFIGURATION_SHA256)->toArray();
        $tradeState['phase'] = 'resyncing';
        $tradeState['resync_by_symbol']['BTCUSDT'] = [
            'attempt' => 1,
            'frontier' => OkxPaperStreamFrontier::fromEvent($this->tradeEvent('rest_recovery'))->toArray(),
            'source_sequence' => null,
            'deadline_at' => '2026-07-22T10:00:10.000000Z',
            'policy' => 'frontier_overlap_v1',
        ];
        $tradeState['pending_transition'] = [
            'kind' => 'rest_fetch',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/rest/candle_1m',
            'stage' => 'current_candles',
        ];
        $this->assertCheckpointInvalid($tradeState);

        $tradeFrontier = $tradeState['resync_by_symbol']['BTCUSDT']['frontier'];
        $wrongTargetStream = $tradeState;
        $wrongTargetStream['stream_frontiers']['BTCUSDT/ws/public_trade'] = $tradeFrontier;
        $wrongTargetStream['pending_transition'] = [
            'kind' => 'rest_fetch',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/rest/public_trade',
            'stage' => 'recent_trades',
        ];
        $this->assertCheckpointInvalid($wrongTargetStream);

        $exactTargetStream = $wrongTargetStream;
        $exactTargetStream['pending_transition']['stream'] = 'BTCUSDT/ws/public_trade';
        self::assertSame(
            $exactTargetStream,
            OkxPaperLiveCheckpoint::fromArray($exactTargetStream)->toArray(),
        );
    }

    public function testCompletePhaseContainsNoPendingWorkOrActiveRecoveryState(): void
    {
        $complete = OkxPaperLiveCheckpoint::fresh(self::DATASET_ID, self::CONFIGURATION_SHA256)->toArray();
        $complete['phase'] = 'complete';
        $complete['remaining_symbols'] = [];
        $complete['remaining_boundaries'] = [];
        $complete['healthy_stop'] = ['requested' => true, 'remaining_symbols' => []];
        $this->assertCheckpointInvalid($complete);

        $complete['stream_frontiers']['BTCUSDT/control/connection_state'] =
            OkxPaperStreamFrontier::fromEvent($this->stoppedConnectionEvent())->toArray();
        $complete['stream_frontiers']['ETHUSDT/control/connection_state'] =
            OkxPaperStreamFrontier::fromEvent($this->stoppedConnectionEvent('ETHUSDT'))->toArray();
        self::assertSame($complete, OkxPaperLiveCheckpoint::fromArray($complete)->toArray());

        $withRemainingSymbol = $complete;
        $withRemainingSymbol['remaining_symbols'] = ['BTCUSDT'];
        $this->assertCheckpointInvalid($withRemainingSymbol);

        $withRemainingBoundary = $complete;
        $withRemainingBoundary['remaining_boundaries'] = [
            ['symbol' => 'BTCUSDT', 'reason' => 'initial'],
        ];
        $this->assertCheckpointInvalid($withRemainingBoundary);

        $withReconnect = $complete;
        $withReconnect['reconnect'] = [
            'attempt' => 1,
            'deadline_at' => '2026-07-22T10:00:10.000000Z',
            'stable_since' => null,
            'accepted_events' => 0,
        ];
        $this->assertCheckpointInvalid($withReconnect);

        $withResync = $complete;
        $withResync['resync_by_symbol']['BTCUSDT'] = [
            'attempt' => 1,
            'frontier' => OkxPaperStreamFrontier::fromEvent($this->bookEvent())->toArray(),
            'source_sequence' => '9001',
            'deadline_at' => '2026-07-22T10:00:10.000000Z',
            'policy' => 'book_seq_overlap_v1',
        ];
        $this->assertCheckpointInvalid($withResync);
    }

    public function testCompleteCannotBeEnteredDirectlyEvenWithForgedStoppedFrontiers(): void
    {
        $directory = $this->datasetDirectory('terminal-complete');
        $initialStore = new OkxPaperLiveCheckpointStore($directory);
        $fresh = $initialStore->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        unset($initialStore);

        $state = $fresh->toArray();
        $state['phase'] = 'stopping';
        $state['remaining_symbols'] = [];
        $state['remaining_boundaries'] = [];
        $state['healthy_stop'] = ['requested' => true, 'remaining_symbols' => []];
        $state['stream_frontiers']['BTCUSDT/control/connection_state'] =
            OkxPaperStreamFrontier::fromEvent($this->stoppedConnectionEvent())->toArray();
        $state['stream_frontiers']['ETHUSDT/control/connection_state'] =
            OkxPaperStreamFrontier::fromEvent($this->stoppedConnectionEvent('ETHUSDT'))->toArray();
        $this->replaceCheckpointState($directory, $state);

        $store = new OkxPaperLiveCheckpointStore($directory);
        $stopping = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $completeState = $stopping->toArray();
        $completeState['phase'] = 'complete';
        $complete = OkxPaperLiveCheckpoint::fromArray($completeState);
        $path = $this->checkpointPath($directory);
        $before = file_get_contents($path);

        try {
            $store->save($complete);
            self::fail('Complete must require the exact durable cleanup finalization.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }
        self::assertSame($before, file_get_contents($path));
    }

    public function testCompleteRequiresBothStoppedAcknowledgementsThenExactOrderedCleanup(): void
    {
        $directory = $this->datasetDirectory('complete-through-acknowledgements');
        $initialStore = new OkxPaperLiveCheckpointStore($directory);
        $fresh = $initialStore->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        unset($initialStore);

        $stoppingState = $fresh->toArray();
        $stoppingState['phase'] = 'stopping';
        $stoppingState['remaining_boundaries'] = [];
        $stoppingState['healthy_stop'] = [
            'requested' => true,
            'remaining_symbols' => ['BTCUSDT', 'ETHUSDT'],
        ];
        $this->replaceCheckpointState($directory, $stoppingState);

        $store = new OkxPaperLiveCheckpointStore($directory);
        $stopping = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $checkpoint = $stopping;
        foreach (['BTCUSDT', 'ETHUSDT'] as $symbol) {
            $transition = [
                'kind' => 'healthy_stop',
                'symbol' => $symbol,
                'stream' => $symbol . '/control/connection_state',
                'stage' => 'emit_stopped',
            ];
            $checkpoint = $store->saveTransition($checkpoint, 'stopping', $transition);
            $event = $this->stoppedConnectionEvent($symbol);
            $pending = $store->savePending(
                $checkpoint,
                $event,
                $this->advanceOrdinal(
                    $checkpoint->ordinalState,
                    $event,
                    'connection|2|stopped',
                ),
                null,
            );
            $checkpoint = $store->acknowledge($pending, $event->eventId);
        }
        self::assertSame('stopping', $checkpoint->phase);
        self::assertSame([], $checkpoint->remainingSymbols);
        self::assertSame([], $checkpoint->healthyStop['remaining_symbols']);

        $prematureComplete = $checkpoint->toArray();
        $prematureComplete['phase'] = 'complete';
        $path = $this->checkpointPath($directory);
        $before = file_get_contents($path);
        try {
            $store->saveTransition(
                OkxPaperLiveCheckpoint::fromArray($prematureComplete),
                'complete',
                null,
            );
            self::fail('Both stopped acknowledgements are insufficient before cleanup.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }
        self::assertSame($before, file_get_contents($path));

        $cleanup = [
            ['kind' => 'transport_close', 'symbol' => null, 'stream' => 'public', 'stage' => 'close'],
            ['kind' => 'transport_close', 'symbol' => null, 'stream' => 'business', 'stage' => 'close'],
            ['kind' => 'timer_cancel', 'symbol' => null, 'stream' => null, 'stage' => 'cancel_reconnect_timer'],
            ['kind' => 'timer_cancel', 'symbol' => 'BTCUSDT', 'stream' => 'BTCUSDT/ws/top_of_book', 'stage' => 'cancel_resync_timer'],
            ['kind' => 'timer_cancel', 'symbol' => 'ETHUSDT', 'stream' => 'ETHUSDT/ws/top_of_book', 'stage' => 'cancel_resync_timer'],
            ['kind' => 'loop_stop', 'symbol' => null, 'stream' => null, 'stage' => 'stop_loop'],
            ['kind' => 'healthy_stop', 'symbol' => null, 'stream' => null, 'stage' => 'finalize'],
        ];
        foreach ($cleanup as $transition) {
            try {
                $checkpoint = $store->saveTransition($checkpoint, 'stopping', $transition);
            } catch (OkxPaperLiveIntegrityException $exception) {
                self::fail(sprintf(
                    'The exact cleanup continuation %s/%s must be persistable: %s',
                    $transition['kind'],
                    $transition['stage'],
                    $exception->getMessage(),
                ));
            }
            self::assertSame('stopping', $checkpoint->phase);
            self::assertSame($transition, $checkpoint->pendingTransition);
        }

        $complete = $store->saveTransition($checkpoint, 'complete', null);

        self::assertSame('complete', $complete->phase);
        self::assertNull($complete->pendingTransition);
        self::assertSame('2|stopped', $complete->streamFrontiers[
            'BTCUSDT/control/connection_state'
        ]?->sourceIdentity);
        self::assertSame('2|stopped', $complete->streamFrontiers[
            'ETHUSDT/control/connection_state'
        ]?->sourceIdentity);
    }

    public function testCompleteRejectsAStoppedEventThatIsNotTheExactFinalHealthyWorkUnit(): void
    {
        $directory = $this->datasetDirectory('complete-without-final-work');
        $initialStore = new OkxPaperLiveCheckpointStore($directory);
        $fresh = $initialStore->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        unset($initialStore);

        $state = $fresh->toArray();
        $state['phase'] = 'stopping';
        $state['remaining_symbols'] = [];
        $state['remaining_boundaries'] = [];
        $state['healthy_stop'] = ['requested' => true, 'remaining_symbols' => []];
        $state['stream_frontiers']['BTCUSDT/control/connection_state'] =
            OkxPaperStreamFrontier::fromEvent($this->stoppedConnectionEvent())->toArray();
        $this->replaceCheckpointState($directory, $state);

        $store = new OkxPaperLiveCheckpointStore($directory);
        $stopping = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $eth = $this->stoppedConnectionEvent('ETHUSDT');
        $path = $this->checkpointPath($directory);
        $before = file_get_contents($path);

        try {
            $store->savePending(
                $stopping,
                $eth,
                $this->advanceOrdinal($stopping->ordinalState, $eth, 'connection|2|stopped'),
                null,
            );
            self::fail('A stopped event without remaining healthy work must not become pending.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }
        self::assertSame($before, file_get_contents($path));
    }

    public function testPrivateCheckpointReplacementIsDetectedAndNeverOverwritten(): void
    {
        $directory = $this->datasetDirectory('checkpoint-replacement');
        $store = new OkxPaperLiveCheckpointStore($directory);
        $checkpoint = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $path = $this->checkpointPath($directory);
        $original = file_get_contents($path);
        self::assertIsString($original);
        self::assertTrue(rename($path, $path . '.displaced'));
        $replacement = str_replace('"warming"', '"streaming"', $original);
        self::assertNotSame($original, $replacement);
        self::assertSame(strlen($replacement), file_put_contents($path, $replacement));
        self::assertTrue(chmod($path, 0600));

        try {
            $store->saveTransition($checkpoint, 'connecting', [
                'kind' => 'transport_connect', 'symbol' => null, 'stream' => 'public', 'stage' => 'connect',
            ]);
            self::fail('A replaced checkpoint must be detected before overwrite.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }
        self::assertSame($replacement, file_get_contents($path));
    }

    public function testRawCheckpointAndPendingInputsAreRedactedFromPhpTraces(): void
    {
        foreach ([
            [OkxPaperStreamFrontier::class, 'fromEvent', ['event']],
            [OkxPaperLiveCheckpointStore::class, 'save', ['checkpoint']],
            [OkxPaperLiveCheckpointStore::class, 'saveTransition', ['checkpoint', 'pendingTransition']],
            [OkxPaperLiveCheckpointStore::class, 'fail', ['checkpoint']],
            [OkxPaperLiveCheckpointStore::class, 'savePending', [
                'checkpoint', 'event', 'ordinalState', 'pendingFrontier',
            ]],
            [OkxPaperLiveCheckpointStore::class, 'acknowledge', ['checkpoint']],
        ] as [$class, $method, $parameterNames]) {
            $reflection = new \ReflectionMethod($class, $method);
            foreach ($parameterNames as $parameterName) {
                $parameter = $reflection->getParameters()[array_search(
                    $parameterName,
                    array_map(
                        static fn (\ReflectionParameter $candidate): string => $candidate->getName(),
                        $reflection->getParameters(),
                    ),
                    true,
                )];
                self::assertCount(1, $parameter->getAttributes(\SensitiveParameter::class));
            }
        }
    }

    public function testSaveRejectsAStaleCheckpointAfterAcknowledgementWithoutRollingBackBytes(): void
    {
        $directory = $this->datasetDirectory('stale-save');
        $store = new OkxPaperLiveCheckpointStore($directory);
        $stale = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $event = $this->tradeEvent('rest_recovery');
        $pending = $store->savePending(
            $stale,
            $event,
            $this->ordinalStateFor($event, 'trade|242720721'),
            [
                'stream' => 'BTCUSDT/rest/public_trade',
                'frontier' => OkxPaperStreamFrontier::fromEvent($event)->toArray(),
            ],
        );
        $acknowledged = $store->acknowledge($pending, $event->eventId);
        $path = $this->checkpointPath($directory);
        $before = file_get_contents($path);

        try {
            $store->save($stale);
            self::fail('A stale checkpoint must not roll back acknowledged state.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }

        self::assertSame($before, file_get_contents($path));
        self::assertSame($event->eventId, $acknowledged->lastAcknowledgedEventId);
        self::assertSame('242720721', $acknowledged->streamFrontiers[
            'BTCUSDT/rest/public_trade'
        ]?->sourceIdentity);
    }

    public function testSavePendingAtomicallyReplacesThePersistedEventProducingTransition(): void
    {
        $directory = $this->datasetDirectory('transition-to-pending');
        $store = new OkxPaperLiveCheckpointStore($directory);
        $checkpoint = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $checkpoint = $this->acknowledgeInitialWarmupRestPrefix($store, $checkpoint, 'BTCUSDT', 4);
        $transition = [
            'kind' => 'rest_fetch',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/rest/public_trade',
            'stage' => 'recent_trades',
        ];
        $writeAhead = $store->saveTransition($checkpoint, 'warming', $transition);
        $event = $this->tradeEvent('rest_recovery');
        $frontier = OkxPaperStreamFrontier::fromEvent($event);

        $pending = $store->savePending(
            $writeAhead,
            $event,
            $this->advanceOrdinal($writeAhead->ordinalState, $event, 'trade|242720721'),
            ['stream' => $transition['stream'], 'frontier' => $frontier->toArray()],
        );

        self::assertNull($pending->pendingTransition);
        self::assertSame($event->eventId, $pending->pendingEvent?->eventId);
        self::assertNotNull($pending->pendingFrontier);
        self::assertSame($frontier->toArray(), $pending->pendingFrontier['frontier']->toArray());
        unset($store);

        $resumed = (new OkxPaperLiveCheckpointStore($directory))->loadOrCreate(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        );
        self::assertNull($resumed->pendingTransition);
        self::assertSame($event->eventId, $resumed->pendingEvent?->eventId);
    }

    public function testSavePendingRejectsMissingFrontierWithoutChangingBytes(): void
    {
        $directory = $this->datasetDirectory('pending-missing-frontier');
        $store = new OkxPaperLiveCheckpointStore($directory);
        $checkpoint = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $event = $this->tradeEvent('rest_recovery');
        $path = $this->checkpointPath($directory);
        $before = file_get_contents($path);

        try {
            $store->savePending(
                $checkpoint,
                $event,
                $this->ordinalStateFor($event, 'trade|242720721'),
                null,
            );
            self::fail('Every pending live event must carry its exact stream frontier.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }
        self::assertSame($before, file_get_contents($path));
    }

    public function testSavePendingWithoutTransitionBindsValidatedOriginToTheExactStreamTransport(): void
    {
        foreach ([
            ['ws_aggregated', 'BTCUSDT/rest/public_trade'],
            ['rest_recovery', 'BTCUSDT/ws/public_trade'],
            ['unknown_origin', 'BTCUSDT/rest/public_trade'],
        ] as [$origin, $stream]) {
            $directory = $this->datasetDirectory('pending-origin-' . str_replace('_', '-', $origin));
            $store = new OkxPaperLiveCheckpointStore($directory);
            $checkpoint = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
            $event = $this->tradeEvent($origin);
            $path = $this->checkpointPath($directory);
            $before = file_get_contents($path);

            try {
                $store->savePending(
                    $checkpoint,
                    $event,
                    $this->ordinalStateFor($event, 'trade|242720721'),
                    [
                        'stream' => $stream,
                        'frontier' => OkxPaperStreamFrontier::fromEvent($event)->toArray(),
                    ],
                );
                self::fail('A pending market event origin must match its exact REST/WS stream.');
            } catch (OkxPaperLiveIntegrityException|\InvalidArgumentException $exception) {
                self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
            }
            self::assertSame($before, file_get_contents($path));
            unset($store);
        }
    }

    public function testRestFetchTransitionRejectsAWebSocketOriginForTheSameCanonicalRow(): void
    {
        $directory = $this->datasetDirectory('pending-rest-action-ws-origin');
        $store = new OkxPaperLiveCheckpointStore($directory);
        $fresh = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $fresh = $this->acknowledgeInitialWarmupRestPrefix($store, $fresh, 'BTCUSDT', 4);
        $transition = [
            'kind' => 'rest_fetch',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/rest/public_trade',
            'stage' => 'recent_trades',
        ];
        $writeAhead = $store->saveTransition($fresh, 'warming', $transition);
        $webSocketEvent = $this->tradeEvent('ws_aggregated', true);
        $path = $this->checkpointPath($directory);
        $before = file_get_contents($path);

        try {
            $store->savePending(
                $writeAhead,
                $webSocketEvent,
                $this->advanceOrdinal(
                    $writeAhead->ordinalState,
                    $webSocketEvent,
                    'trade|242720721',
                ),
                [
                    'stream' => $transition['stream'],
                    'frontier' => OkxPaperStreamFrontier::fromEvent($webSocketEvent)->toArray(),
                ],
            );
            self::fail('A REST action must not persist a WebSocket-origin row.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }
        self::assertSame($before, file_get_contents($path));
    }

    public function testOrderBookTransitionAcceptsOnlyItsInitialOrRecoverySnapshotOrigin(): void
    {
        $initialDirectory = $this->datasetDirectory('initial-book-origin');
        $initialStore = new OkxPaperLiveCheckpointStore($initialDirectory);
        $fresh = $initialStore->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $fresh = $this->acknowledgeInitialWarmupRestPrefix($initialStore, $fresh, 'BTCUSDT', 5);
        $transition = [
            'kind' => 'rest_fetch',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/rest/top_of_book',
            'stage' => 'order_book',
        ];
        $writeAhead = $initialStore->saveTransition($fresh, 'warming', $transition);
        $path = $this->checkpointPath($initialDirectory);
        $beforeWrongInitialOrigin = file_get_contents($path);
        $wrongInitial = $this->bookEvent('rest_resync_snapshot');
        try {
            $initialStore->savePending(
                $writeAhead,
                $wrongInitial,
                $this->advanceOrdinal($writeAhead->ordinalState, $wrongInitial, 'book|9001'),
                [
                    'stream' => $transition['stream'],
                    'frontier' => OkxPaperStreamFrontier::fromEvent($wrongInitial)->toArray(),
                ],
            );
            self::fail('Initial order-book work must reject rest_resync_snapshot.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }
        self::assertSame($beforeWrongInitialOrigin, file_get_contents($path));
        $initial = $this->bookEvent('rest_initial_snapshot');
        try {
            $pending = $initialStore->savePending(
                $writeAhead,
                $initial,
                $this->advanceOrdinal($writeAhead->ordinalState, $initial, 'book|9001'),
                [
                    'stream' => $transition['stream'],
                    'frontier' => OkxPaperStreamFrontier::fromEvent($initial)->toArray(),
                ],
            );
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::fail('The initial order-book transition must accept rest_initial_snapshot: ' . $exception->getMessage());
        }
        self::assertSame('rest_initial_snapshot', $pending->pendingEvent?->payload['origin'] ?? null);
        unset($initialStore);

        $recoveryDirectory = $this->datasetDirectory('recovery-book-origin');
        $initialStore = new OkxPaperLiveCheckpointStore($recoveryDirectory);
        $fresh = $initialStore->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        unset($initialStore);
        $bookFrontier = OkxPaperStreamFrontier::fromEvent($this->bookEvent())->toArray();
        $recoveryState = $fresh->toArray();
        $recoveryState['phase'] = 'resyncing';
        $recoveryState['remaining_symbols'] = ['BTCUSDT'];
        $recoveryState['remaining_boundaries'] = [
            ['symbol' => 'BTCUSDT', 'reason' => 'sequence_gap'],
        ];
        $recoveryState['stream_frontiers']['BTCUSDT/ws/top_of_book'] = $bookFrontier;
        $recoveryState['resync_by_symbol']['BTCUSDT'] = [
            'attempt' => 1,
            'frontier' => $bookFrontier,
            'source_sequence' => '9001',
            'deadline_at' => '2026-07-22T10:00:10.000000Z',
            'policy' => 'book_seq_overlap_v1',
        ];
        $this->replaceCheckpointState($recoveryDirectory, $recoveryState);
        $recoveryStore = new OkxPaperLiveCheckpointStore($recoveryDirectory);
        $recovery = $recoveryStore->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $writeAhead = $recoveryStore->saveTransition($recovery, 'resyncing', $transition);
        $path = $this->checkpointPath($recoveryDirectory);
        $beforeWrongRecoveryOrigin = file_get_contents($path);
        $wrongRecovery = $this->bookEvent('rest_initial_snapshot');
        try {
            $recoveryStore->savePending(
                $writeAhead,
                $wrongRecovery,
                $this->advanceOrdinal($writeAhead->ordinalState, $wrongRecovery, 'book|9001'),
                [
                    'stream' => $transition['stream'],
                    'frontier' => OkxPaperStreamFrontier::fromEvent($wrongRecovery)->toArray(),
                ],
            );
            self::fail('Recovery order-book work must reject rest_initial_snapshot.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }
        self::assertSame($beforeWrongRecoveryOrigin, file_get_contents($path));
        $resync = $this->bookEvent('rest_resync_snapshot');
        $pending = $recoveryStore->savePending(
            $writeAhead,
            $resync,
            $this->advanceOrdinal($writeAhead->ordinalState, $resync, 'book|9001'),
            [
                'stream' => $transition['stream'],
                'frontier' => OkxPaperStreamFrontier::fromEvent($resync)->toArray(),
            ],
        );
        self::assertSame('rest_resync_snapshot', $pending->pendingEvent?->payload['origin'] ?? null);
    }

    public function testControlEventsWithoutExplicitFrontierStillAdvanceTheirUniqueControlStreams(): void
    {
        $stopDirectory = $this->datasetDirectory('pending-control-stop');
        $initialStore = new OkxPaperLiveCheckpointStore($stopDirectory);
        $fresh = $initialStore->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        unset($initialStore);
        $streamingState = $fresh->toArray();
        $streamingState['phase'] = 'streaming';
        $streamingState['remaining_symbols'] = [];
        $streamingState['remaining_boundaries'] = [];
        $this->replaceCheckpointState($stopDirectory, $streamingState);
        $stopStore = new OkxPaperLiveCheckpointStore($stopDirectory);
        $fresh = $stopStore->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $stopTransition = [
            'kind' => 'healthy_stop',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/control/connection_state',
            'stage' => 'emit_stopped',
        ];
        $stopState = $fresh->toArray();
        $stopState['phase'] = 'stopping';
        $stopState['remaining_symbols'] = ['BTCUSDT', 'ETHUSDT'];
        $stopState['healthy_stop'] = [
            'requested' => true,
            'remaining_symbols' => ['BTCUSDT', 'ETHUSDT'],
        ];
        $writeAhead = $stopStore->saveTransition(
            OkxPaperLiveCheckpoint::fromArray($stopState),
            'stopping',
            $stopTransition,
        );
        $stopped = $this->stoppedConnectionEvent();
        $pendingStop = $stopStore->savePending(
            $writeAhead,
            $stopped,
            $this->ordinalStateFor($stopped, 'connection|2|stopped'),
            null,
        );
        self::assertNull($pendingStop->pendingFrontier);
        $acknowledgedStop = $stopStore->acknowledge($pendingStop, $stopped->eventId);
        self::assertSame(
            OkxPaperStreamFrontier::fromEvent($stopped)->toArray(),
            $acknowledgedStop->streamFrontiers['BTCUSDT/control/connection_state']?->toArray(),
        );
        unset($stopStore);

        $boundaryDirectory = $this->datasetDirectory('pending-control-boundary');
        $boundaryStore = new OkxPaperLiveCheckpointStore($boundaryDirectory);
        $fresh = $boundaryStore->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $fresh = $this->acknowledgeInitialWarmupRestPrefix($boundaryStore, $fresh, 'BTCUSDT', 6);
        $boundaryTransition = [
            'kind' => 'emit_boundary',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/control/snapshot_boundary',
            'stage' => 'initial',
        ];
        $writeAhead = $boundaryStore->saveTransition($fresh, 'warming', $boundaryTransition);
        $boundary = $this->initialBoundaryEvent();
        $pendingBoundary = $boundaryStore->savePending(
            $writeAhead,
            $boundary,
            $this->advanceOrdinal(
                $writeAhead->ordinalState,
                $boundary,
                'boundary|1|8001|initial',
            ),
            null,
        );
        self::assertNull($pendingBoundary->pendingFrontier);
        $acknowledgedBoundary = $boundaryStore->acknowledge($pendingBoundary, $boundary->eventId);
        self::assertSame(
            OkxPaperStreamFrontier::fromEvent($boundary)->toArray(),
            $acknowledgedBoundary->streamFrontiers['BTCUSDT/control/snapshot_boundary']?->toArray(),
        );
    }

    public function testSavePendingRejectsOrdinalSnapshotWithoutTheExactPendingEvent(): void
    {
        $directory = $this->datasetDirectory('pending-ordinal');
        $store = new OkxPaperLiveCheckpointStore($directory);
        $checkpoint = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $event = $this->tradeEvent('rest_recovery');
        $path = $this->checkpointPath($directory);
        $before = file_get_contents($path);

        try {
            $store->savePending(
                $checkpoint,
                $event,
                ['schema_version' => 1, 'scopes' => []],
                [
                    'stream' => 'BTCUSDT/rest/public_trade',
                    'frontier' => OkxPaperStreamFrontier::fromEvent($event)->toArray(),
                ],
            );
            self::fail('The ordinal snapshot must contain the exact pending event.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }
        self::assertSame($before, file_get_contents($path));
    }

    public function testSavePendingPreservesEveryOrdinalScopeAndAdvancesOnlyThePendingScope(): void
    {
        $directory = $this->datasetDirectory('pending-ordinal-progression');
        $store = new OkxPaperLiveCheckpointStore($directory);
        $current = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);

        $candle = $this->candleEvent('rest_warmup', '2026-07-22T10:00:01.000000Z');
        $ordinal = $this->advanceOrdinal($current->ordinalState, $candle, 'candle|1m|1784714400000');
        $current = $store->acknowledge(
            $store->savePending($current, $candle, $ordinal, [
                'stream' => 'BTCUSDT/rest/candle_1m',
                'frontier' => OkxPaperStreamFrontier::fromEvent($candle)->toArray(),
            ]),
            $candle->eventId,
        );

        $firstTrade = $this->tradeEvent('rest_recovery');
        $ordinal = $this->advanceOrdinal($current->ordinalState, $firstTrade, 'trade|242720721');
        $current = $store->acknowledge(
            $store->savePending($current, $firstTrade, $ordinal, [
                'stream' => 'BTCUSDT/rest/public_trade',
                'frontier' => OkxPaperStreamFrontier::fromEvent($firstTrade)->toArray(),
            ]),
            $firstTrade->eventId,
        );

        $secondTrade = $this->tradeEvent('rest_recovery', false, '242720722', '65000.2', '2');
        $missingCandleScope = $this->advanceOrdinal(
            $current->ordinalState,
            $secondTrade,
            'trade|242720722',
        );
        unset($missingCandleScope['scopes']['okx/BTCUSDT/candle_1m']);
        $path = $this->checkpointPath($directory);
        $before = file_get_contents($path);

        try {
            $store->savePending($current, $secondTrade, $missingCandleScope, [
                'stream' => 'BTCUSDT/rest/public_trade',
                'frontier' => OkxPaperStreamFrontier::fromEvent($secondTrade)->toArray(),
            ]);
            self::fail('A pending ordinal snapshot must not delete another durable scope.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }
        self::assertSame($before, file_get_contents($path));

        $reusedSequence = $this->tradeEvent('rest_recovery', false, '242720722', '65000.2', '1');
        try {
            $store->savePending(
                $current,
                $reusedSequence,
                $this->ordinalStateFor($reusedSequence, 'trade|242720722'),
                [
                    'stream' => 'BTCUSDT/rest/public_trade',
                    'frontier' => OkxPaperStreamFrontier::fromEvent($reusedSequence)->toArray(),
                ],
            );
            self::fail('A pending ordinal snapshot must not reuse a durable sequence.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }
        self::assertSame($before, file_get_contents($path));
    }

    public function testStableControlFrontierDigestExcludesLocallySynthesizedReceiptTime(): void
    {
        $first = OkxPaperStreamFrontier::fromEvent($this->connectionEvent(
            '2026-07-22T10:00:00.000000Z',
        ));
        $retried = OkxPaperStreamFrontier::fromEvent($this->connectionEvent(
            '2026-07-22T10:00:05.000000Z',
        ));

        self::assertSame($first->sourceIdentity, $retried->sourceIdentity);
        self::assertSame($first->naturalIdentity, $retried->naturalIdentity);
        self::assertSame($first->canonicalDigest, $retried->canonicalDigest);
    }

    public function testSaveTransitionAtomicallyPersistsIncrementedAttemptAndOriginalDeadline(): void
    {
        $directory = $this->datasetDirectory('transition-budget');
        $store = new OkxPaperLiveCheckpointStore($directory);
        $current = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $current = $this->completeInitialWarmupAndEnterStreaming($store, $current);
        $saved = $this->startReconnectAttempt(
            $store,
            $current,
            '2026-07-22T10:00:01.000000Z',
        );
        self::assertSame([
            'attempt' => 1,
            'deadline_at' => '2026-07-22T10:00:01.000000Z',
            'stable_since' => null,
            'accepted_events' => 0,
        ], $saved->reconnect);
        unset($store);

        $resumed = (new OkxPaperLiveCheckpointStore($directory))->loadOrCreate(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        );
        self::assertSame([
            'kind' => 'timer_schedule',
            'symbol' => null,
            'stream' => null,
            'stage' => 'reconnect_delay',
        ], $resumed->pendingTransition);
        self::assertSame($saved->reconnect, $resumed->reconnect);
    }

    public function testReconnectAttemptKeepsItsOriginalDeadlineUntilVerifiedStreamingTransition(): void
    {
        $withoutDeadline = OkxPaperLiveCheckpoint::fresh(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        )->toArray();
        $withoutDeadline['reconnect'] = [
            'attempt' => 1,
            'deadline_at' => null,
            'stable_since' => null,
            'accepted_events' => 0,
        ];
        $this->assertCheckpointInvalid($withoutDeadline);

        $directory = $this->datasetDirectory('reconnect-deadline-stability');
        $store = new OkxPaperLiveCheckpointStore($directory);
        $fresh = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $fresh = $this->completeInitialWarmupAndEnterStreaming($store, $fresh);
        $attempt = $this->startReconnectAttempt($store, $fresh);
        $path = $this->checkpointPath($directory);
        $before = file_get_contents($path);

        $ordinaryStability = $attempt->toArray();
        $ordinaryStability['phase'] = 'streaming';
        $ordinaryStability['pending_transition'] = null;
        $ordinaryStability['reconnect'] = [
            'attempt' => 1,
            'deadline_at' => null,
            'stable_since' => '2026-07-22T10:00:01.000000Z',
            'accepted_events' => 0,
        ];
        try {
            $store->save(OkxPaperLiveCheckpoint::fromArray($ordinaryStability));
            self::fail('Only a verified streaming transition may close a reconnect deadline.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }
        self::assertSame($before, file_get_contents($path));
    }

    public function testStaleSaveCannotResetOverlapPaginationBudgetOrTypeOneCursor(): void
    {
        $directory = $this->datasetDirectory('stale-pagination');
        $store = new OkxPaperLiveCheckpointStore($directory);
        $current = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $current = $this->completeInitialWarmupAndEnterStreaming($store, $current);
        $event = $this->tradeEvent('rest_recovery', sequence: '2');
        $current = $this->acknowledgeEventForTest(
            $store,
            $current,
            $event,
            'trade|242720721',
            'BTCUSDT/rest/public_trade',
        );
        $current = $this->startReconnectAttempt($store, $current);
        $current = $this->advanceReconnectToBusinessSubscription($store, $current);
        $frontier = OkxPaperStreamFrontier::fromEvent($event)->toArray();
        $resync = [
            'attempt' => 1,
            'frontier' => $frontier,
            'source_sequence' => null,
            'deadline_at' => '2026-07-22T10:00:10.000000Z',
            'policy' => 'frontier_overlap_v1',
        ];
        $pagination = [
            'endpoint' => 'history_trades',
            'pagination_type' => 2,
            'next_cursor' => '1784714400000',
            'pages_consumed' => 0,
            'pages_remaining' => 10,
            'target_frontier' => $frontier,
            'deadline_at' => '2026-07-22T10:00:10.000000Z',
        ];
        $pageZero = $this->startReconnectFrontierRecovery(
            $store,
            $current,
            'BTCUSDT/rest/public_trade',
            $resync,
            $pagination,
        );

        $pageOneState = $pageZero->toArray();
        $pageOneState['overlap_pagination_by_stream']['BTCUSDT/rest/public_trade'] = [
            'endpoint' => 'history_trades',
            'pagination_type' => 1,
            'next_cursor' => '242720699',
            'pages_consumed' => 1,
            'pages_remaining' => 9,
            'target_frontier' => $frontier,
            'deadline_at' => '2026-07-22T10:00:10.000000Z',
        ];
        $pageOne = $store->saveTransition(
            OkxPaperLiveCheckpoint::fromArray($pageOneState),
            'reconnecting',
            $pageZero->pendingTransition,
        );
        $path = $this->checkpointPath($directory);
        $before = file_get_contents($path);

        try {
            $store->save($pageZero);
            self::fail('A stale save must not restore consumed pagination budget.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }
        self::assertSame($before, file_get_contents($path));
        self::assertSame(
            1,
            $pageOne->overlapPaginationByStream['BTCUSDT/rest/public_trade']['pages_consumed'] ?? null,
        );
    }

    public function testOrdinarySaveCannotClearAnyActiveRecoveryBudget(): void
    {
        $directory = $this->datasetDirectory('active-budget-clear');
        $store = new OkxPaperLiveCheckpointStore($directory);
        $fresh = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $fresh = $this->completeInitialWarmupAndEnterStreaming($store, $fresh);
        $event = $this->tradeEvent('rest_recovery', sequence: '2');
        $fresh = $this->acknowledgeEventForTest(
            $store,
            $fresh,
            $event,
            'trade|242720721',
            'BTCUSDT/rest/public_trade',
        );
        $fresh = $this->startReconnectAttempt($store, $fresh);
        $fresh = $this->advanceReconnectToBusinessSubscription($store, $fresh);
        $frontier = OkxPaperStreamFrontier::fromEvent($event)->toArray();
        $deadline = '2026-07-22T10:00:10.000000Z';
        $resync = [
            'attempt' => 1,
            'frontier' => $frontier,
            'source_sequence' => null,
            'deadline_at' => $deadline,
            'policy' => 'frontier_overlap_v1',
        ];
        $pagination = [
            'endpoint' => 'history_trades',
            'pagination_type' => 2,
            'next_cursor' => '1784714400000',
            'pages_consumed' => 0,
            'pages_remaining' => 10,
            'target_frontier' => $frontier,
            'deadline_at' => $deadline,
        ];
        $active = $this->startReconnectFrontierRecovery(
            $store,
            $fresh,
            'BTCUSDT/rest/public_trade',
            $resync,
            $pagination,
        );
        $path = $this->checkpointPath($directory);
        $before = file_get_contents($path);

        $clearedRecovery = $active->toArray();
        $clearedRecovery['pending_transition'] = null;
        $clearedRecovery['resync_by_symbol']['BTCUSDT'] = null;
        $clearedRecovery['overlap_pagination_by_stream']['BTCUSDT/rest/public_trade'] = null;
        try {
            $store->save(OkxPaperLiveCheckpoint::fromArray($clearedRecovery));
            self::fail('An ordinary save must not clear resync or pagination budgets.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }
        self::assertSame($before, file_get_contents($path));

        $resetReconnect = $active->toArray();
        $resetReconnect['reconnect'] = [
            'attempt' => 2,
            'deadline_at' => $deadline,
            'stable_since' => null,
            'accepted_events' => 0,
        ];
        try {
            $store->save(OkxPaperLiveCheckpoint::fromArray($resetReconnect));
            self::fail('An ordinary save must not recreate the reconnect budget.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }
        self::assertSame($before, file_get_contents($path));
    }

    public function testExactOverlapWithoutALaterRowClosesRecoveryThroughADurableTransition(): void
    {
        $directory = $this->datasetDirectory('overlap-without-later-row');
        $store = new OkxPaperLiveCheckpointStore($directory);
        $fresh = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $fresh = $this->completeInitialWarmupAndEnterStreaming($store, $fresh);
        $nextStreamEvent = $this->candleEvent(
            'ws_candle',
            '2026-07-22T10:00:02.000000Z',
            sequence: '2',
        );
        $fresh = $this->acknowledgeEventForTest(
            $store,
            $fresh,
            $nextStreamEvent,
            OkxPaperStreamFrontier::fromEvent($nextStreamEvent)->naturalIdentity,
            'BTCUSDT/ws/candle_1m',
        );
        $event = $this->tradeEvent('rest_recovery', sequence: '2');
        $acknowledged = $this->acknowledgeEventForTest(
            $store,
            $fresh,
            $event,
            'trade|242720721',
            'BTCUSDT/rest/public_trade',
        );
        $acknowledged = $this->startReconnectAttempt($store, $acknowledged);
        $acknowledged = $this->advanceReconnectToBusinessSubscription($store, $acknowledged);
        $frontier = OkxPaperStreamFrontier::fromEvent($event)->toArray();
        $deadline = '2026-07-22T10:00:10.000000Z';
        $resync = [
            'attempt' => 1,
            'frontier' => $frontier,
            'source_sequence' => null,
            'deadline_at' => $deadline,
            'policy' => 'frontier_overlap_v1',
        ];
        $pagination = [
            'endpoint' => 'history_trades',
            'pagination_type' => 2,
            'next_cursor' => '1784714400000',
            'pages_consumed' => 0,
            'pages_remaining' => 10,
            'target_frontier' => $frontier,
            'deadline_at' => $deadline,
        ];
        $active = $this->startReconnectFrontierRecovery(
            $store,
            $acknowledged,
            'BTCUSDT/rest/public_trade',
            $resync,
            $pagination,
        );

        $nextTransition = [
            'kind' => 'rest_fetch',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/ws/candle_1m',
            'stage' => 'current_candles',
        ];
        $closedState = $active->toArray();
        $closedState['pending_transition'] = $nextTransition;
        $closedState['resync_by_symbol']['BTCUSDT'] = null;
        $closedState['overlap_pagination_by_stream']['BTCUSDT/rest/public_trade'] = null;
        try {
            $closed = $store->saveTransition(
                OkxPaperLiveCheckpoint::fromArray($closedState),
                'reconnecting',
                $nextTransition,
            );
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::fail('An exact overlap with no later row must close through its next durable transition: ' . $exception->getMessage());
        }

        self::assertNull($closed->resyncBySymbol['BTCUSDT']);
        self::assertNull($closed->overlapPaginationByStream['BTCUSDT/rest/public_trade']);
        self::assertSame($nextTransition, $closed->pendingTransition);
        unset($store);

        $resumed = (new OkxPaperLiveCheckpointStore($directory))->loadOrCreate(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        );
        self::assertSame(
            CanonicalJson::encode($closed->toArray()),
            CanonicalJson::encode($resumed->toArray()),
        );
    }

    public function testHeadOverlapClosureAdvancesBeforeDeferredEthAndRestartKeepsContinuation(): void
    {
        $directory = $this->datasetDirectory('head-overlap-before-deferred-eth');
        $store = new OkxPaperLiveCheckpointStore($directory);
        $checkpoint = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $checkpoint = $this->completeInitialWarmupAndEnterStreaming($store, $checkpoint);
        foreach (['BTCUSDT', 'ETHUSDT'] as $symbol) {
            $book = $this->bookEvent('ws_books', '9001', $symbol, 1, '2');
            $checkpoint = $this->acknowledgeEventForTest(
                $store,
                $checkpoint,
                $book,
                'book|9001',
                $symbol . '/ws/top_of_book',
            );
        }

        $checkpoint = $this->startReconnectAttempt($store, $checkpoint);
        $checkpoint = $this->advanceReconnectToBusinessSubscription($store, $checkpoint);
        $checkpoint = $this->closeReconnectMarketStreams(
            $store,
            $checkpoint,
            'BTCUSDT',
            '2026-07-22T10:00:10.000000Z',
        );
        $checkpoint = $this->acknowledgeReconnectBookAndBoundary($store, $checkpoint, 'BTCUSDT');
        $checkpoint = $this->openReconnectTradeHistoryPagination(
            $store,
            $checkpoint,
            'ETHUSDT',
            '2026-07-22T10:00:10.000000Z',
        );
        $ethPagination = $checkpoint->toArray()['overlap_pagination_by_stream'][
            'ETHUSDT/rest/public_trade'
        ];
        self::assertNotNull($ethPagination);

        $checkpoint = $this->startReconnectAttempt(
            $store,
            $checkpoint,
            '2026-07-22T10:00:20.000000Z',
        );
        $checkpoint = $this->advanceReconnectToBusinessSubscription($store, $checkpoint);
        $checkpoint = $this->closeReconnectMarketStreams(
            $store,
            $checkpoint,
            'BTCUSDT',
            '2026-07-22T10:00:20.000000Z',
            paginatePublicTrade: true,
        );
        $expectedContinuation = [
            'kind' => 'rest_fetch',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/rest/top_of_book',
            'stage' => 'order_book',
        ];

        self::assertSame($expectedContinuation, $checkpoint->pendingTransition);
        self::assertSame(
            $ethPagination,
            $checkpoint->toArray()['overlap_pagination_by_stream'][
                'ETHUSDT/rest/public_trade'
            ],
        );
        $durableBytes = file_get_contents($this->checkpointPath($directory));
        unset($store);

        $store = new OkxPaperLiveCheckpointStore($directory);
        $checkpoint = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        self::assertSame($durableBytes, file_get_contents($this->checkpointPath($directory)));
        self::assertSame($expectedContinuation, $checkpoint->pendingTransition);

        $checkpoint = $this->acknowledgeReconnectBookAndBoundary($store, $checkpoint, 'BTCUSDT');
        self::assertSame(
            [
                'kind' => 'rest_fetch',
                'symbol' => 'ETHUSDT',
                'stream' => 'ETHUSDT/rest/public_trade',
                'stage' => 'history_trades',
            ],
            $checkpoint->pendingTransition,
            'BTC recovery must reach its boundary before the deferred ETH pagination becomes executable.',
        );
        self::assertNull($checkpoint->resyncBySymbol['BTCUSDT']);
    }

    public function testLoadRejectsCanonicalDeferredTransitionAheadOfReconnectHeadWithoutWriting(): void
    {
        $directory = $this->datasetDirectory('load-rejects-deferred-transition-ahead-of-head');
        $store = new OkxPaperLiveCheckpointStore($directory);
        $checkpoint = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $frontier = OkxPaperStreamFrontier::fromEvent(
            $this->tradeEvent('rest_recovery', symbol: 'ETHUSDT'),
        )->toArray();
        $deadline = '2026-07-22T10:00:10.000000Z';
        $state = $checkpoint->toArray();
        $state['phase'] = 'reconnecting';
        $state['pending_transition'] = [
            'kind' => 'rest_fetch',
            'symbol' => 'ETHUSDT',
            'stream' => 'ETHUSDT/rest/public_trade',
            'stage' => 'history_trades',
        ];
        $state['stream_frontiers']['ETHUSDT/rest/public_trade'] = $frontier;
        $state['resync_by_symbol']['ETHUSDT'] = [
            'attempt' => 1,
            'frontier' => $frontier,
            'source_sequence' => null,
            'deadline_at' => $deadline,
            'policy' => 'frontier_overlap_v1',
        ];
        $state['overlap_pagination_by_stream']['ETHUSDT/rest/public_trade'] = [
            'endpoint' => 'history_trades',
            'pagination_type' => 2,
            'next_cursor' => '1784714400000',
            'pages_consumed' => 0,
            'pages_remaining' => 10,
            'target_frontier' => $frontier,
            'deadline_at' => $deadline,
        ];
        unset($store);
        $this->replaceCheckpointState($directory, $state);
        $path = $this->checkpointPath($directory);
        $before = file_get_contents($path);

        try {
            (new OkxPaperLiveCheckpointStore($directory))->loadOrCreate(
                self::DATASET_ID,
                self::CONFIGURATION_SHA256,
            );
            self::fail('A deferred ETH transition must not be resumable while BTC is the work head.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }
        self::assertSame($before, file_get_contents($path));
    }

    public function testLoadRejectsReconnectWorkWithoutTransitionOrPendingEventWithoutWriting(): void
    {
        $directory = $this->datasetDirectory('load-rejects-null-active-reconnect');
        $store = new OkxPaperLiveCheckpointStore($directory);
        $checkpoint = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $frontier = OkxPaperStreamFrontier::fromEvent($this->tradeEvent('rest_recovery'))->toArray();
        $deadline = '2026-07-22T10:00:10.000000Z';
        $state = $checkpoint->toArray();
        $state['phase'] = 'reconnecting';
        $state['pending_transition'] = null;
        $state['stream_frontiers']['BTCUSDT/rest/public_trade'] = $frontier;
        $state['resync_by_symbol']['BTCUSDT'] = [
            'attempt' => 1,
            'frontier' => $frontier,
            'source_sequence' => null,
            'deadline_at' => $deadline,
            'policy' => 'frontier_overlap_v1',
        ];
        $state['overlap_pagination_by_stream']['BTCUSDT/rest/public_trade'] = [
            'endpoint' => 'history_trades',
            'pagination_type' => 2,
            'next_cursor' => '1784714400000',
            'pages_consumed' => 0,
            'pages_remaining' => 10,
            'target_frontier' => $frontier,
            'deadline_at' => $deadline,
        ];
        unset($store);
        $this->replaceCheckpointState($directory, $state);
        $path = $this->checkpointPath($directory);
        $before = file_get_contents($path);

        try {
            (new OkxPaperLiveCheckpointStore($directory))->loadOrCreate(
                self::DATASET_ID,
                self::CONFIGURATION_SHA256,
            );
            self::fail('Active reconnect work must never resume without a durable continuation.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }
        self::assertSame($before, file_get_contents($path));
    }

    public function testNewOverlapRowAcknowledgementClosesOnlyItsExactFrontierRecovery(): void
    {
        foreach ([false, true] as $otherPaginationRemains) {
            $suffix = $otherPaginationRemains ? 'with-sibling' : 'without-sibling';
            $directory = $this->datasetDirectory('overlap-new-row-acknowledgement-' . $suffix);
            $store = new OkxPaperLiveCheckpointStore($directory);
            $fresh = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
            $targetEvent = $this->tradeEvent('rest_recovery', sequence: '2');
            $target = OkxPaperStreamFrontier::fromEvent($targetEvent)->toArray();
            if ($otherPaginationRemains) {
                $seededState = $fresh->toArray();
                $seededState['stream_frontiers']['BTCUSDT/ws/public_trade'] = $target;
                unset($store);
                $this->replaceCheckpointState($directory, $seededState);
                $store = new OkxPaperLiveCheckpointStore($directory);
                $fresh = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
            }
            $fresh = $this->completeInitialWarmupAndEnterStreaming($store, $fresh);
            $nextStreamEvent = $this->candleEvent(
                'ws_candle',
                '2026-07-22T10:00:02.000000Z',
                sequence: '2',
            );
            $fresh = $this->acknowledgeEventForTest(
                $store,
                $fresh,
                $nextStreamEvent,
                OkxPaperStreamFrontier::fromEvent($nextStreamEvent)->naturalIdentity,
                'BTCUSDT/ws/candle_1m',
            );
            $acknowledged = $this->acknowledgeEventForTest(
                $store,
                $fresh,
                $targetEvent,
                'trade|242720721',
                'BTCUSDT/rest/public_trade',
            );
            $acknowledged = $this->startReconnectAttempt($store, $acknowledged);
            $acknowledged = $this->advanceReconnectToBusinessSubscription($store, $acknowledged);
            $deadline = '2026-07-22T10:00:10.000000Z';
            $history = [
                'kind' => 'rest_fetch',
                'symbol' => 'BTCUSDT',
                'stream' => 'BTCUSDT/rest/public_trade',
                'stage' => 'history_trades',
            ];
            $pagination = [
                'endpoint' => 'history_trades',
                'pagination_type' => 2,
                'next_cursor' => '1784714400000',
                'pages_consumed' => 0,
                'pages_remaining' => 10,
                'target_frontier' => $target,
                'deadline_at' => $deadline,
            ];
            $activeState = $acknowledged->toArray();
            $activeState['phase'] = 'reconnecting';
            $activeState['pending_transition'] = $history;
            $activeState['resync_by_symbol']['BTCUSDT'] = [
                'attempt' => 1,
                'frontier' => $target,
                'source_sequence' => null,
                'deadline_at' => $deadline,
                'policy' => 'frontier_overlap_v1',
            ];
            $activeState['overlap_pagination_by_stream']['BTCUSDT/rest/public_trade'] = $pagination;
            if ($otherPaginationRemains) {
                $activeState['overlap_pagination_by_stream']['BTCUSDT/ws/public_trade'] = $pagination;
                unset($store);
                $this->replaceCheckpointState($directory, $activeState);
                $store = new OkxPaperLiveCheckpointStore($directory);
                $active = $store->loadOrCreate(
                    self::DATASET_ID,
                    self::CONFIGURATION_SHA256,
                );
                $active = $store->saveTransition(
                    $active,
                    'reconnecting',
                    $history,
                );
            } else {
                $active = $this->startReconnectFrontierRecovery(
                    $store,
                    $acknowledged,
                    'BTCUSDT/rest/public_trade',
                    $activeState['resync_by_symbol']['BTCUSDT'],
                    $pagination,
                );
            }
            $later = $this->tradeEvent(
                'rest_recovery',
                false,
                '242720722',
                '65000.2',
                '3',
            );
            $pending = $store->savePending(
                $active,
                $later,
                $this->advanceOrdinal($active->ordinalState, $later, 'trade|242720722'),
                [
                    'stream' => 'BTCUSDT/rest/public_trade',
                    'frontier' => OkxPaperStreamFrontier::fromEvent($later)->toArray(),
                ],
            );

            $closed = $store->acknowledge($pending, $later->eventId);
            $expectedNext = $otherPaginationRemains ? [
                'kind' => 'rest_fetch',
                'symbol' => 'BTCUSDT',
                'stream' => 'BTCUSDT/ws/public_trade',
                'stage' => 'history_trades',
            ] : [
                'kind' => 'rest_fetch',
                'symbol' => 'BTCUSDT',
                'stream' => 'BTCUSDT/ws/candle_1m',
                'stage' => 'current_candles',
            ];

            self::assertSame('242720722', $closed->streamFrontiers[
                'BTCUSDT/rest/public_trade'
            ]?->sourceIdentity);
            self::assertNull($closed->overlapPaginationByStream['BTCUSDT/rest/public_trade']);
            self::assertSame(
                $otherPaginationRemains ? $pagination : null,
                $closed->toArray()['overlap_pagination_by_stream']['BTCUSDT/ws/public_trade'],
            );
            self::assertSame(
                $otherPaginationRemains ? $activeState['resync_by_symbol']['BTCUSDT'] : null,
                $closed->toArray()['resync_by_symbol']['BTCUSDT'],
            );
            self::assertSame(
                $expectedNext,
                $closed->pendingTransition,
                'The acknowledgement must atomically install the exact next reconnect work.',
            );
            $path = $this->checkpointPath($directory);
            $closedBytes = file_get_contents($path);
            unset($store);

            $store = new OkxPaperLiveCheckpointStore($directory);
            $resumed = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
            self::assertSame($closedBytes, file_get_contents($path));
            self::assertSame($expectedNext, $resumed->pendingTransition);

            $arbitraryRest = [
                'kind' => 'rest_fetch',
                'symbol' => 'BTCUSDT',
                'stream' => 'BTCUSDT/rest/candle_15m',
                'stage' => 'current_candles',
            ];
            $candidateState = $resumed->toArray();
            $candidateState['pending_transition'] = $arbitraryRest;
            $beforeRejectedRest = file_get_contents($path);
            try {
                $store->saveTransition(
                    OkxPaperLiveCheckpoint::fromArray($candidateState),
                    'reconnecting',
                    $arbitraryRest,
                );
                self::fail(
                    'ACK_OVERLAP_TO_ARBITRARY_REST_WORK: acknowledgement must persist its deterministic successor.',
                );
            } catch (OkxPaperLiveIntegrityException|\InvalidArgumentException $exception) {
                self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
            }
            self::assertSame($beforeRejectedRest, file_get_contents($path));
            unset($store);
        }
    }

    public function testAcknowledgingBtcRecoveryCannotPersistDeferredEthPaginationAheadOfWorkHead(): void
    {
        [$directory, $store, $active, $later] =
            $this->reconnectingBtcTradePaginationReadyForAcknowledgement(
                'acknowledge-btc-before-deferred-eth',
                true,
            );
        $pending = $store->savePending(
            $active,
            $later,
            $this->advanceOrdinal($active->ordinalState, $later, 'trade|242720722'),
            [
                'stream' => 'BTCUSDT/rest/public_trade',
                'frontier' => OkxPaperStreamFrontier::fromEvent($later)->toArray(),
            ],
        );

        $acknowledged = $store->acknowledge($pending, $later->eventId);
        $expectedBtcContinuation = [
            'kind' => 'rest_fetch',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/ws/candle_1m',
            'stage' => 'current_candles',
        ];

        self::assertNotNull(
            $acknowledged->overlapPaginationByStream['ETHUSDT/rest/public_trade'],
        );
        self::assertSame($expectedBtcContinuation, $acknowledged->pendingTransition);
        $durableBytes = file_get_contents($this->checkpointPath($directory));
        unset($store);

        $resumed = (new OkxPaperLiveCheckpointStore($directory))->loadOrCreate(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        );
        self::assertSame($durableBytes, file_get_contents($this->checkpointPath($directory)));
        self::assertNotNull($resumed->overlapPaginationByStream['ETHUSDT/rest/public_trade']);
        self::assertSame($expectedBtcContinuation, $resumed->pendingTransition);
    }

    public function testAcknowledgingLastReconnectPaginationSelectsDeterministicHeadRecovery(): void
    {
        [$directory, $store, $active, $later] =
            $this->reconnectingBtcTradePaginationReadyForAcknowledgement(
                'acknowledge-last-reconnect-pagination',
                false,
            );
        $pending = $store->savePending(
            $active,
            $later,
            $this->advanceOrdinal($active->ordinalState, $later, 'trade|242720722'),
            [
                'stream' => 'BTCUSDT/rest/public_trade',
                'frontier' => OkxPaperStreamFrontier::fromEvent($later)->toArray(),
            ],
        );

        $acknowledged = $store->acknowledge($pending, $later->eventId);
        $expectedBtcContinuation = [
            'kind' => 'rest_fetch',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/ws/candle_1m',
            'stage' => 'current_candles',
        ];

        self::assertNull(
            $acknowledged->overlapPaginationByStream['BTCUSDT/rest/public_trade'],
        );
        self::assertSame($expectedBtcContinuation, $acknowledged->pendingTransition);
        $durableBytes = file_get_contents($this->checkpointPath($directory));
        unset($store);

        $resumed = (new OkxPaperLiveCheckpointStore($directory))->loadOrCreate(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        );
        self::assertSame($durableBytes, file_get_contents($this->checkpointPath($directory)));
        self::assertSame($expectedBtcContinuation, $resumed->pendingTransition);
    }

    public function testBookRecoveryClosesOnlyAfterSnapshotAndBoundaryAcknowledgements(): void
    {
        $directory = $this->datasetDirectory('book-recovery-acknowledgements');
        $initialStore = new OkxPaperLiveCheckpointStore($directory);
        $fresh = $initialStore->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        unset($initialStore);
        $wsFrontier = OkxPaperStreamFrontier::fromEvent($this->bookEvent())->toArray();
        $state = $fresh->toArray();
        $state['phase'] = 'resyncing';
        $state['remaining_symbols'] = ['BTCUSDT'];
        $state['remaining_boundaries'] = [
            ['symbol' => 'BTCUSDT', 'reason' => 'sequence_gap'],
        ];
        $state['source_epochs']['BTCUSDT'] = 2;
        $state['stream_frontiers']['BTCUSDT/rest/top_of_book'] = OkxPaperStreamFrontier::fromEvent(
            $this->bookEvent('rest_initial_snapshot', '8001', sourceEpoch: 1),
        )->toArray();
        $state['stream_frontiers']['BTCUSDT/ws/top_of_book'] = $wsFrontier;
        $state['stream_frontiers']['BTCUSDT/control/snapshot_boundary'] =
            OkxPaperStreamFrontier::fromEvent($this->initialBoundaryEvent())->toArray();
        $state['resync_by_symbol']['BTCUSDT'] = [
            'attempt' => 1,
            'frontier' => $wsFrontier,
            'source_sequence' => '9001',
            'deadline_at' => '2026-07-22T10:00:10.000000Z',
            'policy' => 'book_seq_overlap_v1',
        ];
        $this->replaceCheckpointState($directory, $state);

        $store = new OkxPaperLiveCheckpointStore($directory);
        $checkpoint = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $snapshotTransition = [
            'kind' => 'rest_fetch',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/rest/top_of_book',
            'stage' => 'order_book',
        ];
        $checkpoint = $store->saveTransition($checkpoint, 'resyncing', $snapshotTransition);
        $snapshot = $this->bookEvent('rest_resync_snapshot', '9002');
        $pendingSnapshot = $store->savePending(
            $checkpoint,
            $snapshot,
            $this->advanceOrdinal($checkpoint->ordinalState, $snapshot, 'book|9002'),
            [
                'stream' => 'BTCUSDT/rest/top_of_book',
                'frontier' => OkxPaperStreamFrontier::fromEvent($snapshot)->toArray(),
            ],
        );
        $checkpoint = $store->acknowledge($pendingSnapshot, $snapshot->eventId);
        self::assertNotNull($checkpoint->resyncBySymbol['BTCUSDT']);

        $boundaryTransition = [
            'kind' => 'emit_boundary',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/control/snapshot_boundary',
            'stage' => 'sequence_gap',
        ];
        $checkpoint = $store->saveTransition($checkpoint, 'resyncing', $boundaryTransition);
        $boundary = $this->sequenceGapBoundaryEvent('9002');
        $pendingBoundary = $store->savePending(
            $checkpoint,
            $boundary,
            $this->advanceOrdinal(
                $checkpoint->ordinalState,
                $boundary,
                'boundary|2|9002|sequence_gap',
            ),
            null,
        );
        $closed = $store->acknowledge($pendingBoundary, $boundary->eventId);

        self::assertNull($closed->resyncBySymbol['BTCUSDT']);
        self::assertSame([], $closed->remainingSymbols);
        self::assertSame([], $closed->remainingBoundaries);
        self::assertSame('streaming', $closed->phase);
    }

    public function testSaveAndSaveTransitionCannotModifyRemainingWorkOutsideAcknowledgement(): void
    {
        $saveDirectory = $this->datasetDirectory('save-remaining-work');
        $saveStore = new OkxPaperLiveCheckpointStore($saveDirectory);
        $fresh = $saveStore->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $withoutBtc = $fresh->toArray();
        $withoutBtc['remaining_symbols'] = ['ETHUSDT'];
        $path = $this->checkpointPath($saveDirectory);
        $before = file_get_contents($path);
        try {
            $saveStore->save(OkxPaperLiveCheckpoint::fromArray($withoutBtc));
            self::fail('save() must not remove remaining symbols outside an acknowledgement.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }
        self::assertSame($before, file_get_contents($path));
        unset($saveStore);

        $transitionDirectory = $this->datasetDirectory('transition-remaining-work');
        $transitionStore = new OkxPaperLiveCheckpointStore($transitionDirectory);
        $fresh = $transitionStore->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $withoutBoundary = $fresh->toArray();
        $withoutBoundary['remaining_boundaries'] = [
            ['symbol' => 'ETHUSDT', 'reason' => 'initial'],
        ];
        $candidate = OkxPaperLiveCheckpoint::fromArray($withoutBoundary);
        $path = $this->checkpointPath($transitionDirectory);
        $before = file_get_contents($path);
        try {
            $transitionStore->saveTransition($candidate, 'connecting', [
                'kind' => 'transport_connect',
                'symbol' => null,
                'stream' => 'public',
                'stage' => 'connect',
            ]);
            self::fail('saveTransition() must not remove boundaries outside an acknowledgement.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }
        self::assertSame($before, file_get_contents($path));
    }

    public function testTradePaginationUsesTimestampThenStrictlyDecreasingTradeIds(): void
    {
        $base = OkxPaperLiveCheckpoint::fresh(self::DATASET_ID, self::CONFIGURATION_SHA256)->toArray();
        $frontier = OkxPaperStreamFrontier::fromEvent($this->tradeEvent('rest_recovery'))->toArray();
        $deadline = '2026-07-22T10:00:10.000000Z';
        $base['phase'] = 'reconnecting';
        $base['pending_transition'] = [
            'kind' => 'rest_fetch',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/rest/public_trade',
            'stage' => 'history_trades',
        ];
        $resync = [
            'attempt' => 1,
            'frontier' => $frontier,
            'source_sequence' => null,
            'deadline_at' => $deadline,
            'policy' => 'frontier_overlap_v1',
        ];
        $base['resync_by_symbol']['BTCUSDT'] = $resync;
        $base['stream_frontiers']['BTCUSDT/rest/public_trade'] = $frontier;
        $page = [
            'endpoint' => 'history_trades',
            'pagination_type' => 2,
            'next_cursor' => '242720700',
            'pages_consumed' => 0,
            'pages_remaining' => 10,
            'target_frontier' => $frontier,
            'deadline_at' => $deadline,
        ];
        $base['overlap_pagination_by_stream']['BTCUSDT/rest/public_trade'] = $page;
        $this->assertCheckpointInvalid($base);
        $base['overlap_pagination_by_stream']['BTCUSDT/rest/public_trade']['next_cursor'] = null;
        $this->assertCheckpointInvalid($base);

        $acceptedCursors = [];
        foreach (['242720700', '242720701'] as $nextCursor) {
            $directory = $this->datasetDirectory('trade-cursor-' . $nextCursor);
            $store = new OkxPaperLiveCheckpointStore($directory);
            $fresh = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
            $fresh = $this->completeInitialWarmupAndEnterStreaming($store, $fresh);
            $event = $this->tradeEvent('rest_recovery', sequence: '2');
            $fresh = $this->acknowledgeEventForTest(
                $store,
                $fresh,
                $event,
                'trade|242720721',
                'BTCUSDT/rest/public_trade',
            );
            $fresh = $this->startReconnectAttempt($store, $fresh);
            $fresh = $this->advanceReconnectToBusinessSubscription($store, $fresh);
            $pageZeroPagination = array_replace($page, [
                'next_cursor' => '1784714400000',
            ]);
            $pageZero = $this->startReconnectFrontierRecovery(
                $store,
                $fresh,
                'BTCUSDT/rest/public_trade',
                $resync,
                $pageZeroPagination,
            );
            $pageOneState = $pageZero->toArray();
            $pageOneState['overlap_pagination_by_stream']['BTCUSDT/rest/public_trade'] = array_replace(
                $page,
                [
                    'pagination_type' => 1,
                    'next_cursor' => '242720700',
                    'pages_consumed' => 1,
                    'pages_remaining' => 9,
                ],
            );
            $pageOne = $store->saveTransition(
                OkxPaperLiveCheckpoint::fromArray($pageOneState),
                'reconnecting',
                $pageZero->pendingTransition,
            );
            $nextState = $pageOne->toArray();
            $nextState['overlap_pagination_by_stream']['BTCUSDT/rest/public_trade']['next_cursor'] = $nextCursor;
            $nextState['overlap_pagination_by_stream']['BTCUSDT/rest/public_trade']['pages_consumed'] = 2;
            $nextState['overlap_pagination_by_stream']['BTCUSDT/rest/public_trade']['pages_remaining'] = 8;
            try {
                $store->saveTransition(
                    OkxPaperLiveCheckpoint::fromArray($nextState),
                    'reconnecting',
                    $pageZero->pendingTransition,
                );
                $acceptedCursors[] = $nextCursor;
            } catch (OkxPaperLiveIntegrityException $exception) {
                self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
            }
            unset($store);
        }

        self::assertSame([], $acceptedCursors);
    }

    public function testCandlePaginationCursorStrictlyDecreasesOnEveryConsumedPage(): void
    {
        $acceptedCursors = [];
        foreach (['1784714399000', '1784714400000'] as $nextCursor) {
            $directory = $this->datasetDirectory('candle-cursor-' . $nextCursor);
            $store = new OkxPaperLiveCheckpointStore($directory);
            $fresh = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
            $fresh = $this->completeInitialWarmupAndEnterStreaming($store, $fresh);
            $frontier = OkxPaperStreamFrontier::fromEvent($this->candleEvent(
                'rest_warmup',
                '2026-07-22T10:00:01.000000Z',
            ))->toArray();
            $candle = $this->candleEvent(
                'rest_warmup',
                '2026-07-22T10:00:01.000000Z',
                sequence: '2',
            );
            $fresh = $this->acknowledgeEventForTest(
                $store,
                $fresh,
                $candle,
                'candle|1m|1784714400000',
                'BTCUSDT/rest/candle_1m',
            );
            $fresh = $this->startReconnectAttempt($store, $fresh);
            $fresh = $this->advanceReconnectToBusinessSubscription($store, $fresh);
            $deadline = '2026-07-22T10:00:10.000000Z';
            $resync = [
                'attempt' => 1,
                'frontier' => $frontier,
                'source_sequence' => null,
                'deadline_at' => $deadline,
                'policy' => 'frontier_overlap_v1',
            ];
            $pagination = [
                'endpoint' => 'history_candles',
                'pagination_type' => null,
                'next_cursor' => '1784714400000',
                'pages_consumed' => 0,
                'pages_remaining' => 10,
                'target_frontier' => $frontier,
                'deadline_at' => $deadline,
            ];
            $pageZero = $this->startReconnectFrontierRecovery(
                $store,
                $fresh,
                'BTCUSDT/rest/candle_1m',
                $resync,
                $pagination,
            );
            $pageOneState = $pageZero->toArray();
            $pageOneState['overlap_pagination_by_stream']['BTCUSDT/rest/candle_1m'][
                'next_cursor'
            ] = '1784714399000';
            $pageOneState['overlap_pagination_by_stream']['BTCUSDT/rest/candle_1m'][
                'pages_consumed'
            ] = 1;
            $pageOneState['overlap_pagination_by_stream']['BTCUSDT/rest/candle_1m'][
                'pages_remaining'
            ] = 9;
            $pageOne = $store->saveTransition(
                OkxPaperLiveCheckpoint::fromArray($pageOneState),
                'reconnecting',
                $pageZero->pendingTransition,
            );
            $nextState = $pageOne->toArray();
            $nextState['overlap_pagination_by_stream']['BTCUSDT/rest/candle_1m'][
                'next_cursor'
            ] = $nextCursor;
            $nextState['overlap_pagination_by_stream']['BTCUSDT/rest/candle_1m'][
                'pages_consumed'
            ] = 2;
            $nextState['overlap_pagination_by_stream']['BTCUSDT/rest/candle_1m'][
                'pages_remaining'
            ] = 8;
            try {
                $store->saveTransition(
                    OkxPaperLiveCheckpoint::fromArray($nextState),
                    'reconnecting',
                    $pageZero->pendingTransition,
                );
                $acceptedCursors[] = $nextCursor;
            } catch (OkxPaperLiveIntegrityException $exception) {
                self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
            }
            unset($store);
        }

        self::assertSame([], $acceptedCursors);
    }

    public function testRecoveryContinuationMustMatchItsAttemptPaginationTargetAndDeadline(): void
    {
        $base = OkxPaperLiveCheckpoint::fresh(self::DATASET_ID, self::CONFIGURATION_SHA256)->toArray();
        $history = [
            'kind' => 'rest_fetch',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/rest/public_trade',
            'stage' => 'history_trades',
        ];
        $state = $base;
        $state['phase'] = 'reconnecting';
        $state['pending_transition'] = $history;
        $this->assertCheckpointInvalid($state);

        $event = $this->tradeEvent('rest_recovery');
        $frontier = OkxPaperStreamFrontier::fromEvent($event)->toArray();
        $state = $base;
        $state['phase'] = 'reconnecting';
        $state['resync_by_symbol']['BTCUSDT'] = [
            'attempt' => 1,
            'frontier' => $frontier,
            'source_sequence' => null,
            'deadline_at' => '2026-07-22T10:00:10.000000Z',
            'policy' => 'frontier_overlap_v1',
        ];
        $state['overlap_pagination_by_stream']['BTCUSDT/rest/public_trade'] = [
            'endpoint' => 'history_trades',
            'pagination_type' => 2,
            'next_cursor' => '1784714400000',
            'pages_consumed' => 0,
            'pages_remaining' => 10,
            'target_frontier' => $frontier,
            'deadline_at' => '2026-07-22T10:00:11.000000Z',
        ];
        $this->assertCheckpointInvalid($state);

        $state = $base;
        $state['phase'] = 'reconnecting';
        $state['pending_transition'] = [
            'kind' => 'timer_schedule',
            'symbol' => null,
            'stream' => null,
            'stage' => 'reconnect_delay',
        ];
        $this->assertCheckpointInvalid($state);
    }

    public function testIndependentActivePaginationStreamsSurviveBehindOneExactNextTransition(): void
    {
        $state = OkxPaperLiveCheckpoint::fresh(self::DATASET_ID, self::CONFIGURATION_SHA256)->toArray();
        $frontier = OkxPaperStreamFrontier::fromEvent($this->tradeEvent('rest_recovery'))->toArray();
        $deadline = '2026-07-22T10:00:10.000000Z';
        $state['phase'] = 'reconnecting';
        $state['pending_transition'] = [
            'kind' => 'rest_fetch',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/rest/public_trade',
            'stage' => 'history_trades',
        ];
        $state['resync_by_symbol']['BTCUSDT'] = [
            'attempt' => 1,
            'frontier' => $frontier,
            'source_sequence' => null,
            'deadline_at' => $deadline,
            'policy' => 'frontier_overlap_v1',
        ];
        $state['stream_frontiers']['BTCUSDT/rest/public_trade'] = $frontier;
        foreach (['BTCUSDT/rest/public_trade', 'BTCUSDT/ws/public_trade'] as $stream) {
            $state['overlap_pagination_by_stream'][$stream] = [
                'endpoint' => 'history_trades',
                'pagination_type' => 2,
                'next_cursor' => '1784714400000',
                'pages_consumed' => 0,
                'pages_remaining' => 10,
                'target_frontier' => $frontier,
                'deadline_at' => $deadline,
            ];
        }

        $checkpoint = OkxPaperLiveCheckpoint::fromArray($state);

        self::assertSame($state, $checkpoint->toArray());
    }

    public function testConnectingPendingActionCannotJumpDirectlyToReconnectDelay(): void
    {
        $directory = $this->datasetDirectory('connecting-pending-reconnect-delay');
        $store = new OkxPaperLiveCheckpointStore($directory);
        $checkpoint = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $checkpoint = $this->completeInitialWarmup($store, $checkpoint);
        $checkpoint = $store->saveTransition($checkpoint, 'connecting', [
            'kind' => 'transport_connect',
            'symbol' => null,
            'stream' => 'public',
            'stage' => 'connect',
        ]);
        $reconnectDelay = [
            'kind' => 'timer_schedule',
            'symbol' => null,
            'stream' => null,
            'stage' => 'reconnect_delay',
        ];
        $candidateState = $checkpoint->toArray();
        $candidateState['phase'] = 'reconnecting';
        $candidateState['pending_transition'] = $reconnectDelay;
        $candidateState['remaining_symbols'] = ['BTCUSDT', 'ETHUSDT'];
        $candidateState['remaining_boundaries'] = [
            ['symbol' => 'BTCUSDT', 'reason' => 'reconnect'],
            ['symbol' => 'ETHUSDT', 'reason' => 'reconnect'],
        ];
        ++$candidateState['connection_epoch'];
        $candidateState['reconnect'] = [
            'attempt' => 1,
            'deadline_at' => '2026-07-22T10:00:10.000000Z',
            'stable_since' => null,
            'accepted_events' => 0,
        ];
        $path = $this->checkpointPath($directory);
        $before = file_get_contents($path);

        try {
            $store->saveTransition(
                OkxPaperLiveCheckpoint::fromArray($candidateState),
                'reconnecting',
                $reconnectDelay,
            );
            self::fail(
                'CONNECT_PENDING_TO_RECONNECT_TIMER: pending connect must enter reconnect through both transport closes.',
            );
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }

        self::assertSame($before, file_get_contents($path));

        try {
            $interrupted = $store->saveTransition(
                $checkpoint,
                'reconnecting',
                $checkpoint->pendingTransition,
            );
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::fail(
                'CONNECT_PENDING_TO_RECONNECT_TIMER: interrupted connect authority must survive reconnect entry: '
                . $exception->getMessage(),
            );
        }
        self::assertSame($checkpoint->pendingTransition, $interrupted->pendingTransition);
        $interruptedBytes = file_get_contents($path);
        self::assertSame($interruptedBytes, file_get_contents($path));
        self::assertSame($checkpoint->pendingTransition, $interrupted->pendingTransition);
        $publicClosed = $store->saveTransition($interrupted, 'reconnecting', [
            'kind' => 'transport_close',
            'symbol' => null,
            'stream' => 'public',
            'stage' => 'close',
        ]);
        $businessClosed = $store->saveTransition($publicClosed, 'reconnecting', [
            'kind' => 'transport_close',
            'symbol' => null,
            'stream' => 'business',
            'stage' => 'close',
        ]);
        $delayState = $businessClosed->toArray();
        $delayState['pending_transition'] = $reconnectDelay;
        $delayState['remaining_symbols'] = ['BTCUSDT', 'ETHUSDT'];
        $delayState['remaining_boundaries'] = [
            ['symbol' => 'BTCUSDT', 'reason' => 'reconnect'],
            ['symbol' => 'ETHUSDT', 'reason' => 'reconnect'],
        ];
        ++$delayState['connection_epoch'];
        $delayState['reconnect'] = [
            'attempt' => 1,
            'deadline_at' => '2026-07-22T10:00:10.000000Z',
            'stable_since' => null,
            'accepted_events' => 0,
        ];
        $delayed = $store->saveTransition(
            OkxPaperLiveCheckpoint::fromArray($delayState),
            'reconnecting',
            $reconnectDelay,
        );

        self::assertSame($reconnectDelay, $delayed->pendingTransition);
        self::assertSame($delayState['reconnect'], $delayed->reconnect);
    }

    public function testResyncPendingActionsSurviveReconnectEntryBeforeTheExactPublicClose(): void
    {
        $resyncTimeout = [
            'kind' => 'timer_schedule',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/ws/top_of_book',
            'stage' => 'resync_timeout',
        ];
        $sequenceGap = [
            'kind' => 'emit_boundary',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/control/snapshot_boundary',
            'stage' => 'sequence_gap',
        ];
        $cancelResyncTimer = [
            'kind' => 'timer_cancel',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/ws/top_of_book',
            'stage' => 'cancel_resync_timer',
        ];
        $publicClose = [
            'kind' => 'transport_close',
            'symbol' => null,
            'stream' => 'public',
            'stage' => 'close',
        ];
        $businessClose = [
            'kind' => 'transport_close',
            'symbol' => null,
            'stream' => 'business',
            'stage' => 'close',
        ];
        $reconnectDelay = [
            'kind' => 'timer_schedule',
            'symbol' => null,
            'stream' => null,
            'stage' => 'reconnect_delay',
        ];

        foreach ([$resyncTimeout, $sequenceGap, $cancelResyncTimer] as $transition) {
            $directory = $this->datasetDirectory('resync-pending-reconnect-' . $transition['stage']);
            $initialStore = new OkxPaperLiveCheckpointStore($directory);
            $fresh = $initialStore->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
            unset($initialStore);

            $wsFrontier = OkxPaperStreamFrontier::fromEvent($this->bookEvent())->toArray();
            $state = $fresh->toArray();
            $state['phase'] = 'resyncing';
            $state['remaining_symbols'] = ['BTCUSDT'];
            $state['remaining_boundaries'] = [[
                'symbol' => 'BTCUSDT',
                'reason' => 'sequence_gap',
            ]];
            $state['source_epochs']['BTCUSDT'] = 2;
            $state['stream_frontiers']['BTCUSDT/rest/top_of_book'] =
                OkxPaperStreamFrontier::fromEvent(
                    $this->bookEvent('rest_initial_snapshot', '8001', sourceEpoch: 1),
                )->toArray();
            $state['stream_frontiers']['BTCUSDT/ws/top_of_book'] = $wsFrontier;
            $state['stream_frontiers']['BTCUSDT/control/snapshot_boundary'] =
                OkxPaperStreamFrontier::fromEvent($this->initialBoundaryEvent())->toArray();
            $state['resync_by_symbol']['BTCUSDT'] = [
                'attempt' => 1,
                'frontier' => $wsFrontier,
                'source_sequence' => '9001',
                'deadline_at' => '2026-07-22T10:00:10.000000Z',
                'policy' => 'book_seq_overlap_v1',
            ];
            $this->replaceCheckpointState($directory, $state);

            $store = new OkxPaperLiveCheckpointStore($directory);
            $checkpoint = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
            if ($transition === $sequenceGap) {
                $snapshotTransition = [
                    'kind' => 'rest_fetch',
                    'symbol' => 'BTCUSDT',
                    'stream' => 'BTCUSDT/rest/top_of_book',
                    'stage' => 'order_book',
                ];
                $checkpoint = $store->saveTransition(
                    $checkpoint,
                    'resyncing',
                    $snapshotTransition,
                );
                $snapshot = $this->bookEvent('rest_resync_snapshot', '9002');
                $pending = $store->savePending(
                    $checkpoint,
                    $snapshot,
                    $this->advanceOrdinal($checkpoint->ordinalState, $snapshot, 'book|9002'),
                    [
                        'stream' => 'BTCUSDT/rest/top_of_book',
                        'frontier' => OkxPaperStreamFrontier::fromEvent($snapshot)->toArray(),
                    ],
                );
                $checkpoint = $store->acknowledge($pending, $snapshot->eventId);
            }
            $authoritative = $store->saveTransition($checkpoint, 'resyncing', $transition);
            $authorityState = $authoritative->toArray();

            try {
                $interrupted = $store->saveTransition(
                    $authoritative,
                    'reconnecting',
                    $transition,
                );
            } catch (OkxPaperLiveIntegrityException $exception) {
                self::fail(
                    'CONNECT_PENDING_TO_RECONNECT_TIMER: resync authority must survive reconnect entry: '
                    . $transition['stage'] . ': ' . $exception->getMessage(),
                );
            }

            self::assertSame($transition, $interrupted->pendingTransition);
            foreach ([
                'connection_epoch',
                'source_epochs',
                'ordinal_state',
                'remaining_symbols',
                'remaining_boundaries',
                'reconnect',
                'resync_by_symbol',
                'overlap_pagination_by_stream',
            ] as $field) {
                self::assertSame($authorityState[$field], $interrupted->toArray()[$field]);
            }
            $interruptedBytes = file_get_contents($this->checkpointPath($directory));
            unset($store);

            $store = new OkxPaperLiveCheckpointStore($directory);
            $resumed = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
            self::assertSame($interruptedBytes, file_get_contents($this->checkpointPath($directory)));
            self::assertSame($transition, $resumed->pendingTransition);

            $publicClosed = $store->saveTransition($resumed, 'reconnecting', $publicClose);

            self::assertSame($publicClose, $publicClosed->pendingTransition);
            foreach ([
                'connection_epoch',
                'source_epochs',
                'ordinal_state',
                'remaining_symbols',
                'remaining_boundaries',
                'reconnect',
                'resync_by_symbol',
                'overlap_pagination_by_stream',
            ] as $field) {
                self::assertSame(
                    CanonicalJson::encode($authorityState[$field]),
                    CanonicalJson::encode($publicClosed->toArray()[$field]),
                );
            }
            $businessClosed = $store->saveTransition(
                $publicClosed,
                'reconnecting',
                $businessClose,
            );
            $beforeCleanupRegression = file_get_contents($this->checkpointPath($directory));
            try {
                $store->saveTransition($businessClosed, 'reconnecting', $publicClose);
                self::fail(
                    'CONNECT_PENDING_TO_RECONNECT_TIMER: reconnect cleanup must not regress to public close.',
                );
            } catch (OkxPaperLiveIntegrityException $exception) {
                self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
            }
            self::assertSame(
                $beforeCleanupRegression,
                file_get_contents($this->checkpointPath($directory)),
            );

            $delayState = $businessClosed->toArray();
            $delayState['pending_transition'] = $reconnectDelay;
            $delayState['remaining_symbols'] = ['BTCUSDT', 'ETHUSDT'];
            $delayState['remaining_boundaries'] = [
                ['symbol' => 'BTCUSDT', 'reason' => 'reconnect'],
                ['symbol' => 'ETHUSDT', 'reason' => 'reconnect'],
            ];
            ++$delayState['connection_epoch'];
            $delayState['reconnect'] = [
                'attempt' => 1,
                'deadline_at' => '2026-07-22T10:00:20.000000Z',
                'stable_since' => null,
                'accepted_events' => 0,
            ];
            $delayed = $store->saveTransition(
                OkxPaperLiveCheckpoint::fromArray($delayState),
                'reconnecting',
                $reconnectDelay,
            );

            self::assertSame($reconnectDelay, $delayed->pendingTransition);
            self::assertSame($authorityState['connection_epoch'] + 1, $delayed->connectionEpoch);
            self::assertSame($authorityState['source_epochs'], $delayed->sourceEpochs);
            self::assertSame(
                CanonicalJson::encode($authorityState['resync_by_symbol']),
                CanonicalJson::encode($delayed->toArray()['resync_by_symbol']),
            );
            unset($store);
        }
    }

    public function testReconnectOverlapCompletionCannotSelectArbitraryRestWork(): void
    {
        $directory = $this->datasetDirectory('reconnect-overlap-arbitrary-rest');
        $initialStore = new OkxPaperLiveCheckpointStore($directory);
        $fresh = $initialStore->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        unset($initialStore);

        $frontier = OkxPaperStreamFrontier::fromEvent(
            $this->tradeEvent('rest_recovery'),
        )->toArray();
        $deadline = '2026-07-22T10:00:10.000000Z';
        $history = [
            'kind' => 'rest_fetch',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/rest/public_trade',
            'stage' => 'history_trades',
        ];
        $state = $fresh->toArray();
        $state['phase'] = 'reconnecting';
        $state['pending_transition'] = $history;
        $state['stream_frontiers']['BTCUSDT/rest/public_trade'] = $frontier;
        $state['resync_by_symbol']['BTCUSDT'] = [
            'attempt' => 1,
            'frontier' => $frontier,
            'source_sequence' => null,
            'deadline_at' => $deadline,
            'policy' => 'frontier_overlap_v1',
        ];
        $state['overlap_pagination_by_stream']['BTCUSDT/rest/public_trade'] = [
            'endpoint' => 'history_trades',
            'pagination_type' => 2,
            'next_cursor' => '1784714400000',
            'pages_consumed' => 0,
            'pages_remaining' => 10,
            'target_frontier' => $frontier,
            'deadline_at' => $deadline,
        ];
        $this->replaceCheckpointState($directory, $state);

        $store = new OkxPaperLiveCheckpointStore($directory);
        $active = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $arbitraryRest = [
            'kind' => 'rest_fetch',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/rest/candle_15m',
            'stage' => 'current_candles',
        ];
        $candidateState = $active->toArray();
        $candidateState['pending_transition'] = $arbitraryRest;
        $candidateState['resync_by_symbol']['BTCUSDT'] = null;
        $candidateState['overlap_pagination_by_stream']['BTCUSDT/rest/public_trade'] = null;
        $path = $this->checkpointPath($directory);
        $before = file_get_contents($path);

        try {
            $store->saveTransition(
                OkxPaperLiveCheckpoint::fromArray($candidateState),
                'reconnecting',
                $arbitraryRest,
            );
            self::fail(
                'RECONNECT_OVERLAP_TO_ARBITRARY_REST_WORK: overlap completion must select only the exact reconnect work successor.',
            );
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }

        self::assertSame($before, file_get_contents($path));
    }

    public function testReconnectDelayCannotCreateRecoveryBeforeExactBusinessSubscriptionSuccessor(): void
    {
        $unexpectedlyAccepted = [];
        foreach ($this->equalFrontierRecoveryCases() as $case => $recovery) {
            $directory = $this->datasetDirectory('delay-cannot-create-recovery-' . $case);
            $initialStore = new OkxPaperLiveCheckpointStore($directory);
            $streaming = $initialStore->loadOrCreate(
                self::DATASET_ID,
                self::CONFIGURATION_SHA256,
            );
            $streaming = $this->completeInitialWarmupAndEnterStreaming(
                $initialStore,
                $streaming,
            );
            $streamingState = $streaming->toArray();
            $streamingState['stream_frontiers'][$recovery['rest_stream']] =
                $recovery['frontier'];
            $streamingState['stream_frontiers'][$recovery['ws_stream']] =
                $recovery['frontier'];
            unset($initialStore);
            $this->replaceCheckpointState($directory, $streamingState);

            $store = new OkxPaperLiveCheckpointStore($directory);
            $checkpoint = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
            $checkpoint = $this->startReconnectAttempt($store, $checkpoint, $recovery['deadline']);
            $candidateState = $checkpoint->toArray();
            $candidateState['pending_transition'] = $recovery['rest_initial_transition'];
            $candidateState['resync_by_symbol']['BTCUSDT'] = $recovery['resync'];
            $path = $this->checkpointPath($directory);
            $before = file_get_contents($path);

            try {
                $store->saveTransition(
                    OkxPaperLiveCheckpoint::fromArray($candidateState),
                    'reconnecting',
                    $recovery['rest_initial_transition'],
                );
                $unexpectedlyAccepted[] = $case;
            } catch (OkxPaperLiveIntegrityException $exception) {
                self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
                self::assertSame($before, file_get_contents($path));
            }
            unset($store);
        }

        self::assertSame([], $unexpectedlyAccepted, sprintf(
            'A reconnect delay created a recovery before the exact business subscribe successor for: %s.',
            implode(', ', $unexpectedlyAccepted),
        ));
    }

    public function testReconnectRecoveryDefersEthWorkUntilEthBecomesTheRemainingSymbolHead(): void
    {
        $unexpectedlyRejected = [];
        foreach ($this->deferredEthRecoveryCases() as $case => $recovery) {
            $directory = $this->datasetDirectory('deferred-eth-recovery-' . $case);
            $initialStore = new OkxPaperLiveCheckpointStore($directory);
            $streaming = $initialStore->loadOrCreate(
                self::DATASET_ID,
                self::CONFIGURATION_SHA256,
            );
            $streaming = $this->completeInitialWarmupAndEnterStreaming(
                $initialStore,
                $streaming,
            );
            $state = $streaming->toArray();
            $state['phase'] = 'reconnecting';
            $state['pending_transition'] = $recovery['transition'];
            $state['remaining_symbols'] = ['ETHUSDT'];
            $state['remaining_boundaries'] = [[
                'symbol' => 'ETHUSDT',
                'reason' => 'reconnect',
            ]];
            $state['stream_frontiers'][$recovery['frontier_stream']] = $recovery['frontier'];
            $state['resync_by_symbol']['ETHUSDT'] = $recovery['resync'];
            $state['overlap_pagination_by_stream'][$recovery['pagination_stream']] =
                $recovery['pagination'];
            $ethResyncBytes = CanonicalJson::encode($state['resync_by_symbol']['ETHUSDT']);
            $ethPaginationBytes = CanonicalJson::encode(
                $state['overlap_pagination_by_stream'][$recovery['pagination_stream']],
            );
            unset($initialStore);
            $this->replaceCheckpointState($directory, $state);

            $store = new OkxPaperLiveCheckpointStore($directory);
            $checkpoint = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
            $checkpoint = $this->startReconnectAttempt($store, $checkpoint, $recovery['deadline']);
            self::assertSame(
                $ethResyncBytes,
                CanonicalJson::encode($checkpoint->toArray()['resync_by_symbol']['ETHUSDT']),
            );
            self::assertSame(
                $ethPaginationBytes,
                CanonicalJson::encode(
                    $checkpoint->toArray()['overlap_pagination_by_stream'][
                        $recovery['pagination_stream']
                    ],
                ),
            );

            $businessSubscription = null;
            foreach ([
                ['kind' => 'transport_connect', 'symbol' => null, 'stream' => 'public', 'stage' => 'connect'],
                ['kind' => 'subscription_send', 'symbol' => null, 'stream' => 'public', 'stage' => 'subscribe'],
                ['kind' => 'transport_connect', 'symbol' => null, 'stream' => 'business', 'stage' => 'connect'],
                ['kind' => 'subscription_send', 'symbol' => null, 'stream' => 'business', 'stage' => 'subscribe'],
            ] as $transition) {
                $checkpoint = $store->saveTransition($checkpoint, 'reconnecting', $transition);
                $businessSubscription = $transition;
            }
            self::assertSame(
                $ethResyncBytes,
                CanonicalJson::encode($checkpoint->toArray()['resync_by_symbol']['ETHUSDT']),
            );
            self::assertSame(
                $ethPaginationBytes,
                CanonicalJson::encode(
                    $checkpoint->toArray()['overlap_pagination_by_stream'][
                        $recovery['pagination_stream']
                    ],
                ),
            );

            $btcTransition = [
                'kind' => 'rest_fetch',
                'symbol' => 'BTCUSDT',
                'stream' => 'BTCUSDT/rest/candle_15m',
                'stage' => 'current_candles',
            ];
            $btcFrontier = $checkpoint->streamFrontiers[$btcTransition['stream']];
            self::assertInstanceOf(OkxPaperStreamFrontier::class, $btcFrontier);
            $candidateState = $checkpoint->toArray();
            $candidateState['pending_transition'] = $btcTransition;
            $candidateState['resync_by_symbol']['BTCUSDT'] = [
                'attempt' => 1,
                'frontier' => $btcFrontier->toArray(),
                'source_sequence' => null,
                'deadline_at' => $recovery['deadline'],
                'policy' => 'frontier_overlap_v1',
            ];
            $path = $this->checkpointPath($directory);
            $beforeBtcRecovery = file_get_contents($path);

            try {
                $checkpoint = $store->saveTransition(
                    OkxPaperLiveCheckpoint::fromArray($candidateState),
                    'reconnecting',
                    $btcTransition,
                );
            } catch (OkxPaperLiveIntegrityException $exception) {
                self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
                self::assertSame($beforeBtcRecovery, file_get_contents($path));
                $unexpectedlyRejected[] = $case;
                unset($store);

                continue;
            }

            self::assertSame($btcTransition, $checkpoint->pendingTransition);
            self::assertSame(
                $ethResyncBytes,
                CanonicalJson::encode($checkpoint->toArray()['resync_by_symbol']['ETHUSDT']),
            );
            self::assertSame(
                $ethPaginationBytes,
                CanonicalJson::encode(
                    $checkpoint->toArray()['overlap_pagination_by_stream'][
                        $recovery['pagination_stream']
                    ],
                ),
            );

            $ethBeforeHead = $checkpoint->toArray();
            $ethBeforeHead['pending_transition'] = $recovery['transition'];
            $ethBeforeHead['resync_by_symbol']['BTCUSDT'] = null;
            $beforeDeferredEth = file_get_contents($path);
            try {
                $store->saveTransition(
                    OkxPaperLiveCheckpoint::fromArray($ethBeforeHead),
                    'reconnecting',
                    $recovery['transition'],
                );
                self::fail(sprintf(
                    'Deferred ETH recovery ran before ETH became the remaining symbol head for: %s.',
                    $case,
                ));
            } catch (OkxPaperLiveIntegrityException $exception) {
                self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
                self::assertSame($beforeDeferredEth, file_get_contents($path));
            }

            self::assertIsArray($businessSubscription);
            $ethHeadState = $checkpoint->toArray();
            $ethHeadState['pending_transition'] = $businessSubscription;
            $ethHeadState['remaining_symbols'] = ['ETHUSDT'];
            $ethHeadState['remaining_boundaries'] = [[
                'symbol' => 'ETHUSDT',
                'reason' => 'reconnect',
            ]];
            $ethHeadState['resync_by_symbol']['BTCUSDT'] = null;
            unset($store);
            $ethHeadDirectory = $this->datasetDirectory('eth-head-recovery-' . $case);
            $ethHeadStore = new OkxPaperLiveCheckpointStore($ethHeadDirectory);
            $ethHeadStore->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
            unset($ethHeadStore);
            $this->replaceCheckpointState($ethHeadDirectory, $ethHeadState);

            $store = new OkxPaperLiveCheckpointStore($ethHeadDirectory);
            $ethHead = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
            $resumed = $store->saveTransition(
                $ethHead,
                'reconnecting',
                $recovery['transition'],
            );

            self::assertSame($recovery['transition'], $resumed->pendingTransition);
            self::assertSame(
                $ethResyncBytes,
                CanonicalJson::encode($resumed->toArray()['resync_by_symbol']['ETHUSDT']),
            );
            self::assertSame(
                $ethPaginationBytes,
                CanonicalJson::encode(
                    $resumed->toArray()['overlap_pagination_by_stream'][
                        $recovery['pagination_stream']
                    ],
                ),
            );
            unset($store);
        }

        self::assertSame([], $unexpectedlyRejected, sprintf(
            'A deferred ETH recovery blocked the BTC head after business subscribe for: %s.',
            implode(', ', $unexpectedlyRejected),
        ));
    }

    public function testBusinessSubscriptionRequiresTheDeterministicFirstRecoveryAndExactReservation(): void
    {
        $directory = $this->datasetDirectory('business-subscribe-first-recovery');
        $store = new OkxPaperLiveCheckpointStore($directory);
        $checkpoint = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $checkpoint = $this->completeInitialWarmupAndEnterStreaming($store, $checkpoint);
        $checkpoint = $this->startReconnectAttempt($store, $checkpoint);
        foreach ([
            ['kind' => 'transport_connect', 'symbol' => null, 'stream' => 'public', 'stage' => 'connect'],
            ['kind' => 'subscription_send', 'symbol' => null, 'stream' => 'public', 'stage' => 'subscribe'],
            ['kind' => 'transport_connect', 'symbol' => null, 'stream' => 'business', 'stage' => 'connect'],
            ['kind' => 'subscription_send', 'symbol' => null, 'stream' => 'business', 'stage' => 'subscribe'],
        ] as $transition) {
            $checkpoint = $store->saveTransition($checkpoint, 'reconnecting', $transition);
        }

        $arbitraryRest = [
            'kind' => 'rest_fetch',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/rest/public_trade',
            'stage' => 'recent_trades',
        ];
        $path = $this->checkpointPath($directory);
        $before = file_get_contents($path);
        try {
            $store->saveTransition($checkpoint, 'reconnecting', $arbitraryRest);
            self::fail(
                'BUSINESS_SUBSCRIBE_TO_ARBITRARY_REST: the first recovery work must be deterministic and reserve its exact frontier.',
            );
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }
        self::assertSame($before, file_get_contents($path));

        $expected = [
            'kind' => 'rest_fetch',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/rest/candle_15m',
            'stage' => 'current_candles',
        ];
        $frontier = $checkpoint->streamFrontiers[$expected['stream']];
        self::assertInstanceOf(OkxPaperStreamFrontier::class, $frontier);
        $candidateState = $checkpoint->toArray();
        $candidateState['pending_transition'] = $expected;
        $candidateState['resync_by_symbol']['BTCUSDT'] = [
            'attempt' => 1,
            'frontier' => $frontier->toArray(),
            'source_sequence' => null,
            'deadline_at' => '2026-07-22T10:00:20.000000Z',
            'policy' => 'frontier_overlap_v1',
        ];
        $paginationBytes = CanonicalJson::encode($candidateState['overlap_pagination_by_stream']);

        $saved = $store->saveTransition(
            OkxPaperLiveCheckpoint::fromArray($candidateState),
            'reconnecting',
            $expected,
        );

        self::assertSame($expected, $saved->pendingTransition);
        self::assertSame(
            $candidateState['resync_by_symbol']['BTCUSDT'],
            $saved->toArray()['resync_by_symbol']['BTCUSDT'],
        );
        self::assertSame(
            $paginationBytes,
            CanonicalJson::encode($saved->toArray()['overlap_pagination_by_stream']),
        );
    }

    public function testReconnectNullCanOnlyStartWithPublicCloseBeforeDurableReconnectDelay(): void
    {
        $directory = $this->datasetDirectory('reconnect-null-successor');
        $initialStore = new OkxPaperLiveCheckpointStore($directory);
        $checkpoint = $initialStore->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $checkpoint = $this->completeInitialWarmupAndEnterStreaming($initialStore, $checkpoint);
        $state = $checkpoint->toArray();
        $state['phase'] = 'reconnecting';
        $state['pending_transition'] = null;
        unset($initialStore);
        $this->replaceCheckpointState($directory, $state);

        $store = new OkxPaperLiveCheckpointStore($directory);
        $checkpoint = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $reconnectDelay = [
            'kind' => 'timer_schedule',
            'symbol' => null,
            'stream' => null,
            'stage' => 'reconnect_delay',
        ];
        $candidateState = $checkpoint->toArray();
        $candidateState['pending_transition'] = $reconnectDelay;
        $candidateState['remaining_symbols'] = ['BTCUSDT', 'ETHUSDT'];
        $candidateState['remaining_boundaries'] = [
            ['symbol' => 'BTCUSDT', 'reason' => 'reconnect'],
            ['symbol' => 'ETHUSDT', 'reason' => 'reconnect'],
        ];
        ++$candidateState['connection_epoch'];
        $candidateState['reconnect'] = [
            'attempt' => 1,
            'deadline_at' => '2026-07-22T10:00:10.000000Z',
            'stable_since' => null,
            'accepted_events' => 0,
        ];
        $path = $this->checkpointPath($directory);
        $before = file_get_contents($path);

        try {
            $store->saveTransition(
                OkxPaperLiveCheckpoint::fromArray($candidateState),
                'reconnecting',
                $reconnectDelay,
            );
            self::fail(
                'RECONNECT_NULL_TO_DELAY: reconnect delay must not skip the durable public and business closes.',
            );
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }
        self::assertSame($before, file_get_contents($path));

        $publicClosed = $store->saveTransition($checkpoint, 'reconnecting', [
            'kind' => 'transport_close',
            'symbol' => null,
            'stream' => 'public',
            'stage' => 'close',
        ]);
        $businessClosed = $store->saveTransition($publicClosed, 'reconnecting', [
            'kind' => 'transport_close',
            'symbol' => null,
            'stream' => 'business',
            'stage' => 'close',
        ]);
        $delayState = $businessClosed->toArray();
        $delayState['pending_transition'] = $reconnectDelay;
        $delayState['remaining_symbols'] = ['BTCUSDT', 'ETHUSDT'];
        $delayState['remaining_boundaries'] = [
            ['symbol' => 'BTCUSDT', 'reason' => 'reconnect'],
            ['symbol' => 'ETHUSDT', 'reason' => 'reconnect'],
        ];
        ++$delayState['connection_epoch'];
        $delayState['reconnect'] = $candidateState['reconnect'];

        $delayed = $store->saveTransition(
            OkxPaperLiveCheckpoint::fromArray($delayState),
            'reconnecting',
            $reconnectDelay,
        );

        self::assertSame($reconnectDelay, $delayed->pendingTransition);
        self::assertSame(1, $delayed->reconnect['attempt']);
        self::assertSame($businessClosed->connectionEpoch + 1, $delayed->connectionEpoch);
    }

    public function testFailedCleanupCannotStartAtLoopStop(): void
    {
        $directory = $this->datasetDirectory('failed-cleanup-loop-stop-skip');
        $store = new OkxPaperLiveCheckpointStore($directory);
        $fresh = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $failed = $store->fail($fresh, 'okx_paper_public_protocol_error');
        $loopStop = [
            'kind' => 'loop_stop',
            'symbol' => null,
            'stream' => null,
            'stage' => 'stop_loop',
        ];
        $path = $this->checkpointPath($directory);
        $before = file_get_contents($path);

        try {
            $store->saveTransition($failed, 'failed', $loopStop);
            self::fail(
                'FAILED_NULL_TO_LOOP_STOP_SKIPPING_CLOSES: failed cleanup must begin with the exact first transport close.',
            );
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }

        self::assertSame($before, file_get_contents($path));
        self::assertSame([
            'kind' => 'transport_close',
            'symbol' => null,
            'stream' => 'public',
            'stage' => 'close',
        ], $failed->pendingTransition);
    }

    public function testClosingOnePaginationKeepsTheOtherExactRecoveryDurable(): void
    {
        $directory = $this->datasetDirectory('multi-pagination-no-later-row');
        $initialStore = new OkxPaperLiveCheckpointStore($directory);
        $fresh = $initialStore->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        unset($initialStore);

        $frontier = OkxPaperStreamFrontier::fromEvent(
            $this->tradeEvent('rest_recovery'),
        )->toArray();
        $deadline = '2026-07-22T10:00:10.000000Z';
        $currentTransition = [
            'kind' => 'rest_fetch',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/rest/public_trade',
            'stage' => 'history_trades',
        ];
        $state = $fresh->toArray();
        $state['phase'] = 'reconnecting';
        $state['pending_transition'] = $currentTransition;
        $state['resync_by_symbol']['BTCUSDT'] = [
            'attempt' => 1,
            'frontier' => $frontier,
            'source_sequence' => null,
            'deadline_at' => $deadline,
            'policy' => 'frontier_overlap_v1',
        ];
        foreach (['BTCUSDT/rest/public_trade', 'BTCUSDT/ws/public_trade'] as $stream) {
            $state['stream_frontiers'][$stream] = $frontier;
            $state['overlap_pagination_by_stream'][$stream] = [
                'endpoint' => 'history_trades',
                'pagination_type' => 2,
                'next_cursor' => '1784714400000',
                'pages_consumed' => 0,
                'pages_remaining' => 10,
                'target_frontier' => $frontier,
                'deadline_at' => $deadline,
            ];
        }
        $this->replaceCheckpointState($directory, $state);

        $store = new OkxPaperLiveCheckpointStore($directory);
        $active = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $nextTransition = [
            'kind' => 'rest_fetch',
            'symbol' => 'BTCUSDT',
            'stream' => 'BTCUSDT/ws/public_trade',
            'stage' => 'history_trades',
        ];
        $candidateState = $active->toArray();
        $candidateState['pending_transition'] = $nextTransition;
        $candidateState['overlap_pagination_by_stream']['BTCUSDT/rest/public_trade'] = null;
        $resyncBytes = CanonicalJson::encode($candidateState['resync_by_symbol']['BTCUSDT']);
        $remainingPaginationBytes = CanonicalJson::encode(
            $candidateState['overlap_pagination_by_stream']['BTCUSDT/ws/public_trade'],
        );

        try {
            $saved = $store->saveTransition(
                OkxPaperLiveCheckpoint::fromArray($candidateState),
                'reconnecting',
                $nextTransition,
            );
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::fail(
                'MULTI_PAGINATION_NO_LATER_ROW_UNREPRESENTABLE: exact remaining pagination must be persistable: '
                . $exception->getMessage(),
            );
        }

        self::assertNull($saved->overlapPaginationByStream['BTCUSDT/rest/public_trade']);
        self::assertSame(
            $resyncBytes,
            CanonicalJson::encode($saved->toArray()['resync_by_symbol']['BTCUSDT']),
        );
        self::assertSame(
            $remainingPaginationBytes,
            CanonicalJson::encode(
                $saved->toArray()['overlap_pagination_by_stream']['BTCUSDT/ws/public_trade'],
            ),
        );
        self::assertSame($nextTransition, $saved->pendingTransition);
        $savedBytes = file_get_contents($this->checkpointPath($directory));
        unset($store);

        $resumed = (new OkxPaperLiveCheckpointStore($directory))->loadOrCreate(
            self::DATASET_ID,
            self::CONFIGURATION_SHA256,
        );
        self::assertSame($savedBytes, file_get_contents($this->checkpointPath($directory)));
        self::assertSame($resyncBytes, CanonicalJson::encode(
            $resumed->toArray()['resync_by_symbol']['BTCUSDT'],
        ));
        self::assertSame($remainingPaginationBytes, CanonicalJson::encode(
            $resumed->toArray()['overlap_pagination_by_stream']['BTCUSDT/ws/public_trade'],
        ));
        self::assertSame($nextTransition, $resumed->pendingTransition);
    }

    public function testEqualRestAndWebSocketFrontiersCannotEraseThePendingRecoveryAuthority(): void
    {
        $unexpectedlyAccepted = [];
        foreach ($this->equalFrontierRecoveryCases() as $case => $recovery) {
            $directory = $this->datasetDirectory('pending-recovery-authority-' . $case);
            $initialStore = new OkxPaperLiveCheckpointStore($directory);
            $fresh = $initialStore->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
            unset($initialStore);

            $state = $fresh->toArray();
            $state['phase'] = 'reconnecting';
            $state['pending_transition'] = $recovery['initial_transition'];
            $state['resync_by_symbol']['BTCUSDT'] = $recovery['resync'];
            $state['stream_frontiers'][$recovery['rest_stream']] = $recovery['frontier'];
            $state['stream_frontiers'][$recovery['ws_stream']] = $recovery['frontier'];
            $this->replaceCheckpointState($directory, $state);

            $path = $this->checkpointPath($directory);
            $publicClose = [
                'kind' => 'transport_close',
                'symbol' => null,
                'stream' => 'public',
                'stage' => 'close',
            ];

            $store = new OkxPaperLiveCheckpointStore($directory);
            $active = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
            $before = file_get_contents($path);
            try {
                $store->saveTransition($active, 'reconnecting', $publicClose);
                $unexpectedlyAccepted[] = $case;
            } catch (OkxPaperLiveIntegrityException $exception) {
                self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
                self::assertSame($before, file_get_contents($path));
                self::assertSame($recovery['initial_transition'], $active->pendingTransition);
                self::assertNull(
                    $active->overlapPaginationByStream[$recovery['authority_stream']],
                );
            }
        }

        self::assertSame([], $unexpectedlyAccepted, sprintf(
            'A close erased ambiguous pending recovery authority for: %s.',
            implode(', ', $unexpectedlyAccepted),
        ));
    }

    public function testReconnectRecoveryRejectsEveryNonUniqueFrontierMatch(): void
    {
        $unexpectedlyAccepted = [];
        foreach ($this->equalFrontierRecoveryCases() as $case => $recovery) {
            $directory = $this->datasetDirectory('ambiguous-reconnect-recovery-' . $case);
            $initialStore = new OkxPaperLiveCheckpointStore($directory);
            $fresh = $initialStore->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
            unset($initialStore);

            $businessSubscription = [
                'kind' => 'subscription_send',
                'symbol' => null,
                'stream' => 'business',
                'stage' => 'subscribe',
            ];
            $state = $fresh->toArray();
            $state['phase'] = 'reconnecting';
            $state['pending_transition'] = $businessSubscription;
            $state['resync_by_symbol']['BTCUSDT'] = $recovery['resync'];
            $state['stream_frontiers'][$recovery['rest_stream']] = $recovery['frontier'];
            $state['stream_frontiers'][$recovery['ws_stream']] = $recovery['frontier'];
            $this->replaceCheckpointState($directory, $state);

            $store = new OkxPaperLiveCheckpointStore($directory);
            $checkpoint = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
            $path = $this->checkpointPath($directory);
            $before = file_get_contents($path);

            try {
                $store->saveTransition(
                    $checkpoint,
                    'reconnecting',
                    $recovery['rest_initial_transition'],
                );
                $unexpectedlyAccepted[] = $case;
            } catch (OkxPaperLiveIntegrityException $exception) {
                self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
                self::assertSame($before, file_get_contents($path));
                self::assertSame($businessSubscription, $checkpoint->pendingTransition);
            }
            unset($store);
        }

        self::assertSame([], $unexpectedlyAccepted, sprintf(
            'A frontier-only reconnect match was accepted despite equal eligible streams for: %s.',
            implode(', ', $unexpectedlyAccepted),
        ));
    }

    public function testExactPaginationKeepsRestAndWebSocketRecoveryAuthorityAcrossReconnect(): void
    {
        foreach ($this->equalFrontierRecoveryCases() as $case => $recovery) {
            $directory = $this->datasetDirectory('exact-pagination-authority-' . $case);
            $store = new OkxPaperLiveCheckpointStore($directory);
            $checkpoint = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
            $checkpoint = $this->completeInitialWarmupAndEnterStreaming($store, $checkpoint);
            $webSocketAuthority = str_contains($recovery['authority_stream'], '/ws/');
            $authorityEvent = str_ends_with($recovery['authority_stream'], '/public_trade')
                ? $this->tradeEvent(
                    $webSocketAuthority ? 'ws_aggregated' : 'rest_recovery',
                    sequence: '2',
                )
                : $this->candleEvent(
                    $webSocketAuthority ? 'ws_candle' : 'rest_warmup',
                    '2026-07-22T10:00:02.000000Z',
                    sequence: '2',
                );
            $authorityFrontier = OkxPaperStreamFrontier::fromEvent($authorityEvent);
            self::assertSame($recovery['frontier'], $authorityFrontier->toArray());
            $checkpoint = $this->acknowledgeEventForTest(
                $store,
                $checkpoint,
                $authorityEvent,
                $authorityFrontier->naturalIdentity,
                $recovery['authority_stream'],
            );
            $state = $checkpoint->toArray();
            $state['stream_frontiers'][$recovery['other_stream']] = $recovery['frontier'];
            unset($store);
            $this->replaceCheckpointState($directory, $state);

            $store = new OkxPaperLiveCheckpointStore($directory);
            $checkpoint = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
            $checkpoint = $this->startReconnectAttempt($store, $checkpoint, $recovery['deadline']);
            $checkpoint = $this->advanceReconnectToBusinessSubscription($store, $checkpoint);
            $checkpoint = $this->startReconnectFrontierRecovery(
                $store,
                $checkpoint,
                $recovery['authority_stream'],
                $recovery['resync'],
                $recovery['pagination'],
            );

            self::assertSame($recovery['history_transition'], $checkpoint->pendingTransition);
            self::assertSame(
                [$recovery['authority_stream']],
                array_keys(array_filter(
                    $checkpoint->overlapPaginationByStream,
                    static fn (mixed $pagination): bool => $pagination !== null,
                )),
            );
            self::assertSame(
                CanonicalJson::encode($recovery['pagination']),
                CanonicalJson::encode(
                    $checkpoint->toArray()['overlap_pagination_by_stream'][
                        $recovery['authority_stream']
                    ],
                ),
            );
            self::assertSame(
                CanonicalJson::encode($checkpoint->toArray()) . "\n",
                file_get_contents($this->checkpointPath($directory)),
            );
            $resyncBytes = CanonicalJson::encode($checkpoint->toArray()['resync_by_symbol']);
            $paginationBytes = CanonicalJson::encode(
                $checkpoint->toArray()['overlap_pagination_by_stream'],
            );
            $checkpoint = $store->saveTransition($checkpoint, 'reconnecting', [
                'kind' => 'transport_close',
                'symbol' => null,
                'stream' => 'public',
                'stage' => 'close',
            ]);
            $checkpoint = $store->saveTransition($checkpoint, 'reconnecting', [
                'kind' => 'transport_close',
                'symbol' => null,
                'stream' => 'business',
                'stage' => 'close',
            ]);
            $reconnectDelay = [
                'kind' => 'timer_schedule',
                'symbol' => null,
                'stream' => null,
                'stage' => 'reconnect_delay',
            ];
            $delayState = $checkpoint->toArray();
            $delayState['pending_transition'] = $reconnectDelay;
            $delayState['remaining_symbols'] = ['BTCUSDT', 'ETHUSDT'];
            $delayState['remaining_boundaries'] = [
                ['symbol' => 'BTCUSDT', 'reason' => 'reconnect'],
                ['symbol' => 'ETHUSDT', 'reason' => 'reconnect'],
            ];
            ++$delayState['connection_epoch'];
            $delayState['reconnect'] = [
                'attempt' => $checkpoint->reconnect['attempt'] + 1,
                'deadline_at' => $recovery['deadline'],
                'stable_since' => null,
                'accepted_events' => 0,
            ];
            $checkpoint = $store->saveTransition(
                OkxPaperLiveCheckpoint::fromArray($delayState),
                'reconnecting',
                $reconnectDelay,
            );
            foreach ([
                ['kind' => 'transport_connect', 'symbol' => null, 'stream' => 'public', 'stage' => 'connect'],
                ['kind' => 'subscription_send', 'symbol' => null, 'stream' => 'public', 'stage' => 'subscribe'],
                ['kind' => 'transport_connect', 'symbol' => null, 'stream' => 'business', 'stage' => 'connect'],
                ['kind' => 'subscription_send', 'symbol' => null, 'stream' => 'business', 'stage' => 'subscribe'],
            ] as $transition) {
                $checkpoint = $store->saveTransition($checkpoint, 'reconnecting', $transition);
            }
            $checkpoint = $store->saveTransition(
                $checkpoint,
                'reconnecting',
                $recovery['history_transition'],
            );

            self::assertSame($recovery['history_transition'], $checkpoint->pendingTransition);
            self::assertSame(
                $recovery['pagination'],
                $checkpoint->toArray()['overlap_pagination_by_stream'][
                    $recovery['authority_stream']
                ],
            );
            self::assertNull(
                $checkpoint->overlapPaginationByStream[$recovery['other_stream']],
            );
            self::assertSame($resyncBytes, CanonicalJson::encode(
                $checkpoint->toArray()['resync_by_symbol'],
            ));
            self::assertSame($paginationBytes, CanonicalJson::encode(
                $checkpoint->toArray()['overlap_pagination_by_stream'],
            ));
            $path = $this->checkpointPath($directory);
            $savedBytes = file_get_contents($path);
            unset($store);

            $resumed = (new OkxPaperLiveCheckpointStore($directory))->loadOrCreate(
                self::DATASET_ID,
                self::CONFIGURATION_SHA256,
            );
            self::assertSame($savedBytes, file_get_contents($path));
            self::assertSame($recovery['history_transition'], $resumed->pendingTransition);
            self::assertSame($paginationBytes, CanonicalJson::encode(
                $resumed->toArray()['overlap_pagination_by_stream'],
            ));
        }
    }

    /**
     * @return array<string, array{
     *     authority_stream: string,
     *     other_stream: string,
     *     rest_stream: string,
     *     ws_stream: string,
     *     initial_transition: array{kind: string, symbol: string, stream: string, stage: string},
     *     rest_initial_transition: array{kind: string, symbol: string, stream: string, stage: string},
     *     history_transition: array{kind: string, symbol: string, stream: string, stage: string},
     *     frontier: array<string, string>,
     *     resync: array<string, mixed>,
     *     pagination: array<string, mixed>,
     *     deadline: string
     * }>
     */
    private function equalFrontierRecoveryCases(): array
    {
        $deadline = '2026-07-22T10:00:10.000000Z';
        $channels = [
            'public-trade' => [
                'suffix' => 'public_trade',
                'initial_stage' => 'recent_trades',
                'history_stage' => 'history_trades',
                'frontier' => OkxPaperStreamFrontier::fromEvent(
                    $this->tradeEvent('rest_recovery'),
                )->toArray(),
                'pagination_type' => 2,
            ],
            'candle' => [
                'suffix' => 'candle_1m',
                'initial_stage' => 'current_candles',
                'history_stage' => 'history_candles',
                'frontier' => OkxPaperStreamFrontier::fromEvent($this->candleEvent(
                    'rest_warmup',
                    '2026-07-22T10:00:01.000000Z',
                ))->toArray(),
                'pagination_type' => null,
            ],
        ];
        $cases = [];
        foreach ($channels as $channel => $recovery) {
            $restStream = 'BTCUSDT/rest/' . $recovery['suffix'];
            $wsStream = 'BTCUSDT/ws/' . $recovery['suffix'];
            foreach (['rest' => $restStream, 'ws' => $wsStream] as $authority => $authorityStream) {
                $otherStream = $authority === 'rest' ? $wsStream : $restStream;
                $cases[$channel . '-' . $authority] = [
                    'authority_stream' => $authorityStream,
                    'other_stream' => $otherStream,
                    'rest_stream' => $restStream,
                    'ws_stream' => $wsStream,
                    'initial_transition' => [
                        'kind' => 'rest_fetch',
                        'symbol' => 'BTCUSDT',
                        'stream' => $authorityStream,
                        'stage' => $recovery['initial_stage'],
                    ],
                    'rest_initial_transition' => [
                        'kind' => 'rest_fetch',
                        'symbol' => 'BTCUSDT',
                        'stream' => $restStream,
                        'stage' => $recovery['initial_stage'],
                    ],
                    'history_transition' => [
                        'kind' => 'rest_fetch',
                        'symbol' => 'BTCUSDT',
                        'stream' => $authorityStream,
                        'stage' => $recovery['history_stage'],
                    ],
                    'frontier' => $recovery['frontier'],
                    'resync' => [
                        'attempt' => 1,
                        'frontier' => $recovery['frontier'],
                        'source_sequence' => null,
                        'deadline_at' => $deadline,
                        'policy' => 'frontier_overlap_v1',
                    ],
                    'pagination' => [
                        'endpoint' => $recovery['history_stage'],
                        'pagination_type' => $recovery['pagination_type'],
                        'next_cursor' => '1784714400000',
                        'pages_consumed' => 0,
                        'pages_remaining' => 10,
                        'target_frontier' => $recovery['frontier'],
                        'deadline_at' => $deadline,
                    ],
                    'deadline' => $deadline,
                ];
            }
        }

        return $cases;
    }

    /**
     * @return array<string, array{
     *     transition: array{kind: string, symbol: string, stream: string, stage: string},
     *     frontier_stream: string,
     *     pagination_stream: string,
     *     frontier: array<string, string>,
     *     resync: array<string, mixed>,
     *     pagination: array<string, mixed>|null,
     *     deadline: string
     * }>
     */
    private function deferredEthRecoveryCases(): array
    {
        $deadline = '2026-07-22T10:00:10.000000Z';
        $bookFrontier = OkxPaperStreamFrontier::fromEvent(
            $this->bookEvent('ws_books', '9001', 'ETHUSDT', 1),
        )->toArray();
        $candleFrontier = OkxPaperStreamFrontier::fromEvent($this->candleEvent(
            'rest_recovery',
            '2026-07-22T10:00:01.000000Z',
            '1m',
            'ETHUSDT',
        ))->toArray();
        $tradeFrontier = OkxPaperStreamFrontier::fromEvent(
            $this->tradeEvent('rest_recovery', symbol: 'ETHUSDT'),
        )->toArray();

        return [
            'book' => [
                'transition' => [
                    'kind' => 'rest_fetch',
                    'symbol' => 'ETHUSDT',
                    'stream' => 'ETHUSDT/rest/top_of_book',
                    'stage' => 'order_book',
                ],
                'frontier_stream' => 'ETHUSDT/ws/top_of_book',
                'pagination_stream' => 'ETHUSDT/rest/candle_1m',
                'frontier' => $bookFrontier,
                'resync' => [
                    'attempt' => 1,
                    'frontier' => $bookFrontier,
                    'source_sequence' => '9001',
                    'deadline_at' => $deadline,
                    'policy' => 'book_seq_overlap_v1',
                ],
                'pagination' => null,
                'deadline' => $deadline,
            ],
            'candle' => $this->deferredEthFrontierRecoveryCase(
                'ETHUSDT/rest/candle_1m',
                'history_candles',
                null,
                $candleFrontier,
                $deadline,
            ),
            'trade' => $this->deferredEthFrontierRecoveryCase(
                'ETHUSDT/rest/public_trade',
                'history_trades',
                2,
                $tradeFrontier,
                $deadline,
            ),
        ];
    }

    /**
     * @param array<string, string> $frontier
     * @return array{
     *     transition: array{kind: string, symbol: string, stream: string, stage: string},
     *     frontier_stream: string,
     *     pagination_stream: string,
     *     frontier: array<string, string>,
     *     resync: array<string, mixed>,
     *     pagination: array<string, mixed>,
     *     deadline: string
     * }
     */
    private function deferredEthFrontierRecoveryCase(
        string $stream,
        string $historyStage,
        ?int $paginationType,
        array $frontier,
        string $deadline,
    ): array {
        return [
            'transition' => [
                'kind' => 'rest_fetch',
                'symbol' => 'ETHUSDT',
                'stream' => $stream,
                'stage' => $historyStage,
            ],
            'frontier_stream' => $stream,
            'pagination_stream' => $stream,
            'frontier' => $frontier,
            'resync' => [
                'attempt' => 1,
                'frontier' => $frontier,
                'source_sequence' => null,
                'deadline_at' => $deadline,
                'policy' => 'frontier_overlap_v1',
            ],
            'pagination' => [
                'endpoint' => $historyStage,
                'pagination_type' => $paginationType,
                'next_cursor' => '1784714400000',
                'pages_consumed' => 0,
                'pages_remaining' => 10,
                'target_frontier' => $frontier,
                'deadline_at' => $deadline,
            ],
            'deadline' => $deadline,
        ];
    }

    private function completeInitialWarmup(
        OkxPaperLiveCheckpointStore $store,
        OkxPaperLiveCheckpoint $checkpoint,
    ): OkxPaperLiveCheckpoint {
        foreach (['BTCUSDT', 'ETHUSDT'] as $symbol) {
            $checkpoint = $this->acknowledgeInitialWarmupRestPrefix($store, $checkpoint, $symbol, 6);
            $transition = [
                'kind' => 'emit_boundary',
                'symbol' => $symbol,
                'stream' => $symbol . '/control/snapshot_boundary',
                'stage' => 'initial',
            ];
            $writeAhead = $store->saveTransition($checkpoint, 'warming', $transition);
            $boundary = $this->initialBoundaryEvent($symbol);
            $pending = $store->savePending(
                $writeAhead,
                $boundary,
                $this->advanceOrdinal(
                    $writeAhead->ordinalState,
                    $boundary,
                    'boundary|1|8001|initial',
                ),
                null,
            );
            $checkpoint = $store->acknowledge($pending, $boundary->eventId);
        }

        return $checkpoint;
    }

    private function completeInitialWarmupAndEnterStreaming(
        OkxPaperLiveCheckpointStore $store,
        OkxPaperLiveCheckpoint $checkpoint,
    ): OkxPaperLiveCheckpoint {
        $checkpoint = $this->completeInitialWarmup($store, $checkpoint);
        foreach ([
            ['connecting', ['kind' => 'transport_connect', 'symbol' => null, 'stream' => 'public', 'stage' => 'connect']],
            ['subscribing', ['kind' => 'subscription_send', 'symbol' => null, 'stream' => 'public', 'stage' => 'subscribe']],
            ['connecting', ['kind' => 'transport_connect', 'symbol' => null, 'stream' => 'business', 'stage' => 'connect']],
            ['subscribing', ['kind' => 'subscription_send', 'symbol' => null, 'stream' => 'business', 'stage' => 'subscribe']],
        ] as [$phase, $transition]) {
            $checkpoint = $store->saveTransition($checkpoint, $phase, $transition);
        }

        return $store->saveTransition($checkpoint, 'streaming', null);
    }

    private function startReconnectAttempt(
        OkxPaperLiveCheckpointStore $store,
        OkxPaperLiveCheckpoint $streaming,
        string $deadline = '2026-07-22T10:00:10.000000Z',
    ): OkxPaperLiveCheckpoint {
        $checkpoint = $store->saveTransition($streaming, 'reconnecting', [
            'kind' => 'transport_close',
            'symbol' => null,
            'stream' => 'public',
            'stage' => 'close',
        ]);
        $checkpoint = $store->saveTransition($checkpoint, 'reconnecting', [
            'kind' => 'transport_close',
            'symbol' => null,
            'stream' => 'business',
            'stage' => 'close',
        ]);
        $transition = [
            'kind' => 'timer_schedule',
            'symbol' => null,
            'stream' => null,
            'stage' => 'reconnect_delay',
        ];
        $state = $checkpoint->toArray();
        $state['pending_transition'] = $transition;
        $state['remaining_symbols'] = ['BTCUSDT', 'ETHUSDT'];
        $state['remaining_boundaries'] = [
            ['symbol' => 'BTCUSDT', 'reason' => 'reconnect'],
            ['symbol' => 'ETHUSDT', 'reason' => 'reconnect'],
        ];
        ++$state['connection_epoch'];
        $state['reconnect'] = [
            'attempt' => $streaming->reconnect['attempt'] + 1,
            'deadline_at' => $deadline,
            'stable_since' => null,
            'accepted_events' => 0,
        ];

        return $store->saveTransition(
            OkxPaperLiveCheckpoint::fromArray($state),
            'reconnecting',
            $transition,
        );
    }

    private function advanceReconnectToBusinessSubscription(
        OkxPaperLiveCheckpointStore $store,
        OkxPaperLiveCheckpoint $checkpoint,
    ): OkxPaperLiveCheckpoint {
        foreach ([
            ['kind' => 'transport_connect', 'symbol' => null, 'stream' => 'public', 'stage' => 'connect'],
            ['kind' => 'subscription_send', 'symbol' => null, 'stream' => 'public', 'stage' => 'subscribe'],
            ['kind' => 'transport_connect', 'symbol' => null, 'stream' => 'business', 'stage' => 'connect'],
            ['kind' => 'subscription_send', 'symbol' => null, 'stream' => 'business', 'stage' => 'subscribe'],
        ] as $transition) {
            $checkpoint = $store->saveTransition($checkpoint, 'reconnecting', $transition);
        }

        return $checkpoint;
    }

    /**
     * @return array{
     *     string,
     *     OkxPaperLiveCheckpointStore,
     *     OkxPaperLiveCheckpoint,
     *     PaperMarketEvent
     * }
     */
    private function reconnectingBtcTradePaginationReadyForAcknowledgement(
        string $directoryName,
        bool $withDeferredEthPagination,
    ): array {
        $directory = $this->datasetDirectory($directoryName);
        $store = new OkxPaperLiveCheckpointStore($directory);
        $checkpoint = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $checkpoint = $this->completeInitialWarmupAndEnterStreaming($store, $checkpoint);
        $nextStreamEvent = $this->candleEvent(
            'ws_candle',
            '2026-07-22T10:00:02.000000Z',
            sequence: '2',
        );
        $checkpoint = $this->acknowledgeEventForTest(
            $store,
            $checkpoint,
            $nextStreamEvent,
            OkxPaperStreamFrontier::fromEvent($nextStreamEvent)->naturalIdentity,
            'BTCUSDT/ws/candle_1m',
        );
        $targetEvent = $this->tradeEvent('rest_recovery', sequence: '2');
        $checkpoint = $this->acknowledgeEventForTest(
            $store,
            $checkpoint,
            $targetEvent,
            'trade|242720721',
            'BTCUSDT/rest/public_trade',
        );
        $checkpoint = $this->startReconnectAttempt($store, $checkpoint);
        $checkpoint = $this->advanceReconnectToBusinessSubscription($store, $checkpoint);
        $target = OkxPaperStreamFrontier::fromEvent($targetEvent)->toArray();
        $deadline = '2026-07-22T10:00:10.000000Z';
        $pagination = [
            'endpoint' => 'history_trades',
            'pagination_type' => 2,
            'next_cursor' => '1784714400000',
            'pages_consumed' => 0,
            'pages_remaining' => 10,
            'target_frontier' => $target,
            'deadline_at' => $deadline,
        ];
        $active = $this->startReconnectFrontierRecovery(
            $store,
            $checkpoint,
            'BTCUSDT/rest/public_trade',
            [
                'attempt' => 1,
                'frontier' => $target,
                'source_sequence' => null,
                'deadline_at' => $deadline,
                'policy' => 'frontier_overlap_v1',
            ],
            $pagination,
        );

        if ($withDeferredEthPagination) {
            $ethFrontier = $active->streamFrontiers['ETHUSDT/rest/public_trade'];
            self::assertInstanceOf(OkxPaperStreamFrontier::class, $ethFrontier);
            $state = $active->toArray();
            $state['resync_by_symbol']['ETHUSDT'] = [
                'attempt' => 1,
                'frontier' => $ethFrontier->toArray(),
                'source_sequence' => null,
                'deadline_at' => $deadline,
                'policy' => 'frontier_overlap_v1',
            ];
            $state['overlap_pagination_by_stream']['ETHUSDT/rest/public_trade'] = [
                'endpoint' => 'history_trades',
                'pagination_type' => 2,
                'next_cursor' => '1784714400000',
                'pages_consumed' => 0,
                'pages_remaining' => 10,
                'target_frontier' => $ethFrontier->toArray(),
                'deadline_at' => $deadline,
            ];
            unset($store);
            $this->replaceCheckpointState($directory, $state);
            $store = new OkxPaperLiveCheckpointStore($directory);
            $active = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        }

        return [
            $directory,
            $store,
            $active,
            $this->tradeEvent(
                'rest_recovery',
                false,
                '242720722',
                '65000.2',
                '3',
            ),
        ];
    }

    /**
     * @param array<string, mixed>      $resync
     * @param array<string, mixed>|null $pagination
     */
    private function startReconnectFrontierRecovery(
        OkxPaperLiveCheckpointStore $store,
        OkxPaperLiveCheckpoint $checkpoint,
        string $stream,
        array $resync,
        ?array $pagination = null,
    ): OkxPaperLiveCheckpoint {
        $symbol = strstr($stream, '/', true);
        $initialStage = match (true) {
            str_ends_with($stream, '/public_trade') => 'recent_trades',
            str_contains($stream, '/candle_') => 'current_candles',
            default => throw new \InvalidArgumentException('Invalid frontier recovery stream.'),
        };
        if (!\is_string($symbol)) {
            throw new \InvalidArgumentException('Invalid frontier recovery symbol.');
        }
        $initialTransition = [
            'kind' => 'rest_fetch',
            'symbol' => $symbol,
            'stream' => $stream,
            'stage' => $initialStage,
        ];
        $reservationState = $checkpoint->toArray();
        $reservationState['pending_transition'] = $initialTransition;
        $reservationState['resync_by_symbol'][$symbol] = $resync;
        $checkpoint = $store->saveTransition(
            OkxPaperLiveCheckpoint::fromArray($reservationState),
            'reconnecting',
            $initialTransition,
        );
        if ($pagination === null) {
            return $checkpoint;
        }

        $historyTransition = [
            'kind' => 'rest_fetch',
            'symbol' => $symbol,
            'stream' => $stream,
            'stage' => $pagination['endpoint'],
        ];
        $paginationState = $checkpoint->toArray();
        $paginationState['pending_transition'] = $historyTransition;
        $paginationState['overlap_pagination_by_stream'][$stream] = $pagination;

        return $store->saveTransition(
            OkxPaperLiveCheckpoint::fromArray($paginationState),
            'reconnecting',
            $historyTransition,
        );
    }

    private function closeReconnectMarketStreams(
        OkxPaperLiveCheckpointStore $store,
        OkxPaperLiveCheckpoint $checkpoint,
        string $symbol,
        string $deadline,
        bool $paginatePublicTrade = false,
    ): OkxPaperLiveCheckpoint {
        $streams = [
            $symbol . '/rest/candle_15m' => 'current_candles',
            $symbol . '/rest/candle_1H' => 'current_candles',
            $symbol . '/rest/candle_1m' => 'current_candles',
            $symbol . '/rest/candle_5m' => 'current_candles',
            $symbol . '/rest/public_trade' => 'recent_trades',
        ];
        $streamNames = array_keys($streams);
        foreach ($streamNames as $position => $stream) {
            $frontier = $checkpoint->streamFrontiers[$stream] ?? null;
            self::assertInstanceOf(OkxPaperStreamFrontier::class, $frontier);
            $transition = [
                'kind' => 'rest_fetch',
                'symbol' => $symbol,
                'stream' => $stream,
                'stage' => $streams[$stream],
            ];
            $reservation = $checkpoint->toArray();
            $reservation['pending_transition'] = $transition;
            $reservation['resync_by_symbol'][$symbol] = [
                'attempt' => 1,
                'frontier' => $frontier->toArray(),
                'source_sequence' => null,
                'deadline_at' => $deadline,
                'policy' => 'frontier_overlap_v1',
            ];
            $checkpoint = $store->saveTransition(
                OkxPaperLiveCheckpoint::fromArray($reservation),
                'reconnecting',
                $transition,
            );

            if ($paginatePublicTrade && $stream === $symbol . '/rest/public_trade') {
                $history = $transition;
                $history['stage'] = 'history_trades';
                $pagination = $checkpoint->toArray();
                $pagination['pending_transition'] = $history;
                $pagination['overlap_pagination_by_stream'][$stream] = [
                    'endpoint' => 'history_trades',
                    'pagination_type' => 2,
                    'next_cursor' => '1784714400000',
                    'pages_consumed' => 0,
                    'pages_remaining' => 10,
                    'target_frontier' => $frontier->toArray(),
                    'deadline_at' => $deadline,
                ];
                $checkpoint = $store->saveTransition(
                    OkxPaperLiveCheckpoint::fromArray($pagination),
                    'reconnecting',
                    $history,
                );
            }

            $nextStream = $streamNames[$position + 1] ?? $symbol . '/rest/top_of_book';
            $next = [
                'kind' => 'rest_fetch',
                'symbol' => $symbol,
                'stream' => $nextStream,
                'stage' => isset($streams[$nextStream]) ? $streams[$nextStream] : 'order_book',
            ];
            $closed = $checkpoint->toArray();
            $closed['pending_transition'] = $next;
            $closed['resync_by_symbol'][$symbol] = null;
            $closed['overlap_pagination_by_stream'][$stream] = null;
            $checkpoint = $store->saveTransition(
                OkxPaperLiveCheckpoint::fromArray($closed),
                'reconnecting',
                $next,
            );
        }

        return $checkpoint;
    }

    private function openReconnectTradeHistoryPagination(
        OkxPaperLiveCheckpointStore $store,
        OkxPaperLiveCheckpoint $checkpoint,
        string $symbol,
        string $deadline,
    ): OkxPaperLiveCheckpoint {
        $streamsBeforeTrade = [
            $symbol . '/rest/candle_15m',
            $symbol . '/rest/candle_1H',
            $symbol . '/rest/candle_1m',
            $symbol . '/rest/candle_5m',
        ];
        foreach ($streamsBeforeTrade as $position => $stream) {
            $frontier = $checkpoint->streamFrontiers[$stream] ?? null;
            self::assertInstanceOf(OkxPaperStreamFrontier::class, $frontier);
            $transition = [
                'kind' => 'rest_fetch',
                'symbol' => $symbol,
                'stream' => $stream,
                'stage' => 'current_candles',
            ];
            $reservation = $checkpoint->toArray();
            $reservation['pending_transition'] = $transition;
            $reservation['resync_by_symbol'][$symbol] = [
                'attempt' => 1,
                'frontier' => $frontier->toArray(),
                'source_sequence' => null,
                'deadline_at' => $deadline,
                'policy' => 'frontier_overlap_v1',
            ];
            $checkpoint = $store->saveTransition(
                OkxPaperLiveCheckpoint::fromArray($reservation),
                'reconnecting',
                $transition,
            );
            $nextStream = $streamsBeforeTrade[$position + 1] ?? $symbol . '/rest/public_trade';
            $next = [
                'kind' => 'rest_fetch',
                'symbol' => $symbol,
                'stream' => $nextStream,
                'stage' => str_ends_with($nextStream, '/public_trade')
                    ? 'recent_trades'
                    : 'current_candles',
            ];
            $closed = $checkpoint->toArray();
            $closed['pending_transition'] = $next;
            $closed['resync_by_symbol'][$symbol] = null;
            $checkpoint = $store->saveTransition(
                OkxPaperLiveCheckpoint::fromArray($closed),
                'reconnecting',
                $next,
            );
        }

        $frontier = $checkpoint->streamFrontiers[$symbol . '/rest/public_trade'] ?? null;
        self::assertInstanceOf(OkxPaperStreamFrontier::class, $frontier);

        return $this->startReconnectFrontierRecovery(
            $store,
            $checkpoint,
            $symbol . '/rest/public_trade',
            [
                'attempt' => 1,
                'frontier' => $frontier->toArray(),
                'source_sequence' => null,
                'deadline_at' => $deadline,
                'policy' => 'frontier_overlap_v1',
            ],
            [
                'endpoint' => 'history_trades',
                'pagination_type' => 2,
                'next_cursor' => '1784714400000',
                'pages_consumed' => 0,
                'pages_remaining' => 10,
                'target_frontier' => $frontier->toArray(),
                'deadline_at' => $deadline,
            ],
        );
    }

    private function acknowledgeReconnectBookAndBoundary(
        OkxPaperLiveCheckpointStore $store,
        OkxPaperLiveCheckpoint $checkpoint,
        string $symbol,
    ): OkxPaperLiveCheckpoint {
        $transition = [
            'kind' => 'rest_fetch',
            'symbol' => $symbol,
            'stream' => $symbol . '/rest/top_of_book',
            'stage' => 'order_book',
        ];
        self::assertSame($transition, $checkpoint->pendingTransition);
        $wsFrontier = $checkpoint->streamFrontiers[$symbol . '/ws/top_of_book'] ?? null;
        self::assertInstanceOf(OkxPaperStreamFrontier::class, $wsFrontier);
        $sourceEpoch = $checkpoint->sourceEpochs[$symbol] + 1;
        $sourceSequence = (string) (9000 + $sourceEpoch);
        $reservation = $checkpoint->toArray();
        $reservation['pending_transition'] = $transition;
        $reservation['source_epochs'][$symbol] = $sourceEpoch;
        $reservation['resync_by_symbol'][$symbol] = [
            'attempt' => 1,
            'frontier' => $wsFrontier->toArray(),
            'source_sequence' => $wsFrontier->sourceIdentity,
            'deadline_at' => $checkpoint->reconnect['deadline_at'],
            'policy' => 'book_seq_overlap_v1',
        ];
        $checkpoint = $store->saveTransition(
            OkxPaperLiveCheckpoint::fromArray($reservation),
            'reconnecting',
            $transition,
        );
        $snapshot = $this->bookEvent(
            'rest_resync_snapshot',
            $sourceSequence,
            $symbol,
            $sourceEpoch,
            (string) ($sourceEpoch + 1),
        );
        $pending = $store->savePending(
            $checkpoint,
            $snapshot,
            $this->advanceOrdinal(
                $checkpoint->ordinalState,
                $snapshot,
                'book|' . $sourceSequence,
            ),
            [
                'stream' => $symbol . '/rest/top_of_book',
                'frontier' => OkxPaperStreamFrontier::fromEvent($snapshot)->toArray(),
            ],
        );
        $checkpoint = $store->acknowledge($pending, $snapshot->eventId);
        $boundaryTransition = [
            'kind' => 'emit_boundary',
            'symbol' => $symbol,
            'stream' => $symbol . '/control/snapshot_boundary',
            'stage' => 'reconnect',
        ];
        self::assertSame($boundaryTransition, $checkpoint->pendingTransition);
        $boundary = $this->reconnectBoundaryEvent($symbol, $sourceEpoch, $sourceSequence);
        $pending = $store->savePending(
            $checkpoint,
            $boundary,
            $this->advanceOrdinal(
                $checkpoint->ordinalState,
                $boundary,
                sprintf('boundary|%d|%s|reconnect', $sourceEpoch, $sourceSequence),
            ),
            null,
        );

        return $store->acknowledge($pending, $boundary->eventId);
    }

    private function acknowledgeInitialWarmupRestPrefix(
        OkxPaperLiveCheckpointStore $store,
        OkxPaperLiveCheckpoint $checkpoint,
        string $symbol,
        int $workUnits,
    ): OkxPaperLiveCheckpoint {
        if (!\in_array($symbol, ['BTCUSDT', 'ETHUSDT'], true) || $workUnits < 0 || $workUnits > 6) {
            throw new \InvalidArgumentException('Invalid warm-up test prefix.');
        }

        $units = [];
        foreach (['1m', '5m', '15m', '1H'] as $bar) {
            $units[] = [
                [
                    'kind' => 'rest_fetch',
                    'symbol' => $symbol,
                    'stream' => $symbol . '/rest/candle_' . $bar,
                    'stage' => 'current_candles',
                ],
                $this->candleEvent(
                    'rest_warmup',
                    '2026-07-22T10:00:01.000000Z',
                    $bar,
                    $symbol,
                    '2026-07-22T09:00:00.000000Z',
                ),
            ];
        }
        $units[] = [
            [
                'kind' => 'rest_fetch',
                'symbol' => $symbol,
                'stream' => $symbol . '/rest/public_trade',
                'stage' => 'recent_trades',
            ],
            $this->tradeEvent('rest_recovery', tradeId: '142720721', symbol: $symbol),
        ];
        $units[] = [
            [
                'kind' => 'rest_fetch',
                'symbol' => $symbol,
                'stream' => $symbol . '/rest/top_of_book',
                'stage' => 'order_book',
            ],
            $this->bookEvent('rest_initial_snapshot', '8001', $symbol, 1),
        ];

        foreach (array_slice($units, 0, $workUnits) as [$transition, $event]) {
            $writeAhead = $store->saveTransition($checkpoint, 'warming', $transition);
            $frontier = OkxPaperStreamFrontier::fromEvent($event);
            $pending = $store->savePending(
                $writeAhead,
                $event,
                $this->advanceOrdinal(
                    $writeAhead->ordinalState,
                    $event,
                    $frontier->naturalIdentity,
                ),
                ['stream' => $transition['stream'], 'frontier' => $frontier->toArray()],
            );
            $checkpoint = $store->acknowledge($pending, $event->eventId);
        }

        return $checkpoint;
    }

    private function candleEvent(
        string $origin,
        string $receivedTimestamp,
        string $bar = '1m',
        string $symbol = 'BTCUSDT',
        string $exchangeTimestamp = '2026-07-22T10:00:00.000000Z',
        string $sequence = '1',
    ): PaperMarketEvent
    {
        $channel = match ($bar) {
            '1m' => PaperMarketDataChannel::CANDLE_1M,
            '5m' => PaperMarketDataChannel::CANDLE_5M,
            '15m' => PaperMarketDataChannel::CANDLE_15M,
            '1H' => PaperMarketDataChannel::CANDLE_1H,
            default => throw new \InvalidArgumentException('Invalid candle bar.'),
        };
        $nativeSymbol = $symbol === 'BTCUSDT' ? 'BTC-USDT-SWAP' : 'ETH-USDT-SWAP';

        return PaperMarketEvent::create(
            venue: PaperMarketDataVenue::OKX,
            symbol: $symbol,
            channel: $channel,
            exchangeTimestamp: new \DateTimeImmutable($exchangeTimestamp),
            receivedTimestamp: new \DateTimeImmutable($receivedTimestamp),
            sequence: $sequence,
            payload: [
                'native_symbol' => $nativeSymbol,
                'bar' => $bar === '1H' ? '1h' : $bar,
                'open' => '65000.1',
                'high' => '65100.0',
                'low' => '64900.0',
                'close' => '65050.0',
                'volume_contracts' => '10',
                'volume_base' => '0.1',
                'volume_quote' => '6505.0',
                'confirmed' => true,
                'origin' => $origin,
            ],
        );
    }

    private function tradeEvent(
        string $origin,
        bool $aggregated = false,
        string $tradeId = '242720721',
        string $price = '65000.1',
        string $sequence = '1',
        string $receivedTimestamp = '2026-07-22T10:00:01.000000Z',
        string $symbol = 'BTCUSDT',
    ): PaperMarketEvent
    {
        $nativeSymbol = $symbol === 'BTCUSDT' ? 'BTC-USDT-SWAP' : 'ETH-USDT-SWAP';

        return PaperMarketEvent::create(
            venue: PaperMarketDataVenue::OKX,
            symbol: $symbol,
            channel: PaperMarketDataChannel::PUBLIC_TRADE,
            exchangeTimestamp: new \DateTimeImmutable('2026-07-22T10:00:00.123000Z'),
            receivedTimestamp: new \DateTimeImmutable($receivedTimestamp),
            sequence: $sequence,
            payload: [
                'native_symbol' => $nativeSymbol,
                'trade_id' => $tradeId,
                'price' => $price,
                'size_contracts' => '3',
                'taker_side' => 'buy',
                'aggregate_count' => $aggregated ? '2' : null,
                'source' => '0',
                'source_seq_id' => $aggregated ? '9001' : null,
                'origin' => $origin,
            ],
        );
    }

    private function bookEvent(
        string $origin = 'ws_books',
        string $sourceSequence = '9001',
        string $symbol = 'BTCUSDT',
        int $sourceEpoch = 2,
        string $sequence = '1',
    ): PaperMarketEvent
    {
        $nativeSymbol = $symbol === 'BTCUSDT' ? 'BTC-USDT-SWAP' : 'ETH-USDT-SWAP';

        return PaperMarketEvent::create(
            venue: PaperMarketDataVenue::OKX,
            symbol: $symbol,
            channel: PaperMarketDataChannel::TOP_OF_BOOK,
            exchangeTimestamp: new \DateTimeImmutable('2026-07-22T10:00:00.123000Z'),
            receivedTimestamp: new \DateTimeImmutable('2026-07-22T10:00:01.000000Z'),
            sequence: $sequence,
            payload: [
                'native_symbol' => $nativeSymbol,
                'bid_price' => '65000.0',
                'bid_size_contracts' => '10',
                'bid_order_count' => '2',
                'ask_price' => '65000.1',
                'ask_size_contracts' => '8',
                'ask_order_count' => '3',
                'source_seq_id' => $sourceSequence,
                'source_prev_seq_id' => '9000',
                'source_epoch' => $sourceEpoch,
                'origin' => $origin,
            ],
        );
    }

    private function ethBookEvent(): PaperMarketEvent
    {
        return PaperMarketEvent::create(
            venue: PaperMarketDataVenue::OKX,
            symbol: 'ETHUSDT',
            channel: PaperMarketDataChannel::TOP_OF_BOOK,
            exchangeTimestamp: new \DateTimeImmutable('2026-07-22T10:00:00.123000Z'),
            receivedTimestamp: new \DateTimeImmutable('2026-07-22T10:00:01.000000Z'),
            sequence: '1',
            payload: [
                'native_symbol' => 'ETH-USDT-SWAP',
                'bid_price' => '3500.0',
                'bid_size_contracts' => '10',
                'bid_order_count' => '2',
                'ask_price' => '3500.1',
                'ask_size_contracts' => '8',
                'ask_order_count' => '3',
                'source_seq_id' => '9001',
                'source_prev_seq_id' => '9000',
                'source_epoch' => 2,
                'origin' => 'ws_books',
            ],
        );
    }

    private function connectionEvent(string $timestamp): PaperMarketEvent
    {
        return PaperMarketEvent::create(
            venue: PaperMarketDataVenue::OKX,
            symbol: 'BTCUSDT',
            channel: PaperMarketDataChannel::CONNECTION_STATE,
            exchangeTimestamp: new \DateTimeImmutable($timestamp),
            receivedTimestamp: new \DateTimeImmutable($timestamp),
            sequence: '1',
            payload: [
                'native_symbol' => 'BTC-USDT-SWAP',
                'state' => 'connected',
                'connection_epoch' => 2,
            ],
        );
    }

    private function stoppedConnectionEvent(string $symbol = 'BTCUSDT'): PaperMarketEvent
    {
        $nativeSymbol = $symbol === 'BTCUSDT' ? 'BTC-USDT-SWAP' : 'ETH-USDT-SWAP';

        return PaperMarketEvent::create(
            venue: PaperMarketDataVenue::OKX,
            symbol: $symbol,
            channel: PaperMarketDataChannel::CONNECTION_STATE,
            exchangeTimestamp: new \DateTimeImmutable('2026-07-22T10:00:10.000000Z'),
            receivedTimestamp: new \DateTimeImmutable('2026-07-22T10:00:10.000000Z'),
            sequence: '1',
            payload: [
                'connection_epoch' => 2,
                'native_symbol' => $nativeSymbol,
                'state' => 'stopped',
            ],
        );
    }

    private function initialBoundaryEvent(
        string $symbol = 'BTCUSDT',
        string $sourceSequence = '8001',
    ): PaperMarketEvent
    {
        $nativeSymbol = $symbol === 'BTCUSDT' ? 'BTC-USDT-SWAP' : 'ETH-USDT-SWAP';

        return PaperMarketEvent::create(
            venue: PaperMarketDataVenue::OKX,
            symbol: $symbol,
            channel: PaperMarketDataChannel::SNAPSHOT_BOUNDARY,
            exchangeTimestamp: new \DateTimeImmutable('2026-07-22T10:00:10.000000Z'),
            receivedTimestamp: new \DateTimeImmutable('2026-07-22T10:00:10.000000Z'),
            sequence: '1',
            payload: [
                'native_symbol' => $nativeSymbol,
                'reason' => 'initial',
                'source_epoch' => 1,
                'source_seq_id' => $sourceSequence,
            ],
        );
    }

    private function sequenceGapBoundaryEvent(string $sourceSequence = '9001'): PaperMarketEvent
    {
        return PaperMarketEvent::create(
            venue: PaperMarketDataVenue::OKX,
            symbol: 'BTCUSDT',
            channel: PaperMarketDataChannel::SNAPSHOT_BOUNDARY,
            exchangeTimestamp: new \DateTimeImmutable('2026-07-22T10:00:10.000000Z'),
            receivedTimestamp: new \DateTimeImmutable('2026-07-22T10:00:10.000000Z'),
            sequence: '1',
            payload: [
                'native_symbol' => 'BTC-USDT-SWAP',
                'reason' => 'sequence_gap',
                'source_epoch' => 2,
                'source_seq_id' => $sourceSequence,
            ],
        );
    }

    private function reconnectBoundaryEvent(
        string $symbol = 'BTCUSDT',
        int $sourceEpoch = 2,
        string $sourceSequence = '9002',
    ): PaperMarketEvent
    {
        return PaperMarketEvent::create(
            venue: PaperMarketDataVenue::OKX,
            symbol: $symbol,
            channel: PaperMarketDataChannel::SNAPSHOT_BOUNDARY,
            exchangeTimestamp: new \DateTimeImmutable('2026-07-22T10:00:10.000000Z'),
            receivedTimestamp: new \DateTimeImmutable('2026-07-22T10:00:10.000000Z'),
            sequence: (string) $sourceEpoch,
            payload: [
                'native_symbol' => $symbol === 'BTCUSDT' ? 'BTC-USDT-SWAP' : 'ETH-USDT-SWAP',
                'reason' => 'reconnect',
                'source_epoch' => $sourceEpoch,
                'source_seq_id' => $sourceSequence,
            ],
        );
    }

    /** @param array<string, mixed> $state */
    private function assertCheckpointInvalid(array $state): void
    {
        try {
            OkxPaperLiveCheckpoint::fromArray($state);
            self::fail('An invalid checkpoint must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('okx_paper_live_checkpoint_invalid', $exception->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function ordinalStateFor(PaperMarketEvent $event, string $naturalIdentity): array
    {
        return $this->advanceOrdinal(
            ['schema_version' => 1, 'scopes' => []],
            $event,
            $naturalIdentity,
        );
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function advanceOrdinal(array $state, PaperMarketEvent $event, string $naturalIdentity): array
    {
        $ordinals = OkxPaperSourceOrdinal::restore($state);
        $digest = OkxPaperSourceOrdinal::assignmentDigest(
            $naturalIdentity,
            $event->exchangeTimestamp,
            $event->payload,
        );
        $ordinals->commit(
            implode('/', [$event->sourceVenue->value, $event->symbol, $event->channel->value]),
            $naturalIdentity,
            $digest,
            $event,
        );

        return $ordinals->snapshot();
    }

    private function datasetDirectory(string $name): string
    {
        $directory = $this->testRoot . '/' . $name;
        self::assertTrue(mkdir($directory, 0700));

        return $directory;
    }

    private function checkpointPath(string $datasetDirectory): string
    {
        return $datasetDirectory . '/checkpoints/okx-live/checkpoint.json';
    }

    private function acknowledgeEventForTest(
        OkxPaperLiveCheckpointStore $store,
        OkxPaperLiveCheckpoint $checkpoint,
        PaperMarketEvent $event,
        string $naturalIdentity,
        string $stream,
    ): OkxPaperLiveCheckpoint {
        $pending = $store->savePending(
            $checkpoint,
            $event,
            $this->advanceOrdinal($checkpoint->ordinalState, $event, $naturalIdentity),
            [
                'stream' => $stream,
                'frontier' => OkxPaperStreamFrontier::fromEvent($event)->toArray(),
            ],
        );

        return $store->acknowledge($pending, $event->eventId);
    }

    /** @return array{OkxPaperLiveCheckpoint, array<string, mixed>} */
    private function persistExplicitFailureAfterPendingConflict(string $directory): array
    {
        $store = new OkxPaperLiveCheckpointStore($directory);
        $fresh = $store->loadOrCreate(self::DATASET_ID, self::CONFIGURATION_SHA256);
        $accepted = $this->tradeEvent('rest_recovery');
        $acknowledged = $this->acknowledgeEventForTest(
            $store,
            $fresh,
            $accepted,
            'trade|242720721',
            'BTCUSDT/rest/public_trade',
        );
        $conflicting = $this->tradeEvent(
            'rest_recovery',
            false,
            '242720720',
            '64999.9',
            '2',
        );
        $pending = $store->savePending(
            $acknowledged,
            $conflicting,
            $this->advanceOrdinal(
                $acknowledged->ordinalState,
                $conflicting,
                'trade|242720720',
            ),
            [
                'stream' => 'BTCUSDT/rest/public_trade',
                'frontier' => OkxPaperStreamFrontier::fromEvent($conflicting)->toArray(),
            ],
        );
        $path = $this->checkpointPath($directory);
        $beforeInvalidAcknowledgement = file_get_contents($path);
        try {
            $store->acknowledge($pending, $conflicting->eventId);
            self::fail('The non-monotonic pending frontier must conflict.');
        } catch (OkxPaperLiveIntegrityException) {
            self::addToAssertionCount(1);
        }
        self::assertSame($beforeInvalidAcknowledgement, file_get_contents($path));

        self::assertTrue(
            method_exists($store, 'fail'),
            'The store needs an explicit transactional failure operation.',
        );
        $failed = $store->fail($pending, 'market_event_identity_conflict');

        self::assertSame('failed', $failed->phase);
        self::assertSame('market_event_identity_conflict', $failed->failureReason);
        self::assertNull($failed->pendingEvent);
        self::assertNull($failed->pendingFrontier);
        self::assertSame($pending->ordinalState, $failed->ordinalState);
        self::assertSame(
            '242720721',
            $failed->streamFrontiers['BTCUSDT/rest/public_trade']?->sourceIdentity,
        );
        self::assertNotSame($beforeInvalidAcknowledgement, file_get_contents($path));

        return [$failed, $pending->ordinalState];
    }

    /** @param array<string, mixed> $state */
    private function replaceCheckpointState(string $datasetDirectory, array $state): void
    {
        $path = $this->checkpointPath($datasetDirectory);
        self::assertNotFalse(file_put_contents($path, CanonicalJson::encode($state) . "\n"));
        self::assertTrue(chmod($path, 0600));
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            if (file_exists($directory) || is_link($directory)) {
                unlink($directory);
            }

            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeDirectory($directory . '/' . $entry);
        }
        rmdir($directory);
    }
}

final class RecordingOkxPaperLiveCheckpointFilesystem extends PaperDatasetRecorderFilesystem
{
    /** @var list<string> */
    public array $operations = [];
    public ?string $moveSourceDirectory = null;
    public ?string $moveDestinationDirectory = null;

    public function move(string $source, string $destination, string $operation): bool
    {
        $this->operations[] = 'move:' . basename($destination);
        $this->moveSourceDirectory = dirname($source);
        $this->moveDestinationDirectory = dirname($destination);

        return parent::move($source, $destination, $operation);
    }

    public function sync($handle, string $operation): bool
    {
        $this->operations[] = 'sync:' . $operation;

        return parent::sync($handle, $operation);
    }
}

final class FailingOkxPaperLiveCheckpointFilesystem extends PaperDatasetRecorderFilesystem
{
    public bool $failCheckpointSync = false;

    public function sync($handle, string $operation): bool
    {
        if ($this->failCheckpointSync && $operation === 'okx_paper_live_checkpoint_sync') {
            return false;
        }

        return parent::sync($handle, $operation);
    }
}

final class ReplacingDirectoryAfterTemporaryOpenFilesystem extends PaperDatasetRecorderFilesystem
{
    public bool $replaceDirectory = false;
    public bool $wroteCheckpointAfterReplacement = false;

    public function createPrivateFile(#[\SensitiveParameter] string $path, string $operation)
    {
        $handle = parent::createPrivateFile($path, $operation);
        if ($handle === false
            || !$this->replaceDirectory
            || $operation !== 'okx_paper_live_checkpoint_create'
        ) {
            return $handle;
        }

        $directory = dirname($path);
        if (!rename($directory, $directory . '-displaced') || !mkdir($directory, 0700)) {
            throw new \RuntimeException('Unable to inject the directory replacement.');
        }

        return $handle;
    }

    public function write($handle, #[\SensitiveParameter] string $contents, string $operation): int|false
    {
        if ($this->replaceDirectory && $operation === 'okx_paper_live_checkpoint_write') {
            $this->wroteCheckpointAfterReplacement = true;
        }

        return parent::write($handle, $contents, $operation);
    }
}
