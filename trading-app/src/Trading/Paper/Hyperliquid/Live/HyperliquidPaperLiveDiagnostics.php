<?php

declare(strict_types=1);

namespace App\Trading\Paper\Hyperliquid\Live;

/**
 * Bounded, masked text for transport diagnostics. Uses the same path and secret masking
 * as the supervised capture output (SymfonyPaperPublicCaptureAttemptExecutor::redact()).
 */
final class HyperliquidPaperLiveDiagnostics
{
    private const MAX_TEXT_BYTES = 512;
    private const MAX_PREVIOUS_EXCEPTIONS = 3;

    public static function text(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $text = mb_scrub($text, 'UTF-8');
        $text = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text) ?? '';
        $text = preg_replace('#(?<![A-Za-z0-9])/(?:[^\s:/]+/)*[^\s:]*#', '[path]', $text) ?? '';
        $text = preg_replace(
            '/\S*(?:secret|password|token|api[_-]?key|wallet)\S*/i',
            '[redacted]',
            $text,
        ) ?? '';

        return \strlen($text) > self::MAX_TEXT_BYTES
            ? mb_strcut($text, 0, self::MAX_TEXT_BYTES, 'UTF-8') . '…'
            : $text;
    }

    /**
     * @return array{
     *     exception_class: string,
     *     exception_message: string,
     *     exception_code: int|string,
     *     exception_previous: string|null
     * }
     */
    public static function exception(\Throwable $exception): array
    {
        $previous = [];
        for ($cause = $exception->getPrevious();
            $cause !== null && \count($previous) < self::MAX_PREVIOUS_EXCEPTIONS;
            $cause = $cause->getPrevious()
        ) {
            $previous[] = $cause::class . ': ' . self::text($cause->getMessage());
        }

        return [
            'exception_class' => $exception::class,
            'exception_message' => self::text($exception->getMessage()) ?? '',
            'exception_code' => $exception->getCode(),
            'exception_previous' => $previous === [] ? null : implode(' <- ', $previous),
        ];
    }
}
