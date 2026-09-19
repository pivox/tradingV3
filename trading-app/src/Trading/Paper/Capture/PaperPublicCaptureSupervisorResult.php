<?php

declare(strict_types=1);

namespace App\Trading\Paper\Capture;

final readonly class PaperPublicCaptureSupervisorResult
{
    private const SCHEMA_VERSION = 'paper-public-capture-supervision-result-v1';

    private function __construct(
        public bool $ok,
        public string $sourceVenue,
        public int $attemptsUsed,
        public ?string $datasetId,
        public ?string $blocker,
    ) {
    }

    public static function success(string $venue, int $attempts, string $datasetId): self
    {
        return new self(true, $venue, $attempts, $datasetId, null);
    }

    public static function exhausted(string $venue, int $attempts): self
    {
        return new self(
            false,
            $venue,
            $attempts,
            null,
            'paper_public_capture_attempts_exhausted',
        );
    }

    public static function orphanFinalizationFailed(string $venue, int $attempts): self
    {
        return new self(
            false,
            $venue,
            $attempts,
            null,
            'paper_public_capture_orphan_finalization_failed',
        );
    }

    public static function interrupted(string $venue, int $attempts): self
    {
        return new self(
            false,
            $venue,
            $attempts,
            null,
            'paper_public_capture_supervision_interrupted',
        );
    }

    /** @return array<string, bool|int|string> */
    public function toArray(): array
    {
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => $this->ok,
            'source_venue' => $this->sourceVenue,
            'attempts_used' => $this->attemptsUsed,
        ];
        if ($this->datasetId !== null) {
            $payload['dataset_id'] = $this->datasetId;
        }
        if ($this->blocker !== null) {
            $payload['blocker'] = $this->blocker;
        }

        return $payload;
    }
}
