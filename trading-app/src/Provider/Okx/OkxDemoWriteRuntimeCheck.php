<?php

declare(strict_types=1);

namespace App\Provider\Okx;

use App\Exchange\Okx\Demo\OkxDemoWriteGate;
use App\Exchange\Okx\OkxConfig;
use App\Exchange\Okx\PrivateWebSocket\OkxPrivateWebSocketEndpointGuard;

final readonly class OkxDemoWriteRuntimeCheck
{
    public function __construct(
        private OkxConfig $config,
        private OkxDemoWriteGate $gate,
        private OkxPrivateWebSocketEndpointGuard $wsEndpointGuard,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function check(): array
    {
        $environmentReasons = $this->gate->environmentReasons();
        $credentials = [
            'api_key_present' => trim($this->config->apiKey) !== '',
            'api_secret_present' => trim($this->config->apiSecret) !== '',
            'api_passphrase_present' => trim($this->config->apiPassphrase) !== '',
        ];
        $credentialsPresent = !\in_array(false, $credentials, true);
        $killSwitch = $this->gate->killSwitchDecision(
            'runtime_check',
            null,
            OkxDemoWriteGate::NON_SIZING_NOTIONAL,
            'runtime_check',
        );
        $restAllowed = $this->gate->restEndpointAllowed();
        $wsAllowed = true;
        try {
            $this->wsEndpointGuard->assertAllowed($this->config->wsPrivateUri());
        } catch (\InvalidArgumentException) {
            $wsAllowed = false;
        }

        $blocking = $environmentReasons;
        if (!$credentialsPresent) {
            $blocking[] = 'okx_demo_credentials_missing';
        }
        if (!$killSwitch->allowed) {
            $blocking = [...$blocking, ...$killSwitch->reasons];
        }

        return [
            'exchange' => 'okx',
            'flags' => $this->gate->flags(),
            'credentials' => $credentials + ['all_present' => $credentialsPresent],
            'kill_switch' => ['clear' => $killSwitch->allowed, 'reasons' => $killSwitch->reasons],
            'endpoint_guard' => ['rest_allowed' => $restAllowed, 'ws_private_allowed' => $wsAllowed],
            'write_ready' => $blocking === [],
            'blocking_reasons' => array_values(array_unique($blocking)),
        ];
    }
}
