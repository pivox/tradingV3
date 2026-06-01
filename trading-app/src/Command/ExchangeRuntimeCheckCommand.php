<?php

declare(strict_types=1);

namespace App\Command;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Contract\Provider\ExchangeProviderRegistryInterface;
use App\Exchange\Contract\ExchangeAdapterInterface;
use App\Exchange\Contract\ExchangeAdapterRegistryInterface;
use App\Exchange\Hyperliquid\HyperliquidConfig;
use App\Exchange\Okx\OkxConfig;
use App\Provider\Bitmart\Http\BitmartConfig;
use App\Provider\Context\ExchangeContext;
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
    public function __construct(
        private readonly ExchangeAdapterRegistryInterface $adapters,
        private readonly ExchangeProviderRegistryInterface $providers,
        private readonly BitmartConfig $bitmartConfig,
        private readonly OkxConfig $okxConfig,
        private readonly HyperliquidConfig $hyperliquidConfig,
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
        $providerStatus = $this->providerStatus($context);
        $credentials = $this->credentialsStatus($exchange);
        $liveTrading = $this->liveTradingStatus($exchange, $credentials);
        $privateWs = $this->privateWsStatus($adapter);
        $scheduleReady = $adapterStatus === 'found' && $providerStatus === 'found';
        $recommendedDryRun = !$scheduleReady || $credentials !== 'ok' || $liveTrading !== 'enabled';

        $output->writeln(sprintf('Exchange: %s', $exchange->value));
        $output->writeln(sprintf('Market type: %s', $marketType->value));
        $output->writeln(sprintf('Adapter: %s', $adapterStatus));
        $output->writeln(sprintf('Provider bundle: %s', $providerStatus));
        $output->writeln(sprintf('Credentials: %s', $credentials));
        $output->writeln('REST: unknown');
        $output->writeln(sprintf('Private WS: %s', $privateWs));
        $output->writeln(sprintf('Live trading: %s', $liveTrading));
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

    private function providerStatus(ExchangeContext $context): string
    {
        try {
            $this->providers->get($context);

            return 'found';
        } catch (\Throwable) {
            return 'missing';
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
            Exchange::OKX => $this->okxConfig->isDemo()
                ? ($this->okxConfig->demoTradingEnabled ? 'enabled' : 'disabled')
                : ($this->okxConfig->liveEnabled ? 'enabled' : 'disabled'),
            Exchange::HYPERLIQUID => $this->hyperliquidConfig->isTestnet()
                ? 'enabled'
                : ($this->hyperliquidConfig->mainnetEnabled ? 'enabled' : 'disabled'),
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

    private function hasOkxCredentials(): bool
    {
        return trim($this->okxConfig->apiKey) !== ''
            && trim($this->okxConfig->apiSecret) !== ''
            && trim($this->okxConfig->apiPassphrase) !== '';
    }

    private function hasBitmartCredentials(): bool
    {
        return trim($this->bitmartConfig->getApiKey()) !== ''
            && trim($this->bitmartConfig->getApiSecret()) !== ''
            && trim($this->bitmartConfig->getApiMemo()) !== '';
    }

    private function hasHyperliquidCredentials(): bool
    {
        return trim($this->hyperliquidConfig->accountAddress) !== ''
            && trim($this->hyperliquidConfig->privateKey) !== '';
    }
}
