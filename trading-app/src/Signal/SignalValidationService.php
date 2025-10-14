<?php

namespace App\Signal;

use App\Entity\Kline;
use App\Entity\Contract;
use Psr\Log\LoggerInterface;
use App\Config\MtfConfigProviderInterface;

/**
 * Validation unique d'un timeframe + encapsulation du statut MTF attendu par le contrôleur.
 * Remplace l'ancien SignalService + SignalValidationService (namespace Signals\Timeframe).
 */
final class SignalValidationService
{
    private const VALIDATION_KEY = 'validation';

    /** @var SignalServiceInterface[] */
    private array $services = [];

    public function __construct(
        iterable $timeframeServices,
        private readonly LoggerInterface $validationLogger,
        private readonly MtfConfigProviderInterface $tradingParameters,
    ) {
        foreach ($timeframeServices as $svc) {
            if ($svc instanceof SignalServiceInterface) {
                $this->services[] = $svc;
            }
        }
    }

    /** Retourne un résumé de contexte à partir des signaux connus + éventuel courant. */
    public function buildContextSummary(array $knownSignals, string $currentTf, string $currentSignal): array
    {
        $cfg = $this->tradingParameters->getConfig();
        $contextTfs = array_map('strtolower', (array)($cfg[self::VALIDATION_KEY]['context'] ?? ($cfg['mtf']['context'] ?? [])));
        $contextSignals = [];
        foreach ($contextTfs as $ctxTf) {
            if ($ctxTf === $currentTf) {
                $contextSignals[$ctxTf] = $currentSignal;
            } else {
                $contextSignals[$ctxTf] = strtoupper((string)($knownSignals[$ctxTf]['signal'] ?? 'NONE'));
            }
        }

        $nonNoneSignals = array_filter($contextSignals, fn($v) => $v !== 'NONE');
        $contextAligned = false; $contextDir = 'NONE';
        if ($nonNoneSignals !== []) {
            $uniqNonNone = array_unique($nonNoneSignals);
            if (count($uniqNonNone) === 1) {
                $contextAligned = true;
                $contextDir = reset($uniqNonNone);
            }
        }
        $fullyPopulated = count($nonNoneSignals) === count($contextSignals) && $contextSignals !== [];
        $fullyAligned = $fullyPopulated && $contextAligned; // tous présents & même direction

        return [
            'context_signals' => $contextSignals,
            'context_aligned' => $contextAligned,
            'context_dir' => $contextDir,
            'context_tfs' => $contextTfs,
            'context_fully_populated' => $fullyPopulated,
            'context_fully_aligned' => $fullyAligned,
        ];
    }

    /**
     * @param string $tf
     * @param Kline[] $klines
     * @param array<string,array{signal?:string}> $knownSignals
     * @return array{signals:array,final:array{signal:string},status:string}
     */
    public function validate(string $tf, array $klines, array $knownSignals = [], ?Contract $contract = null): array
    {
        $tfLower = strtolower($tf);
        $svc = $this->findService($tfLower);
        if (!$svc) {
            return [
                'signals' => [$tfLower => ['signal' => 'NONE', 'reason' => 'unsupported_tf']],
                'final'   => ['signal' => 'NONE'],
                'status'  => 'FAILED',
                'context' => [ 'context_aligned' => false, 'context_dir' => 'NONE', 'context_signals' => [] ],
            ];
        }
        $cfg = $this->tradingParameters->getConfig();
        $contextTfs = array_map('strtolower', (array)($cfg[self::VALIDATION_KEY]['context'] ?? ($cfg['mtf']['context'] ?? [])));
        $execTfs    = array_map('strtolower', (array)($cfg[self::VALIDATION_KEY]['execution'] ?? ($cfg['mtf']['execution'] ?? [])));
        $evaluation = $svc->evaluate($contract ?? new Contract(), $klines, []);
        $curr = strtoupper((string)($evaluation['signal'] ?? 'NONE'));

        $summary = $this->buildContextSummary($knownSignals, $tfLower, $curr);
        $contextSignals = $summary['context_signals'];
        $contextAligned = $summary['context_aligned'];
        $contextDir = $summary['context_dir'];
        $fullyPopulated = $summary['context_fully_populated'];
        $fullyAligned = $summary['context_fully_aligned'];

        $isContextTf = in_array($tfLower, $contextTfs, true);
        $isExecTf    = in_array($tfLower, $execTfs, true);

        $status = 'FAILED';
        if ($isContextTf) {
            $idx = array_search($tfLower, $contextTfs, true);
            if ($idx === 0) {
                $status = in_array($curr, ['LONG','SHORT'], true) ? 'PENDING' : 'FAILED';
            } else {
                $partial = array_slice($contextTfs, 0, $idx + 1);
                $partialSignals = array_intersect_key($contextSignals, array_flip($partial));
                $uniquePart = array_unique($partialSignals);
                $nonNonePart = array_filter($uniquePart, fn($v) => $v !== 'NONE');
                $alignedPartial = (count($nonNonePart) === 1 && count($uniquePart) === 1);
                $status = ($alignedPartial && $curr !== 'NONE') ? 'PENDING' : 'FAILED';
            }
        } elseif ($isExecTf) {
            // Exige alignement complet (tous context TF présents) pour valider.
            if ($fullyAligned && $curr === $contextDir && $curr !== 'NONE') {
                $status = 'VALIDATED';
            }
        }

        $out = [
            'signals' => [$tfLower => $evaluation + [
                'context_aligned' => $contextAligned,
                'context_dir'     => $contextDir,
                'context_signals' => $contextSignals,
                'context_fully_populated' => $fullyPopulated,
                'context_fully_aligned' => $fullyAligned,
            ]],
            'final'   => ['signal' => $curr],
            'status'  => $status,
            'context' => [
                'aligned' => $contextAligned,
                'dir' => $contextDir,
                'signals' => $contextSignals,
                'fully_populated' => $fullyPopulated,
                'fully_aligned' => $fullyAligned,
            ],
        ];
        $this->validationLogger->info('validation.mtf_status', [
            'tf' => $tfLower,
            'status' => $status,
            'curr' => $curr,
            'context_aligned' => $contextAligned,
            'context_dir' => $contextDir,
            'context_signals' => $contextSignals,
            'fully_populated' => $fullyPopulated,
            'fully_aligned' => $fullyAligned,
        ]);
        return $out;
    }

    private function findService(string $tf): ?SignalServiceInterface
    {
        foreach ($this->services as $svc) {
            if ($svc->supportsTimeframe($tf)) { return $svc; }
        }
        return null;
    }
}
