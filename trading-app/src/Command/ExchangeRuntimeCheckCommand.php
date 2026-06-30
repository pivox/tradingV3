<?php

declare(strict_types=1);

namespace App\Command;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Contract\Provider\ExchangeProviderRegistryInterface;
use App\Exchange\Contract\ExchangeAdapterInterface;
use App\Exchange\Contract\ExchangeAdapterRegistryInterface;
use App\Exchange\Hyperliquid\HyperliquidConfig;
use App\Exchange\Dto\ExchangeCapabilities;
use App\Exchange\Okx\OkxConfig;
use App\Exchange\Readiness\ExchangeReadinessInput;
use App\Exchange\Readiness\ExchangeReadinessLevel;
use App\Exchange\Readiness\ExchangeReadinessReport;
use App\Provider\Context\ExchangeContext;
use App\Provider\Okx\OkxRuntimeCheck;
use App\Provider\Registry\ExchangeProviderBundle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:exchange:runtime-check',
    description: 'Diagnose exchange runtime readiness for Temporal MTF schedules'
)]
final class ExchangeRuntimeCheckCommand extends Command
{
    /**
     * @param array<string, string> $bitmartEnv
     */
    public function __construct(
        private readonly ExchangeAdapterRegistryInterface $adapters,
        private readonly ExchangeProviderRegistryInterface $providers,
        private readonly OkxConfig $okxConfig,
        private readonly HyperliquidConfig $hyperliquidConfig,
        private readonly array $bitmartEnv = [],
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('exchange', InputArgument::REQUIRED, 'Exchange enum value')
            ->addArgument('market_type', InputArgument::REQUIRED, 'Market type enum value');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $exchange = $this->parseExchange($input->getArgument('exchange'));
        if (!$exchange instanceof Exchange) {
            $output->writeln(sprintf('Unsupported exchange: %s', (string) $input->getArgument('exchange')));

            return Command::FAILURE;
        }

        $marketType = $this->parseMarketType($input->getArgument('market_type'));
        if (!$marketType instanceof MarketType) {
            $output->writeln(sprintf('Unsupported market type: %s', (string) $input->getArgument('market_type')));

            return Command::FAILURE;
        }

        $context = new ExchangeContext($exchange, $marketType);
        [$adapterStatus, $adapter] = $this->adapterStatus($exchange, $marketType);
        [$providerStatus, $providerBundle] = $this->providerStatus($context);
        $credentials = $this->credentialsStatus($exchange);
        $liveTrading = $this->liveTradingStatus($exchange, $credentials);
        $privateWs = $this->privateWsStatus($adapter);
        $okxReadinessReport = $exchange === Exchange::OKX
            ? $this->okxReadinessReport($marketType, $adapterStatus, $providerStatus, $credentials, $adapter, $providerBundle)
            : null;
        $scheduleReady = $this->scheduleReady($exchange, $adapterStatus, $providerStatus, $okxReadinessReport);
        $recommendedDryRun = !$scheduleReady || $credentials !== 'ok' || $liveTrading !== 'enabled';

        $output->writeln(sprintf('Exchange: %s', $exchange->value));
        $output->writeln(sprintf('Market type: %s', $marketType->value));
        $output->writeln(sprintf('Adapter: %s', $adapterStatus));
        $output->writeln(sprintf('Provider bundle: %s', $providerStatus));
        $output->writeln(sprintf('Credentials: %s', $credentials));
        $output->writeln('REST: unknown');
        $output->writeln(sprintf('Private WS: %s', $privateWs));
        $output->writeln(sprintf('Live trading: %s', $liveTrading));
        if ($exchange === Exchange::OKX) {
            // PR11: OKX is dry-run only. Surface the gate explicitly so that demo-trading
            // capability is never mistaken for live-trading authorization.
            $output->writeln('Dry-run only: yes');
            $output->writeln('Live allowed: no');
            $output->writeln(sprintf('Demo trading enabled: %s', $this->okxConfig->demoTradingEnabled ? 'yes' : 'no'));
            if ($okxReadinessReport instanceof ExchangeReadinessReport) {
                $okxReadiness = $okxReadinessReport->toArray();
                $output->writeln(sprintf('Readiness level: %s', $okxReadinessReport->readyLevel->value));
                $output->writeln(sprintf('Readiness blocking errors: %s', $this->formatReasons($this->stringReasons($okxReadiness['blocking_errors']))));
                $output->writeln(sprintf('Readiness warnings: %s', $this->formatReasons($this->stringReasons($okxReadiness['warnings']))));
                $output->writeln(sprintf('Mainnet write guard: %s', $okxReadinessReport->mainnetWriteGuard ? 'yes' : 'no'));
                $output->writeln(sprintf('Demo/testnet write guard: %s', $okxReadinessReport->demoTestnetWriteGuard ? 'yes' : 'no'));
                $output->writeln(sprintf('Stop loss capability: %s', $okxReadinessReport->stopLossCapability ? 'yes' : 'no'));
                $output->writeln(sprintf('Kill switch: %s', $okxReadinessReport->killSwitch ? 'enabled' : 'disabled'));
            }
        }
        if ($exchange === Exchange::HYPERLIQUID) {
            // PR12: Hyperliquid is dry-run only. Surface the gate explicitly so that the
            // configured network (testnet/mainnet) and the mainnet capability flag are never
            // mistaken for live-trading authorization.
            $output->writeln('Dry-run only: yes');
            $output->writeln('Live allowed: no');
            $output->writeln(sprintf('Network: %s', $this->hyperliquidConfig->normalizedEnvironment()));
            $output->writeln(sprintf('Mainnet enabled: %s', $this->hyperliquidConfig->mainnetEnabled ? 'yes' : 'no'));
        }
        $output->writeln(sprintf('Recommended dry_run: %s', $recommendedDryRun ? 'true' : 'false'));
        $output->writeln(sprintf('Schedule ready: %s', $scheduleReady ? 'yes' : 'no'));

        return Command::SUCCESS;
    }

