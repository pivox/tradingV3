<?php

declare(strict_types=1);

namespace App\Command\Bitmart;

use App\Repository\ContractRepository;
use App\Service\Bitmart\Private\OrdersService;
use App\Service\Bitmart\Private\PositionsService;
use App\Service\Trading\PositionOpener;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:bitmart:order:create', description: 'Crée un ordre Futures (privé)')]
final class OrderCreateCommand extends Command
{
    private const FLAG_USE_POSITION_OPENER = 'use-position-opener';

    public function __construct(
        private readonly OrdersService $orders,
        private readonly ContractRepository $contractRepository,
        private readonly PositionsService $positionsService,
        private readonly PositionOpener $positionOpener,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('symbol', InputArgument::REQUIRED, 'Symbole ex: BTCUSDT');
        $this->addArgument('side', InputArgument::REQUIRED, 'buy|sell');
        $this->addArgument('type', InputArgument::REQUIRED, 'limit|market');
        $this->addArgument('margin', InputArgument::REQUIRED, 'Montant à engager (USDT)');
        $this->addArgument('leverage', InputArgument::REQUIRED, 'Levier (ex: 10)');
        $this->addArgument('price', InputArgument::OPTIONAL, 'Prix (si limit)');
        $this->addArgument('client_order_id', InputArgument::OPTIONAL, 'Idempotence');
        $this->addOption(self::FLAG_USE_POSITION_OPENER, null, InputOption::VALUE_NONE, 'Utilise PositionOpener::openLimitWithTpSlPct');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symbol = (string) $input->getArgument('symbol');
        $side = (string) $input->getArgument('side');
        $type = (string) $input->getArgument('type');
        $margin = (float) $input->getArgument('margin');
        $leverage = (int) $input->getArgument('leverage');
        $price = $input->getArgument('price');

        $usePositionOpener = (bool) $input->getOption(self::FLAG_USE_POSITION_OPENER);

        if ($usePositionOpener) {
            if ($type !== 'limit') {
                $output->writeln('<error>Le flag --' . self::FLAG_USE_POSITION_OPENER . ' ne supporte que les ordres limit.</error>');
                return Command::FAILURE;
            }

            $finalSideUpper = $side === 'buy' ? 'LONG' : 'SHORT';

            try {
                $result = $this->positionOpener->openLimitWithTpSlPct(
                    symbol: $symbol,
                    finalSideUpper: $finalSideUpper,
                    marginUsdt: $margin,
                    leverage: $leverage,
                    timeframe: 'cli',
                    meta: ['source' => 'OrderCreateCommand']
                );
            } catch (\Throwable $e) {
                $output->writeln('<error>Echec PositionOpener: ' . $e->getMessage() . '</error>');
                return Command::FAILURE;
            }

            $output->writeln('Résultat PositionOpener:');
            $output->writeln(json_encode($result, JSON_PRETTY_PRINT));
            return Command::SUCCESS;
        }

        // Récupérer les détails du contrat
        $contract = $this->contractRepository->find($symbol);

        if (!$contract) {
            $output->writeln("<error>Contrat $symbol non trouvé</error>");
            return Command::FAILURE;
        }

        // Calculer la taille en contrats basée sur la marge et le levier
        $contractSize = $contract->getContractSize() ?? 0.001;

        // Récupérer le prix actuel depuis la base de données
        $priceArgument = $input->getArgument('price');
        $priceValue = (is_string($priceArgument) && $priceArgument !== '')
            ? (float) $priceArgument
            : ($contract->getLastPrice() ?? $contract->getIndexPrice() ?? 50000);
        if (!$priceValue || $priceValue <= 0) {
            $output->writeln("<error>Prix non disponible pour $symbol</error>");
            return Command::FAILURE;
        }

        $notional = $margin * $leverage;

        $sizeRaw = $notional / ($priceValue * $contractSize);
        $minVolume = $contract->getMinVolume() ?? 1;

        if ($sizeRaw < $minVolume) {
            $output->writeln("<error>La taille calculée ({$sizeRaw}) est inférieure au minimum ({$minVolume})</error>");
            $output->writeln("Essayez d'augmenter la marge ou le levier.");
            return Command::FAILURE;
        }

        $size = (int) floor($sizeRaw);
        if ($size < $minVolume) {
            $output->writeln("<comment>Taille ajustée au minimum ({$minVolume}) pour respecter les contraintes BitMart.</comment>");
            $size = $minVolume;
        }

        if ($size <= 0) {
            $output->writeln("<error>Taille calculée invalide (<= 0 contrat)</error>");
            return Command::FAILURE;
        }

        // Générer un client_order_id unique
        $clientOrderId = $input->getArgument('client_order_id');
        if (!$clientOrderId) {
            $clientOrderId = 'CMD_' . bin2hex(random_bytes(6));
        }

        // Mapper le side (buy/sell -> 1/4 pour one-way)
        $sideMapped = $side === 'buy' ? 1 : 4;

        $params = [
            'symbol' => $symbol,
            'client_order_id' => $clientOrderId,
            'side' => $sideMapped,
            'mode' => 1, // GTC
            'type' => $type,
            'open_type' => 'isolated',
            'leverage' => (string) $leverage,
            'size' => $size,
        ];

        $pricePrecisionRaw = $contract->getPricePrecision();
        $priceDecimals = 2;
        if ($pricePrecisionRaw !== null && $pricePrecisionRaw > 0) {
            if ($pricePrecisionRaw >= 1) {
                $priceDecimals = (int) $pricePrecisionRaw;
            } else {
                $priceDecimals = (int) max(0, round(-log10($pricePrecisionRaw)));
            }
        }

        $quantizedPrice = $priceValue;
        if ($pricePrecisionRaw !== null && $pricePrecisionRaw > 0 && $pricePrecisionRaw < 1) {
            $steps = round($priceValue / $pricePrecisionRaw);
            $quantizedPrice = $steps * $pricePrecisionRaw;
        }

        $priceFormatted = number_format($quantizedPrice, $priceDecimals, '.', '');

        if ($type === 'limit') {
            $params['price'] = $priceFormatted;
        }
        $output->writeln('Payload: '.json_encode($params, JSON_PRETTY_PRINT));

        // Définir le levier avant de créer l'ordre
        try {
            $output->writeln("Définition du levier...");
            $this->positionsService->setLeverage($symbol, $leverage, 'isolated');
            $output->writeln("Levier défini: {$leverage}x");
        } catch (\Throwable $e) {
            $output->writeln("<warning>Erreur lors de la définition du levier: {$e->getMessage()}</warning>");
        }

        $output->writeln("Calculs:");
        $output->writeln("- Marge: {$margin} USDT");
        $output->writeln("- Levier: {$leverage}x");
        $output->writeln("- Notionnel: {$notional} USDT");
        $output->writeln("- Prix: {$priceValue} USDT");
        $output->writeln("- Prix quantisé: {$quantizedPrice} USDT");
        $output->writeln("- Contract Size: {$contractSize}");
        $output->writeln("- Min Volume: {$minVolume}");
        $output->writeln("- Taille brute: {$sizeRaw}");
        $output->writeln("- Taille envoyée: {$size} contrats");
        if ($type === 'limit') {
            $output->writeln("- Prix envoyé: {$priceFormatted} USDT");
        }
        $output->writeln("");

        $resp = $this->orders->create($params);
        $output->writeln(json_encode($resp, JSON_PRETTY_PRINT));
        return Command::SUCCESS;
    }
}
