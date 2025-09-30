<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\Trading\PositionOpener;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:positions:open-limit',
    description: 'Ouvre une position LIMIT (levier auto calculé, SL/TP en % ; Dead-Man’s Switch optionnel)'
)]
final class OpenLimitPositionCommand extends Command
{
    public function __construct(
        private readonly PositionOpener $positionOpener,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('symbol', InputArgument::REQUIRED, 'Symbole Futures (ex: BTCUSDT)')
            ->addOption('side', null, InputOption::VALUE_REQUIRED, "LONG ou SHORT", 'LONG')
            ->addOption('margin', null, InputOption::VALUE_REQUIRED, 'Marge en USDT', '5')
            ->addOption('sl', null, InputOption::VALUE_REQUIRED, 'Stop-loss en % (ex: 5 pour 5%)', '5')
            ->addOption('tp', null, InputOption::VALUE_REQUIRED, 'Take-profit en % (ex: 10 pour 10%)', '10')
            ->addOption('timeframe', null, InputOption::VALUE_REQUIRED, "Pour logs/meta", 'manual')
            ->addOption('expire', null, InputOption::VALUE_OPTIONAL, "Annulation auto (cancel-all-after) en secondes (>=5, 0 pour désactiver)", null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $symbol  = strtoupper((string)$input->getArgument('symbol'));
        $side    = strtoupper((string)$input->getOption('side')) === 'SHORT' ? 'SHORT' : 'LONG';
        $margin  = (float)$input->getOption('margin');
        $slPct   = ((float)$input->getOption('sl')) / 100.0;   // ex: 5 -> 0.05
        $tpPct   = ((float)$input->getOption('tp')) / 100.0;   // ex: 10 -> 0.10
        $tf      = (string)$input->getOption('timeframe');
        $expire  = $input->getOption('expire');
        $expireI = $expire === null ? null : (int)$expire;

        try {
            $io->title('Ouverture LIMIT BitMart (levier auto)');
            $io->listing([
                "Symbol : $symbol",
                "Side   : $side",
                sprintf("Marge : %.2f USDT", $margin),
                sprintf("SL    : %.2f %%", $slPct*100),
                sprintf("TP    : %.2f %%", $tpPct*100),
                "Expire : " . ($expireI === null ? 'n/a' : $expireI . ' s'),
                "TF     : $tf",
            ]);

            $result = $this->positionOpener->openLimitAutoLevWithTpSlPct(
                symbol: $symbol,
                finalSideUpper: $side,
                marginUsdt: $margin,
                slPct: $slPct,
                tpPct: $tpPct,
                timeframe: $tf,
                meta: ['from' => 'command'],
                expireAfterSec: $expireI
            );

            $io->success('Ordre LIMIT envoyé (levier auto).');
            $io->section('Récapitulatif');
            $io->listing([
                "OrderId        : " . ($result['order_id'] ?? 'n/a'),
                "ClientOrderId  : " . ($result['client_order_id'] ?? 'n/a'),
                sprintf("Levier        : %dx", $result['leverage']),
                sprintf("Contracts     : %d", $result['contracts']),
                sprintf("Prix limite   : %.6f", $result['limit']),
                sprintf("SL            : %.6f", $result['sl']),
                sprintf("TP            : %.6f", $result['tp']),
                sprintf("Notional      : %.4f USDT", $result['notional']),
            ]);

            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            $this->logger->error('OpenLimitPositionCommand (auto-lev) failed', [
                'symbol' => $symbol,
                'error'  => $e->getMessage(),
            ]);
            return Command::FAILURE;
        }
    }
}
