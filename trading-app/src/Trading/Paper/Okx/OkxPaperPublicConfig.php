<?php

declare(strict_types=1);

namespace App\Trading\Paper\Okx;

final readonly class OkxPaperPublicConfig
{
    public const REST_BASE_URI = 'https://www.okx.com';
    public const WEB_SOCKET_URI = 'wss://ws.okx.com:8443/ws/v5/public';
    public const BUSINESS_WEB_SOCKET_URI = 'wss://ws.okx.com:8443/ws/v5/business';

    public function __construct(
        public bool $acquisitionEnabled,
        public string $restBaseUri,
        public string $webSocketUri,
        public string $dataRoot,
        public string $businessWebSocketUri = self::BUSINESS_WEB_SOCKET_URI,
    ) {
        if ($this->restBaseUri !== self::REST_BASE_URI) {
            throw new \InvalidArgumentException('okx_paper_public_rest_uri_not_allowed');
        }

        if ($this->webSocketUri !== self::WEB_SOCKET_URI) {
            throw new \InvalidArgumentException('okx_paper_public_ws_uri_not_allowed');
        }

        if ($this->businessWebSocketUri !== self::BUSINESS_WEB_SOCKET_URI) {
            throw new \InvalidArgumentException('okx_paper_public_business_ws_uri_not_allowed');
        }
    }
}
