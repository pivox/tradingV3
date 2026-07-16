<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Fake;

use App\Exchange\Fake\FakeExchangeEvent;
use App\Exchange\Fake\FakePrivateWsDelivery;
use App\Exchange\Fake\FakePrivateWsScenario;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(FakePrivateWsDelivery::class)]
#[CoversClass(FakePrivateWsScenario::class)]
final class FakePrivateWsScenarioTest extends TestCase
{
    public function testFingerprintIsCanonicalButPayloadSensitive(): void
    {
        $first = FakePrivateWsDelivery::fromEvent('entry-1', new FakeExchangeEvent(
            'order.created',
            'btcusdt',
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            ['event_sequence' => 1, 'nested' => ['b' => 2, 'a' => 1]],
        ));
        $same = FakePrivateWsDelivery::fromEvent('entry-2', new FakeExchangeEvent(
            'order.created',
            'BTCUSDT',
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            ['nested' => ['a' => 1, 'b' => 2], 'event_sequence' => 1],
        ));
        $conflict = FakePrivateWsDelivery::fromEvent('entry-3', new FakeExchangeEvent(
            'order.created',
            'BTCUSDT',
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            ['event_sequence' => 1, 'nested' => ['a' => 9, 'b' => 2]],
        ));

        self::assertSame('1', $first->sequence);
        self::assertSame($first->fingerprint, $same->fingerprint);
        self::assertNotSame($first->fingerprint, $conflict->fingerprint);
    }

    public function testCanonicalizationPreservesListOrder(): void
    {
        $first = FakePrivateWsDelivery::fromEvent('entry-1', $this->event(
            ['event_sequence' => 'sequence-a', 'items' => [['b' => 2, 'a' => 1], 'tail']],
        ));
        $same = FakePrivateWsDelivery::fromEvent('entry-2', $this->event(
            ['items' => [['a' => 1, 'b' => 2], 'tail'], 'event_sequence' => 'sequence-a'],
        ));
        $reorderedList = FakePrivateWsDelivery::fromEvent('entry-3', $this->event(
            ['event_sequence' => 'sequence-a', 'items' => ['tail', ['a' => 1, 'b' => 2]]],
        ));

        self::assertSame($first->fingerprint, $same->fingerprint);
        self::assertNotSame($first->fingerprint, $reorderedList->fingerprint);
    }

    public function testDeliveryRoundTripsAndRejectsTamperedFingerprint(): void
    {
        $delivery = FakePrivateWsDelivery::fromEvent('entry-1', $this->event([
            'event_sequence' => 1,
            'nested' => ['value' => 2],
        ]));

        self::assertEquals($delivery, FakePrivateWsDelivery::fromArray($delivery->toArray()));

        $payload = $delivery->toArray();
        $payload['fingerprint'] = str_repeat('a', 64);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('fake_private_ws_delivery_fingerprint_invalid');
        FakePrivateWsDelivery::fromArray($payload);
    }

    /** @param array<string,mixed> $payload */
    #[DataProvider('invalidDeliveryProvider')]
    public function testDeliveryRejectsInvalidIdentity(array $payload, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        FakePrivateWsDelivery::fromArray($payload);
    }

    /**
     * @return iterable<string,array{array<string,mixed>,string}>
     */
    public static function invalidDeliveryProvider(): iterable
    {
        $event = [
            'type' => 'order.created',
            'symbol' => 'BTCUSDT',
            'occurred_at' => '2026-01-01T00:00:00+00:00',
            'payload' => ['event_sequence' => 1],
        ];

        yield 'blank fixture entry id' => [[
            'fixture_entry_id' => ' ',
            'sequence' => '1',
            'event' => $event,
            'fingerprint' => str_repeat('a', 64),
        ], 'fake_private_ws_delivery_fixture_entry_id_invalid'];

        $missingSequence = $event;
        unset($missingSequence['payload']['event_sequence']);
        yield 'missing event sequence' => [[
            'fixture_entry_id' => 'entry-1',
            'sequence' => '',
            'event' => $missingSequence,
            'fingerprint' => str_repeat('a', 64),
        ], 'fake_private_ws_delivery_sequence_invalid'];

        $arraySequence = $event;
        $arraySequence['payload']['event_sequence'] = ['invalid'];
        yield 'non scalar event sequence' => [[
            'fixture_entry_id' => 'entry-1',
            'sequence' => 'Array',
            'event' => $arraySequence,
            'fingerprint' => str_repeat('a', 64),
        ], 'fake_private_ws_delivery_sequence_invalid'];
    }

    public function testScenarioIsFiniteOrderedAndRoundTrips(): void
    {
        $first = $this->event(['event_sequence' => 2], '2026-01-01T00:00:02+00:00');
        $second = $this->event(['event_sequence' => 1], '2026-01-01T00:00:01+00:00');
        $scenario = FakePrivateWsScenario::fromEvents('out-of-order-v1', [$first, $second]);

        self::assertSame(['2', '1'], array_map(
            static fn (FakePrivateWsDelivery $delivery): string => $delivery->sequence,
            $scenario->deliveries,
        ));
        self::assertEquals($scenario, FakePrivateWsScenario::fromArray($scenario->toArray()));
    }

    public function testScenarioRejectsBlankIdAndNonListDeliveries(): void
    {
        try {
            new FakePrivateWsScenario(' ', []);
            self::fail('A scenario ID cannot be blank.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('fake_private_ws_scenario_id_invalid', $exception->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('fake_private_ws_scenario_deliveries_invalid');
        FakePrivateWsScenario::fromArray([
            'scenario_id' => 'scenario-1',
            'deliveries' => ['entry' => []],
        ]);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function event(
        array $payload,
        string $occurredAt = '2026-01-01T00:00:00+00:00',
    ): FakeExchangeEvent {
        return new FakeExchangeEvent(
            'order.created',
            'BTCUSDT',
            new \DateTimeImmutable($occurredAt),
            $payload,
        );
    }
}
