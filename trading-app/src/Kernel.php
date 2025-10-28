<?php
namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use App\Indicator\Compiler\IndicatorCompilerPass;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;
    // Les méthodes configureContainer / configureRoutes du trait suffisent.

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new IndicatorCompilerPass());
    }
}
