<?php

declare(strict_types=1);

namespace App\Provider\Repository;

use App\Config\MtfContractsConfig;
use App\Provider\Entity\Contract;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Doctrine\DBAL\Types\Types;

/**
 * @extends ServiceEntityRepository<Contract>
 */
class ContractRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly Connection $conn,
        private readonly MtfContractsConfig $config,
        private readonly ClockInterface $clock
    )
    {
        parent::__construct($registry, Contract::class);
    }

    /**
     * Récupère tous les contrats actifs selon les critères de trading
     */
    public function findActiveContracts(): array
    {
        $symbols = $this->findSymbolsMixedLiquidity();
        if ($symbols === []) {
            return [];
        }

        // Utiliser un tri en PHP pour éviter les problèmes avec les fonctions SQL natives
        // qui ne sont pas reconnues par Doctrine DQL
        $qb = $this->createQueryBuilder('c')
            ->where('c.symbol IN (:symbols)')
            ->setParameter('symbols', $symbols);

        $entities = $qb->getQuery()->getResult();

        // Trier en PHP pour préserver l'ordre des symboles
        $bySymbol = [];
        foreach ($entities as $e) {
            $bySymbol[$e->getSymbol()] = $e;
        }
        $orderedEntities = [];
        foreach ($symbols as $s) {
            if (isset($bySymbol[$s])) {
                $orderedEntities[] = $bySymbol[$s];
            }
        }

        return $orderedEntities;
    }

    /**
     * Récupère un contrat par son symbole
     */
    public function findBySymbol(string $symbol): ?Contract
    {
        return $this->findOneBy(['symbol' => $symbol]);
    }

    /**
     * Récupère les contrats par devise de quote
     */
    public function findByQuoteCurrency(string $quoteCurrency): array
    {
        return $this->findBy(['quoteCurrency' => $quoteCurrency]);
    }

    /**
     * Récupère les contrats par statut
     */
    public function findByStatus(string $status): array
    {
        return $this->findBy(['status' => $status]);
    }

    /**
     * Compte le nombre de contrats actifs
     */
    public function countActiveContracts(): int
    {
        return count($this->findSymbolsMixedLiquidity());
    }


    /**
     * Récupère les statistiques des contrats
     */
    public function getContractStats(): array
    {
        $qb = $this->createQueryBuilder('c');

        $stats = $qb
            ->select([
                'c.quoteCurrency',
                'c.status',
                'COUNT(c.id) as count',
                'AVG(c.volume24h) as avgVolume',
                'SUM(c.volume24h) as totalVolume'
            ])
            ->groupBy('c.quoteCurrency', 'c.status')
            ->orderBy('c.quoteCurrency', 'ASC')
            ->addOrderBy('c.status', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return $stats;
    }

    /**
     * UPSERT un contrat (insert ou update)
     */
    public function upsertContract(array $contractData): Contract
    {
        $symbol = $contractData['symbol'] ?? '';
        if (!$symbol) {
            throw new \InvalidArgumentException('Symbol is required');
        }

        $contract = $this->findBySymbol($symbol);

        if (!$contract) {
            $contract = new \App\Provider\Entity\Contract();
            $contract->setSymbol($symbol);
        }

        // Mettre à jour les données
        $contract->setName($contractData['name'] ?? null);
        $contract->setProductType($contractData['product_type'] ?? null);
        $contract->setOpenTimestamp($contractData['open_timestamp'] ?? null);
        $contract->setExpireTimestamp($contractData['expire_timestamp'] ?? null);
        $contract->setSettleTimestamp($contractData['settle_timestamp'] ?? null);
        $contract->setBaseCurrency($contractData['base_currency'] ?? null);
        $contract->setQuoteCurrency($contractData['quote_currency'] ?? null);
        $contract->setLastPrice($contractData['last_price'] ?? null);
        $contract->setVolume24h($contractData['volume_24h'] ?? null);
        $contract->setTurnover24h($contractData['turnover_24h'] ?? null);
        $contract->setStatus($contractData['status'] ?? null);
        $contract->setMinSize($contractData['min_size'] ?? null);
        $contract->setMaxSize($contractData['max_size'] ?? null);
        $contract->setTickSize($contractData['tick_size'] ?? null);
        $contract->setMultiplier($contractData['multiplier'] ?? null);

        // Ajout des nouveaux champs
        $contract->setIndexPrice($contractData['index_price'] ?? null);
        $contract->setIndexName($contractData['index_name'] ?? null);
        $contract->setContractSize($contractData['contract_size'] ?? null);
        $contract->setMinLeverage($contractData['min_leverage'] ?? null);
        $contract->setMaxLeverage($contractData['max_leverage'] ?? null);
        $contract->setPricePrecision($contractData['price_precision'] ?? null);
        $contract->setVolPrecision($contractData['vol_precision'] ?? null);
        $contract->setMaxVolume($contractData['max_volume'] ?? null);
        $contract->setMarketMaxVolume($contractData['market_max_volume'] ?? null);
        $contract->setMinVolume($contractData['min_volume'] ?? null);
        $contract->setFundingRate($contractData['funding_rate'] ?? null);
        $contract->setExpectedFundingRate($contractData['expected_funding_rate'] ?? null);
        $contract->setOpenInterest($contractData['open_interest'] ?? null);
        $contract->setOpenInterestValue($contractData['open_interest_value'] ?? null);
        $contract->setHigh24h($contractData['high_24h'] ?? null);
        $contract->setLow24h($contractData['low_24h'] ?? null);
        $contract->setChange24h($contractData['change_24h'] ?? null);
        $contract->setFundingIntervalHours($contractData['funding_interval_hours'] ?? null);
        $contract->setDelistTime($contractData['delist_time'] ?? null);

        $contract->setUpdatedAt($this->clock->now()->setTimezone(new \DateTimeZone('UTC')));

        $this->getEntityManager()->persist($contract);
        $this->getEntityManager()->flush();

        return $contract;
    }

    /**
     * Convertit un ContractDto en tableau pour upsertContract
     */
    private function contractDtoToArray($contractDto): array
    {
        if (!$contractDto instanceof \App\Provider\Bitmart\Dto\ContractDto) {
            throw new \InvalidArgumentException('Expected ContractDto instance');
        }

        return [
            'symbol' => $contractDto->symbol,
            'name' => $contractDto->symbol, // Utilise le symbole comme nom par défaut
            'product_type' => $contractDto->productType,
            'open_timestamp' => $contractDto->openTimestamp->getTimestamp(),
            'expire_timestamp' => $contractDto->expireTimestamp->getTimestamp(),
            'settle_timestamp' => $contractDto->settleTimestamp->getTimestamp(),
            'base_currency' => $contractDto->baseCurrency,
            'quote_currency' => $contractDto->quoteCurrency,
            'last_price' => $contractDto->lastPrice->toScale(8, \Brick\Math\RoundingMode::HALF_UP)->__toString(),
            'volume_24h' => $contractDto->volume24h->toScale(8, \Brick\Math\RoundingMode::HALF_UP)->__toString(),
            'turnover_24h' => $contractDto->turnover24h->toScale(8, \Brick\Math\RoundingMode::HALF_UP)->__toString(),
            'index_price' => $contractDto->indexPrice->toScale(8, \Brick\Math\RoundingMode::HALF_UP)->__toString(),
            'index_name' => $contractDto->indexName,
            'contract_size' => $contractDto->contractSize->toScale(8, \Brick\Math\RoundingMode::HALF_UP)->__toString(),
            'min_leverage' => $contractDto->minLeverage->toScale(8, \Brick\Math\RoundingMode::HALF_UP)->__toString(),
            'max_leverage' => $contractDto->maxLeverage->toScale(8, \Brick\Math\RoundingMode::HALF_UP)->__toString(),
            'price_precision' => $contractDto->pricePrecision->toScale(8, \Brick\Math\RoundingMode::HALF_UP)->__toString(),
            'vol_precision' => $contractDto->volPrecision->toScale(8, \Brick\Math\RoundingMode::HALF_UP)->__toString(),
            'max_volume' => $contractDto->maxVolume->toScale(8, \Brick\Math\RoundingMode::HALF_UP)->__toString(),
            'min_volume' => $contractDto->minVolume->toScale(8, \Brick\Math\RoundingMode::HALF_UP)->__toString(),
            'funding_rate' => $contractDto->fundingRate->toScale(8, \Brick\Math\RoundingMode::HALF_UP)->__toString(),
            'expected_funding_rate' => $contractDto->expectedFundingRate->toScale(8, \Brick\Math\RoundingMode::HALF_UP)->__toString(),
            'open_interest' => $contractDto->openInterest->toScale(8, \Brick\Math\RoundingMode::HALF_UP)->__toString(),
            'open_interest_value' => $contractDto->openInterestValue->toScale(8, \Brick\Math\RoundingMode::HALF_UP)->__toString(),
            'high_24h' => $contractDto->high24h->toScale(8, \Brick\Math\RoundingMode::HALF_UP)->__toString(),
            'low_24h' => $contractDto->low24h->toScale(8, \Brick\Math\RoundingMode::HALF_UP)->__toString(),
            'change_24h' => $contractDto->change24h->toScale(8, \Brick\Math\RoundingMode::HALF_UP)->__toString(),
            'market_max_volume' => $contractDto->marketMaxVolume->toScale(8, \Brick\Math\RoundingMode::HALF_UP)->__toString(),
            'funding_interval_hours' => $contractDto->fundingIntervalHours,
            'status' => $contractDto->status,
            'delist_time' => $contractDto->delistTime->getTimestamp(),
            'min_size' => null, // Non disponible dans le DTO
            'max_size' => null, // Non disponible dans le DTO
            'tick_size' => null, // Non disponible dans le DTO
            'multiplier' => null, // Non disponible dans le DTO
        ];
    }

    /**
     * UPSERT plusieurs contrats en lot
     */
    public function upsertContracts(array $contractsData): int
    {
        $upsertedCount = 0;
        $batchSize = 50;

        foreach (array_chunk($contractsData, $batchSize) as $batch) {
            foreach ($batch as $contractData) {
                try {
                    // Convertir ContractDto en tableau si nécessaire
                    if ($contractData instanceof \App\Provider\Bitmart\Dto\ContractDto) {
                        $contractData = $this->contractDtoToArray($contractData);
                    }

                    $this->upsertContract($contractData);
                    $upsertedCount++;
                } catch (\Exception $e) {
                    // Log l'erreur mais continue avec les autres contrats
                    $symbol = is_array($contractData) ? ($contractData['symbol'] ?? 'unknown') : 'unknown';
                    error_log("Error upserting contract {$symbol}: " . $e->getMessage());
                }
            }

            // Flush le batch
            $this->getEntityManager()->flush();
            $this->getEntityManager()->clear();
        }

        return $upsertedCount;
    }

    /**
     * Retourne la liste des symboles des contrats actifs, en excluant les blacklists.
     * Critères appliqués selon les colonnes disponibles: status=Trading, quote=USDT,
     * volume_24h >= seuil, et non expiré (expire_timestamp NULL, 0 ou > maintenant).
     *
     * @param array $symbols Si fourni, filtre les résultats pour ne garder que ces symboles
     * @param bool $ignoreLimits Si true, ignore les limites top_n/mid_n et retourne tous les symboles actifs
     * @return string[]
     */
    public function allActiveSymbolNames(array $symbols = [], bool $ignoreLimits = false): array
    {
        $all = $ignoreLimits
            ? $this->findAllActiveSymbolsWithoutLimits()
            : $this->findSymbolsMixedLiquidity();           // liste ordonnée (TOP + MID)
        if ($symbols === []) {
            return $all;
        }
        // Restreindre en conservant l'ordre initial
        $filter = array_flip($symbols);
        return array_values(array_filter($all, static fn(string $s) => isset($filter[$s])));
    }

    /**
     * Retourne TOUS les symboles actifs sans limite (top_n/mid_n).
     * Utile pour récupérer tous les contrats éligibles sans restriction de nombre.
     * @return string[]
     */
    public function findAllActiveSymbolsWithoutLimits(): array
    {
        // --- Config YAML ---
        $status            = (string) $this->config->getFilter('status', 'Trading');
        $quoteCurrency     = (string) $this->config->getFilter('quote_currency', 'USDT');
        $minTurnover       = (float)  $this->config->getFilter('min_turnover', 500000);
        $requireNotExpired = (bool)   $this->config->getFilter('require_not_expired', true);
        $expireUnit        = (string) $this->config->getFilter('expire_unit', false); // 's' | 'ms'
        $maxAgeHours       = (int)    $this->config->getFilter('max_age_hours', 880);

        // --- Horloge & unités ---
        $now    = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $nowSec = (int) $now->format('U');             // epoch seconds
        $nowMs  = (int) round(microtime(true) * 1000); // epoch millis
        $nowForExpire = ($expireUnit === 'ms') ? $nowMs : $nowSec;

        $openTsMinSec = $maxAgeHours > 0
            ? $now->sub(new \DateInterval('PT'.$maxAgeHours.'H'))->getTimestamp()
            : null;
        $openTsMin = $openTsMinSec;
        $openTsMinSet = !is_null($openTsMin);

        // --- Expression turnover ---
        $turnoverExpr = 'turnover_24h';

        // --- SQL sans LIMIT ---
        $sql = "
SELECT c.symbol
FROM contracts c
WHERE c.status = :status
  AND c.quote_currency = :quoteCurrency
  AND {$turnoverExpr} >= :minTurnover
  AND (
        :requireNotExpired = FALSE
     OR c.expire_timestamp IS NULL
     OR c.expire_timestamp = 0
     OR c.expire_timestamp > :nowForExpire
  )
  AND ( :openTsMinSet = FALSE OR c.open_timestamp > :openTsMin )
  AND NOT EXISTS (
      SELECT 1 FROM blacklisted_contract b
      WHERE b.symbol = c.symbol
        AND (b.expires_at IS NULL OR b.expires_at > :dtnow)
  )
  AND NOT EXISTS (
      SELECT 1 FROM mtf_switch m
      WHERE m.switch_key LIKE :symbolPattern
        AND SUBSTRING(m.switch_key FROM 8) = c.symbol
        AND m.is_on = :isOff
        AND (m.expires_at IS NULL OR m.expires_at > :dtnow)
  )
ORDER BY {$turnoverExpr} DESC
";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue('status', $status);
        $stmt->bindValue('quoteCurrency', $quoteCurrency);
        $stmt->bindValue('minTurnover', $minTurnover);
        $stmt->bindValue('requireNotExpired', $requireNotExpired, ParameterType::BOOLEAN);
        $stmt->bindValue('nowForExpire', $nowForExpire, ParameterType::INTEGER);
        $stmt->bindValue('openTsMin', $openTsMin, $openTsMin === null ? ParameterType::NULL : ParameterType::INTEGER);
        $stmt->bindValue('dtnow', $now, Types::DATETIME_IMMUTABLE);
        $stmt->bindValue('symbolPattern', 'SYMBOL:%');
        $stmt->bindValue('isOff', false, ParameterType::BOOLEAN);
        $stmt->bindValue('openTsMinSet', $openTsMinSet, ParameterType::BOOLEAN);

        $symbols = $stmt->executeQuery()->fetchFirstColumn();

        return array_values(array_unique($symbols));
    }
    public function findWithFilters(?string $status = null, ?string $symbol = null): array
    {
        $qb = $this->createQueryBuilder('c')
            ->orderBy('c.symbol', 'ASC');

        if ($status) {
            $qb->andWhere('c.status = :status')
                ->setParameter('status', $status);
        }

        if ($symbol) {
            $qb->andWhere('c.symbol LIKE :symbol')
                ->setParameter('symbol', '%' . $symbol . '%');
        }

        return $qb->getQuery()->getResult();
    }

    public function findSymbolsMixedLiquidity(): array
    {
        // --- Config YAML ---
        $status            = (string) $this->config->getFilter('status', 'Trading');
        $quoteCurrency     = (string) $this->config->getFilter('quote_currency', 'USDT');
        $minTurnover       = (float)  $this->config->getFilter('min_turnover', 500000);
        $midMaxTurnover    = (float)  $this->config->getFilter('mid_max_turnover', 2000000);
        $requireNotExpired = (bool)   $this->config->getFilter('require_not_expired', true);
        $expireUnit        = (string) $this->config->getFilter('expire_unit', 's');   // 's' | 'ms'
        $maxAgeHours       = (int)    $this->config->getFilter('max_age_hours', 880);
        $openUnit          = (string) $this->config->getFilter('open_unit', 's');     // 's' | 'ms'

        $topN   = (int) $this->config->getLimit('top_n', 0);
        $midN   = (int) $this->config->getLimit('mid_n', 0);

        $orderTop = strtoupper($this->config->getOrder('top', 'DESC'));
        $orderMid = strtoupper($this->config->getOrder('mid', 'ASC'));
        $validOrder = ['ASC', 'DESC'];
        if (!in_array($orderTop, $validOrder, true)) {
            $orderTop = 'DESC';
        }
        if (!in_array($orderMid, $validOrder, true)) {
            $orderMid = 'ASC';
        }

        // --- Horloge & unités ---
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));

        $nowSec = (int) $now->format('U');             // epoch seconds
        $nowMs  = (int) round(microtime(true) * 1000); // epoch millis

        $nowForExpire = ($expireUnit === 'ms') ? $nowMs : $nowSec;

        // borne min pour open_timestamp
        $openTsMin = null;
        if ($maxAgeHours > 0) {
            $boundarySec = $now->sub(new \DateInterval('PT'.$maxAgeHours.'H'))->getTimestamp();
            $openTsMin   = ($openUnit === 'ms') ? $boundarySec * 1000 : $boundarySec;
        }
        $openTsMinSet = ($openTsMin !== null);

        // --- Expression turnover (caster si stocké en texte) ---
        $turnoverExpr = 'turnover_24h'; // ou CAST(...) si besoin

        // --- Pas de requête si aucun bucket demandé ---
        if ($topN <= 0 && $midN <= 0) {
            return [];
        }

        // --- SQL principal (table: contracts) ---
        $sql = "
