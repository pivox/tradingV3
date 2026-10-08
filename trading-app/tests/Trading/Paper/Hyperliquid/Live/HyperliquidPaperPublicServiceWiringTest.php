<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Hyperliquid\Live;

use App\Kernel;
use App\Trading\Paper\Dataset\PaperDatasetManifestCodec;
use App\Trading\Paper\Dataset\PaperDatasetRecorderFilesystem;
use App\Trading\Paper\Hyperliquid\HyperliquidPaperPublicConfigFactory;
use App\Trading\Paper\Hyperliquid\Http\HyperliquidPaperInstrumentMetadataClientInterface;
use App\Trading\Paper\Hyperliquid\Http\HyperliquidPaperFundingRateClientInterface;
use App\Trading\Paper\Hyperliquid\Http\HyperliquidPaperPublicRestClientInterface;
use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperLivePolicy;
use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperPublicLiveSource;
use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperPublicLiveSourceFactory;
use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperPublicWebSocketTransportFactoryInterface;
use App\Trading\Paper\Hyperliquid\Live\PawlHyperliquidPaperPublicWebSocketTransportFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;

#[CoversNothing]
final class HyperliquidPaperPublicServiceWiringTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testOnlyPublicFactoryInterfacesAreResolved(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        self::assertInstanceOf(
            HyperliquidPaperPublicLiveSourceFactory::class,
            $container->get(HyperliquidPaperPublicLiveSourceFactory::class),
        );
        self::assertInstanceOf(
            PawlHyperliquidPaperPublicWebSocketTransportFactory::class,
            $container->get(
                HyperliquidPaperPublicWebSocketTransportFactoryInterface::class,
            ),
        );
        self::assertFalse($container->has(HyperliquidPaperPublicLiveSource::class));
    }

    public function testFactoryReceivesTheApplicationLoggerForTransportDiagnostics(): void
    {
        self::bootKernel();
        $factory = static::getContainer()->get(HyperliquidPaperPublicLiveSourceFactory::class);
        self::assertInstanceOf(HyperliquidPaperPublicLiveSourceFactory::class, $factory);

        $logger = (new \ReflectionProperty($factory, 'logger'))->getValue($factory);

        self::assertInstanceOf(LoggerInterface::class, $logger);
        self::assertNotInstanceOf(NullLogger::class, $logger);
        self::assertSame(
            HyperliquidPaperLivePolicy::CONNECTION_ROTATION_SECONDS,
            (new \ReflectionProperty($factory, 'connectionRotationSeconds'))->getValue($factory),
        );
    }

    public function testFactoryConstructorIsCredentialFreeAndExactlyBounded(): void
    {
        $constructor = (new \ReflectionClass(
            HyperliquidPaperPublicLiveSourceFactory::class,
        ))->getConstructor();
        self::assertNotNull($constructor);
        $parameters = $constructor->getParameters();
        self::assertSame([
            'configFactory',
            'transportFactory',
            'clock',
            'manifestCodec',
            'filesystem',
            'metadataClient',
            'fundingClient',
            'restClient',
            'logger',
            'connectionRotationSeconds',
        ], array_map(
            static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            $parameters,
        ));
        self::assertSame([
            HyperliquidPaperPublicConfigFactory::class,
            HyperliquidPaperPublicWebSocketTransportFactoryInterface::class,
            ClockInterface::class,
            PaperDatasetManifestCodec::class,
            PaperDatasetRecorderFilesystem::class,
            HyperliquidPaperInstrumentMetadataClientInterface::class,
            HyperliquidPaperFundingRateClientInterface::class,
            HyperliquidPaperPublicRestClientInterface::class,
            LoggerInterface::class,
            'float',
        ], array_map(
            static function (\ReflectionParameter $parameter): string {
                $type = $parameter->getType();
                self::assertInstanceOf(\ReflectionNamedType::class, $type);

                return $type->getName();
            },
            $parameters,
        ));
        foreach ($parameters as $parameter) {
            self::assertDoesNotMatchRegularExpression(
                '/credential|api.?key|secret|wallet|sign|private|account|(?<!rec)order|fill|execution|exchange.?action|fakeexchange/i',
                $parameter->getName() . ':' . (string) $parameter->getType(),
            );
        }
    }
}
