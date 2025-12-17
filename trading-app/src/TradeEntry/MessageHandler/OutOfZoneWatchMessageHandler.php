<?php
declare(strict_types=1);

namespace App\TradeEntry\MessageHandler;

use App\TradeEntry\Message\OutOfZoneWatchMessage;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(fromTransport: 'order_timeout')]
final class OutOfZoneWatchMessageHandler
{
    private const NOTIFY_CHANNEL = 'out_of_zone_watch';

    public function __construct(
        private readonly Connection $connection,
        #[Autowire(service: 'monolog.logger.positions')]
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(OutOfZoneWatchMessage $message): void
    {
        try {
            // Construire le payload JSON
            $payload = [
                'watch_id' => $message->watchId,
                'trace_id' => $message->traceId,
                'symbol' => $message->symbol,
                'side' => $message->side,
                'zone_min' => $message->zoneMin,
                'zone_max' => $message->zoneMax,
                'ttl_sec' => $message->ttlSec,
                'dry_run' => $message->dryRun,
                'execute_payload' => $message->executePayload,
                'created_at' => time(),
            ];

            $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if ($jsonPayload === false) {
                $this->logger->error('out_of_zone_watch.notify.json_encode_failed', [
                    'watch_id' => $message->watchId,
                    'symbol' => $message->symbol,
                    'error' => json_last_error_msg(),
                ]);
                return;
            }

            // Échapper le payload pour PostgreSQL (NOTIFY attend une chaîne SQL-safe)
            // Utiliser pg_notify via SQL
            $sql = sprintf(
                "SELECT pg_notify('%s', %s)",
                self::NOTIFY_CHANNEL,
                $this->connection->quote($jsonPayload)
            );

            $this->connection->executeStatement($sql);

            $this->logger->info('out_of_zone_watch.notify.published', [
                'watch_id' => $message->watchId,
                'symbol' => $message->symbol,
                'trace_id' => $message->traceId,
                'channel' => self::NOTIFY_CHANNEL,
                'zone_min' => $message->zoneMin,
                'zone_max' => $message->zoneMax,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('out_of_zone_watch.notify.failed', [
                'watch_id' => $message->watchId,
                'symbol' => $message->symbol,
                'trace_id' => $message->traceId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}

