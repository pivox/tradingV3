<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'bitmart:cron:refresh-4h',
    description: 'Déclenche manuellement le cron Bitmart 4h (via l’API Symfony interne)'
)]
class BitmartCronRefresh4hCommand extends Command
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $symfonyBaseUrl = 'http://nginx' // ou http://php si ton service php est exposé
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('🚀 Déclenchement manuel du cron Bitmart (4h)');

        try {
            $url = rtrim($this->symfonyBaseUrl, '/') . '/api/bitmart/cron/refresh-4h';
            $response = $this->http->request('POST', $url);

            $data = $response->toArray(false);
            $io->success('✅ Cron Bitmart 4h exécuté avec succès');
            $io->writeln(json_encode($data, JSON_PRETTY_PRINT));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('❌ Erreur : ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
