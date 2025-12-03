<?php

declare(strict_types=1);

namespace App\Contract\MtfValidator\Dto;

/**
 * DTO pour les requêtes d'exécution MTF
 */
use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
final class MtfRunRequestDto
{
    public function __construct(
        public readonly array $symbols,
        public readonly bool $dryRun = false,
        public readonly bool $forceRun = false,
        public readonly ?string $currentTf = null,
        public readonly bool $forceTimeframeCheck = false,
        public readonly bool $skipContextValidation = false,
        public readonly bool $lockPerSymbol = false,
        public readonly bool $skipOpenStateFilter = false,
        public readonly ?string $userId = null,
        public readonly ?string $ipAddress = null,
        public readonly ?Exchange $exchange = null,
        public readonly ?MarketType $marketType = null,
        public readonly ?string $profile = null,
        public readonly ?string $mode = null,
    ) {}

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $symbols = isset($data['symbols']) && is_array($data['symbols'])
            ? $data['symbols']
            : [];

        $dryRun   = (bool)($data['dry_run'] ?? $data['dryRun'] ?? false);
        $forceRun = (bool)($data['force_run'] ?? $data['forceRun'] ?? false);

        $currentTf = $data['current_tf'] ?? $data['currentTf'] ?? null;
        $currentTf = is_string($currentTf) && $currentTf !== '' ? $currentTf : null;

        $forceTimeframeCheck = (bool)($data['force_timeframe_check'] ?? $data['forceTimeframeCheck'] ?? false);

        $skipContextValidation = (bool)($data['skip_context_validation'] ?? $data['skipContextValidation'] ?? $data['skip_context'] ?? false);
        $lockPerSymbol         = (bool)($data['lock_per_symbol'] ?? $data['lockPerSymbol'] ?? false);
        $skipOpenStateFilter   = (bool)($data['skip_open_state_filter'] ?? $data['skipOpenStateFilter'] ?? false);

        $userId = isset($data['user_id']) && is_string($data['user_id']) && $data['user_id'] !== ''
            ? $data['user_id']
            : null;

        $ipAddress = isset($data['ip_address']) && is_string($data['ip_address']) && $data['ip_address'] !== ''
            ? $data['ip_address']
            : null;

        $exchangeRaw = $data['exchange'] ?? null;
        $marketTypeRaw = $data['market_type'] ?? null;

        [$profile, $validationMode] = self::extractProfileAndMode($data);

        $exchange = null;
        if (is_string($exchangeRaw) && $exchangeRaw !== '') {
            $exchange = Exchange::tryFrom(strtoupper($exchangeRaw)) ?? null;
        }

        $marketType = null;
        if (is_string($marketTypeRaw) && $marketTypeRaw !== '') {
            $marketType = MarketType::tryFrom(strtoupper($marketTypeRaw)) ?? null;
        }

        return new self(
            symbols: $symbols,
            dryRun: $dryRun,
            forceRun: $forceRun,
            currentTf: $currentTf,
            forceTimeframeCheck: $forceTimeframeCheck,
            skipContextValidation: $skipContextValidation,
            lockPerSymbol: $lockPerSymbol,
            skipOpenStateFilter: $skipOpenStateFilter,
            userId: $userId,
            ipAddress: $ipAddress,
            exchange: $exchange,
            marketType: $marketType,
            profile: $profile,
            mode: $validationMode,
        );
    }

    private static function extractProfileAndMode(array $data): array
    {
        $profileSources = [
            $data['profile'] ?? null,
            $data['mtf_profile'] ?? null,
        ];

        $profile = null;
        foreach ($profileSources as $source) {
            if (is_string($source) && $source !== '') {
                $profile = trim($source);
                break;
            }
        }

        $mode = null;
        $modeCandidates = [
            $data['validation_mode'] ?? null,
            $data['context_mode'] ?? null,
        ];

        foreach ($modeCandidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                $mode = strtolower(trim($candidate));
                break;
            }
        }

        $genericMode = $data['mode'] ?? null;
        if (is_string($genericMode) && $genericMode !== '') {
            $genericModeTrimmed = trim($genericMode);
            $lower = strtolower($genericModeTrimmed);
            if (in_array($lower, ['pragmatic', 'strict'], true)) {
                $mode = $lower;
            } elseif ($profile === null) {
                $profile = $genericModeTrimmed;
            }
        }

        return [$profile, $mode];
    }
}
