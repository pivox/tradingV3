<?php
namespace App\Service\Temporal\Orchestrators;

use App\Service\Temporal\Dto\PrioInTemporal;
use App\Service\Temporal\Dto\UrlType;
use App\Service\Temporal\TemporalGatewayInterface;
use App\Service\Temporal\Dto\WorkflowRef;
use App\Service\Temporal\Dto\SignalPayload;

final class BitmartOrchestrator
{
    private ?WorkflowRef $workflowRef = null;
    public function __construct(
        private readonly TemporalGatewayInterface $gateway,
        private array $envelopes = [], // pour stocker les résultats intermédiaires
    ) {
    }

    /** Déclenche le fetch de tous les contrats */
    public function requestGetAllContracts(
        WorkflowRef $ref,
        string $baseUrl,
        string $callback,
        ?string $note = null
    ): void {
        $envelope = [
            'url_type'     => 'get all contracts',
            'url_callback' => $callback,
            'base_url'     => $baseUrl,
            'payload'      => [
                'source' => 'bitmart',
                'note'   => $note ?? 'fetch contracts via callback',
            ],
        ];
        $this->gateway->ensureWorkflowRunning($ref);
        $this->gateway->sendSignal($ref, new SignalPayload('submit', [PrioInTemporal::REGULAR->value => $envelope]));
    }

    /**
     * Déclenche le fetch de klines pour un contrat (fenêtre explicite start/end).
     *
     * @param \DateTimeInterface|int $start  début (UTC) — soit DateTime soit epoch seconds
     * @param \DateTimeInterface|int $end    fin (UTC, exclus) — soit DateTime soit epoch seconds
     */
    public function requestGetKlines(
        WorkflowRef $ref,
        string $baseUrl,
        string $callback,
        string $contract,
        string $timeframe = '4h',
        int $limit = 100,
        ?\DateTimeInterface $start = null,
        ?\DateTimeInterface $end   = null,
        ?string $note = null,
    ): void {
        $startTs = $start->getTimestamp();
        $endTs   = $end->getTimestamp();

        $this->envelopes[] = [
            'url_type'     => 'get kline',
            'url_callback' => $callback,
            'base_url'     => $baseUrl,
            'payload'      => [
                'source'     => 'bitmart',
                'contract'   => $contract,
                'timeframe'  => $timeframe,
                'limit'      => $limit,
                // ⬇️ nouvelle stratégie: fenêtre explicite
                'start'   => $start->format('Y-m-d H:i:s'),
                 'end'     => $end->format('Y-m-d H:i:s'),
                'start_ts'   => $startTs,
                'end_ts'     => $endTs,
                'note'       => $note,
            ],
        ];
        if (!$this->workflowRef) {
            $this->workflowRef = $ref;
        }

    }

    public function go() {
        if (count($this->envelopes) == 0) {
            $this->workflowRef = null;
            return;
        }
        $envelopes = [];
        foreach ($this->envelopes as $envelope) {
            $key = $this->getPrioKey($envelope['payload']['timeframe'] ?? '4h');
            if (!isset($envelopes[$key])) {
                $envelopes[$key] = [];
            }
            $envelopes[$key][] = $envelope;
        }
//        dd($envelopes, $this->envelopes);

        $this->gateway->ensureWorkflowRunning($this->workflowRef);
        $this->gateway->sendSignal($this->workflowRef, new SignalPayload('submit', $envelopes));
        $this->envelopes = [];
        $this->workflowRef = null;
    }

    /**
     * Déclenche le fetch de klines pour plusieurs contrats (même fenêtre pour tous).
     *
     * @param \DateTimeInterface|int $start
     * @param \DateTimeInterface|int $end
     */
    public function requestGetKlinesForManyContracts(
        WorkflowRef $ref,
        string $baseUrl,
        string $callback,
        array $contracts,
        string $timeframe = '4h',
        int $limit = 100,
        \DateTimeInterface|int $start = 0,
        \DateTimeInterface|int $end   = 0,
        ?string $note = null,
    ): array {
        $startTs = is_int($start) ? $start : $start->getTimestamp();
        $endTs   = is_int($end)   ? $end   : $end->getTimestamp();

        $envelopes = [];
        foreach ($contracts as $contract) {
            $envelopes[] = [
                'url_type'     => 'get kline',
                'url_callback' => $callback,
                'base_url'     => $baseUrl,
                'payload'      => [
                    'source'     => 'bitmart',
                    'contract'   => $contract,
                    'timeframe'  => $timeframe,
                    'limit'      => $limit,
                    'start_ts'   => $startTs,
                    'end_ts'     => $endTs,
                    'note'       => $note,
                ],
            ];
        }
        $this->gateway->ensureWorkflowRunning($ref);
        return $this->gateway->sendSignal($ref, new SignalPayload('submit', [$this->getPrioKey($timeframe) => $envelopes]));
    }

    public function setWorkflowRef(WorkflowRef $workflowRef): self
    {
        $this->workflowRef = $workflowRef;
        return $this;
    }

    private function getPrioKey(string $timeframe): string
    {
        return match ($timeframe) {
            '1m' => PrioInTemporal::ONE_MINUTE_CRON->value,
            '5m' => PrioInTemporal::FIVE_MINUTES_CRON->value,
            '15m' => PrioInTemporal::FIFTEEN_MINUTES_CRON->value,
            '1h' => PrioInTemporal::ONE_HOUR_CRON->value,
            '4h' => PrioInTemporal::FOUR_HOURS_CRON->value,
            default => PrioInTemporal::REGULAR->value,
        };
    }

}
