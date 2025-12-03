<?php

declare(strict_types=1);

namespace App\Command\Position;

use App\Common\Enum\PositionSide;
use App\Entity\FuturesTransaction;
use App\Logging\TradeLifecycleLogger;
use App\Repository\PositionRepository;
use App\Trading\Dto\PositionHistoryEntryDto;
use App\Trading\Storage\PositionStateRepositoryInterface;
use Brick\Math\BigDecimal;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'position:close-manual',
    description: 'Met à jour manuellement une position fermée dans la base de données'
)]
class CloseManualCommand extends Command
{
    public function __construct(
        private readonly PositionStateRepositoryInterface $positionStateRepository,
        private readonly PositionRepository $positionRepository,
        private readonly TradeLifecycleLogger $tradeLifecycleLogger,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('symbol', InputArgument::REQUIRED, 'Symbole de la position (ex: ZENUSDT)')
            ->addArgument('side', InputArgument::REQUIRED, 'Side de la position (LONG ou SHORT)')
            ->addOption('entry-price', null, InputOption::VALUE_REQUIRED, 'Prix d\'entrée', null)
            ->addOption('exit-price', 'x', InputOption::VALUE_REQUIRED, 'Prix de sortie', null)
            ->addOption('size', 's', InputOption::VALUE_REQUIRED, 'Taille de la position', null)
            ->addOption('realized-pnl', 'p', InputOption::VALUE_REQUIRED, 'PnL réalisé en USDT', null)
            ->addOption('fees', 'f', InputOption::VALUE_OPTIONAL, 'Frais en USDT (négatif pour débit)', null)
            ->addOption('closed-at', 'c', InputOption::VALUE_REQUIRED, 'Date de fermeture (format: Y-m-d H:i:s ou Y-m-d H:i:s.u)', null)
            ->addOption('leverage', 'l', InputOption::VALUE_OPTIONAL, 'Levier utilisé', null)
            ->addOption('reason-code', 'r', InputOption::VALUE_OPTIONAL, 'Code de raison de la fermeture', null)
            ->addOption('exchange', null, InputOption::VALUE_OPTIONAL, 'Exchange (ex: bitmart)', null)
            ->addOption('account-id', null, InputOption::VALUE_OPTIONAL, 'ID du compte', null)
            ->addOption('run-id', null, InputOption::VALUE_OPTIONAL, 'ID du run MTF', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $symbol = strtoupper($input->getArgument('symbol'));
        $sideStr = strtoupper($input->getArgument('side'));
        $entryPrice = $input->getOption('entry-price');
        $exitPrice = $input->getOption('exit-price');
        $size = $input->getOption('size');
        $realizedPnl = $input->getOption('realized-pnl');
        $fees = $input->getOption('fees');
        $closedAtStr = $input->getOption('closed-at');
        $leverage = $input->getOption('leverage');
        $reasonCode = $input->getOption('reason-code');
        $exchange = $input->getOption('exchange');
        $accountId = $input->getOption('account-id');
        $runId = $input->getOption('run-id');

        // Validation du side
        try {
            $side = PositionSide::from(strtolower($sideStr));
        } catch (\ValueError $e) {
            $io->error("Side invalide: $sideStr. Utilisez LONG ou SHORT.");
            return Command::FAILURE;
        }

        // Récupération ou création de la position existante
        $existingPosition = $this->positionRepository->findOneBySymbolSide($symbol, $side->value);
        
        // Déterminer openedAt
        $openedAt = $existingPosition?->getInsertedAt() 
            ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        // Validation des paramètres requis
        if ($entryPrice === null) {
            if ($existingPosition !== null && $existingPosition->getAvgEntryPrice() !== null) {
                $entryPrice = $existingPosition->getAvgEntryPrice();
                $io->note("Prix d'entrée récupéré depuis la position existante: $entryPrice");
            } else {
                $io->error('Le prix d\'entrée est requis (--entry-price) ou doit exister dans la position.');
                return Command::FAILURE;
            }
        }

        if ($exitPrice === null) {
            $io->error('Le prix de sortie est requis (--exit-price).');
            return Command::FAILURE;
        }

        if ($size === null) {
            if ($existingPosition !== null && $existingPosition->getSize() !== null) {
                $size = $existingPosition->getSize();
                $io->note("Taille récupérée depuis la position existante: $size");
            } else {
                $io->error('La taille est requise (--size) ou doit exister dans la position.');
                return Command::FAILURE;
            }
        }

        if ($realizedPnl === null) {
            $io->error('Le PnL réalisé est requis (--realized-pnl).');
            return Command::FAILURE;
        }

        // Parsing de la date de fermeture
        if ($closedAtStr === null) {
            $closedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $io->note("Date de fermeture non fournie, utilisation de la date actuelle: {$closedAt->format('Y-m-d H:i:s')}");
        } else {
            try {
                // Essayer avec microsecondes d'abord
                $closedAt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $closedAtStr, new \DateTimeZone('UTC'));
                if ($closedAt === false) {
                    // Essayer sans microsecondes
                    $closedAt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $closedAtStr, new \DateTimeZone('UTC'));
                }
                if ($closedAt === false) {
                    throw new \InvalidArgumentException("Format de date invalide: $closedAtStr");
                }
            } catch (\Exception $e) {
                $io->error("Format de date invalide: $closedAtStr. Utilisez Y-m-d H:i:s ou Y-m-d H:i:s.u");
                return Command::FAILURE;
            }
        }

