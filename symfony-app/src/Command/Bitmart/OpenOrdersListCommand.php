<?php

declare(strict_types=1);

namespace App\Command\Bitmart;

use App\Service\Bitmart\Private\OrdersService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:bitmart:orders:open',
    description: 'Affiche les ordres ouverts sur BitMart (optionnellement filtrés par symbole).'
)]
final class OpenOrdersListCommand extends Command
{
    public function __construct(
        private readonly OrdersService $ordersService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('symbol', InputArgument::OPTIONAL, 'Symbole à filtrer (ex: BTCUSDT)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $symbol = $input->getArgument('symbol');

        try {
            $response = $this->ordersService->open(
                $symbol ? ['symbol' => (string) $symbol] : []
            );
        } catch (\Throwable $e) {
            $io->error('Impossible de récupérer les ordres: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $orders = $this->normalizeOrders($response['orders'] ?? []);
        $planOrders = $this->normalizeOrders($response['plan_orders'] ?? []);

        if ($orders === [] && $planOrders === []) {
            $io->success('Aucun ordre ouvert.');
            return Command::SUCCESS;
        }

        if ($orders) {
            $io->section('Ordres ouverts');
            $io->table($this->headers(), array_map(fn(array $order) => $this->formatOrderRow($order), $orders));
            $io->writeln(sprintf('%d ordre(s) ouverts.', count($orders)));
        }

        if ($planOrders) {
            $io->section('Plan orders en cours');
            $io->table($this->headers(), array_map(fn(array $order) => $this->formatOrderRow($order), $planOrders));
            $io->writeln(sprintf('%d plan order(s).', count($planOrders)));
        }

        $io->success('Liste des ordres récupérée');

        return Command::SUCCESS;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeOrders(array $data): array
    {
        if (isset($data['order_list']) && is_array($data['order_list'])) {
            return $data['order_list'];
        }

        if (isset($data['orders']) && is_array($data['orders'])) {
            return $data['orders'];
        }

        if (is_array($data) && $this->isList($data)) {
            return array_filter($data, 'is_array');
        }

        if (is_array($data)) {
            $orders = [];
            foreach ($data as $entry) {
                if (is_array($entry) && (isset($entry['order_id']) || isset($entry['client_order_id']) || isset($entry['client_oid']))) {
                    $orders[] = $entry;
                }
            }
            return $orders;
        }

        return [];
    }

    private function headers(): array
    {
        return ['Symbol', 'Order ID', 'Client ID', 'Side', 'Type', 'Mode', 'Size', 'Price', 'Leverage', 'Status', 'Created At'];
    }

    /**
     * @param array<string,mixed> $order
     * @return array<int,string>
     */
    private function formatOrderRow(array $order): array
    {
        return [
            $order['symbol'] ?? 'n/a',
            $order['order_id'] ?? 'n/a',
            $order['client_order_id'] ?? ($order['client_oid'] ?? 'n/a'),
            $this->mapSide((int)($order['side'] ?? 0)),
            $order['type'] ?? 'n/a',
            $order['mode'] ?? 'n/a',
            $order['size'] ?? 'n/a',
            $order['price'] ?? 'n/a',
            $order['leverage'] ?? 'n/a',
            $order['state'] ?? $order['status'] ?? 'n/a',
            $order['created_time'] ?? $order['created_at'] ?? 'n/a',
        ];
    }

    private function isList(array $array): bool
    {
        if (function_exists('array_is_list')) {
            return array_is_list($array);
        }

        $expectedKey = 0;
        foreach ($array as $key => $_) {
            if ($key !== $expectedKey) {
                return false;
            }
            $expectedKey++;
        }
        return true;
    }

    private function mapSide(int $side): string
    {
        return match ($side) {
            1 => 'Buy (Open)',
            2 => 'Buy (Close)',
            3 => 'Sell (Close)',
            4 => 'Sell (Open)',
            default => (string) $side,
        };
    }
}
