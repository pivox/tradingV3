<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Capture;

use App\Command\PaperPublicCaptureCommand;
use App\Kernel;
use App\Trading\Paper\Capture\PaperPublicCaptureRunner;
use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperPublicLiveSourceFactory;
use App\Trading\Paper\Okx\Live\OkxPaperPublicLiveSourceFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(PaperPublicCaptureRunner::class)]
final class PaperPublicCaptureServiceWiringTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testCommandGraphContainsOnlyPublicMarketDataAndDatasetDependencies(): void
    {
        self::bootKernel(['environment' => 'test', 'debug' => false]);
        $container = static::getContainer();
        $command = $container->get(PaperPublicCaptureCommand::class);
        self::assertInstanceOf(PaperPublicCaptureCommand::class, $command);
        $runner = (new \ReflectionProperty(PaperPublicCaptureCommand::class, 'runner'))->getValue($command);
        self::assertInstanceOf(PaperPublicCaptureRunner::class, $runner);
        self::assertInstanceOf(
            OkxPaperPublicLiveSourceFactory::class,
            (new \ReflectionProperty(PaperPublicCaptureRunner::class, 'okxSourceFactory'))->getValue($runner),
        );
        self::assertInstanceOf(
            HyperliquidPaperPublicLiveSourceFactory::class,
            (new \ReflectionProperty(PaperPublicCaptureRunner::class, 'hyperliquidSourceFactory'))->getValue($runner),
        );
        self::assertSame(
            $container->getParameter('kernel.project_dir') . '/var/paper-market-data',
            (new \ReflectionProperty(PaperPublicCaptureRunner::class, 'dataRoot'))->getValue($runner),
        );

        $classes = $this->applicationGraphClasses($command);
        foreach ($classes as $class) {
            self::assertDoesNotMatchRegularExpression(
                '/(?:^App\\\\Exchange\\\\|Doctrine|EntityManager|Execution\\\\|OrderIntent|TradeEntry|Private|Signer|Wallet|Account)/',
                $class,
                'Public capture must not acquire a private/execution/database dependency: ' . $class,
            );
        }
        self::assertContains(OkxPaperPublicLiveSourceFactory::class, $classes);
        self::assertContains(HyperliquidPaperPublicLiveSourceFactory::class, $classes);
    }

    /** @return list<class-string> */
    private function applicationGraphClasses(object $root): array
    {
        $pending = [$root];
        $seen = new \SplObjectStorage();
        $classes = [];
        while ($pending !== []) {
            $object = array_pop($pending);
            if ($seen->contains($object)) {
                continue;
            }
            $seen->attach($object);
            $reflection = new \ReflectionObject($object);
            $class = $reflection->getName();
            if (!str_starts_with($class, 'App\\')) {
                continue;
            }
            $classes[] = $class;
            for ($cursor = $reflection; $cursor !== false; $cursor = $cursor->getParentClass()) {
                foreach ($cursor->getProperties() as $property) {
                    if ($property->isStatic() || !$property->isInitialized($object)) {
                        continue;
                    }
                    $value = $property->getValue($object);
                    if (is_object($value)) {
                        $pending[] = $value;
                    } elseif (is_array($value)) {
                        foreach ($value as $item) {
                            if (is_object($item)) {
                                $pending[] = $item;
                            }
                        }
                    }
                }
            }
            if (count($classes) > 256) {
                self::fail('paper_public_capture_dependency_graph_unbounded');
            }
        }
        $classes = array_values(array_unique($classes));
        sort($classes, SORT_STRING);

        /** @var list<class-string> $classes */
        return $classes;
    }
}
