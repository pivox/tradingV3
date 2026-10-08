<?php

declare(strict_types=1);

namespace App\Trading\Paper\Certification\Campaign;

use App\Trading\Paper\MarketData\CanonicalJson;

/**
 * One dedicated PostgreSQL instance per campaign cell (#132): the live trading tables allow a
 * single open position per (exchange, market type, symbol, side), so two cells trading the same
 * symbol and side can only run at the same time in two databases.
 *
 * Read from a private 0600 JSON file (written by the campaign-db tool) whose `databases` list is
 * in campaign cell order. The URLs carry the instance passwords: they are never logged, never
 * persisted in the campaign state and never part of an error; only the credential-free identity
 * of each instance (host, port, database) is fingerprinted into the campaign inputs.
 */
final readonly class PaperCertificationCampaignCellDatabases
{
    public const SCHEMA_VERSION = 'paper-campaign-cell-databases-v1';
    private const MAX_BYTES = 65_536;
    private const MAX_CELLS = 64;
    private const DATABASE = 'trading_paper';

    /** @param list<string> $urls */
    private function __construct(
        #[\SensitiveParameter] private array $urls,
        private string $fingerprint,
    ) {
    }

    public static function fromFile(#[\SensitiveParameter] string $path): self
    {
        if (!str_starts_with($path, DIRECTORY_SEPARATOR) || is_link($path)) {
            throw new \InvalidArgumentException('paper_campaign_cell_databases_invalid');
        }
        clearstatcache(true, $path);
        $statistics = @lstat($path);
        if (!\is_array($statistics)
            || ($statistics['mode'] & 0170000) !== 0100000
            || ($statistics['mode'] & 0777) !== 0600
            || $statistics['size'] < 2
            || $statistics['size'] > self::MAX_BYTES
        ) {
            throw new \InvalidArgumentException('paper_campaign_cell_databases_invalid');
        }
        $contents = @file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);
        if (!\is_string($contents) || \strlen($contents) !== $statistics['size']) {
            throw new \InvalidArgumentException('paper_campaign_cell_databases_invalid');
        }
        try {
            $document = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('paper_campaign_cell_databases_invalid');
        }

        return self::fromDocument(\is_array($document) ? $document : []);
    }

    /** @param array<mixed> $document */
    public static function fromDocument(#[\SensitiveParameter] array $document): self
    {
        $urls = $document['databases'] ?? null;
        if (array_keys($document) !== ['schema_version', 'databases']
            || $document['schema_version'] !== self::SCHEMA_VERSION
            || !\is_array($urls)
            || !array_is_list($urls)
            || $urls === []
            || \count($urls) > self::MAX_CELLS
        ) {
            throw new \InvalidArgumentException('paper_campaign_cell_databases_invalid');
        }
        $instances = [];
        foreach ($urls as $url) {
            $instance = \is_string($url) ? self::instance($url) : null;
            if ($instance === null || \in_array($instance, $instances, true)) {
                throw new \InvalidArgumentException('paper_campaign_cell_databases_invalid');
            }
            $instances[] = $instance;
        }
        /** @var list<string> $urls */

        return new self($urls, 'sha256:' . hash('sha256', CanonicalJson::encode([
            'schema_version' => self::SCHEMA_VERSION,
            'instances' => $instances,
        ])));
    }

    public function count(): int
    {
        return \count($this->urls);
    }

    public function urlFor(int $cellIndex): string
    {
        return $this->urls[$cellIndex] ?? throw new \InvalidArgumentException('paper_campaign_cell_databases_mismatch');
    }

    /** Credential-free identity of the instances, in cell order. */
    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    /** host:port/database of a local, dedicated trading_paper instance; null for anything else. */
    private static function instance(#[\SensitiveParameter] string $url): ?string
    {
        $parts = parse_url($url);
        if (!\is_array($parts)
            || !\in_array($parts['scheme'] ?? null, ['postgresql', 'postgres', 'pgsql'], true)
            || !\in_array($parts['host'] ?? null, ['127.0.0.1', 'localhost'], true)
            || !\is_int($parts['port'] ?? null)
            || ($parts['path'] ?? null) !== '/' . self::DATABASE
            || !\is_string($parts['user'] ?? null)
            || !\is_string($parts['pass'] ?? null)
            || $parts['pass'] === ''
        ) {
            return null;
        }

        return '127.0.0.1:' . $parts['port'] . '/' . self::DATABASE;
    }
}
