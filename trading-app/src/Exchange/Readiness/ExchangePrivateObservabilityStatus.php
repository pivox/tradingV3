<?php

declare(strict_types=1);

namespace App\Exchange\Readiness;

use App\Common\Enum\Exchange;

final readonly class ExchangePrivateObservabilityStatus
{
    private const SENSITIVE_PATTERN = '/(api[_-]?key|secret|private[_-]?key|passphrase|password|authorization|cookie|token|signature|sign|credentials?|memo)/i';

    /**
     * @param list<string> $blockingErrors
     * @param list<string> $warnings
     */
    public function __construct(
        public Exchange $exchange,
        public string $environment,
        public bool $privateWsSupported,
        public bool $privateWsConnected,
        public bool $privateWsAuthenticated,
        public bool $ordersStreamReady,
        public bool $fillsStreamReady,
        public bool $positionsStreamReady,
        public bool $initialSnapshotLoaded,
        public ?\DateTimeImmutable $lastEventAt = null,
        public bool $reconnecting = false,
        public bool $reconciliationFresh = false,
        public array $blockingErrors = [],
        public array $warnings = [],
    ) {
        if (trim($this->environment) === '') {
            throw new \InvalidArgumentException('environment must not be blank.');
        }
    }

    public static function absent(Exchange $exchange, string $environment): self
    {
        return new self(
            exchange: $exchange,
            environment: $environment,
            privateWsSupported: false,
            privateWsConnected: false,
            privateWsAuthenticated: false,
            ordersStreamReady: false,
            fillsStreamReady: false,
            positionsStreamReady: false,
            initialSnapshotLoaded: false,
            reconnecting: false,
            reconciliationFresh: false,
            blockingErrors: ['private_observability_status_missing'],
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'exchange' => $this->exchange->value,
            'environment' => $this->environment,
            'private_ws_supported' => $this->privateWsSupported,
            'private_ws_connected' => $this->privateWsConnected,
            'private_ws_authenticated' => $this->privateWsAuthenticated,
            'orders_stream_ready' => $this->ordersStreamReady,
            'fills_stream_ready' => $this->fillsStreamReady,
            'positions_stream_ready' => $this->positionsStreamReady,
            'initial_snapshot_loaded' => $this->initialSnapshotLoaded,
            'last_event_at' => $this->lastEventAt?->format(\DateTimeInterface::ATOM),
            'reconnecting' => $this->reconnecting,
            'reconciliation_fresh' => $this->reconciliationFresh,
            'blocking_errors' => $this->redactMessages($this->blockingErrors),
            'warnings' => $this->redactMessages($this->warnings),
        ];
    }

    /**
     * @param list<string> $messages
     * @return list<string>
     */
    private function redactMessages(array $messages): array
    {
        return array_map(
            static fn (string $message): string => preg_match(self::SENSITIVE_PATTERN, $message) === 1 ? '[redacted]' : $message,
            array_values(array_unique($messages)),
        );
    }
}
