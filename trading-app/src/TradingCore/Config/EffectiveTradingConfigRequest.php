<?php

declare(strict_types=1);

namespace App\TradingCore\Config;

use App\TradingCore\Config\Exception\TradingConfigException;
use App\TradingCore\Mode\ModeContractValidator;
use App\TradingCore\Setup\SetupContractValidator;

final readonly class EffectiveTradingConfigRequest
{
    public function __construct(
        public string $modeId,
        public string $modeVersion,
        public string $setupId,
        public string $setupVersion,
        public string $exchange,
        public string $environment,
        public string $side,
    ) {
        if (!in_array($modeId, ModeContractValidator::MODE_IDS, true)) {
            throw new TradingConfigException(sprintf('Unknown modern mode id "%s"; aliases and legacy profiles are forbidden.', $modeId));
        }
        if (!in_array($setupId, SetupContractValidator::SETUP_IDS, true)) {
            throw new TradingConfigException(sprintf('Unknown canonical setup id "%s".', $setupId));
        }
        foreach (['mode_version' => $modeVersion, 'setup_version' => $setupVersion] as $field => $version) {
            if (preg_match('/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/', $version) !== 1) {
                throw new TradingConfigException(sprintf('%s must be an exact semantic version; aliases and ranges are forbidden.', $field));
            }
        }
        if (!in_array($side, ['long', 'short'], true)) {
            throw new TradingConfigException('side must be exactly "long" or "short".');
        }
        if (!in_array($exchange, ['fake', 'okx', 'hyperliquid'], true)) {
            throw new TradingConfigException(sprintf('Unsupported modern exchange "%s"; no fallback is allowed.', $exchange));
        }
        $allowed = [
            'fake' => ['local', 'test'],
            'okx' => ['demo', 'mainnet'],
            'hyperliquid' => ['testnet', 'mainnet'],
        ];
        if (!in_array($environment, $allowed[$exchange], true)) {
            throw new TradingConfigException(sprintf('Unsupported modern exchange/environment pair "%s/%s".', $exchange, $environment));
        }
    }

    /** @return array{mode_id:string,mode_version:string,setup_id:string,setup_version:string,exchange:string,environment:string,side:string} */
    public function toArray(): array
    {
        return [
            'mode_id' => $this->modeId,
            'mode_version' => $this->modeVersion,
            'setup_id' => $this->setupId,
            'setup_version' => $this->setupVersion,
            'exchange' => $this->exchange,
            'environment' => $this->environment,
            'side' => $this->side,
        ];
    }
}
