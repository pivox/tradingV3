<?php
namespace App\MessageHandler;

use App\Message\LogRecord;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(fromTransport: 'async_logging')]
final class LogRecordHandler
{
    public function __construct(private readonly string $projectDir = '') {}

    public function __invoke(\App\Message\LogRecord $msg): void
    {
        $dir = rtrim($this->projectDir ?: \dirname(__DIR__, 2), '/').'/var/log/async';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $file = $dir.'/all.ndjson';

        $line = [
            'app'            => $msg->app,
            'channel'        => $msg->channel,
            'level'          => $msg->level,
            'message'        => $msg->message,
            'context'        => $msg->context,
            'extra'          => $msg->extra,
            'datetime'       => $msg->datetime,
            'correlation_id' => $msg->correlationId,
        ];
        $json = json_encode($line, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
            ?: '{"error":"json_encode_failed"}';

        @file_put_contents($file, $json.PHP_EOL, FILE_APPEND|LOCK_EX);
    }
}
