<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Fake;

use App\Exchange\Fake\FakeAccountLedgerOrigin;
use App\Exchange\Fake\FakeExchangePrivateStateSnapshot;
use App\Exchange\Fake\FakeExchangeStateStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FakeAccountLedgerOrigin::class)]
#[CoversClass(FakeExchangePrivateStateSnapshot::class)]
#[CoversClass(FakeExchangeStateStore::class)]
final class FakeExchangePrivateStateSnapshotTest extends TestCase
{
    public function testFreshStateExposesAuthenticatedOriginAndCertifiedRevision(): void
    {
        $state = new FakeExchangeStateStore();

        $snapshot = $state->privateStateSnapshot();
        $origin = FakeAccountLedgerOrigin::fromBalance($snapshot->balances[0]);

        self::assertSame(1, $snapshot->stateRevision);
        self::assertSame('USDT', $origin->currency());
        self::assertSame('100000', $origin->openingBalance());
        self::assertMatchesRegularExpression('/\Asha256:[a-f0-9]{64}\z/D', $origin->identityHash());
        self::assertSame([], $snapshot->orders);
        self::assertSame([], $snapshot->positions);
        self::assertSame([], $snapshot->events);
    }

    public function testEveryPersistedMutationAdvancesAndRestoresExactRevision(): void
    {
        $path = sys_get_temp_dir() . '/fake-private-state-' . bin2hex(random_bytes(6)) . '.dat';
        try {
            $state = new FakeExchangeStateStore($path);
            $initial = $state->privateStateSnapshot();

            $state->setMarkPrice('BTCUSDT', '25123.45');
            $afterMark = $state->privateStateSnapshot();
            $state->setOrderBookTop('BTCUSDT', 25123.0, 25124.0);
            $afterBook = $state->privateStateSnapshot();
            $restored = (new FakeExchangeStateStore($path))->privateStateSnapshot();

            self::assertSame($initial->stateRevision + 1, $afterMark->stateRevision);
            self::assertSame($afterMark->stateRevision + 1, $afterBook->stateRevision);
            self::assertSame($afterBook->stateRevision, $restored->stateRevision);
            self::assertSame('25123.45', $restored->markPrices['BTCUSDT'] ?? null);
            self::assertSame(['bid' => 25123.0, 'ask' => 25124.0], $restored->orderBooks['BTCUSDT'] ?? null);
            self::assertSame(
                FakeAccountLedgerOrigin::fromBalance($afterBook->balances[0])->encoded(),
                FakeAccountLedgerOrigin::fromBalance($restored->balances[0])->encoded(),
            );
        } finally {
            @unlink($path);
            @unlink($path . '.lock');
        }
    }

    public function testStateWithoutCertifiedRevisionCannotBecomePrivateEvidence(): void
    {
        $path = sys_get_temp_dir() . '/fake-private-state-legacy-' . bin2hex(random_bytes(6)) . '.dat';
        try {
            $state = new FakeExchangeStateStore($path);
            $state->setMarkPrice('BTCUSDT', '25123.45');
            $raw = file_get_contents($path);
            self::assertIsString($raw);
            $envelope = unserialize($raw, ['allowed_classes' => true]);
            self::assertIsArray($envelope);
            self::assertIsArray($envelope['payload'] ?? null);
            unset(
                $envelope['payload']['stateRevision'],
                $envelope['payload']['stateRevisionCertified'],
            );
            $envelope['payload_checksum'] = hash('sha256', serialize($envelope['payload']));
            self::assertIsInt(file_put_contents($path, serialize($envelope)));

            $legacy = new FakeExchangeStateStore($path);

            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('fake_exchange_state_revision_uncertified');
            $legacy->privateStateSnapshot();
        } finally {
            @unlink($path);
            @unlink($path . '.lock');
        }
    }
}
