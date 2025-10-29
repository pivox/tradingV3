<?php

declare(strict_types=1);

namespace App\Signal;

use App\Common\Enum\SignalSide;
use App\Common\Enum\Timeframe;
use App\Config\MtfConfigProviderInterface;
use App\Contract\Signal\Dto\SignalEvaluationDto;
use App\Contract\Signal\Dto\SignalValidationContextDto;
use App\Contract\Signal\Dto\SignalValidationResultDto;
use App\Contract\Signal\SignalServiceInterface;
use App\Contract\Signal\SignalValidationServiceInterface;
use App\Entity\Contract;
use App\Entity\Kline;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

/**
 * Validation unique d'un timeframe + encapsulation du statut MTF attendu par le contrôleur.
 * Remplace l'ancien SignalService + SignalValidationService (namespace Signals\Timeframe).
 */
#[AsAlias(id: SignalValidationServiceInterface::class)]
final class SignalValidationService implements SignalValidationServiceInterface
{
    private const VALIDATION_KEY = 'validation';

    /** @var SignalServiceInterface[] */
    private array $services = [];

    public function __construct(
        #[TaggedIterator('app.signal.timeframe')]
        iterable $timeframeServices,
        private readonly LoggerInterface $validationLogger,
        private readonly MtfConfigProviderInterface $tradingParameters,
        private readonly SignalPersistenceService $signalPersistenceService,
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

        $nonNoneSignals = array_filter($contextSignals, static fn($v) => $v !== 'NONE');
        $contextAligned = false;
        $contextDir = 'NONE';
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
     * @param Kline[] $klines
     * @param array<string,array{signal?:string}> $knownSignals
     */
    public function validate(string $tf, array $klines, array $knownSignals = [], ?Contract $contract = null): SignalValidationResultDto
    {
        $tfLower = strtolower($tf);
        $timeframeEnum = $this->resolveTimeframe($tfLower);
        $service = $this->findService($tfLower);

        if (!$service) {
            $evaluation = new SignalEvaluationDto(
                timeframe: $timeframeEnum,
                signal: SignalSide::NONE,
                payload: ['reason' => 'unsupported_tf']
            );
            $context = new SignalValidationContextDto([], false, 'NONE', [], false, false);
            return new SignalValidationResultDto($evaluation, $context, 'FAILED', SignalSide::NONE);
        }

        $contractEntity = $contract ?? new Contract();
        $evaluationPayload = $service->evaluate($contractEntity, $klines, []);
        $currentSignalValue = strtoupper((string)($evaluationPayload['signal'] ?? 'NONE'));
        $currentSignal = SignalSide::from($currentSignalValue);

        $config = $this->tradingParameters->getConfig();
        $contextTfs = array_map('strtolower', (array)($config[self::VALIDATION_KEY]['context'] ?? ($config['mtf']['context'] ?? [])));
        $executionTfs = array_map('strtolower', (array)($config[self::VALIDATION_KEY]['execution'] ?? ($config['mtf']['execution'] ?? [])));

        $summary = $this->buildContextSummary($knownSignals, $tfLower, $currentSignalValue);
        $contextSignals = $summary['context_signals'];

        $isContextTf = in_array($tfLower, $contextTfs, true);
        $isExecutionTf = in_array($tfLower, $executionTfs, true);

        $status = 'FAILED';
        if ($isContextTf) {
            $idx = array_search($tfLower, $contextTfs, true);
            if ($idx === 0) {
                $status = $currentSignal->isNone() ? 'FAILED' : 'PENDING';
            } else {
                $partial = array_slice($contextTfs, 0, $idx + 1);
                $partialSignals = array_intersect_key($contextSignals, array_flip($partial));
                $uniquePart = array_unique($partialSignals);
                $nonNonePart = array_filter($uniquePart, static fn($v) => $v !== 'NONE');
                $alignedPartial = (count($nonNonePart) === 1 && count($uniquePart) === 1);
                $status = ($alignedPartial && !$currentSignal->isNone()) ? 'PENDING' : 'FAILED';
            }
        } elseif ($isExecutionTf && $summary['context_fully_aligned'] && $currentSignalValue === $summary['context_dir'] && !$currentSignal->isNone()) {
            $status = 'VALIDATED';
        }

        $evaluationDto = new SignalEvaluationDto(
            timeframe: $timeframeEnum,
            signal: $currentSignal,
            payload: array_diff_key($evaluationPayload, ['signal' => true, 'timeframe' => true])
        );

        $contextDto = new SignalValidationContextDto(
            signals: $contextSignals,
            aligned: $summary['context_aligned'],
            direction: $summary['context_dir'],
            timeframes: $summary['context_tfs'],
            fullyPopulated: $summary['context_fully_populated'],
            fullyAligned: $summary['context_fully_aligned'],
        );

        $result = new SignalValidationResultDto(
            evaluation: $evaluationDto,
            context: $contextDto,
            status: $status,
            finalSignal: $currentSignal,
        );

        $this->persistValidationResult($result, $contractEntity, $klines, $knownSignals);

        $this->validationLogger->info('validation.mtf_status', [
            'tf' => $tfLower,
            'status' => $status,
            'curr' => $currentSignalValue,
            'context_aligned' => $summary['context_aligned'],
            'context_dir' => $summary['context_dir'],
            'context_signals' => $contextSignals,
            'fully_populated' => $summary['context_fully_populated'],
            'fully_aligned' => $summary['context_fully_aligned'],
        ]);

        return $result;
    }

    private function findService(string $tf): ?SignalServiceInterface
    {
        foreach ($this->services as $svc) {
            if ($svc->supportsTimeframe($tf)) {
                return $svc;
            }
        }

        return null;
    }

    private function resolveTimeframe(string $tf): Timeframe
    {
        return Timeframe::from($tf);
    }

    /**
     * @param Kline[] $klines
     */
    private function persistValidationResult(
        SignalValidationResultDto $result,
        Contract $contract,
        array $klines,
        array $knownSignals
    ): void {
        $symbol = $this->resolveSymbol($contract, $klines);
        if ($symbol === null) {
            return;
        }

        $klineTime = $this->resolveKlineTime($klines);
        $meta = [
            'known_signals' => $knownSignals,
            'persisted_by' => 'signal_validation_service',
        ];

        $signalDto = $result->toSignalDto($symbol, $klineTime, $meta);

        $this->signalPersistenceService->persistMtfSignal(
            $signalDto,
            $result->context->signals,
            $result->toArray()
        );
    }

    /**
     * @param Kline[] $klines
     */
    private function resolveSymbol(Contract $contract, array $klines): ?string
    {
        $symbol = null;
        try {
            $symbol = $contract->getSymbol();
        } catch (\Error) {
            $symbol = null;
        }

        if (!empty($symbol)) {
            return $symbol;
        }

        $lastKline = end($klines);
        if ($lastKline instanceof Kline) {
            return $lastKline->getSymbol();
        }

        return null;
    }

    /**
     * @param Kline[] $klines
     */
    private function resolveKlineTime(array $klines): DateTimeImmutable
    {
        $lastKline = end($klines);
        if ($lastKline instanceof Kline) {
            return $lastKline->getOpenTime();
        }

        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
