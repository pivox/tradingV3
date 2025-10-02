<?php

declare(strict_types=1);

namespace App\Service\Strategy;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class HighConvictionTraceWriter
{
    public function __construct(
        #[Autowire(param: 'high_conviction.trace_path')] private readonly string $tracePath,
        #[Autowire(service: 'monolog.logger.highconviction')] private readonly LoggerInterface $logger,
    ) {}

    /**
     * Enregistre un snapshot JSONL avec timestamp pour faciliter les post-mortems.
     * @param array<string,mixed> $payload
     */
    public function record(array $payload): void
    {
        $payload['recorded_at'] = (new DateTimeImmutable('now'))->format(DateTimeImmutable::ATOM);

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $this->logger->warning('HC trace serialization failed', ['payload_keys' => array_keys($payload)]);
            return;
        }

        $dir = dirname($this->tracePath);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            $this->logger->error('HC trace directory creation failed', ['dir' => $dir]);
            return;
        }

        $written = @file_put_contents($this->tracePath, $json.PHP_EOL, FILE_APPEND | LOCK_EX);
        if ($written === false) {
            $this->logger->error('HC trace write failure', ['path' => $this->tracePath]);
        }
    }
}
