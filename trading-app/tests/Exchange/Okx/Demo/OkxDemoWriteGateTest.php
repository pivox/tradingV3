<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Okx\Demo;

use App\Exchange\Okx\Demo\FilesystemOkxDemoTrip;
use App\Exchange\Okx\Demo\OkxDemoWriteGate;
use App\Exchange\Okx\Demo\OkxDemoWriteKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(OkxDemoWriteGate::class)]
#[CoversClass(OkxDemoWriteKind::class)]
#[CoversClass(FilesystemOkxDemoTrip::class)]
final class OkxDemoWriteGateTest extends TestCase
{
    /**
     * @return iterable<string,array{OkxDemoWriteKind}>
     */
    public static function kinds(): iterable
    {
        foreach (OkxDemoWriteKind::cases() as $kind) {
            yield $kind->value => [$kind];
        }
    }

    #[DataProvider('kinds')]
    public function testEveryKindIsAllowedWhenEverythingIsClear(OkxDemoWriteKind $kind): void
    {
        $decision = $this->evaluate(new OkxDemoWriteHarness(OkxDemoWriteHarness::config()), $kind);

        self::assertTrue($decision->allowed);
        self::assertSame($kind, $decision->kind);
        self::assertFalse($decision->exemptionApplied());
    }

    #[DataProvider('kinds')]
    public function testStalePrivateStreamBlocksOnlyNonProtectiveKinds(OkxDemoWriteKind $kind): void
    {
        $harness = new OkxDemoWriteHarness(OkxDemoWriteHarness::config());
        $harness->healthyPrivateStream = false;

        $decision = $this->evaluate($harness, $kind);

        if ($kind === OkxDemoWriteKind::PROTECTIVE) {
            self::assertTrue($decision->allowed);
            self::assertTrue($decision->exemptionApplied());
            self::assertContains('private_ws_not_connected', $decision->exemptedReasons);
        } else {
            self::assertFalse($decision->allowed);
            self::assertContains('private_ws_not_connected', $decision->reasons);
            self::assertFalse($decision->exemptionApplied());
        }
    }

    /**
     * @return iterable<string,array{array<string,mixed>,string}>
     */
    public static function nonExemptConfigs(): iterable
    {
        yield 'env' => [['environment' => 'live', 'live' => true], 'okx_env_not_demo'];
        yield 'simulated' => [['simulated' => false], 'okx_simulated_trading_disabled'];
        yield 'okx demo' => [['demoEnabled' => false], 'okx_demo_trading_disabled'];
        yield 'global demo' => [['globalEnabled' => false], 'demo_trading_disabled'];
        yield 'live' => [['live' => true], 'okx_live_enabled'];
        yield 'endpoint' => [['apiBaseUri' => 'https://www.okx.com'], 'okx_private_rest_endpoint_not_allowed'];
    }

    /**
     * @param array<string,mixed> $overrides
     */
    #[DataProvider('nonExemptConfigs')]
    public function testFlagsAndEndpointNeverExemptEvenForProtective(array $overrides, string $reason): void
    {
        foreach (OkxDemoWriteKind::cases() as $kind) {
            $harness = new OkxDemoWriteHarness(OkxDemoWriteHarness::config(...$overrides));
            $harness->healthyPrivateStream = false;

            $decision = $this->evaluate($harness, $kind);

            self::assertFalse($decision->allowed, $kind->value);
            self::assertContains($reason, $decision->reasons, $kind->value);
        }
    }

    #[DataProvider('kinds')]
    public function testMaxNotionalExemptsOnlyProtectiveReduceOnlyWrites(OkxDemoWriteKind $kind): void
    {
        $harness = new OkxDemoWriteHarness(OkxDemoWriteHarness::config(), maxNotional: 10.0);

        $decision = $this->evaluate($harness, $kind, 500.0);

        if ($kind === OkxDemoWriteKind::PROTECTIVE) {
            self::assertTrue($decision->allowed);
            self::assertTrue($decision->exemptionApplied());
            self::assertSame(['max_notional_exceeded'], $decision->exemptedReasons);
        } else {
            self::assertFalse($decision->allowed);
            self::assertContains('max_notional_exceeded', $decision->reasons);
        }
    }

