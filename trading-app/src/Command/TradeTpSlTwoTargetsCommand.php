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
            ->addOption('split', null, InputOption::VALUE_REQUIRED, 'Fraction du size sur TP1 (0..1)')
            ->addOption('keep-sl', null, InputOption::VALUE_NONE, 'Ne pas annuler SL existant même si différent')
            ->addOption('keep-tp', null, InputOption::VALUE_NONE, 'Ne pas annuler TP existants')
            ->addOption('sl-full-size', null, InputOption::VALUE_NEGATABLE, 'Forcer SL full size (--sl-full-size|--no-sl-full-size)', null)
            ->addOption('momentum', null, InputOption::VALUE_REQUIRED, 'Indice momentum pour TpSplit (faible|moyen|fort)')
            ->addOption('mtf-valid', null, InputOption::VALUE_REQUIRED, 'Nombre de signaux MTF valides (0..3)')
            ->addOption('pullback-clear', null, InputOption::VALUE_NEGATABLE, 'Précise si le pullback est propre (--pullback-clear|--no-pullback-clear)', null)
            ->addOption('late-entry', null, InputOption::VALUE_NEGATABLE, 'Indique une entrée tardive (--late-entry|--no-late-entry)', null)
            ->addOption('decision', null, InputOption::VALUE_REQUIRED, 'Decision key (journalisation)')
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

        $dto = $this->buildRequest($symbol, $side, $input);
        $decisionKey = $input->getOption('decision');
        $decisionKey = $decisionKey !== null ? (string)$decisionKey : null;

        try {
            $result = ($this->service)($dto, $decisionKey);
        } catch (\Throwable $e) {
            $output->writeln('<error>Failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if ($decisionKey !== null) {
            $output->writeln(sprintf('Decision key: %s', $decisionKey));
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

    private function buildRequest(string $symbol, Side $side, InputInterface $input): TpSlTwoTargetsRequest
    {
        $entry = $input->getOption('entry');
        $entryPrice = $entry !== null ? (float)$entry : null;

        $size = $input->getOption('size');
        $sizeValue = $size !== null ? (int)$size : null;

        $split = $this->normalizeSplit($input->getOption('split'));

        $r = $input->getOption('r');
        $rMultiple = $r !== null ? (float)$r : null;

        $slFullSize = $this->normalizeNegatableOption($input->getOption('sl-full-size'));
        $pullbackClear = $this->normalizeNegatableOption($input->getOption('pullback-clear'));
        $lateEntry = $this->normalizeNegatableOption($input->getOption('late-entry'));

        $mtfValid = $input->getOption('mtf-valid');
        $mtfValidCount = $mtfValid !== null ? max(0, min(3, (int)$mtfValid)) : null;

        $momentum = $input->getOption('momentum');
        $momentumValue = $momentum !== null ? strtolower((string)$momentum) : null;

        return new TpSlTwoTargetsRequest(
            symbol: $symbol,
            side: $side,
            entryPrice: $entryPrice,
            size: $sizeValue,
            rMultiple: $rMultiple,
            splitPct: $split,
            cancelExistingStopLossIfDifferent: !$input->getOption('keep-sl'),
            cancelExistingTakeProfits: !$input->getOption('keep-tp'),
            slFullSize: $slFullSize,
            momentum: $momentumValue,
            mtfValidCount: $mtfValidCount,
            pullbackClear: $pullbackClear,
            lateEntry: $lateEntry,
        );
    }

    private function normalizeSplit(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $split = (float)$value;
        if ($split > 1.0 && $split <= 100.0) {
            $split *= 0.01;
        }

        return max(0.0, min(1.0, $split));
    }

    private function normalizeNegatableOption(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        return (bool)$value;
    }
}
