<?php

namespace App\Command;

use App\Service\Trading\OpenedLockedSyncService;
use App\Service\Trading\PositionEvaluator;
use App\Service\Trading\PositionFetcher;
use App\Service\Pipeline\MtfPipelineViewService;
use App\Service\Pipeline\MtfStateService;
use App\Repository\ContractRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:evaluate:positions',
    description: 'Récupère les positions ouvertes pour les contrats et les évalue',
)]
class EvaluateOpenPositionsCommand extends Command
{
    public function __construct(
        private readonly MtfPipelineViewService $pipelineView,
        private readonly MtfStateService $mtfState,
        private readonly ContractRepository $contracts,
        private readonly PositionFetcher $positionFetcher,
        private readonly PositionEvaluator $positionEvaluator,
        private readonly OpenedLockedSyncService $openedLockedSync
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $syncResult = $this->openedLockedSync->sync();
        if (!empty($syncResult['removed_symbols']) || !empty($syncResult['cleared_order_ids'])) {
            $io->comment(sprintf(
                'Sync unlocked %d pipelines; cleared order ids for %d symbol(s).',
                count($syncResult['removed_symbols']),
                count($syncResult['cleared_order_ids'])
            ));
        }

        // 1) Récupérer les pipelines "lockés" (OPENED_LOCKED)
        $lockedPipelines = array_filter(
            $this->pipelineView->list(),
            fn(array $row) => $this->isLockedPipeline($row)
        );

        if ($lockedPipelines === []) {
            $io->success('Aucune position ouverte trouvée dans le pipeline.');
            return Command::SUCCESS;
        }

        foreach ($lockedPipelines as $pipeline) {
            $symbol = $pipeline['symbol'];

            $io->section("Contrat $symbol");

            // 2) Aller chercher la position réelle BitMart
            $position = $this->positionFetcher->fetchPosition($symbol);

            if (!$position) {
                $io->warning("Pas de position trouvée pour $symbol (peut-être fermée).");
                $eventId = $this->buildEventId('POSITION_CLOSED', $symbol);
                $this->mtfState->applyPositionClosed($eventId, $symbol, ['1m','5m']);
                continue;
            }

            $contract = $this->contracts->find($symbol);
            $contractSize = (float) ($contract?->getContractSize() ?? 1.0);
            if ($contractSize <= 0) {
                $contractSize = 1.0;
            }

            $contractsQty = (float)($position->quantity ?? 0.0);
            $baseQty = $contractsQty * $contractSize;

            $position->contractSize = $contractSize;
            $position->contractsQty = $contractsQty;
            $position->baseQuantity = $baseQty;

            if ((!isset($position->margin) || $position->margin === null || $position->margin === 0.0)
                && ($position->entryPrice ?? 0) > 0 && ($position->leverage ?? 0) > 0
            ) {
                $notional = $baseQty * (float)$position->entryPrice;
                if ($notional > 0) {
                    $position->margin = $notional / (float)$position->leverage;
                }
            }

            // 3) Évaluer la position (PnL, risque, invalidation…)
            $evaluation = $this->positionEvaluator->evaluate($position);
            $eval = $evaluation; // alias

            $fmtNum = fn($v, $dec=2) => is_numeric($v) ? number_format((float)$v, $dec, '.', ' ') : $v;
            $fmtPct = fn($v, $dec=2) => is_numeric($v) ? $fmtNum($v, $dec) . '%' : 'n/a';

// couleurs
            $colorize = function (string $text, string $type) : string {
                return match ($type) {
                    'good' => "<fg=green>$text</>",
                    'bad'  => "<fg=red>$text</>",
                    'warn' => "<fg=yellow>$text</>",
                    default => $text,
                };
            };

// status coloré
            $statusColored = $eval['status'] === 'OK'
                ? $colorize('OK', 'good')
                : ($eval['status'] === 'ALERTE' ? $colorize('ALERTE', 'bad') : $colorize('Neutre', 'warn'));

            $pnlStr = ($eval['pnl'] >= 0)
                ? $colorize($fmtNum($eval['pnl'], 3) . ' USDT', 'good')
                : $colorize($fmtNum($eval['pnl'], 3) . ' USDT', 'bad');

// (optionnel) ROI sur marge si tu connais la marge engagée
            $marge = $position->margin ?? null; // ex: notional/leverage
            $roiOnMargin = ($marge && $marge > 0) ? $fmtPct(($eval['pnl'] / $marge) * 100) : 'n/a';

            $qtyDisplay = $fmtNum($baseQty, $baseQty >= 1 ? 0 : 4);
            if ($contractSize !== 1.0) {
                $qtyDisplay .= sprintf(' (%s cts)', $fmtNum($contractsQty, $contractsQty >= 1 ? 0 : 4));
            }

// temps en position formaté
            $time = 'n/a';
            if (!empty($eval['time_in_position_s'])) {
                $sec = (int)$eval['time_in_position_s'];
                $h = intdiv($sec, 3600);
                $m = intdiv($sec % 3600, 60);
                $s = $sec % 60;
                $time = sprintf('%02dh %02dm %02ds', $h, $m, $s);
            }

            $io->table(
                [
                    'Side', 'Qty', 'Entry', 'Mark',
                    'PnL', 'ROI (pos.)', 'ROI (marge)',
                    'Δ% (Price)', 'Effect %', 'R/R', 'R mult.',
                    'Dist SL %', 'Dist TP %', 'Liq Risk %',
                    'Temps',
                    'Risk', 'Status',
                ],
                [[
                    $position->side,
                    $qtyDisplay,
                    $fmtNum($position->entryPrice, $position->entryPrice > 100 ? 2 : 4),
                    $fmtNum($position->markPrice,  $position->markPrice  > 100 ? 2 : 4),

                    $pnlStr,
                    $fmtPct($eval['roi_pct'] ?? null),
                    $roiOnMargin,

                    $fmtPct($eval['price_change_pct'] ?? null),
                    $fmtPct($eval['position_effect_pct'] ?? null),
                    $eval['rr_ratio']     ?? 'n/a',
                    $eval['r_multiple']   ?? 'n/a',

                    isset($eval['dist_to_sl_pct']) ? $fmtPct($eval['dist_to_sl_pct']) : 'n/a',
                    isset($eval['dist_to_tp_pct']) ? $fmtPct($eval['dist_to_tp_pct']) : 'n/a',
                    isset($eval['liq_risk_pct'])   ? $fmtPct($eval['liq_risk_pct'])   : 'n/a',

                    $time,

                    $eval['risk_label'] ?? 'n/a',
                    $statusColored,
                ]]
            );
        }

        return Command::SUCCESS;
    }

    private function isLockedPipeline(array $pipeline): bool
    {
        foreach ($pipeline['eligibility'] ?? [] as $row) {
            $status = strtoupper((string)($row['status'] ?? ''));
            if (in_array($status, ['LOCKED_POSITION','LOCKED_ORDER'], true)) {
                return true;
            }
        }
        return false;
    }

    private function buildEventId(string $type, string $symbol): string
    {
        return sprintf('%s|%s|%s', $type, strtoupper($symbol), (int) (microtime(true) * 1000));
    }
}
