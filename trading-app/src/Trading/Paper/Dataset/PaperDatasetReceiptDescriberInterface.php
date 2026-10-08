<?php

declare(strict_types=1);

namespace App\Trading\Paper\Dataset;

interface PaperDatasetReceiptDescriberInterface
{
    /**
     * Fails closed unless the receipt validates for this dataset and event limit; returns
     * its public identity (no path).
     *
     * @return array{receipt_sha256: string, dataset_id: string, event_count: int, events_sha256: string, code_tree_sha256: string, event_limit: int}
     */
    public function describe(
        #[\SensitiveParameter] string $receiptPath,
        #[\SensitiveParameter] string $datasetDirectory,
        int $eventLimit,
    ): array;
}
