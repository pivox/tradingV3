<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:dispatch:contract-task',
    description: 'Envoie une tâche Contract à Temporal via signal API.'
)]
class DispatchContractTaskCommand extends Command
{
    private HttpClientInterface $httpClient;
    private string $signalApiUrl;

    public function __construct(HttpClientInterface $httpClient, string $signalApiUrl = 'http://temporal-signal-api:5000/signal')
    {
        parent::__construct();
        $this->httpClient = $httpClient;
        $this->signalApiUrl = $signalApiUrl;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('exchange', InputArgument::OPTIONAL, 'Exchange name', 'bitmart')
            ->addArgument('response', InputArgument::OPTIONAL, 'Symfony API response target', '/api/contracts');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $exchange = $input->getArgument('exchange');
        $responseTarget = $input->getArgument('response');

        $url = 'https://api-cloud.bitmart.com/contract/v2/tickers';

        $task = [
            'exchange' => $exchange,
            'url' => $url,
            'method' => 'GET',
            'response_target' => $responseTarget,
            'payload' => [],
        ];

        $payload = [
            'Name' => 'call_api_with_throttle',
            'Input' => [$task],
        ];

        $this->httpClient->request('POST', $this->signalApiUrl, [
            'json' => $payload,
        ]);

        $io->success('Contract task envoyé via Signal API');
        $io->note(json_encode($task, JSON_PRETTY_PRINT));

        return Command::SUCCESS;
    }
}
