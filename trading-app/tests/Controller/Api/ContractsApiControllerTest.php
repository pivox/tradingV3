<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Controller\Api\ContractsApiController;
use App\Kernel;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

#[CoversClass(ContractsApiController::class)]
final class ContractsApiControllerTest extends KernelTestCase
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

    public function testContractsRouteIsRegistered(): void
    {
        self::bootKernel();

        $router = self::$kernel?->getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        self::assertSame('/api/mtf/contracts', $router->generate('api_mtf_contracts'));

        $route = $router->getRouteCollection()->get('api_mtf_contracts');
        self::assertNotNull($route);
        self::assertSame(['GET'], $route->getMethods());
    }
}