    private function parseExchange(mixed $value): ?Exchange
    {
        if (!is_string($value)) {
            return null;
        }

        return Exchange::tryFrom(strtolower(trim($value)));
    }

    private function parseMarketType(mixed $value): ?MarketType
    {
        if (!is_string($value)) {
            return null;
        }

        return match (strtolower(trim($value))) {
            'perpetual', 'perp', 'future', 'futures' => MarketType::PERPETUAL,
            'spot' => MarketType::SPOT,
            default => null,
        };
    }

    /**
     * @return array{0: string, 1: ?ExchangeAdapterInterface}
     */
    private function adapterStatus(Exchange $exchange, MarketType $marketType): array
    {
        try {
            return ['found', $this->adapters->get($exchange, $marketType)];
        } catch (\Throwable) {
            return ['missing', null];
        }
    }

    /**
     * @return array{0: string, 1: ?ExchangeProviderBundle}
     */
    private function providerStatus(ExchangeContext $context): array
    {
        try {
            $bundle = $this->providers->get($context);

            return ['found', $bundle];
        } catch (\Throwable) {
            return ['missing', null];
        }
    }

    private function credentialsStatus(Exchange $exchange): string
    {
        return match ($exchange) {
            Exchange::BITMART => $this->hasBitmartCredentials() ? 'ok' : 'missing',
            Exchange::OKX => $this->hasOkxCredentials() ? 'ok' : 'missing',
            Exchange::HYPERLIQUID => $this->hasHyperliquidCredentials() ? 'ok' : 'missing',
            default => 'ok',
        };
    }

    private function liveTradingStatus(Exchange $exchange, string $credentials): string
    {
        if ($credentials !== 'ok') {
            return 'disabled';
        }

        return match ($exchange) {
            // PR11: OKX stays dry-run only. Demo-trading capability (OKX_DEMO_TRADING_ENABLED)
            // is NOT live trading, and OKX_LIVE_ENABLED is intentionally ignored here: live
            // remains disabled until a dedicated OKX live-readiness PR flips this gate.
            Exchange::OKX => 'disabled',
            // PR12: Hyperliquid stays dry-run only. Testnet/mainnet network selection and
            // HYPERLIQUID_MAINNET_ENABLED are NOT live-trading authorization: live remains
            // disabled until a dedicated Hyperliquid live-readiness PR flips this gate.
            Exchange::HYPERLIQUID => 'disabled',
            default => 'enabled',
        };
    }

    private function privateWsStatus(?ExchangeAdapterInterface $adapter): string
    {
        if (!$adapter instanceof ExchangeAdapterInterface) {
            return 'disabled';
        }

        return $adapter->capabilities()->supportsWebSocketPrivate ? 'enabled' : 'unsupported';
    }

    private function scheduleReady(
        Exchange $exchange,
        string $adapterStatus,
        string $providerStatus,
        ?ExchangeReadinessReport $okxReadinessReport = null,
    ): bool {
        if ($adapterStatus !== 'found' || $providerStatus !== 'found') {
            return false;
        }

        if ($exchange === Exchange::OKX) {
            return $okxReadinessReport instanceof ExchangeReadinessReport
                && \in_array($okxReadinessReport->readyLevel, [
                    ExchangeReadinessLevel::LocalDryRunReady,
                    ExchangeReadinessLevel::DemoTestnetCandidate,
                ], true);
        }

        return true;
    }

