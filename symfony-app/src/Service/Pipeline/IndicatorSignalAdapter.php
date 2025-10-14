<?php

declare(strict_types=1);

namespace App\Service\Pipeline;

use App\Repository\KlineRepository;
use App\Service\Signals\Timeframe\SignalService;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class IndicatorSignalAdapter implements IndicatorServiceInterface
{
    /** @var array<string,int> */
    private const LOOKBACK_BY_TF = [
        '4h' => 260,
        '1h' => 260,
        '15m' => 260,
        '5m' => 260,
        '1m' => 400,
    ];

    public function __construct(
        private readonly KlineRepository $klines,
        private readonly SignalService $signalService,
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
    ) {}

    public function evaluate(string $symbol, string $tf, DateTimeImmutable $slot): array
    {
        $tf = strtolower($tf);
        $lookback = self::LOOKBACK_BY_TF[$tf] ?? 260;
        $candles = $this->klines->findRecentBySymbolAndTimeframe($symbol, $tf, $lookback);
        if (!$candles) {
            $this->logger->warning('[indicator] no candles available', compact('symbol', 'tf', 'lookback'));
            throw new RuntimeException(sprintf('No candles available for %s on %s', $symbol, $tf));
        }

        $knownSignals = $this->loadKnownSignals($symbol);
        $result = $this->signalService->evaluate($tf, $candles, $knownSignals);
        $signalPayload = $result['signals'][$tf] ?? [];
        $side = strtoupper((string)($signalPayload['signal'] ?? 'NONE'));
        $status = strtoupper((string)($result['status'] ?? 'FAILED'));
        $passed = $status !== 'FAILED';

        $meta = [
            'slot' => $slot->format(DateTimeImmutable::ATOM),
            'tf' => $tf,
            'status' => $status,
            'signals' => $result['signals'] ?? [],
            'final' => $result['final'] ?? [],
            'known_signals' => $knownSignals,
        ];

        return [
            'passed' => $passed,
            'side' => $side,
            'score' => $signalPayload['score'] ?? null,
            'meta' => $meta,
        ];
    }

    /**
     * @return array<string,array{signal:string}>
     */
    private function loadKnownSignals(string $symbol): array
    {
        $sql = <<<SQL
SELECT tf, side, meta_json
FROM latest_signal_by_tf
WHERE symbol = :symbol
SQL;
        $rows = $this->db->fetchAllAssociative($sql, ['symbol' => $symbol]);
        $map = [];
        foreach ($rows as $row) {
            $tf = strtolower((string)$row['tf']);
            $side = strtoupper((string)$row['side']);
            $meta = [];
            if ($row['meta_json'] !== null) {
                $decoded = json_decode((string)$row['meta_json'], true);
                if (is_array($decoded)) {
                    $meta = $decoded;
                }
            }
            $map[$tf] = array_merge($meta, ['signal' => $side]);
        }
        return $map;
    }
}
