<?php

namespace App\Service\Persister;

use App\Dto\FuturesKlineCollection;
use App\Dto\FuturesKlineDto;
use App\Entity\Contract;
use App\Entity\Kline;
use App\Repository\KlineRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

class KlinePersister
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly KlineRepository $klineRepository,
        private readonly Connection $db,
    ) {}

    /**
     * Persiste une liste "brute" (sans dédoublonnage) via ORM.
     * Conserve pour compat / usage direct si besoin.
     *
     * @param iterable<FuturesKlineDto> $klines
     * @return Kline[]
     */
    public function persist(iterable $klines, Contract $contract, int $stepMinutes): array
    {
        $persisted = [];

        foreach ($klines as $dto) {
            if (!$dto instanceof FuturesKlineDto) {
                continue;
            }
            /** @var Kline $kline */
            $kline = (new Kline())->fillFromDto($dto, $contract, $stepMinutes);
            $this->em->persist($kline);
            $persisted[] = $kline;
        }
        $this->em->flush();

        return $persisted;
    }

    /**
     * Persiste uniquement les nouvelles bougies (pas de doublon sur (contract, step, timestamp)) via ORM.
     *
     * @param Contract|string             $contractOrSymbol (autorise le symbol direct)
     * @param iterable<FuturesKlineDto>   $dtos             Liste de DTOs Kline (Bitmart)
     * @param int                         $stepMinutes      Minutes du timeframe (1,5,15,60,240,…)
     * @param bool                        $flush            flush immédiat
     * @return Kline[]                    Les entités effectivement persistées (nouvelles)
     */
    public function persistMany(Contract|string $contractOrSymbol, iterable $dtos, int $stepMinutes, bool $flush = false): array
    {
        $symbol = $contractOrSymbol instanceof Contract ? $contractOrSymbol->getSymbol() : (string) $contractOrSymbol;
        /** @var Contract $contractRef */
        $contractRef = $this->em->getReference(Contract::class, $symbol);
        $stepSec = $stepMinutes * 60;

        $candidates = [];   // [ ['entity'=>Kline, 'ts'=>int], ... ]
        $minTs = PHP_INT_MAX;
        $maxTs = PHP_INT_MIN;

        foreach ($dtos as $dto) {
            if (!$dto instanceof FuturesKlineDto) {
                continue;
            }
            /** @var Kline $k */
            $k = (new Kline())->fillFromDto($dto, $contractRef, $stepMinutes);
            $ts = (int) $k->getTimestamp()->format('U');
            $candidates[] = ['entity' => $k, 'ts' => $ts];

            if ($ts < $minTs) { $minTs = $ts; }
            if ($ts > $maxTs) { $maxTs = $ts; }
        }

        if ($minTs === PHP_INT_MAX) {
            if ($flush) { $this->em->flush(); $this->em->clear(); }
            return [];
        }

        // Charge ce qui existe déjà sur [minTs, maxTs]
        $existing = $this->klineRepository->fetchOpenTimestampsRange($symbol, $stepMinutes, $minTs, $maxTs);
        $existingSet = \array_fill_keys($existing, true);

        $new = [];
        foreach ($candidates as $row) {
            if (!isset($existingSet[$row['ts']])) {
                $this->em->persist($row['entity']);
                $new[] = $row['entity'];
            }
        }

        if ($flush) {
            $this->em->flush();
            $this->em->clear();
        }

        return $new;
    }

    public function clear(): void
    {
        $this->em->clear();
    }

    /**
     * Upsert en masse des klines (MySQL) pour un pas donné.
     * Utilise un INSERT multi-VALUES avec ON DUPLICATE KEY UPDATE (syntax MySQL 8+).
     *
     * Contraintes côté DB requises :
     *   ALTER TABLE `kline`
     *     ADD UNIQUE KEY `uniq_kline_contract_ts_step` (`contract_id`,`timestamp`,`step`);
     *
     * @param Contract                   $contract
     * @param int                        $stepMinutes  ex: 15 pour 15m
     * @param iterable<FuturesKlineDto>  $dtos
     * @param int                        $chunkSize    nombre max de lignes par batch INSERT
     * @return int                       lignes affectées (insert=1, update=2 par ligne ; 0 si no-op)
     */
    public function upsertMany(Contract $contract, int $stepMinutes, FuturesKlineCollection $dtos, int $chunkSize = 500): int
    {
        $stepSec = $stepMinutes * 60;
        $symbol  = $contract->getSymbol();

        $rows = [];
        foreach ($dtos as $dto) {
            if (!$dto instanceof FuturesKlineDto) {
                continue;
            }
            // Ne stocke que des bougies closes et alignées sur le pas.
            if (($dto->timestamp % $stepSec) !== 0) {
                continue;
            }

            $rows[] = [
                'contract_id' => $symbol,
                'timestamp'   => $dto->timestamp,
                'open'        => $dto->open,
                'close'       => $dto->close,
                'high'        => $dto->high,
                'low'         => $dto->low,
                'volume'      => $dto->volume,
                'step'        => $stepMinutes,
            ];
        }

        if ($rows === []) {
            return 0;
        }

        $affected = 0;
        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            [$sql, $params] = $this->buildUpsertQuery($chunk);
            $affected += $this->db->executeStatement($sql, $params);
        }

        return $affected;
    }

    /**
     * Prépare la requête MySQL INSERT ... ON DUPLICATE KEY UPDATE pour un batch.
     *
     * @param array<int, array<string, int|float|string>> $rows
     * @return array{0:string,1:array<string,int|float|string>}
     */
    private function buildUpsertQuery(array $rows): array
    {
        $placeholders = [];
        $params = [];

        foreach ($rows as $index => $row) {
            $suffix = (string) $index;
            $placeholders[] = sprintf(
                '( :cid_%1$s, FROM_UNIXTIME(:ts_%1$s), :open_%1$s, :close_%1$s, :high_%1$s, :low_%1$s, :volume_%1$s, :step_%1$s )',
                $suffix
            );

            $params['cid_'.$suffix]    = $row['contract_id'];
            $params['ts_'.$suffix]     = $row['timestamp'];
            $params['open_'.$suffix]   = $row['open'];
            $params['close_'.$suffix]  = $row['close'];
            $params['high_'.$suffix]   = $row['high'];
            $params['low_'.$suffix]    = $row['low'];
            $params['volume_'.$suffix] = $row['volume'];
            $params['step_'.$suffix]   = $row['step'];
        }

        $values = implode(",\n  ", $placeholders);

        $sql = sprintf(
            <<<SQL
INSERT INTO `kline` (`contract_id`, `timestamp`, `open`, `close`, `high`, `low`, `volume`, `step`)
VALUES
  %s
AS new
ON DUPLICATE KEY UPDATE
  `open`   = new.`open`,
  `close`  = new.`close`,
  `high`   = new.`high`,
  `low`    = new.`low`,
  `volume` = new.`volume`,
  `step`   = new.`step`
SQL,
            $values
        );

        return [$sql, $params];
    }
}
