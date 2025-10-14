<?php

namespace App\Bitmart\Ws;
final class PrivateUserStream
{
    public function __construct(
        private \React\EventLoop\LoopInterface $loop,
        private \Psr\Log\LoggerInterface $logger,
        private string $wsUrl,
        private string $apiKey,
        private string $secret,
        private string $memo,
        private string $device = 'web',
    ) {}

    /** @param string[] $topics */
    public function run(array $topics): void
    {
        // 1 connexion, login "access", puis subscribe aux 2 topics
        // appelle ensuite tes callbacks: $this->onOrder($msg) / $this->onPosition($msg)
        // -> route vers OrderStream / PositionStream (ou vers Messenger)
    }

    public function onOrder(callable $cb): void {}
    public function onPosition(callable $cb): void {}
}
