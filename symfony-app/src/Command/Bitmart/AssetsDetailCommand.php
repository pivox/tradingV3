<?php

declare(strict_types=1);

namespace App\Command\Bitmart;

use App\Service\Bitmart\Private\AssetsService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:bitmart:assets-detail', description: 'Affiche les assets futures (privé)')]
final class AssetsDetailCommand extends Command
{
    public function __construct(private readonly AssetsService $assetsService)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        //$resp = $this->assetsService->getAssetsDetail();
        try {
            $resp = $this->assetsService->getAssetsDetail();
            $code = $resp['code'] ?? null;
            if ($code !== 1000) {
                print_r('Cron 1m: échec récupération assets-detail, on annule l\'envoi'.json_encode(['response' => $resp]));
            }
            $data = $resp['data'] ?? [];
            $usdtAvailable = 0.0;
            foreach ($data as $row) {
                if (($row['currency'] ?? null) === 'USDT') {
                    $usdtAvailable = (float)($row['available_balance'] ?? 0);
                    break;
                }
            }
            if ($usdtAvailable <= 50.0) {
                print_r("notify: Cron 1m: balance USDT insuffisante ($usdtAvailable), on annule l'envoi".json_encode(['response' => $resp]));
                die;
            }
            // Log succès contrôle
            print_r(["response" => $resp]);

        } catch (\Throwable $e) {

            die($e->getMessage());
        }
        $output->writeln(json_encode($resp, JSON_PRETTY_PRINT));
        return Command::SUCCESS;
    }
}


