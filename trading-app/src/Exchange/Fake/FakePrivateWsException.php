<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

final class FakePrivateWsException extends \RuntimeException
{
    private const RESYNC_REQUIRED = 'resync_required';

    private function __construct(
        public readonly string $errorCode,
        public readonly string $state,
        public readonly ?string $lastAcknowledgedSequence,
        public readonly ?string $expectedSequence = null,
        public readonly ?string $actualSequence = null,
    ) {
        parent::__construct($errorCode);
    }

    public static function disconnected(?string $lastAcknowledgedSequence): self
    {
        return new self(
            errorCode: 'fake_private_ws_disconnected',
            state: self::RESYNC_REQUIRED,
            lastAcknowledgedSequence: $lastAcknowledgedSequence,
        );
    }

    public static function sequenceGap(
        ?string $lastAcknowledgedSequence,
        string $expectedSequence,
        string $actualSequence,
    ): self {
        return new self(
            errorCode: 'fake_private_ws_sequence_gap',
            state: self::RESYNC_REQUIRED,
            lastAcknowledgedSequence: $lastAcknowledgedSequence,
            expectedSequence: $expectedSequence,
            actualSequence: $actualSequence,
        );
    }

    public static function snapshotResyncRequired(?string $lastAcknowledgedSequence): self
    {
        return new self(
            errorCode: 'fake_private_ws_snapshot_resync_required',
            state: self::RESYNC_REQUIRED,
            lastAcknowledgedSequence: $lastAcknowledgedSequence,
        );
    }
}
