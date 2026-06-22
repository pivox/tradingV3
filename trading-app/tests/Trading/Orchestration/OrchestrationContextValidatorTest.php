<?php

declare(strict_types=1);

namespace App\Tests\Trading\Orchestration;

use App\Trading\Orchestration\OrchestrationContextException;
use App\Trading\Orchestration\OrchestrationContextValidator;
use App\Trading\Service\RunCorrelationId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * OBS-003 — Validation fail-closed du contexte d'orchestration : contradictions
 * vérifiables dans la requête refusées avec un code stable ; chemin legacy intact.
 */
#[CoversClass(OrchestrationContextValidator::class)]
final class OrchestrationContextValidatorTest extends TestCase
{
    private OrchestrationContextValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new OrchestrationContextValidator();
    }

    public function testLegacyRequestWithoutHeadersPasses(): void
    {
        $this->expectNotToPerformAssertions();
        $this->validator->validate(null, null, null, null, ['symbols' => ['BTCUSDT']]);
    }

    public function testConsistentContextPasses(): void
    {
        $this->expectNotToPerformAssertions();
        $runId = 'run_dashA_20260617';
        $this->validator->validate(
            $runId,
            RunCorrelationId::canonical($runId),
            's1',
            'dashA',
            ['set_id' => 's1', 'dashboard_id' => 'dashA', 'profile' => 'scalper', 'exchange' => 'bitmart'],
        );
    }

    public function testCorrelationMismatchIsRejected(): void
    {
        $this->assertCode('ORCHESTRATION_CORRELATION_MISMATCH', function (): void {
            $this->validator->validate('run_dashA_20260617', 'not-the-canonical', null, null, []);
        });
    }

    public function testCorrelationMatchesCanonicalOfLongRunId(): void
    {
        $this->expectNotToPerformAssertions();
        $long = 'run_' . str_repeat('b', 64); // > 64 => haché
        $this->validator->validate($long, RunCorrelationId::canonical($long), null, null, []);
    }

    public function testSetMismatchIsRejected(): void
    {
        $this->assertCode('ORCHESTRATION_SET_MISMATCH', function (): void {
            $this->validator->validate(null, null, 's1', null, ['set_id' => 's2']);
        });
    }

    public function testDashboardMismatchIsRejected(): void
    {
        $this->assertCode('ORCHESTRATION_DASHBOARD_MISMATCH', function (): void {
            $this->validator->validate(null, null, null, 'dashA', ['dashboard_id' => 'dashB']);
        });
    }

    public function testProfileMismatchIsRejected(): void
    {
        $this->assertCode('ORCHESTRATION_PROFILE_MISMATCH', function (): void {
            $this->validator->validate(null, null, null, null, ['profile' => 'scalper', 'mtf_profile' => 'regular']);
        });
    }

    public function testProfileCaseInsensitiveEqualPasses(): void
    {
        $this->expectNotToPerformAssertions();
        $this->validator->validate(null, null, null, null, ['profile' => 'Scalper', 'mtf_profile' => 'scalper']);
    }

    public function testExchangeMismatchIsRejected(): void
    {
        $this->assertCode('ORCHESTRATION_EXCHANGE_MISMATCH', function (): void {
            $this->validator->validate(null, null, null, null, ['exchange' => 'bitmart', 'cex' => 'okx']);
        });
    }

    public function testMarketTypeMismatchIsRejected(): void
    {
        $this->assertCode('ORCHESTRATION_MARKET_TYPE_MISMATCH', function (): void {
            $this->validator->validate(null, null, null, null, ['market_type' => 'perpetual', 'type_contract' => 'spot']);
        });
    }

    public function testHeaderTakesPriorityWhenBodyAbsent(): void
    {
        // En-tête présent mais pas de doublon dans le payload : aucune contradiction.
        $this->expectNotToPerformAssertions();
        $this->validator->validate(null, null, 's1', 'dashA', ['symbols' => ['BTCUSDT']]);
    }

    private function assertCode(string $expectedCode, callable $fn): void
    {
        try {
            $fn();
            self::fail("Expected OrchestrationContextException with code {$expectedCode}");
        } catch (OrchestrationContextException $e) {
            self::assertSame($expectedCode, $e->errorCode);
        }
    }
}
