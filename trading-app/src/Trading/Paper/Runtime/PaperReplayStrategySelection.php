<?php

declare(strict_types=1);

namespace App\Trading\Paper\Runtime;

final readonly class PaperReplayStrategySelection
{
    private function __construct(
        private ?string $profile,
        private ?string $modeId,
        private ?string $modeVersion,
        private ?string $setupId,
        private ?string $setupVersion,
        private ?string $side,
    ) {
    }

    public static function legacy(string $profile): self
    {
        return self::fromOptions($profile, null, null, null, null, null);
    }

    public static function fromOptions(
        ?string $profile,
        ?string $modeId,
        ?string $modeVersion,
        ?string $setupId,
        ?string $setupVersion,
        ?string $side,
    ): self {
        $values = array_map(self::normalize(...), [
            $profile, $modeId, $modeVersion, $setupId, $setupVersion, $side,
        ]);
        [$profile, $modeId, $modeVersion, $setupId, $setupVersion, $side] = $values;
        $modern = [$modeId, $modeVersion, $setupId, $setupVersion, $side];
        $modernCount = count(array_filter($modern, static fn (?string $value): bool => $value !== null));

        if ($profile !== null && $modernCount > 0) {
            throw new \InvalidArgumentException('paper_strategy_selection_ambiguous');
        }
        if ($profile === null && $modernCount === 0) {
            throw new \InvalidArgumentException('paper_strategy_selection_required');
        }
        if ($profile === null && $modernCount !== count($modern)) {
            throw new \InvalidArgumentException('paper_modern_strategy_identity_incomplete');
        }

        return new self($profile, $modeId, $modeVersion, $setupId, $setupVersion, $side);
    }

    public function isModern(): bool
    {
        return $this->profile === null;
    }

    public function legacyProfile(): string
    {
        if ($this->profile === null) {
            throw new \LogicException('paper_legacy_strategy_profile_unavailable');
        }

        return $this->profile;
    }

    /** @return array{mode_id:string,mode_version:string,setup_id:string,setup_version:string,side:string} */
    public function modernIdentity(): array
    {
        if (!$this->isModern()
            || $this->modeId === null
            || $this->modeVersion === null
            || $this->setupId === null
            || $this->setupVersion === null
            || $this->side === null
        ) {
            throw new \LogicException('paper_modern_strategy_identity_unavailable');
        }

        return [
            'mode_id' => $this->modeId,
            'mode_version' => $this->modeVersion,
            'setup_id' => $this->setupId,
            'setup_version' => $this->setupVersion,
            'side' => $this->side,
        ];
    }

    private static function normalize(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