    public function testProtectiveExemptionsCombineAndNeverCoverFlags(): void
    {
        $harness = new OkxDemoWriteHarness(OkxDemoWriteHarness::config(), maxNotional: 10.0);
        $harness->healthyPrivateStream = false;

        $decision = $this->evaluate($harness, OkxDemoWriteKind::PROTECTIVE, 500.0);

        self::assertTrue($decision->allowed);
        self::assertContains('max_notional_exceeded', $decision->exemptedReasons);
        self::assertContains('private_ws_not_connected', $decision->exemptedReasons);
    }

    public function testProtectiveWithoutReduceOnlyIsRefused(): void
    {
        $decision = (new OkxDemoWriteHarness(OkxDemoWriteHarness::config()))->gate()->evaluate(OkxDemoWriteKind::PROTECTIVE, 'place_order', 'BTCUSDT', 5.0, 'OKX1', [], false);

        self::assertFalse($decision->allowed);
        self::assertSame(['protective_requires_reduce_only'], $decision->reasons);
    }

    #[DataProvider('kinds')]
    public function testDurableTripRefusesEveryKindIncludingProtective(OkxDemoWriteKind $kind): void
    {
        $harness = new OkxDemoWriteHarness(OkxDemoWriteHarness::config());
        $harness->tripped = true;

        $decision = $this->evaluate($harness, $kind);

        self::assertFalse($decision->allowed);
        self::assertSame(['okx_demo_tripped'], $decision->reasons);
    }

    public function testKindFromMetadataDefaultsToEntry(): void
    {
        self::assertSame(OkxDemoWriteKind::PROTECTIVE, OkxDemoWriteKind::fromMetadata('protective'));
        self::assertSame(OkxDemoWriteKind::TAKE_PROFIT, OkxDemoWriteKind::fromMetadata('take_profit'));
        self::assertSame(OkxDemoWriteKind::ENTRY, OkxDemoWriteKind::fromMetadata('bogus'));
        self::assertSame(OkxDemoWriteKind::ENTRY, OkxDemoWriteKind::fromMetadata(null));
    }

    public function testFilesystemTripIsDurableAndFailsClosedOnlyOnMarker(): void
    {
        $path = sys_get_temp_dir() . '/okx-demo-trip-' . bin2hex(random_bytes(4));
        $trip = new FilesystemOkxDemoTrip($path);
        try {
            self::assertFalse($trip->isTripped());
            $trip->trip('audit_after_failed');
            self::assertTrue($trip->isTripped());
            self::assertSame('audit_after_failed', $trip->reason());
            self::assertTrue((new FilesystemOkxDemoTrip($path))->isTripped());
        } finally {
            @unlink($path);
        }
        self::assertFalse($trip->isTripped());
    }

    public function testEntryWithoutStopEvidenceIsRefusedButOtherKindsAreNotAffected(): void
    {
        $gate = (new OkxDemoWriteHarness(OkxDemoWriteHarness::config()))->gate();

        $entry = $gate->evaluate(OkxDemoWriteKind::ENTRY, 'place_order', 'BTCUSDT', 5.0, 'OKX1', [], false, false);
        self::assertFalse($entry->allowed);
        self::assertSame(['stop_loss_required'], $entry->reasons);

        self::assertTrue($gate->evaluate(OkxDemoWriteKind::ENTRY, 'place_order', 'BTCUSDT', 5.0, 'OKX1', [], false, true)->allowed);
        self::assertTrue($gate->evaluate(OkxDemoWriteKind::TAKE_PROFIT, 'place_order', 'BTCUSDT', 5.0, 'OKX1', [], true, null)->allowed);
        self::assertTrue($gate->evaluate(OkxDemoWriteKind::PROTECTIVE, 'place_order', 'BTCUSDT', 5.0, 'OKX1', [], true, null)->allowed);
    }

    private function evaluate(OkxDemoWriteHarness $harness, OkxDemoWriteKind $kind, float $notional = 5.0): \App\Exchange\Okx\Demo\OkxDemoWriteDecision
    {
        return $harness->gate()->evaluate($kind, 'place_order', 'BTCUSDT', $notional, 'OKX1');
    }
}