    private function okxReadinessReport(
        MarketType $marketType,
        string $adapterStatus,
        string $providerStatus,
        string $credentials,
        ?ExchangeAdapterInterface $adapter,
        ?ExchangeProviderBundle $providerBundle,
    ): ExchangeReadinessReport {
        $capabilities = $adapter instanceof ExchangeAdapterInterface ? $adapter->capabilities() : new ExchangeCapabilities();
        $environment = $this->okxConfig->normalizedEnvironment();
        [$publicConnectivity, $instrumentsLoaded, $metadataValid, $precisionValid, $publicWarnings] = $this->okxPublicReadStatus($adapterStatus, $providerStatus, $providerBundle);
        $mainnetGuard = $this->okxConfig->isDemo() && !$this->okxConfig->liveEnabled;
        $demoHeaderGuard = $this->okxConfig->isDemo() && $this->okxConfig->simulatedTrading && !$this->okxConfig->liveEnabled;
        [$privateReadProbeReady, $privateWarnings] = $credentials === 'ok' && $demoHeaderGuard
            ? $this->okxPrivateReadStatus($providerBundle)
            : [false, []];
        $privateReadReady = $publicConnectivity && $instrumentsLoaded && $credentials === 'ok' && $demoHeaderGuard && $privateReadProbeReady;

        return (new OkxRuntimeCheck())->check(new ExchangeReadinessInput(
            exchange: Exchange::OKX,
            marketType: $marketType,
            environment: $environment,
            publicConnectivity: $publicConnectivity,
            privateReadConnectivity: $privateReadReady,
            privateObservability: false,
            instrumentsLoaded: $instrumentsLoaded,
            metadataValid: $metadataValid,
            precisionValid: $precisionValid,
            accountReadable: $privateReadReady,
            permissionsRead: $privateReadReady,
            permissionsTrade: false,
            mainnetWriteGuard: $mainnetGuard,
            demoTestnetWriteGuard: $demoHeaderGuard,
            demoTestnetWriteEnabled: $this->okxConfig->demoTradingEnabled,
            stopLossCapability: $capabilities->supportsTriggerOrders || $capabilities->supportsAttachedStopLossOnEntry,
            killSwitch: true,
            dryRun: true,
            allowedMarkets: [$marketType->value],
            maxNotional: 25.0,
            warnings: array_merge($publicWarnings, $privateWarnings),
        ));
    }

    /**
     * @return array{0: bool, 1: bool, 2: bool, 3: bool, 4: list<string>}
     */
    private function okxPublicReadStatus(
        string $adapterStatus,
        string $providerStatus,
        ?ExchangeProviderBundle $providerBundle,
    ): array {
        if ($adapterStatus !== 'found' || $providerStatus !== 'found' || !$providerBundle instanceof ExchangeProviderBundle) {
            return [false, false, false, false, []];
        }

        try {
            $contracts = $providerBundle->contract()->getContracts();
        } catch (\Throwable) {
            return [false, false, false, false, ['okx_public_read_probe_failed']];
        }

        $instrumentsLoaded = $contracts !== [];

        return [true, $instrumentsLoaded, $instrumentsLoaded, $instrumentsLoaded, []];
    }

    /**
     * @return array{0: bool, 1: list<string>}
     */
    private function okxPrivateReadStatus(?ExchangeProviderBundle $providerBundle): array
    {
        if (!$providerBundle instanceof ExchangeProviderBundle) {
            return [false, []];
        }

        try {
            if ($providerBundle->account()->getAccountInfo() !== null) {
                return [true, []];
            }
        } catch (\Throwable) {
            return [false, ['okx_private_read_probe_failed']];
        }

        return [false, ['okx_private_read_probe_failed']];
    }

    /**
     * @param list<string> $reasons
     */
    private function formatReasons(array $reasons): string
    {
        if ($reasons === []) {
            return 'none';
        }

        return implode(', ', $reasons);
    }

    /**
     * @return list<string>
     */
    private function stringReasons(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $reason): bool => \is_string($reason)));
    }

    private function hasOkxCredentials(): bool
    {
        return trim($this->okxConfig->apiKey) !== ''
            && trim($this->okxConfig->apiSecret) !== ''
            && trim($this->okxConfig->apiPassphrase) !== '';
    }

    private function hasBitmartCredentials(): bool
    {
        return $this->envIsPresent('BITMART_API_KEY')
            && $this->envIsPresent('BITMART_SECRET_KEY')
            && $this->envIsPresent('BITMART_API_MEMO');
    }

    private function hasHyperliquidCredentials(): bool
    {
        return trim($this->hyperliquidConfig->accountAddress) !== ''
            && trim($this->hyperliquidConfig->privateKey) !== '';
    }

    private function envIsPresent(string $name): bool
    {
        return trim($this->envValue($name)) !== '';
    }

    private function envValue(string $name): string
    {
        if (array_key_exists($name, $this->bitmartEnv)) {
            return (string) $this->bitmartEnv[$name];
        }

        if (isset($_ENV[$name])) {
            return (string) $_ENV[$name];
        }

        if (isset($_SERVER[$name])) {
            return (string) $_SERVER[$name];
        }

        $value = getenv($name);

        return is_string($value) ? $value : '';
    }
}
