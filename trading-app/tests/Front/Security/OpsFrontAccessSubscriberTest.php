<?php

declare(strict_types=1);

namespace App\Tests\Front\Security;

use App\Front\Security\OpsFrontAccessSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

#[CoversClass(OpsFrontAccessSubscriber::class)]
final class OpsFrontAccessSubscriberTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach (['APP_ENV', 'OPS_FRONT_USER', 'OPS_FRONT_PASSWORD', 'OPS_FRONT_TOKEN'] as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }
    }

    public function testBlocksOpsFrontInProdWhenAccessIsNotConfigured(): void
    {
        $_SERVER['APP_ENV'] = 'prod';

        $event = $this->eventFor(Request::create('/app/risk'));
        (new OpsFrontAccessSubscriber())->guardOpsFront($event);

        self::assertTrue($event->hasResponse());
        self::assertSame(503, $event->getResponse()?->getStatusCode());
    }

    public function testAllowsOpsFrontWithConfiguredBearerToken(): void
    {
        $_SERVER['APP_ENV'] = 'prod';
        $_SERVER['OPS_FRONT_TOKEN'] = 'secret-token';

        $request = Request::create('/app/api/risk/summary');
        $request->headers->set('Authorization', 'Bearer secret-token');
        $event = $this->eventFor($request);

        (new OpsFrontAccessSubscriber())->guardOpsFront($event);

        self::assertFalse($event->hasResponse());
    }

    public function testBlocksOpsFrontWhenCredentialsAreWrong(): void
    {
        $_SERVER['APP_ENV'] = 'prod';
        $_SERVER['OPS_FRONT_PASSWORD'] = 'secret-password';

        $event = $this->eventFor(Request::create('/app'));
        (new OpsFrontAccessSubscriber())->guardOpsFront($event);

        self::assertTrue($event->hasResponse());
        self::assertSame(401, $event->getResponse()?->getStatusCode());
        self::assertSame('Basic realm="TradingV3 Ops"', $event->getResponse()?->headers->get('WWW-Authenticate'));
    }

    private function eventFor(Request $request): RequestEvent
    {
        return new RequestEvent(
            $this->createMock(KernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }
}
