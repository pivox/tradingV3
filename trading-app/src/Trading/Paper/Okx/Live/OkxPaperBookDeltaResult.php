<?php

declare(strict_types=1);

namespace App\Trading\Paper\Okx\Live;

use App\Trading\Paper\Okx\Normalization\OkxMaterializedBookState;

final readonly class OkxPaperBookDeltaResult
{
    private function __construct(
        private OkxPaperBookDeltaStatus $status,
        private OkxMaterializedBookState|OkxPaperBookDeltaStatus $outcome,
    ) {
    }

    public static function applied(OkxMaterializedBookState $state): self
    {
        return new self(OkxPaperBookDeltaStatus::APPLIED, $state);
    }

    public static function replayed(): self
    {
        return new self(OkxPaperBookDeltaStatus::REPLAYED, OkxPaperBookDeltaStatus::REPLAYED);
    }

    public function status(): OkxPaperBookDeltaStatus
    {
        return $this->status;
    }

    public function materializedState(): OkxMaterializedBookState
    {
        if ($this->status !== OkxPaperBookDeltaStatus::APPLIED
            || !$this->outcome instanceof OkxMaterializedBookState
        ) {
            throw new \RuntimeException('okx_paper_book_delta_state_unavailable');
        }

        return $this->outcome;
    }
}
