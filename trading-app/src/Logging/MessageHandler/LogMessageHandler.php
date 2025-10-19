<?php

declare(strict_types=1);

namespace App\Logging\MessageHandler;

use App\Logging\Message\LogMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(fromTransport: 'async_logging')]
final class LogMessageHandler
{
    public function __construct(private readonly string $logDir)
    {
    }

    public function __invoke(LogMessage $message): void
    {
        $logFile = $this->buildLogFilePath($message->channel, $message->createdAt);
        $formatted = $this->formatLogLine($message);

        $directory = \dirname($logFile);
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create log directory "%s"', $directory));
        }

        if (false === @file_put_contents($logFile, $formatted, FILE_APPEND | LOCK_EX)) {
            throw new \RuntimeException(sprintf('Unable to write log to file "%s"', $logFile));
        }
    }

    private function buildLogFilePath(string $channel, \DateTimeImmutable $date): string
    {
        $dateSuffix = $date->format('Y-m-d');

        return sprintf('%s/%s-%s.log', rtrim($this->logDir, '/'), $channel, $dateSuffix);
    }

    private function formatLogLine(LogMessage $message): string
    {
        $context = $message->context;
        $contextStr = [];
        foreach ($context as $key => $value) {
            $contextStr[] = sprintf('%s=%s', (string) $key, $this->normalizeContextValue($value));
        }

        $contextSegment = [] === $contextStr ? '' : ' ' . implode(' ', $contextStr);

        return sprintf(
            "[%s] %s.%s: %s%s\n",
            $message->createdAt->format('Y-m-d H:i:s.v'),
            $message->channel,
            strtoupper($message->level),
            $message->message,
            $contextSegment,
        );
    }

    private function normalizeContextValue(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return (string) (is_bool($value) ? ($value ? 'true' : 'false') : ($value ?? 'null'));
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
    }
}
