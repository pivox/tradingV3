<?php

declare(strict_types=1);

namespace App\TradingCore\Mode;

final readonly class ModeContract
{
    /** @param array<string, mixed> $document */
    private function __construct(
        public string $modeId,
        public string $modeVersion,
        public string $lifecycleStatus,
        private array $document,
    ) {
    }

    /** @param array<string, mixed> $document */
    public static function fromDocument(array $document, ?ModeContractValidator $validator = null): self
    {
        ($validator ?? new ModeContractValidator())->validate($document);

        return new self(
            $document['mode_id'],
            $document['mode_version'],
            $document['lifecycle']['status'],
            $document,
        );
    }

    public function isExecutable(): bool
    {
        return $this->document['lifecycle']['executable'] === true
            && !in_array($this->lifecycleStatus, ['draft', 'retired'], true)
            && $this->unresolvedConstraints() === [];
    }

    /** @return list<string> */
    public function unresolvedConstraints(): array
    {
        $paths = [];
        $this->collectUnresolved($this->document, '', $paths);

        return $paths;
    }

    /** @return array{regime: list<string>, context: list<string>, trigger: list<string>, execution: list<string>} */
    public function timeframeRoles(): array
    {
        return $this->document['timeframes'];
    }

    /** @return list<string> */
    public function compatibleSetupIds(): array
    {
        return $this->document['compatible_setup_ids'];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->document;
    }

    /**
     * @param array<mixed> $node
     * @param list<string> $paths
     */
    private function collectUnresolved(array $node, string $prefix, array &$paths): void
    {
        if (($node['state'] ?? null) === 'unresolved') {
            $paths[] = $prefix;
        }

        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $this->collectUnresolved($value, $prefix === '' ? (string) $key : $prefix . '.' . $key, $paths);
            }
        }
    }
}
