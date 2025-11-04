<?php
declare(strict_types=1);

namespace App\Command;

use App\TradeEntry\Dto\TpSlTwoTargetsRequest;
use App\TradeEntry\Service\TpSlTwoTargetsService;
use App\TradeEntry\Types\Side;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:trade:tp2', description: 'Attache deux TP (TP1 mécanisme actuel, TP2=R2/S2) et gère SL existant')]
final class TradeTpSlTwoTargetsCommand extends Command
{
    public function __construct(
        private readonly TpSlTwoTargetsService $service,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('symbol', InputArgument::REQUIRED, 'Symbole, ex: BTCUSDT')
            ->addArgument('side', InputArgument::REQUIRED, 'long|short')
            ->addOption('entry', null, InputOption::VALUE_REQUIRED, 'Prix d\'entrée moyen (sinon lu depuis Position)')
            ->addOption('size', null, InputOption::VALUE_REQUIRED, 'Taille (contrats), sinon depuis Position')
            ->addOption('r', null, InputOption::VALUE_REQUIRED, 'R multiple pour TP1', '2.0')
            ->addOption('split', null, InputOption::VALUE_REQUIRED, 'Fraction du size sur TP1 (0..1)', '0.5')
            ->addOption('keep-sl', null, InputOption::VALUE_NONE, 'Ne pas annuler SL existant même si différent')
            ->addOption('keep-tp', null, InputOption::VALUE_NONE, 'Ne pas annuler TP existants')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symbol = (string)$input->getArgument('symbol');
        $sideRaw = strtolower((string)$input->getArgument('side'));
        try {
            $side = Side::from($sideRaw);
        } catch (\ValueError $e) {
            $output->writeln('<error>Invalid side, use long|short</error>');
            return Command::FAILURE;
        }

        $entry = $input->getOption('entry');
        $size = $input->getOption('size');
        $dto = new TpSlTwoTargetsRequest(
            symbol: $symbol,
            side: $side,
            entryPrice: $entry !== null ? (float)$entry : null,
            size: $size !== null ? (int)$size : null,
            rMultiple: (float)$input->getOption('r'),
            splitPct: (float)$input->getOption('split'),
            cancelExistingStopLossIfDifferent: !$input->getOption('keep-sl'),
            cancelExistingTakeProfits: !$input->getOption('keep-tp'),
        );

        try {
            $result = ($this->service)($dto);
        } catch (\Throwable $e) {
            $output->writeln('<error>Failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $output->writeln(sprintf('SL=%.8f TP1=%.8f TP2=%.8f', $result['sl'], $result['tp1'], $result['tp2']));
        foreach ($result['submitted'] as $row) {
            $output->writeln(sprintf(
                'Submitted %s %s @ %.8f size=%d id=%s cid=%s',
                $row['type'],
                $row['side'],
                $row['price'],
                $row['size'],
                $row['order_id'],
                $row['client_order_id'] ?? 'n/a'
            ));
        }
        if (!empty($result['cancelled'])) {
            $output->writeln('Cancelled: ' . implode(', ', $result['cancelled']));
        }

        return Command::SUCCESS;
    }
}
