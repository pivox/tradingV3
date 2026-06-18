<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ExchangeStateController;
use App\Kernel;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

#[CoversClass(ExchangeStateController::class)]
final class ExchangeStateControllerTest extends KernelTestCase
{
    public static function setUpBeforeClass(): void
    {
        $_ENV['DEFAULT_URI'] = 'http://localhost';
        $_SERVER['DEFAULT_URI'] = 'http://localhost';
        putenv('DEFAULT_URI=http://localhost');
    }

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testOpenStateRouteIsRegisteredAsGet(): void
    {
        self::bootKernel();

        $router = self::$kernel?->getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        self::assertSame('/api/exchange/open-state', $router->generate('api_exchange_open_state'));

        $route = $router->getRouteCollection()->get('api_exchange_open_state');
        self::assertNotNull($route);
        self::assertSame(['GET'], $route->getMethods());
    }
}
