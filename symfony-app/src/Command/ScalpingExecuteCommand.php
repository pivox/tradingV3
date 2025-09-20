<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\Scalping\ScalpingExecutor;
use App\Service\Signals\MtfSignalGateway;
use App\Repository\ContractRepository;
use App\Repository\KlineRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:scalping:scan',
    description: 'Scanne la BDD (klines) pour tous les contrats actifs : valide 4H→1m et exécute la/les position(s).'
)]
final class ScalpingExecuteCommand extends Command
{
    public function __construct(
        private readonly ContractRepository $contracts,
        private readonly KlineRepository    $klines,
        private readonly MtfSignalGateway   $signals,
        private readonly ScalpingExecutor   $executor,
        private readonly LoggerInterface    $logger,
    ) { parent::__construct(); }

    protected function configure(): void
    {
        $this
            ->addArgument('equity',   InputArgument::OPTIONAL, 'Capital (ex: 1000)', 100)
            ->addArgument('riskPct',  InputArgument::OPTIONAL, 'Risque % par trade (ex: 2.0)', 2.0)
            ->addOption('side',       null, InputOption::VALUE_REQUIRED, 'long|short (optionnel)', null)
            ->addOption('entry',      null, InputOption::VALUE_REQUIRED, 'Prix d’entrée (sinon auto)', null)
            ->addOption('liq',        null, InputOption::VALUE_REQUIRED, 'Prix de liquidation', null)
            ->addOption('atrPeriod',  null, InputOption::VALUE_REQUIRED, 'ATR period', 14)
            ->addOption('atrMethod',  null, InputOption::VALUE_REQUIRED, 'ATR method', 'wilder')
            ->addOption('atrK',       null, InputOption::VALUE_REQUIRED, 'k pour stop = k*ATR', 1.5)
            ->addOption('symbols',    null, InputOption::VALUE_REQUIRED, 'CSV de symbols à scanner (sinon tous)')
            ->addOption('maxPositions', null, InputOption::VALUE_REQUIRED, 'Limite de positions à ouvrir', 3)
            ->addOption('dry-run',    null, InputOption::VALUE_NONE, 'Log uniquement, ne place pas d’ordres');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $equity      = (float)$input->getArgument('equity');
        $riskPct     = (float)$input->getArgument('riskPct');
        $sideOpt     = $input->getOption('side');
        $entryOpt    = $input->getOption('entry');
        $liqOpt      = $input->getOption('liq');
        $atrPeriod   = (int)$input->getOption('atrPeriod');
        $atrMethod   = (string)$input->getOption('atrMethod');
        $atrK        = (float)$input->getOption('atrK');
        $maxPositions= (int)$input->getOption('maxPositions');
        $dryRun      = (bool)$input->getOption('dry-run');

        $symbols = $this->resolveSymbols((string)$input->getOption('symbols'));
        $opened = 0;
        $r = [];
        foreach ($symbols as $symbol) {
            if ($this->signals->validate4h($symbol, $this->klines)){
                $r[] = $symbol;
            }
        }
        foreach ($symbols as $symbol) {
            if ($opened >= $maxPositions) break;

            // 4H contexte
            if (!$this->signals->validate4h($symbol, $this->klines)) {
                $this->logger->debug("[scan:$symbol] 4H invalide — skip");
                continue;
            }

            // 1H(3) → 15m(3) → 5m(2) → 1m(4)
            if (!$this->sequentialValidate($symbol, $output)) {
                $this->logger->debug("[scan:$symbol] pipeline MTF non confirmé — skip");
                continue;
            }

            $side  = $sideOpt  ?: $this->signals->inferSide($symbol) ?? 'long';
            $entry = $entryOpt ? (float)$entryOpt : $this->signals->entryPrice($symbol) ?? $this->klines->lastPrice($symbol);

            $ohlc1m = $this->klines->fetchRecent($symbol, '1m', limit: 100);

            if ($dryRun) {
                $this->logger->info('[dry-run] Position candidate', [
                    'symbol' => $symbol, 'side' => $side, 'entry' => $entry,
                    'atr' => ['period'=>$atrPeriod, 'method'=>$atrMethod, 'k'=>$atrK],
                ]);
                $opened++;
                continue;
            }

            $this->executor->onOneMinuteConfirmed(
                symbol: $symbol,
                side: $side,
                equity: $equity,
                riskPct: $riskPct,
                entry: (float)$entry,
                ohlcExecutionTF: $ohlc1m,
                liqPrice: $liqOpt ? (float)$liqOpt : null,
                atrPeriod: $atrPeriod,
                atrMethod: $atrMethod,
                atrK: $atrK,
            );

            $this->logger->info('[scan] Position ouverte', ['symbol'=>$symbol, 'side'=>$side, 'entry'=>$entry]);
            $opened++;
        }

        $output->writeln(sprintf('<info>Scan terminé. Positions ouvertes: %d</info>', $opened));
        return Command::SUCCESS;
    }

    /** @return list<string> */
    private function resolveSymbols(?string $csv): array
    {
        if ($csv && trim($csv) !== '') {
            $subset = array_values(array_filter(array_map('trim', explode(',', $csv))));
            return $this->contracts->normalizeSubset($subset);
        }
        return array_column($this->contracts->allActiveSymbols(), 'symbol');
    }

    private function sequentialValidate(string $symbol, OutputInterface $out): bool
    {
        $plan = [
            ['tf' => '1h',  'tries' => 3],
            ['tf' => '15m', 'tries' => 3],
            ['tf' => '5m',  'tries' => 2],
            ['tf' => '1m',  'tries' => 4],
        ];
        foreach ($plan as $step) {
            $ok = false;
            for ($i = 1; $i <= $step['tries']; $i++) {
                $ok = $this->signals->validate($symbol, $step['tf'], $this->klines);
                $out->writeln(sprintf(
                    '<comment>[%s %s] try %d/%d => %s</comment>',
                    $symbol, $step['tf'], $i, $step['tries'], $ok ? 'OK' : 'KO'
                ));
                if ($ok) break;
            }
            if (!$ok) return false;
        }
        return true;
    }
}
