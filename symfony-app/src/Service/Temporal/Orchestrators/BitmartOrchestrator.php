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
        ?string $batchId = null,
        array $meta = []
    ): void {
        // Valeurs par défaut : si $start ou $end sont null
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        if ($end === null) {
            $end = $now;
        }

        if ($start === null) {
            // Exemple : on recule $limit * taille TF (en minutes) si pas précisé
            $tfMap = [
                '1m'  => 1,
                '5m'  => 5,
                '15m' => 15,
                '1h'  => 60,
                '4h'  => 240,
                '1d'  => 1440,
            ];
            $minutes = $tfMap[$timeframe] ?? 60;
            $start = (clone $end)->modify(sprintf('-%d minutes', ($limit - 1) * $minutes));
        }

        // Conversion timestamps
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
                'start'   => $start->format('Y-m-d H:i:s'),
                 'end'     => $end->format('Y-m-d H:i:s'),
                'start_ts'   => $startTs,
                'end_ts'     => $endTs,
                'note'       => $note,
                'batch_id'   => $batchId,
                'meta'       => $meta,
            ],
        ];
        if (!$this->workflowRef) {
            $this->workflowRef = $ref;
        }

    }

    public function reset(): void
    {
        $this->envelopes = [];
        $this->workflowRef = null;
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

        $this->gateway->ensureWorkflowRunning($this->workflowRef);
        $this->gateway->sendSignal($this->workflowRef, new SignalPayload('submit', $envelopes));
        $this->envelopes = [];
        $this->workflowRef = null;
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
