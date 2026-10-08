<?php

declare(strict_types=1);

namespace App\Trading\Paper\Dataset;

use App\Trading\Paper\MarketData\CanonicalJson;

/**
 * Proof that one complete baseline verification (PaperDatasetVerifier::verifyForBaseline)
 * accepted a dataset, bound to everything that verification depended on:
 *
 * - the dataset directory (real path, device and inode),
 * - the exact manifest bytes (SHA-256) and decoded manifest,
 * - the exact events file (SHA-256, size, device and inode),
 * - the verification code tree (SHA-256 of every source file of the Paper Dataset,
 *   MarketData, Hyperliquid and Okx namespaces: verifier, event model, redactor, venue
 *   policies and their versions) and the event limit.
 *
 * A campaign verifies each dataset once and hands the receipt to its cell processes, which
 * then re-hash the files and compare instead of re-running the full verification. Any
 * difference fails closed.
 */
final class PaperDatasetVerificationReceipt implements PaperDatasetReceiptDescriberInterface
{
    public const SCHEMA_VERSION = 'paper-dataset-verification-receipt.v1';
    private const MAX_RECEIPT_BYTES = 262_144;
    /** @var list<string> */
    private const CODE_TREE = ['Dataset', 'MarketData', 'Hyperliquid', 'Okx'];

    public function __construct(
        private readonly PaperDatasetVerifier $verifier = new PaperDatasetVerifier(),
        private readonly PaperDatasetManifestCodec $codec = new PaperDatasetManifestCodec(),
    ) {
    }

