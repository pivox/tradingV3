<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Persistence;

final readonly class PaperExecutionCellState
{
    private function __construct(
        public bool $registered,
        public bool $killed,
        public ?string $datasetId,
        public ?string $eventsFileSha256,
        public ?string $sourceBuildVersion,
    ) {
        if (!$registered && ($killed || $datasetId !== null || $eventsFileSha256 !== null || $sourceBuildVersion !== null)) {
            throw new \InvalidArgumentException('paper_execution_cell_state_invalid');
        }
        if (($datasetId === null) !== ($eventsFileSha256 === null)) {
            throw new \InvalidArgumentException('paper_execution_cell_state_invalid');
        }
        if ($datasetId === null && $sourceBuildVersion !== null) {
            throw new \InvalidArgumentException('paper_execution_cell_state_invalid');
        }
        if ($datasetId !== null
            && (preg_match('/\A[a-z0-9][a-z0-9._-]{2,127}\z/D', $datasetId) !== 1
                || preg_match('/\A[a-f0-9]{64}\z/D', (string) $eventsFileSha256) !== 1)
        ) {
            throw new \InvalidArgumentException('paper_execution_cell_state_invalid');
        }
        if ($sourceBuildVersion !== null
            && ($sourceBuildVersion === '' || trim($sourceBuildVersion) !== $sourceBuildVersion)
        ) {
            throw new \InvalidArgumentException('paper_execution_cell_state_invalid');
        }
    }

    public static function absent(): self
    {
        return new self(false, false, null, null, null);
    }

    public static function registered(
        bool $killed,
        ?string $datasetId,
        ?string $eventsFileSha256,
        ?string $sourceBuildVersion = null,
    ): self {
        return new self(true, $killed, $datasetId, $eventsFileSha256, $sourceBuildVersion);
    }
}
