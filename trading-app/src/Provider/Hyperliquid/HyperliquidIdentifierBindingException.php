<?php

declare(strict_types=1);

namespace App\Provider\Hyperliquid;

final class HyperliquidIdentifierBindingException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('hyperliquid_identifier_lookup_response_mismatch');
    }
}
