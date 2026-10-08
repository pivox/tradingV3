<?php

declare(strict_types=1);

namespace App\Trading\Paper\Okx\Live;

/**
 * Bounded, masked text for OKX transport diagnostics, with the same path and secret
 * masking as the Hyperliquid diagnostics (and the supervised capture output).
 */
final class OkxPaperLiveDiagnostics
{
    private const MAX_TEXT_BYTES = 512;
    private const MAX_FRAME_BYTES = 3_072;
    private const MAX_PREVIOUS_EXCEPTIONS = 3;

    public static function text(?string $text, int $maxBytes = self::MAX_TEXT_BYTES): ?string
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

        return \strlen($text) > $maxBytes
            ? mb_strcut($text, 0, $maxBytes, 'UTF-8') . '…'
            : $text;
    }

    /** A received frame, masked and bounded to 3 KiB. */
    public static function frame(?string $frame): ?string
    {
        return self::text($frame, self::MAX_FRAME_BYTES);
    }

    /**
     * @return array{
     *     exception_class: string,
     *     exception_message: string,
     *     exception_code: int|string,
     *     exception_previous: string|null,
     *     exception_site: string
     * }
     */
    public static function exception(\Throwable $exception): array
    {
        $previous = [];
        for ($cause = $exception->getPrevious();
            $cause !== null && \count($previous) < self::MAX_PREVIOUS_EXCEPTIONS;
            $cause = $cause->getPrevious()
        ) {
            $previous[] = $cause::class . ': ' . self::text($cause->getMessage())
                . ' @' . basename($cause->getFile()) . ':' . $cause->getLine();
        }

        return [
            'exception_class' => $exception::class,
            'exception_message' => self::text($exception->getMessage()) ?? '',
            'exception_code' => $exception->getCode(),
            'exception_previous' => $previous === [] ? null : implode(' <- ', $previous),
            // The raise site (file name only: paths are never logged).
            'exception_site' => basename($exception->getFile()) . ':' . $exception->getLine(),
        ];
    }
}
