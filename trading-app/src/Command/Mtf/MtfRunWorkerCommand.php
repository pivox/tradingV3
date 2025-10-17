<?php

declare(strict_types=1);

namespace App\Command\Mtf;

use App\Domain\Mtf\Service\MtfRunService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mtf:run-worker', description: 'Exécute le traitement MTF pour un sous-ensemble de symboles (mode worker).')]
final class MtfRunWorkerCommand extends Command
{
    public function __construct(private readonly MtfRunService $mtfRunService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('symbols', null, InputOption::VALUE_REQUIRED, 'Liste de symboles séparés par des virgules (obligatoire)')
            ->addOption('dry-run', null, InputOption::VALUE_OPTIONAL, 'Mode simulation (1|0)', '1')
            ->addOption('force-run', null, InputOption::VALUE_NONE, 'Force l\'exécution même si les switchs sont OFF')
            ->addOption('tf', null, InputOption::VALUE_OPTIONAL, 'Limite à un timeframe (4h|1h|15m|5m|1m)')
            ->addOption('force-timeframe-check', null, InputOption::VALUE_NONE, 'Force l\'analyse du timeframe même si la dernière kline est récente')
            ->addOption('auto-switch-invalid', null, InputOption::VALUE_NONE, 'Active la gestion auto des symboles INVALID (transmis au service)')
            ->addOption('switch-duration', null, InputOption::VALUE_OPTIONAL, 'Durée de désactivation pour les INVALID', '1d');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symbolsOpt = (string) ($input->getOption('symbols') ?? '');
        $symbols = array_values(array_filter(array_map('trim', $symbolsOpt !== '' ? explode(',', $symbolsOpt) : [])));

        if (empty($symbols)) {
            $output->writeln(json_encode([
                'error' => 'No symbols provided to mtf:run-worker.',
            ], JSON_THROW_ON_ERROR));
            return Command::INVALID;
        }

        $dryRun = ((string) $input->getOption('dry-run')) !== '0';
        $forceRun = (bool) $input->getOption('force-run');
        $currentTf = $input->getOption('tf');
        $currentTf = is_string($currentTf) && $currentTf !== '' ? $currentTf : null;
        $forceTimeframeCheck = (bool) $input->getOption('force-timeframe-check');
        $autoSwitchInvalid = (bool) $input->getOption('auto-switch-invalid');
        $switchDuration = (string) $input->getOption('switch-duration');

        try {
            $generator = $this->mtfRunService->run($symbols, $dryRun, $forceRun, $currentTf, $forceTimeframeCheck);
            $yielded = iterator_to_array($generator);
            $final = $generator->getReturn();

            $payload = [
                'symbols' => $symbols,
                'yielded' => $yielded,
                'final' => $final,
                'options' => [
                    'dry_run' => $dryRun,
                    'force_run' => $forceRun,
                    'current_tf' => $currentTf,
                    'force_timeframe_check' => $forceTimeframeCheck,
                    'auto_switch_invalid' => $autoSwitchInvalid,
                    'switch_duration' => $switchDuration,
                ],
            ];

            $output->writeln(json_encode($payload, JSON_THROW_ON_ERROR));
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln(json_encode([
                'symbols' => $symbols,
                'error' => $e->getMessage(),
            ], JSON_THROW_ON_ERROR));
            return Command::FAILURE;
        }
    }
}
