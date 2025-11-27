<?php

declare(strict_types=1);

namespace App\Command;

use App\Config\{TradeEntryConfig, TradeEntryConfigProvider, TradeEntryModeContext, ZoneDeviationOverrideStore};
use App\TradeEntry\Service\ZoneDeviationAnalyzerService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'trade-entry:zone:auto-adjust',
    description: 'Auto-tune zone_max_deviation_pct per symbol using persisted skipped_out_of_zone data.'
)]
final class ZoneDeviationAutoAdjustCommand extends Command
{
    public function __construct(
        private readonly ZoneDeviationAnalyzerService $analyzer,
        private readonly ZoneDeviationOverrideStore $overrideStore,
        private readonly TradeEntryConfigProvider $configProvider,
        private readonly TradeEntryModeContext $modeContext,
        private readonly TradeEntryConfig $defaultConfig,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('mode', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Config mode(s) to tune (use "default" for base config).', ['regular', 'scalper'])
            ->addOption('hours', null, InputOption::VALUE_REQUIRED, 'Lookback window (hours) for analyzer statistics.', 24)
            ->addOption('threshold', null, InputOption::VALUE_REQUIRED, 'Minimum relative delta required to apply an override (e.g. 0.05 = ±5%).', 0.05)
            ->addOption('min-events', null, InputOption::VALUE_REQUIRED, 'Minimum number of events per symbol before adjustments.', 3)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only display adjustments without persisting overrides.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $modes = (array) $input->getOption('mode');
        if ($modes === []) {
            $modes = ['regular'];
        }
        $hours = max(1, (int) $input->getOption('hours'));
        $threshold = max(0.0, (float) $input->getOption('threshold'));
        $minEvents = max(1, (int) $input->getOption('min-events'));
        $dryRun = (bool) $input->getOption('dry-run');
        $since = (new \DateTimeImmutable(sprintf('-%d hours', $hours)))->setTimezone(new \DateTimeZone('UTC'));

        $report = $this->analyzer->generateDailyReport($since);
        $stats = $report['symbols'] ?? [];
        if ($stats === []) {
            $io->warning('No trade_zone_events found for the selected window.');
            return Command::SUCCESS;
        }

        $rows = [];
        $changes = 0;

        foreach ($modes as $mode) {
            $modeKey = $this->normalizeMode($mode);
            $config = $this->resolveConfig($modeKey);
            $baseZoneMax = (float) ($config->getDefaults()['zone_max_deviation_pct'] ?? 0.015);
            foreach ($stats as $symbolStats) {
                $symbol = (string) ($symbolStats['symbol'] ?? '');
                if ($symbol === '') {
                    continue;
                }
                $events = (int) ($symbolStats['events'] ?? 0);
                if ($events < $minEvents) {
                    continue;
                }
                $proposed = $symbolStats['proposed_zone_max_pct'] ?? null;
                if (!is_numeric($proposed)) {
                    continue;
                }
                $target = (float) $proposed;
                $currentOverride = $this->overrideStore->getOverride($modeKey === 'default' ? null : $modeKey, $symbol);
                $currentEffective = $currentOverride ?? $baseZoneMax;

                if ($currentEffective <= 0.0) {
                    continue;
                }

                $deltaRatio = abs($target - $currentEffective) / max($currentEffective, 1e-9);
                if ($deltaRatio >= $threshold) {
                    ++$changes;
                    $action = $currentOverride === null ? 'set' : 'update';
                    $rows[] = [
                        $symbol,
                        $modeKey,
                        $events,
                        $this->fmtPct($currentEffective),
                        $this->fmtPct($target),
                        $action,
                    ];
                    if (!$dryRun) {
                        $this->overrideStore->setOverride(
                            $modeKey === 'default' ? null : $modeKey,
                            $symbol,
                            $target,
                            [
                                'source' => 'auto',
                                'events' => $events,
                                'ratio' => $symbolStats['ratio'] ?? null,
                                'since' => $report['since'] ?? $since->format(\DateTimeInterface::ATOM),
                            ]
                        );
                    }
                    continue;
                }

                if ($currentOverride !== null) {
                    $deltaFromBase = abs($target - $baseZoneMax) / max($baseZoneMax, 1e-9);
                    if ($deltaFromBase <= max($threshold / 2, 0.02)) {
                        ++$changes;
                        $rows[] = [
                            $symbol,
                            $modeKey,
                            $events,
                            $this->fmtPct($currentEffective),
                            $this->fmtPct($baseZoneMax),
                            'remove',
                        ];
                        if (!$dryRun) {
                            $this->overrideStore->removeOverride($modeKey === 'default' ? null : $modeKey, $symbol);
                        }
                    }
                }
            }
        }

        if (!$dryRun) {
            $this->overrideStore->flush();
        }

        if ($rows !== []) {
            $io->table(['Symbol', 'Mode', 'Events', 'Current', 'Target', 'Action'], $rows);
            $io->success(sprintf('%d adjustment(s) %s.', $changes, $dryRun ? 'simulated' : 'applied'));
        } else {
            $io->success('No adjustments required for the provided window and threshold.');
        }

        if ($dryRun) {
            $io->note('Dry-run mode: no overrides were persisted.');
        }

        return Command::SUCCESS;
    }

    private function resolveConfig(string $mode): TradeEntryConfig
    {
        $resolvedMode = $mode === 'default'
            ? $this->modeContext->resolve(null)
            : $this->modeContext->resolve($mode);

        try {
            return $this->configProvider->getConfigForMode($resolvedMode);
        } catch (\RuntimeException $e) {
            return $this->defaultConfig;
        }
    }

    private function normalizeMode(?string $mode): string
    {
        $mode = strtolower(trim((string) ($mode ?? '')));

        return $mode === '' ? 'default' : $mode;
    }

    private function fmtPct(float $value): string
    {
        return number_format($value * 100.0, 2) . '%';
    }
}
