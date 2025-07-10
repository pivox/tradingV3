<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:dispatch:kline-task',
    description: 'Envoie une tâche Kline à Temporal via signal API.'
)]
class DispatchKlineTaskCommand extends Command
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
            ->addArgument('symbol', InputArgument::REQUIRED, 'Symbole du contrat (ex: BTC_USDT)')
            ->addOption('start', null, InputOption::VALUE_OPTIONAL, 'Start timestamp or date (optional)')
            ->addOption('end', null, InputOption::VALUE_OPTIONAL, 'End timestamp or date (optional)')
            ->addOption('interval', null, InputOption::VALUE_OPTIONAL, 'Interval (1m, 5m, 15m)', '1m')
            ->addOption('exchange', null, InputOption::VALUE_OPTIONAL, 'Exchange name', 'bitmart')
            ->addOption('response', null, InputOption::VALUE_OPTIONAL, 'Symfony API response target', '/api/kline');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $symbol = $input->getArgument('symbol');
        $start = $input->getOption('start') ?? '';
        $end = $input->getOption('end') ?? '';
        $interval = $input->getOption('interval');
        $exchange = $input->getOption('exchange');
        $responseTarget = $input->getOption('response');

        $url = sprintf(
            'https://api-cloud.bitmart.com/contract/public/kline?symbol=%s&start_time=%s&end_time=%s&interval=%s',
            $symbol,
            $start,
            $end,
            $interval
        );

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

        try {
            $this->httpClient->request('POST', $this->signalApiUrl, [
                'json' => $payload,
            ]);

            $io->success('Kline task envoyée avec succès via Signal API.');
            $io->note(json_encode($task, JSON_PRETTY_PRINT));

        } catch (\Exception $e) {
            $io->error('Erreur lors de l\'envoi de la tâche : ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
