<?php

declare(strict_types=1);

namespace App\Provider\Hyperliquid;

final class HyperliquidNonceReplayException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('hyperliquid_nonce_replay_detected');
    }
}
