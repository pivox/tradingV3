<?php

declare(strict_types=1);

namespace App\Command;

use App\Exchange\Okx\OkxConfig;
use App\Exchange\Okx\PrivateWebSocket\OkxPrivateWebSocketEndpointGuard;
use App\Exchange\Okx\PrivateWebSocket\OkxPrivateWebSocketWorker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:okx:private-ws',
    description: 'Run the read-only OKX demo private WebSocket worker.',
)]
final class OkxPrivateWebSocketCommand extends Command
{
    public function __construct(
        private readonly OkxPrivateWebSocketWorker $worker,
        private readonly OkxConfig $config,
        private readonly OkxPrivateWebSocketEndpointGuard $endpointGuard,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->assertConfiguration();
        } catch (\Throwable $error) {
            $output->writeln('status=refused code=' . $error->getMessage());

            return Command::FAILURE;
        }

        try {
            $this->worker->run();
        } catch (\Throwable) {
            $output->writeln('status=failed code=okx_private_ws_worker_failed');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function assertConfiguration(): void
    {
        if ('demo' !== $this->config->environment) {
            throw new \RuntimeException('okx_private_ws_environment_invalid');
        }
        if (!$this->config->simulatedTrading) {
            throw new \RuntimeException('okx_private_ws_simulated_trading_required');
        }
        if ($this->config->liveEnabled) {
            throw new \RuntimeException('okx_private_ws_live_enabled');
        }
        if ('' === trim($this->config->apiKey)
            || '' === trim($this->config->apiSecret)
            || '' === trim($this->config->apiPassphrase)) {
            throw new \RuntimeException('okx_private_ws_credentials_missing');
        }

        $this->endpointGuard->assertAllowed($this->config->wsPrivateUri());
    }
}
