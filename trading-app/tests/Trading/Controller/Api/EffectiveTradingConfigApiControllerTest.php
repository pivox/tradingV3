<?php

declare(strict_types=1);

namespace App\Tests\Trading\Controller\Api;

use App\Trading\Controller\Api\EffectiveTradingConfigApiController;
use App\TradingCore\Config\EffectiveTradingConfigReadService;
use App\TradingCore\Config\EffectiveTradingConfigResolver;
use App\TradingCore\Config\Audit\EffectiveConfigRedactor;
use App\TradingCore\Config\Audit\EffectiveConfigViewerDocumentFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(EffectiveTradingConfigApiController::class)]
#[CoversClass(EffectiveTradingConfigReadService::class)]
final class EffectiveTradingConfigApiControllerTest extends TestCase
{
    public function testAllVersionedIdentityFieldsAreRequired(): void
    {
        $response = $this->controller()->effective(new Request(['mode_id' => 'scalping']));
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame(
            ['mode_version', 'setup_id', 'setup_version', 'exchange', 'environment', 'side'],
            $this->json($response)['error']['missing'],
        );
    }

    public function testBlockedKnownContractsReturnStructuredFailClosedResult(): void
    {
        $request = [
            'mode_id' => 'scalping', 'mode_version' => '1.0.0',
            'setup_id' => 'scalping.pullback.long', 'setup_version' => '1.0.0',
            'exchange' => 'okx', 'environment' => 'demo', 'side' => 'long',
        ];
        $response = $this->controller()->effective(new Request($request));
        $body = $this->json($response);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('config_not_executable', $body['error']['code']);
        self::assertSame($request, $body['request']);
        self::assertFalse($body['executable']);
        self::assertNotSame([], $body['blockers']);
        self::assertNull($body['config_hash']);
        self::assertSame([], $body['ordered_layers']);
        self::assertSame([], $body['provenance']);
    }

    public function testLegacyAliasesAndBitmartReturnStructuredInvalidRequestWithoutFallback(): void
    {
        $response = $this->controller()->effective(new Request([
            'mode_id' => 'scalper', 'mode_version' => '1.0.0',
            'setup_id' => 'scalping.pullback.long', 'setup_version' => '1.0.0',
            'exchange' => 'bitmart', 'environment' => 'demo', 'side' => 'long',
        ]));
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('invalid_config_request', $this->json($response)['error']['code']);
    }

    public function testDayTradingViewerAcceptsExplicitShadowCapability(): void
    {
        $response = $this->controller()->effective(new Request([
            'mode_id' => 'day_trading', 'mode_version' => '1.1.0',
            'setup_id' => 'day_trading.trend_continuation.long', 'setup_version' => '1.1.0',
            'exchange' => 'fake', 'environment' => 'test', 'side' => 'long',
            'execution_capability' => 'fake',
        ]));
        $body = $this->json($response);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('fake', $body['request']['execution_capability']);
        self::assertTrue($body['executable']);
        self::assertSame('current_preview', $body['document_kind']);
        self::assertSame('1.0.0', $body['resolver_version']);
        self::assertSame('valid', $body['validation_status']);
        self::assertSame([], $body['redacted_paths']);
    }

    public function testViewerRejectsUnknownCapabilityWithoutFallback(): void
    {
        $response = $this->controller()->effective(new Request([
            'mode_id' => 'day_trading', 'mode_version' => '1.1.0',
            'setup_id' => 'day_trading.trend_continuation.long', 'setup_version' => '1.1.0',
            'exchange' => 'fake', 'environment' => 'test', 'side' => 'long',
            'execution_capability' => 'simulated-ish',
        ]));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('invalid_config_request', $this->json($response)['error']['code']);
    }

    private function controller(): EffectiveTradingConfigApiController
    {
        $controller = new EffectiveTradingConfigApiController(new EffectiveTradingConfigReadService(
            new EffectiveTradingConfigResolver(),
            new EffectiveConfigViewerDocumentFactory(new EffectiveConfigRedactor()),
        ));
        $controller->setContainer(new class implements ContainerInterface {
            public function get(string $id): mixed { throw new \RuntimeException($id); }
            public function has(string $id): bool { return false; }
        });
        return $controller;
    }

    /** @return array<string,mixed> */
    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
