<?php

declare(strict_types=1);

namespace App\TradingCore\Config;

use App\TradingCore\Config\Exception\TradingConfigException;
use App\TradingCore\Config\Exception\NonExecutableTradingConfigException;
use App\TradingCore\Mode\Exception\ModeContractException;
use App\TradingCore\Mode\ModeContractLoader;
use App\TradingCore\Rules\Catalog\ConditionCatalog;
use App\TradingCore\Setup\Exception\SetupContractException;
use App\TradingCore\Setup\SetupCompiler;
use App\TradingCore\Setup\SetupContractLoader;
use App\TradingCore\Execution\Enum\ShadowExecutionCapability;
use Psr\Log\LoggerInterface;

final readonly class EffectiveTradingConfigResolver implements EffectiveTradingConfigResolverInterface
{
    public function __construct(
        private ?TradingConfigLayerLoader $loader = null,
        private ?LoggerInterface $logger = null,
        private ?ModeContractLoader $modeContracts = null,
        private ?SetupContractLoader $setupContracts = null,
        private ?SetupCompiler $setupCompiler = null,
        private ?ConditionCatalog $conditionCatalog = null,
        private ?EffectiveTradingConfigComposer $composer = null,
    ) {
    }

    public function resolve(EffectiveTradingConfigRequest|string $request): EffectiveTradingConfigSnapshot
    {
        if (!$request instanceof EffectiveTradingConfigRequest) {
            throw new TradingConfigException('Canonical resolution requires an EffectiveTradingConfigRequest; legacy positional profiles and fallback are forbidden.');
        }

        $modernShadowIdentity = $request->modeId . '@' . $request->modeVersion;
        $isModernShadow = in_array($modernShadowIdentity, [
            'day_trading@1.1.0',
            'scalping@1.1.0',
        ], true);
        if ($isModernShadow && $request->capability === null) {
            throw new TradingConfigException($request->modeId . '_shadow_capability_required');
        }
        if ($isModernShadow && $request->capability === ShadowExecutionCapability::PrivateMainnet) {
            throw new TradingConfigException('private_mainnet_execution_forbidden');
        }
        if ($isModernShadow && $request->capability === ShadowExecutionCapability::Backtest && $request->exchange !== 'fake') {
            throw new TradingConfigException($request->modeId . '_backtest_requires_fake_exchange');
        }

        $modeLoader = $this->modeContracts ?? new ModeContractLoader();
        $setupLoader = $this->setupContracts ?? new SetupContractLoader();
        try {
            $mode = $modeLoader->load($request->modeId, $request->modeVersion);
            $setup = $setupLoader->load($request->setupId, $request->setupVersion);
        } catch (ModeContractException|SetupContractException $exception) {
            throw new TradingConfigException($exception->getMessage(), previous: $exception);
        }

        if (!in_array($setup->setupId, $mode->compatibleSetupIds(), true)) {
            throw new TradingConfigException(sprintf('Setup "%s" is not listed by mode "%s@%s".', $setup->setupId, $mode->modeId, $mode->modeVersion));
        }
        if ($setup->side !== $request->side) {
            throw new TradingConfigException(sprintf('Setup side "%s" does not match requested side "%s".', $setup->side, $request->side));
        }
        $setupDocument = $setup->toArray();
        $compatible = false;
        foreach ($setupDocument['compatible_modes'] as $candidate) {
            if ($candidate['mode_id'] === $mode->modeId && $candidate['mode_version'] === $mode->modeVersion) {
                $compatible = true;
                break;
            }
        }
        if (!$compatible) {
            throw new TradingConfigException('Setup compatibility does not contain the exact requested mode identity and version.');
        }

        try {
            $compiled = ($this->setupCompiler ?? new SetupCompiler())->compile($setup, $this->conditionCatalog);
        } catch (SetupContractException $exception) {
            throw new TradingConfigException($exception->getMessage(), previous: $exception);
        }
        $blockers = [];
        if (!$mode->isExecutable()) {
            $blockers[] = sprintf('mode %s@%s is not executable (%s)', $mode->modeId, $mode->modeVersion, $mode->lifecycleStatus);
        }
        if (!$setup->isExecutable()) {
            $blockers[] = sprintf('setup %s@%s is not executable (%s)', $setup->setupId, $setup->setupVersion, $setup->status);
        }
        if (!$compiled->publishable) {
            $blockers[] = 'compiled setup snapshot is not publishable';
        }
        if ($compiled->conditionCatalogHash === null) {
            $blockers[] = 'condition catalog hash is unresolved';
        }
        if ($blockers !== []) {
            throw new NonExecutableTradingConfigException($request, $blockers);
        }

        $loader = $this->loader ?? new TradingConfigLayerLoader();
        $modeDocument = $mode->toArray();
        $layers = [
            $loader->loadBase(),
            new TradingConfigLayer('mode', $mode->modeId . '@' . $mode->modeVersion, $modeLoader->pathFor($mode->modeId, $mode->modeVersion), true, ['mode' => $modeDocument]),
            new TradingConfigLayer('setup', $setup->setupId . '@' . $setup->setupVersion, $setupLoader->pathFor($setup->setupId, $setup->setupVersion), true, ['setup' => $compiled->effectivePayload()]),
            $loader->requireExchange($request->exchange),
            $loader->requireModeExchange($request->modeId, $request->modeVersion, $request->exchange),
            $loader->requireEnvironment($request->environment),
        ];

        $snapshot = ($this->composer ?? new EffectiveTradingConfigComposer())->compose($request, $layers, $compiled->conditionCatalogHash);
        $this->logger?->info('trading_config.canonical_effective_resolved', [
            ...$request->toArray(),
            'config_hash' => $snapshot->configHash,
            'layers' => $snapshot->orderedLayers(),
        ]);

        return $snapshot;
    }
}