WITH base AS (
  SELECT c.symbol, {$turnoverExpr} AS t24
  FROM contracts c
  WHERE c.status = :status
    AND c.quote_currency = :quoteCurrency
    AND {$turnoverExpr} >= :minTurnover
    AND (
          :requireNotExpired = FALSE
       OR c.expire_timestamp IS NULL
       OR c.expire_timestamp = 0
       OR c.expire_timestamp > :nowForExpire
    )
    AND ( :openTsMinSet = FALSE OR c.open_timestamp < :openTsMin )
    AND NOT EXISTS (
        SELECT 1 FROM blacklisted_contract b
        WHERE b.symbol = c.symbol
          AND (b.expires_at IS NULL OR b.expires_at > :dtnow)
    )
    AND NOT EXISTS (
        SELECT 1 FROM mtf_switch m
        WHERE m.switch_key LIKE :symbolPattern
          AND SUBSTRING(m.switch_key FROM 8) = c.symbol
          AND m.is_on = :isOff
          AND (m.expires_at IS NULL OR m.expires_at > :dtnow)
    )
)
";

        // TOP : t24 > midMaxTurnover
        if ($topN > 0) {
            $sql .= "
SELECT symbol FROM (
  SELECT symbol
  FROM base
  WHERE t24 > :midMaxTurnover
  ORDER BY t24 {$orderTop}
  LIMIT :topN
) t_top
";
        }

        // UNION ALL si TOP + MID
        if ($topN > 0 && $midN > 0) {
            $sql .= "UNION ALL\n";
        }

        // MID : entre minTurnover et midMaxTurnover
        if ($midN > 0) {
            $sql .= "
SELECT symbol FROM (
  SELECT symbol
  FROM base
  WHERE t24 BETWEEN :minTurnover AND :midMaxTurnover
  ORDER BY t24 {$orderMid}
  LIMIT :midN
) t_mid
";
        }

        $stmt = $this->conn->prepare($sql);

        // --- Bind communs ---
        $stmt->bindValue('status',         $status);
        $stmt->bindValue('quoteCurrency',  $quoteCurrency);
        $stmt->bindValue('minTurnover',    $minTurnover);
        $stmt->bindValue('requireNotExpired', $requireNotExpired, ParameterType::BOOLEAN);
        $stmt->bindValue('nowForExpire',   $nowForExpire, ParameterType::INTEGER);
        $stmt->bindValue('dtnow',          $now, Types::DATETIME_IMMUTABLE);
        $stmt->bindValue('symbolPattern',  'SYMBOL:%');
        $stmt->bindValue('isOff',          false, ParameterType::BOOLEAN);
        $stmt->bindValue('openTsMinSet',   $openTsMinSet, ParameterType::BOOLEAN);

        if ($openTsMin !== null) {
            $stmt->bindValue('openTsMin', $openTsMin, ParameterType::INTEGER);
        } else {
            $stmt->bindValue('openTsMin', null, ParameterType::NULL);
        }

        if ($topN > 0) {
            $stmt->bindValue('midMaxTurnover', $midMaxTurnover);
            $stmt->bindValue('topN', $topN, ParameterType::INTEGER);
        }

        if ($midN > 0) {
            $stmt->bindValue('midMaxTurnover', $midMaxTurnover);
            $stmt->bindValue('midN', $midN, ParameterType::INTEGER);
        }

        $symbols = $stmt->executeQuery()->fetchFirstColumn();

        return array_values(array_unique($symbols));
    }
}
