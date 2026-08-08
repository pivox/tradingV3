<?php

declare(strict_types=1);

namespace App\Tests\MtfValidator\Service;

use App\Config\MtfValidationConfig;
use App\Config\TradeEntryConfigResolver;
use App\Contract\Indicator\IndicatorProviderInterface;
use App\Contract\MtfValidator\Dto\ContextDecisionDto;
use App\Contract\MtfValidator\Dto\ExecutionSelectionDto;
use App\Contract\MtfValidator\Dto\MtfResultDto;
use App\Contract\MtfValidator\Dto\MtfRunDto;
use App\Contract\Runtime\AuditLoggerInterface;
use App\Logging\LifecycleContextFactory;
use App\MtfValidator\Execution\ExecutionSelector;
use App\MtfValidator\Message\MtfTradingDecisionMessage;
use App\MtfValidator\MessageHandler\MtfTradingDecisionMessageHandler;
use App\MtfValidator\Repository\MtfSwitchRepository;
use App\MtfValidator\Service\Dto\SymbolResultDto;
use App\MtfValidator\Service\TradingDecisionHandler;
use App\Tests\Trading\Lineage\CanonicalSnapshotFixture;
use App\TradeEntry\Builder\TradeEntryRequestBuilder;
use App\TradeEntry\Service\TradeEntryService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

#[CoversClass(TradingDecisionHandler::class)]
#[CoversClass(MtfTradingDecisionMessageHandler::class)]
final class CanonicalPolicyRejectionTest extends TestCase
{
    public function testSynchronousDecisionReturnsStableRejectionBeforeIndicatorOrDownstreamCalls(): void
    {
        $indicator = $this->createMock(IndicatorProviderInterface::class);
        $indicator->expects(self::never())->method(self::anything());
        $audit = $this->createMock(AuditLoggerInterface::class);
        $audit->expects(self::once())->method('logAction')->with(
            'CANONICAL_POLICY_REJECTED', 'TRADE_ENTRY', 'BTCUSDT',
            self::callback(static fn (array $context): bool => ($context['reason'] ?? null) === 'canonical_risk_pct_pending_304'),
        );
        $handler = $this->handler($indicator, $audit, new NullLogger());

        $result = $handler->handleTradingDecision($this->symbolResult(), $this->runDto(), 'run-policy', $this->identity());

        self::assertSame('rejected', $result->tradingDecision['status'] ?? null);
        self::assertSame('canonical_risk_pct_pending_304', $result->tradingDecision['reason'] ?? null);
        self::assertSame([
            'canonical_risk_pct_pending_304',
            'canonical_daily_loss_policy_pending_304',
            'canonical_end_of_zone_fallback_pending_304',
            'canonical_max_concurrent_positions_pending_304',
            'canonical_mode_exposure_cap_pending_304',
            'canonical_minimum_net_r_pending_304',
        ], array_column($result->tradingDecision['blockers'] ?? [], 'code'));
    }

    public function testMessengerAcknowledgesStableCanonicalPolicyRejectionWithoutThrowing(): void
    {
        $indicator = $this->createMock(IndicatorProviderInterface::class);
        $indicator->expects(self::never())->method(self::anything());
        $audit = $this->createMock(AuditLoggerInterface::class);
        $audit->expects(self::once())->method('logAction');
        $logger = new CollectingLogger();
        $identity = $this->identity();
        $messageHandler = new MtfTradingDecisionMessageHandler($this->handler($indicator, $audit, $logger), $logger);

        $messageHandler(new MtfTradingDecisionMessage('run-policy', $this->runDto(), $this->mtfResult(), $identity));

        self::assertTrue($logger->hasMessage('[MTF Messenger] Canonical policy rejection acknowledged'));
        self::assertSame('canonical_risk_pct_pending_304', $logger->contextFor('[MTF Messenger] Canonical policy rejection acknowledged')['reason'] ?? null);
    }

    private function handler(IndicatorProviderInterface $indicator, AuditLoggerInterface $audit, LoggerInterface $logger): TradingDecisionHandler
    {
        return new TradingDecisionHandler(
            $this->uninitialized(TradeEntryService::class),
            $this->uninitialized(TradeEntryRequestBuilder::class),
            $this->uninitialized(ExecutionSelector::class),
            $indicator,
            $logger,
            $logger,
            $logger,
            $this->uninitialized(TradeEntryConfigResolver::class),
            $this->uninitialized(MtfValidationConfig::class),
            $this->uninitialized(MtfSwitchRepository::class),
            $audit,
            new LifecycleContextFactory('test', 'test-worker'),
        );
    }

    private function symbolResult(): SymbolResultDto
    {
        return new SymbolResultDto('BTCUSDT', 'READY', '1m', signalSide: 'LONG');
    }

    private function runDto(): MtfRunDto
    {
        return new MtfRunDto('BTCUSDT', 'scalping', options: ['exchange' => 'fake', 'market_type' => 'perpetual'], lineageContext: $this->identity());
    }

    private function mtfResult(): MtfResultDto
    {
        return new MtfResultDto('BTCUSDT', 'scalping', 'scalping', new \DateTimeImmutable('2026-08-01T10:00:00Z'), true, 'LONG', '1m', new ContextDecisionDto(true, null, []), new ExecutionSelectionDto('1m', 'LONG', null, []));
    }

    private function identity(): \App\Trading\Lineage\LineageContext
    {
        return CanonicalSnapshotFixture::lineage(CanonicalSnapshotFixture::config());
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function uninitialized(string $class): object
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }
}

final class CollectingLogger extends AbstractLogger
{
    /** @var list<array{message:string,context:array<string,mixed>}> */
    private array $records = [];

    /** @param array<string,mixed> $context */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['message' => (string) $message, 'context' => $context];
    }

    public function hasMessage(string $message): bool
    {
        return $this->contextFor($message) !== null;
    }

    /** @return array<string,mixed>|null */
    public function contextFor(string $message): ?array
    {
        foreach ($this->records as $record) {
            if ($record['message'] === $message) {
                return $record['context'];
            }
        }

        return null;
    }
}
