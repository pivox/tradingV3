<?php

declare(strict_types=1);

namespace App\Trading\Paper\Dataset;

final readonly class HyperliquidHistoricalCoverage
{
    private const TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.u\Z';

    /** @var list<string> */
    public array $intervals;

    /**
     * @param list<string> $intervals
     */
    public function __construct(
        public int $schemaVersion,
        public string $requestSha256,
        public string $from,
        public string $to,
        array $intervals,
        public int $maximumEvents,
        public int $maximumPages,
        public int $maximumResponseBytes,
        public int $maximumRetries,
    ) {
        if ($this->schemaVersion !== 1
            || preg_match('/\A[0-9a-f]{64}\z/D', $this->requestSha256) !== 1
            || $intervals !== ['1m', '5m', '15m', '1h']
            || $this->maximumEvents < 1 || $this->maximumEvents > 1_000_000
            || $this->maximumPages < 1 || $this->maximumPages > 100_000
            || $this->maximumResponseBytes < 1 || $this->maximumResponseBytes > 1_048_576
            || $this->maximumRetries < 0 || $this->maximumRetries > 5
        ) {
            throw new \InvalidArgumentException('paper_dataset_hyperliquid_coverage_invalid');
        }

        $fromTimestamp = self::parseTimestamp($this->from);
        $toTimestamp = self::parseTimestamp($this->to);
        if ($fromTimestamp >= $toTimestamp) {
            throw new \InvalidArgumentException('paper_dataset_hyperliquid_coverage_invalid');
        }

        $this->intervals = $intervals;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(#[\SensitiveParameter] array $data): self
    {
        $keys = array_keys($data);
        sort($keys, SORT_STRING);
        $expected = [
            'from',
            'intervals',
            'maximum_events',
            'maximum_pages',
            'maximum_response_bytes',
            'maximum_retries',
            'request_sha256',
            'schema_version',
            'to',
        ];
        if ($keys !== $expected
            || !\is_int($data['schema_version'])
            || !\is_string($data['request_sha256'])
            || !\is_string($data['from'])
            || !\is_string($data['to'])
            || !\is_array($data['intervals'])
            || !array_is_list($data['intervals'])
            || !\is_int($data['maximum_events'])
            || !\is_int($data['maximum_pages'])
            || !\is_int($data['maximum_response_bytes'])
            || !\is_int($data['maximum_retries'])
        ) {
            throw new \InvalidArgumentException('paper_dataset_hyperliquid_coverage_invalid');
        }

        /** @var list<string> $intervals */
        $intervals = $data['intervals'];

        return new self(
            $data['schema_version'],
            $data['request_sha256'],
            $data['from'],
            $data['to'],
            $intervals,
            $data['maximum_events'],
            $data['maximum_pages'],
            $data['maximum_response_bytes'],
            $data['maximum_retries'],
        );
    }

    /**
     * @return array{
     *   schema_version: 1,
     *   request_sha256: string,
     *   from: string,
     *   to: string,
     *   intervals: list<string>,
     *   maximum_events: int,
     *   maximum_pages: int,
     *   maximum_response_bytes: int,
     *   maximum_retries: int
     * }
     */
    public function toArray(): array
    {
        return [
            'schema_version' => 1,
            'request_sha256' => $this->requestSha256,
            'from' => $this->from,
            'to' => $this->to,
            'intervals' => $this->intervals,
            'maximum_events' => $this->maximumEvents,
            'maximum_pages' => $this->maximumPages,
            'maximum_response_bytes' => $this->maximumResponseBytes,
            'maximum_retries' => $this->maximumRetries,
        ];
    }

    public function fromTimestamp(): \DateTimeImmutable
    {
        return self::parseTimestamp($this->from);
    }

    public function toTimestamp(): \DateTimeImmutable
    {
        return self::parseTimestamp($this->to);
    }

    private static function parseTimestamp(#[\SensitiveParameter] string $value): \DateTimeImmutable
    {
        try {
            $timestamp = \DateTimeImmutable::createFromFormat(
                '!' . self::TIMESTAMP_FORMAT,
                $value,
                new \DateTimeZone('UTC'),
            );
        } catch (\ValueError) {
            throw new \InvalidArgumentException('paper_dataset_hyperliquid_coverage_invalid');
        }
        $errors = \DateTimeImmutable::getLastErrors();
        if ($timestamp === false
            || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
            || $timestamp->format(self::TIMESTAMP_FORMAT) !== $value
        ) {
            throw new \InvalidArgumentException('paper_dataset_hyperliquid_coverage_invalid');
        }

        return $timestamp;
    }
}