    /**
     * Runs the full baseline verification once and writes the receipt (0600, exclusive).
     *
     * @return array<string, mixed> the receipt body
     */
    public function issue(#[\SensitiveParameter] string $datasetDirectory, int $eventLimit, #[\SensitiveParameter] string $receiptPath): array
    {
        $directory = $this->resolvedDirectory($datasetDirectory);
        $before = $this->fileFacts($directory);
        $manifest = $this->verifier->verifyForBaseline($directory, $eventLimit);
        $after = $this->fileFacts($directory);
        if ($before !== $after
            || $manifest->eventsFileSha256 === null
            || !hash_equals($manifest->eventsFileSha256, $after['events_sha256'])
        ) {
            throw new \RuntimeException('paper_dataset_receipt_dataset_changed');
        }
        $body = [
            'schema_version' => self::SCHEMA_VERSION,
            'dataset_directory' => $directory,
            'directory_identity' => $after['directory_identity'],
            'manifest_sha256' => $after['manifest_sha256'],
            'manifest' => $manifest->toArray(),
            'events' => [
                'sha256' => $after['events_sha256'],
                'bytes' => $after['events_bytes'],
                'identity' => $after['events_identity'],
            ],
            'event_limit' => $eventLimit,
            'baseline' => true,
            'code_tree_sha256' => self::codeTreeSha256(),
        ];
        $encoded = CanonicalJson::encode($body + ['receipt_sha256' => hash('sha256', CanonicalJson::encode($body))]) . "\n";
        $previousUmask = umask(0077);
        try {
            $handle = @fopen($receiptPath, 'xb');
        } finally {
            umask($previousUmask);
        }
        if ($handle === false) {
            throw new \RuntimeException('paper_dataset_receipt_unwritable');
        }
        try {
            if (fwrite($handle, $encoded) !== \strlen($encoded) || !fflush($handle) || !fsync($handle)) {
                throw new \RuntimeException('paper_dataset_receipt_unwritable');
            }
        } finally {
            fclose($handle);
        }

        return $body;
    }

    /**
     * Fails closed unless the receipt is intact, issued by this exact code tree for this
     * dataset and limit, and the files are still byte-identical (full SHA-256 re-hash).
     */
    public function manifestFor(
        #[\SensitiveParameter] string $receiptPath,
        #[\SensitiveParameter] string $datasetDirectory,
        int $eventLimit,
    ): PaperDatasetManifest {
        return $this->validated($receiptPath, $datasetDirectory, $eventLimit)['manifest'];
    }

    /**
     * Same fail-closed validation as manifestFor(), plus the receipt's public identity
     * (no path): what a campaign records to detect a receipt replaced between its runs.
     *
     * @return array{receipt_sha256: string, dataset_id: string, event_count: int, events_sha256: string, code_tree_sha256: string, event_limit: int}
     */
    public function describe(
        #[\SensitiveParameter] string $receiptPath,
        #[\SensitiveParameter] string $datasetDirectory,
        int $eventLimit,
    ): array {
        $validated = $this->validated($receiptPath, $datasetDirectory, $eventLimit);
        $manifest = $validated['manifest'];
        if ($manifest->eventsFileSha256 === null) {
            throw new \RuntimeException('paper_dataset_receipt_mismatch');
        }

        return [
            'receipt_sha256' => $validated['receipt_sha256'],
            'dataset_id' => $manifest->datasetId,
            'event_count' => $manifest->eventCount,
            'events_sha256' => $manifest->eventsFileSha256,
            'code_tree_sha256' => $validated['code_tree_sha256'],
            'event_limit' => $eventLimit,
        ];
    }

    /** @return array{manifest: PaperDatasetManifest, receipt_sha256: string, code_tree_sha256: string} */
    private function validated(
        #[\SensitiveParameter] string $receiptPath,
        #[\SensitiveParameter] string $datasetDirectory,
        int $eventLimit,
    ): array {
        [$body, $receiptSha256] = $this->readReceipt($receiptPath);
        $directory = $this->resolvedDirectory($datasetDirectory);
        $codeTreeSha256 = self::codeTreeSha256();
        if ($body['dataset_directory'] !== $directory
            || $body['event_limit'] !== $eventLimit
            || $body['baseline'] !== true
            || !hash_equals((string) $body['code_tree_sha256'], $codeTreeSha256)
        ) {
            throw new \RuntimeException('paper_dataset_receipt_mismatch');
        }
        $facts = $this->fileFacts($directory);
        $events = $body['events'];
        if (!\is_array($events)
            || $facts['directory_identity'] !== $body['directory_identity']
            || !hash_equals((string) $body['manifest_sha256'], $facts['manifest_sha256'])
            || ($events['identity'] ?? null) !== $facts['events_identity']
            || ($events['bytes'] ?? null) !== $facts['events_bytes']
            || !hash_equals((string) ($events['sha256'] ?? ''), $facts['events_sha256'])
        ) {
            throw new \RuntimeException('paper_dataset_receipt_mismatch');
        }
        $manifest = $this->codec->decode($facts['manifest_contents']);
        if (CanonicalJson::encode($manifest->toArray()) !== CanonicalJson::encode($body['manifest'])
            || $manifest->state !== PaperDatasetState::COMPLETE
            || $manifest->eventCount > $eventLimit
            || $manifest->eventsFileSha256 === null
            || !hash_equals($manifest->eventsFileSha256, $facts['events_sha256'])
        ) {
            throw new \RuntimeException('paper_dataset_receipt_mismatch');
        }

        return ['manifest' => $manifest, 'receipt_sha256' => $receiptSha256, 'code_tree_sha256' => $codeTreeSha256];
    }

    public static function codeTreeSha256(): string
    {
        $root = \dirname(__DIR__);
        $files = [];
        foreach (self::CODE_TREE as $namespace) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $namespace, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                    $files[] = substr($file->getPathname(), \strlen($root) + 1);
                }
            }
        }
        sort($files, SORT_STRING);
        $context = hash_init('sha256');
        foreach ($files as $relative) {
            $contents = file_get_contents($root . '/' . $relative);
            if ($contents === false) {
                throw new \RuntimeException('paper_dataset_receipt_code_tree_unreadable');
            }
            hash_update($context, $relative . "\0" . hash('sha256', $contents) . "\n");
        }

        return hash_final($context);
    }

    /** @return array{0: array<string, mixed>, 1: string} the receipt body and its self-digest */
    private function readReceipt(#[\SensitiveParameter] string $receiptPath): array
    {
        // PHP caches stat results per process: a long-lived caller must see current modes.
        clearstatcache(true, $receiptPath);
        if (!str_starts_with($receiptPath, DIRECTORY_SEPARATOR) || is_link($receiptPath)) {
            throw new \RuntimeException('paper_dataset_receipt_invalid');
        }
        $statistics = @lstat($receiptPath);
        if ($statistics === false
            || ($statistics['mode'] & 0170000) !== 0100000
            || ($statistics['mode'] & 0077) !== 0
            || $statistics['size'] < 2
            || $statistics['size'] > self::MAX_RECEIPT_BYTES
        ) {
            throw new \RuntimeException('paper_dataset_receipt_invalid');
        }
        $contents = @file_get_contents($receiptPath);
        try {
            $decoded = \is_string($contents) ? json_decode($contents, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING) : null;
        } catch (\JsonException) {
            $decoded = null;
        }
        if (!\is_array($decoded) || array_is_list($decoded) || !\is_string($decoded['receipt_sha256'] ?? null)) {
            throw new \RuntimeException('paper_dataset_receipt_invalid');
        }
        $claimed = $decoded['receipt_sha256'];
        unset($decoded['receipt_sha256']);
        if (($decoded['schema_version'] ?? null) !== self::SCHEMA_VERSION
            || !hash_equals(hash('sha256', CanonicalJson::encode($decoded)), $claimed)
            || CanonicalJson::encode($decoded + ['receipt_sha256' => $claimed]) . "\n" !== $contents
        ) {
            throw new \RuntimeException('paper_dataset_receipt_invalid');
        }

        if (preg_match('/\A[a-f0-9]{64}\z/D', $claimed) !== 1) {
            throw new \RuntimeException('paper_dataset_receipt_invalid');
        }

        /** @var array<string, mixed> $decoded */
        return [$decoded, $claimed];
    }

    private function resolvedDirectory(#[\SensitiveParameter] string $datasetDirectory): string
    {
        $resolved = realpath($datasetDirectory);
        if (!str_starts_with($datasetDirectory, DIRECTORY_SEPARATOR) || $resolved === false || $resolved !== $datasetDirectory) {
            throw new \RuntimeException('paper_dataset_receipt_dataset_invalid');
        }

        return $resolved;
    }

    /**
     * @return array{directory_identity: array{dev: int, ino: int}, manifest_sha256: string, manifest_contents: string, events_sha256: string, events_bytes: int, events_identity: array{dev: int, ino: int}}
     */
    private function fileFacts(string $directory): array
    {
        clearstatcache(true);
        $directoryStatistics = @lstat($directory);
        $manifestPath = $directory . '/manifest.json';
        $eventsPath = $directory . '/events.ndjson';
        if ($directoryStatistics === false
            || ($directoryStatistics['mode'] & 0170000) !== 0040000
            || is_link($manifestPath)
            || is_link($eventsPath)
        ) {
            throw new \RuntimeException('paper_dataset_receipt_dataset_invalid');
        }
        $manifest = @file_get_contents($manifestPath, false, null, 0, PaperDatasetFormatLimits::MAX_MANIFEST_BYTES + 1);
        if (!\is_string($manifest) || $manifest === '' || \strlen($manifest) > PaperDatasetFormatLimits::MAX_MANIFEST_BYTES) {
            throw new \RuntimeException('paper_dataset_receipt_dataset_invalid');
        }
        $handle = @fopen($eventsPath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('paper_dataset_receipt_dataset_invalid');
        }
        try {
            $opened = fstat($handle);
            $context = hash_init('sha256');
            $bytes = hash_update_stream($context, $handle);
            $closed = fstat($handle);
            $current = @lstat($eventsPath);
            if ($opened === false
                || $closed === false
                || $current === false
                || $bytes !== $opened['size']
                || $closed['size'] !== $opened['size']
                || $current['dev'] !== $opened['dev']
                || $current['ino'] !== $opened['ino']
                || ($current['mode'] & 0170000) !== 0100000
            ) {
                throw new \RuntimeException('paper_dataset_receipt_dataset_changed');
            }
        } finally {
            fclose($handle);
        }

        return [
            'directory_identity' => ['dev' => $directoryStatistics['dev'], 'ino' => $directoryStatistics['ino']],
            'manifest_sha256' => hash('sha256', $manifest),
            'manifest_contents' => $manifest,
            'events_sha256' => hash_final($context),
            'events_bytes' => $bytes,
            'events_identity' => ['dev' => $opened['dev'], 'ino' => $opened['ino']],
        ];
    }
}