        // Conversion en BigDecimal
        try {
            $entryPriceBd = BigDecimal::of($entryPrice);
            $exitPriceBd = BigDecimal::of($exitPrice);
            $sizeBd = BigDecimal::of($size);
            $realizedPnlBd = BigDecimal::of($realizedPnl);
            $feesBd = $fees !== null ? BigDecimal::of($fees) : null;
        } catch (\Exception $e) {
            $io->error("Erreur de conversion numérique: {$e->getMessage()}");
            return Command::FAILURE;
        }

        // Affichage des informations avant confirmation
        $io->section('Informations de la position à fermer');
        $io->table(
            ['Champ', 'Valeur'],
            [
                ['Symbole', $symbol],
                ['Side', $side->value],
                ['Prix d\'entrée', $entryPrice],
                ['Prix de sortie', $exitPrice],
                ['Taille', $size],
                ['PnL réalisé', $realizedPnl . ' USDT'],
                ['Frais', $fees !== null ? $fees . ' USDT' : 'Non spécifié'],
                ['Date d\'ouverture', $openedAt->format('Y-m-d H:i:s')],
                ['Date de fermeture', $closedAt->format('Y-m-d H:i:s')],
                ['Position existante', $existingPosition !== null ? "Oui (ID: {$existingPosition->getId()})" : 'Non (sera créée)'],
            ]
        );

        // Calcul du reasonCode si non fourni
        if ($reasonCode === null) {
            $realizedPnlFloat = (float)$realizedPnlBd->__toString();
            $reasonCode = $realizedPnlFloat < 0.0 ? 'loss_or_stop'
                : ($realizedPnlFloat > 0.0 ? 'profit_or_tp' : 'closed_flat');
            $io->note("Code de raison auto-déterminé: $reasonCode");
        }

        // Confirmation
        if (!$io->confirm('Confirmer la mise à jour de cette position ?', true)) {
            $io->warning('Opération annulée.');
            return Command::FAILURE;
        }

