<?php

declare(strict_types=1);

namespace App\Exchange\Readiness;

use App\Common\Enum\Exchange;

final class ExchangePrivateObservabilityPolicy
{
    private const SENSITIVE_PATTERN = '/(api[_-]?key|secret|private[_-]?key|passphrase|password|authorization|cookie|token|signature|sign|credentials?|memo)/i';

    public function evaluate(
        ExchangePrivateObservabilityStatus $status,
        bool $dryRun,
        ?Exchange $expectedExchange = null,
        ?string $expectedEnvironment = null,
    ): ExchangePrivateObservabilityDecision {
        $blockingErrors = $this->blockingErrors($status, $expectedExchange, $expectedEnvironment);
        $warnings = $this->redactMessages($status->warnings);

        if ($dryRun) {
            if ($blockingErrors !== []) {
                $warnings[] = 'private_observability_absent_for_dry_run';
            }

            return new ExchangePrivateObservabilityDecision(
                allowed: true,
                status: $status,
                blockingErrors: [],
                warnings: array_values(array_unique($warnings)),
            );
        }

        return new ExchangePrivateObservabilityDecision(
            allowed: $blockingErrors === [],
            status: $status,
            blockingErrors: $blockingErrors,
            warnings: array_values(array_unique($warnings)),
        );
    }

    /**
     * @return list<string>
     */
    private function blockingErrors(
        ExchangePrivateObservabilityStatus $status,
        ?Exchange $expectedExchange,
        ?string $expectedEnvironment,
    ): array {
        $errors = $this->redactMessages($status->blockingErrors);

        if ($expectedExchange !== null && $status->exchange !== $expectedExchange) {
            $errors[] = 'private_observability_exchange_mismatch';
        }

        if ($expectedEnvironment !== null && strtolower($status->environment) !== strtolower($expectedEnvironment)) {
            $errors[] = 'private_observability_environment_mismatch';
        }

        if (!$status->privateWsSupported) {
            $errors[] = 'private_ws_not_supported';
        }

        if (!$status->privateWsConnected) {
            $errors[] = 'private_ws_not_connected';
        }

        if (!$status->privateWsAuthenticated) {
            $errors[] = 'private_ws_not_authenticated';
        }

        if (!$status->ordersStreamReady) {
            $errors[] = 'private_orders_stream_not_ready';
        }

        if (!$status->fillsStreamReady) {
            $errors[] = 'private_fills_stream_not_ready';
        }

        if (!$status->positionsStreamReady) {
            $errors[] = 'private_positions_stream_not_ready';
        }

        if (!$status->initialSnapshotLoaded) {
            $errors[] = 'private_observability_initial_snapshot_missing';
        }

        if ($status->reconnecting) {
            $errors[] = 'private_observability_reconnecting';
        }

        if (!$status->reconciliationFresh) {
            $errors[] = 'private_reconciliation_stale';
        }

        return array_values(array_unique($errors));
    }

    /**
     * @param list<string> $messages
     * @return list<string>
     */
    private function redactMessages(array $messages): array
    {
        return array_map(
            static fn (string $message): string => preg_match(self::SENSITIVE_PATTERN, $message) === 1 ? '[redacted]' : $message,
            $messages,
        );
    }
}