        try {
            // Création du DTO
            $historyDto = new PositionHistoryEntryDto(
                symbol: $symbol,
                side: $side,
                size: $sizeBd,
                entryPrice: $entryPriceBd,
                exitPrice: $exitPriceBd,
                realizedPnl: $realizedPnlBd,
                fees: $feesBd,
                openedAt: $openedAt,
                closedAt: $closedAt,
                raw: [
                    'source' => 'manual_close_command',
                    'leverage' => $leverage,
                    'closed_at_manual' => $closedAt->format('Y-m-d H:i:s'),
                ]
            );

            // Sauvegarde de la position fermée
            $this->positionStateRepository->saveClosedPosition($historyDto);
            $io->success('Position fermée sauvegardée avec succès.');

            // Récupération de l'ID de la position après sauvegarde
            $position = $this->positionRepository->findOneBySymbolSide($symbol, $side->value);
            $positionId = $position !== null ? (string)$position->getId() : sprintf('%s:%s:%s', $symbol, strtolower($side->value), $closedAt->format('U'));

            // Création de l'événement TradeLifecycleEvent
            $this->tradeLifecycleLogger->logPositionClosed(
                symbol: $symbol,
                positionId: $positionId,
                side: $side->value,
                runId: $runId,
                exchange: $exchange,
                accountId: $accountId,
                reasonCode: $reasonCode,
                extra: [
                    'source' => 'manual_close_command',
                    'entry_price' => $entryPrice,
                    'exit_price' => $exitPrice,
                    'size' => $size,
                    'realized_pnl' => $realizedPnl,
                    'fees' => $fees,
                    'leverage' => $leverage,
                ]
            );
            $io->success('Événement TradeLifecycleEvent créé avec succès.');

            // Création des transactions futures_transaction
            $transactionsCreated = [];
            
            // Transaction pour le realized PnL (flow_type=2)
            if (!$realizedPnlBd->isZero()) {
                $pnlTransaction = new FuturesTransaction();
                $pnlTransaction->setSymbol($symbol);
                $pnlTransaction->setFlowType(2); // realized PnL
                $pnlTransaction->setAmount($realizedPnlBd->__toString());
                $pnlTransaction->setCurrency('USDT');
                $pnlTransaction->setHappenedAt($closedAt);
                $pnlTransaction->setPosition($position);
                $pnlTransaction->setRawData([
                    'source' => 'manual_close_command',
                    'realized_pnl' => $realizedPnl,
                ]);
                $this->em->persist($pnlTransaction);
                $transactionsCreated[] = 'Realized PnL (flow_type=2)';
            }

            // Transaction pour les fees (flow_type=4)
            if ($feesBd !== null && !$feesBd->isZero()) {
                $feesTransaction = new FuturesTransaction();
                $feesTransaction->setSymbol($symbol);
                $feesTransaction->setFlowType(4); // commission
                $feesTransaction->setAmount($feesBd->__toString());
                $feesTransaction->setCurrency('USDT');
                $feesTransaction->setHappenedAt($closedAt);
                $feesTransaction->setPosition($position);
                $feesTransaction->setRawData([
                    'source' => 'manual_close_command',
                    'fees' => $fees,
                ]);
                $this->em->persist($feesTransaction);
                $transactionsCreated[] = 'Commission fees (flow_type=4)';
            }

            if (!empty($transactionsCreated)) {
                $this->em->flush();
                $io->success('Transactions futures_transaction créées: ' . implode(', ', $transactionsCreated));
            }

            // Affichage du résumé final
            $io->section('Résumé');
            $summaryRows = [
                ['positions', 'Mise à jour/Création', '✅'],
                ['trade_lifecycle_event', 'Création événement POSITION_CLOSED', '✅'],
            ];
            
            if (!empty($transactionsCreated)) {
                foreach ($transactionsCreated as $txType) {
                    $summaryRows[] = ['futures_transaction', "Création $txType", '✅'];
                }
            }
            
            $io->table(
                ['Table', 'Action', 'Statut'],
                $summaryRows
            );

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error("Erreur lors de la mise à jour: {$e->getMessage()}");
            if ($output->isVerbose()) {
                $io->writeln('Trace: ' . $e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }
}

