<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'analyze:sl-sequential',
    description: 'Analyse les positions qui ont touché SL et les ordres séquentiels'
)]
class AnalyzeSlSequentialOrdersCommand extends Command
{
    public function __construct(
        private readonly Connection $connection
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('hours', null, InputOption::VALUE_OPTIONAL, 'Nombre d\'heures à analyser', '24')
            ->addOption('query', null, InputOption::VALUE_OPTIONAL, 'Numéro de requête spécifique (1-8)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $hours = (int) $input->getOption('hours');
        $queryNum = $input->getOption('query');

        $io->title('Analyse des SL et ordres séquentiels');

        $queries = $this->getQueries($hours);

        if ($queryNum) {
            $this->executeQuery($io, (int) $queryNum, $queries[(int) $queryNum - 1] ?? null);
        } else {
            foreach ($queries as $index => $query) {
                $this->executeQuery($io, $index + 1, $query);
            }
        }

        return Command::SUCCESS;
    }

    private function executeQuery(SymfonyStyle $io, int $num, ?array $query): void
    {
        if (!$query) {
            $io->error("Requête #{$num} non trouvée");
            return;
        }

        $io->section($query['title']);
        $io->text($query['description']);

        try {
            $results = $this->connection->fetchAllAssociative($query['sql']);
            
            if (empty($results)) {
                $io->warning('Aucun résultat trouvé');
                return;
            }

            $headers = array_keys($results[0]);
            $io->table($headers, array_map(fn($row) => array_values($row), $results));

            if (isset($query['analysis'])) {
                $io->note($query['analysis']);
            }
        } catch (\Exception $e) {
            $io->error("Erreur lors de l'exécution: " . $e->getMessage());
        }
    }

    private function getQueries(int $hours): array
    {
        $interval = "INTERVAL '{$hours} hours'";
        $days = max(1, (int) ceil($hours / 24));

        return [
            [
                'title' => '1. Ordres séquentiels (même symbole, < 30s)',
                'description' => 'Détecte les ordres envoyés rapidement pour le même symbole',
                'sql' => "
                    WITH order_sequences AS (
                        SELECT 
                            eo1.symbol,
                            eo1.client_order_id as order1_id,
                            eo1.submitted_at as order1_time,
                            eo1.kind as order1_kind,
                            eo2.client_order_id as order2_id,
                            eo2.submitted_at as order2_time,
                            eo2.kind as order2_kind,
                            EXTRACT(EPOCH FROM (eo2.submitted_at - eo1.submitted_at)) as interval_seconds
                        FROM exchange_order eo1
                        JOIN exchange_order eo2 ON eo1.symbol = eo2.symbol 
                            AND eo2.submitted_at > eo1.submitted_at
                            AND eo2.submitted_at <= eo1.submitted_at + INTERVAL '30 seconds'
                        WHERE eo1.submitted_at >= NOW() - {$interval}
                    )
                    SELECT 
                        symbol,
                        COUNT(*) as rapid_sequences,
                        ROUND(MIN(interval_seconds)::numeric, 2) as min_interval_sec,
                        ROUND(AVG(interval_seconds)::numeric, 2) as avg_interval_sec,
                        ROUND(MAX(interval_seconds)::numeric, 2) as max_interval_sec
                    FROM order_sequences
                    GROUP BY symbol
                    ORDER BY rapid_sequences DESC
                    LIMIT 20
                ",
                'analysis' => 'Les symboles avec beaucoup de rapid_sequences indiquent des envois multiples rapides.'
            ],
            [
                'title' => '2. Positions fermées par SL',
                'description' => 'Liste toutes les positions fermées par stop-loss',
                'sql' => "
                    SELECT 
                        p.symbol,
                        p.side,
                        ROUND(p.avg_entry_price::numeric, 8) as entry_price,
                        ROUND(p.size::numeric, 4) as size,
                        p.status,
                        p.inserted_at,
                        p.updated_at,
                        ROUND(eo_close.price::numeric, 8) as close_price,
                        eo_close.submitted_at as close_time,
                        eo_close.kind as close_kind,
                        ROUND(CASE 
                            WHEN p.side = 'LONG' THEN 
                                (p.avg_entry_price::numeric - eo_close.price::numeric) * p.size::numeric
                            ELSE 
                                (eo_close.price::numeric - p.avg_entry_price::numeric) * p.size::numeric
                        END, 4) as realized_loss
                    FROM positions p
                    JOIN exchange_order eo_close ON eo_close.position_id = p.id
                    WHERE p.status = 'CLOSED'
                        AND eo_close.kind = 'SL'
                        AND p.updated_at >= NOW() - {$interval}
                    ORDER BY p.updated_at DESC
                    LIMIT 50
                ",
                'analysis' => 'Vérifiez les realized_loss négatifs - ils indiquent des pertes.'
            ],
            [
                'title' => '3. Distances SL vs Entry (détection SL trop serrés)',
                'description' => 'Analyse les distances SL par rapport au prix d\'entrée',
                'sql' => "
                    SELECT 
                        op.symbol,
                        op.side::text as side,
                        op.plan_time,
                        ROUND((op.risk_json->>'entry')::numeric, 8) as entry_price,
                        ROUND((op.risk_json->>'stop')::numeric, 8) as stop_price,
                        op.exec_json->>'client_order_id' as client_order_id,
                        ROUND(CASE 
                            WHEN op.side::text = 'LONG' THEN
                                ((op.risk_json->>'entry')::numeric - (op.risk_json->>'stop')::numeric) / 
                                (op.risk_json->>'entry')::numeric * 100
                            ELSE
                                ((op.risk_json->>'stop')::numeric - (op.risk_json->>'entry')::numeric) / 
                                (op.risk_json->>'entry')::numeric * 100
                        END, 4) as stop_distance_pct,
                        op.context_json->>'atr' as atr_value,
                        op.context_json->>'atr_k' as atr_k,
                        op.risk_json->>'stop_from' as stop_from_method,
                        op.status
                    FROM order_plan op
                    WHERE op.plan_time >= NOW() - INTERVAL '{$days} days'
                        AND op.risk_json->>'stop' IS NOT NULL
                        AND op.risk_json->>'entry' IS NOT NULL
                    ORDER BY stop_distance_pct ASC
                    LIMIT 50
                ",
                'analysis' => 'Les stop_distance_pct < 0.5% indiquent des SL trop serrés (risque élevé).'
            ],
            [
                'title' => '4. Statut des ordres SL (placés vs exécutés)',
                'description' => 'Compare les ordres SL placés avec ceux exécutés',
                'sql' => "
                    SELECT 
                        eo.symbol,
                        eo.kind,
                        eo.status,
                        COUNT(*) as count,
                        MIN(eo.submitted_at) as first_submitted,
                        MAX(eo.submitted_at) as last_submitted
                    FROM exchange_order eo
                    WHERE eo.submitted_at >= NOW() - {$interval}
                        AND eo.kind = 'SL'
                    GROUP BY eo.symbol, eo.kind, eo.status
                    ORDER BY eo.symbol, eo.status
                ",
                'analysis' => 'FILLED = SL exécuté, SUBMITTED = en attente, CANCELLED = annulé.'
            ],
            [
                'title' => '5. Patterns d\'envoi séquentiel par minute',
                'description' => 'Identifie les fenêtres d\'une minute avec plusieurs ordres',
                'sql' => "
                    SELECT 
                        symbol,
                        DATE_TRUNC('minute', submitted_at) as minute_window,
                        COUNT(*) as orders_count,
                        array_agg(DISTINCT kind ORDER BY kind) as kinds,
                        array_agg(client_order_id ORDER BY submitted_at) as order_ids
                    FROM exchange_order
                    WHERE submitted_at >= NOW() - {$interval}
                    GROUP BY symbol, DATE_TRUNC('minute', submitted_at)
                    HAVING COUNT(*) > 1
                    ORDER BY orders_count DESC, minute_window DESC
                    LIMIT 30
                ",
                'analysis' => 'Les fenêtres avec orders_count > 1 indiquent des envois multiples dans la même minute.'
            ],
            [
                'title' => '6. SL basés sur pivot (vérification serrage)',
                'description' => 'Analyse les SL calculés avec pivot pour détecter ceux trop serrés',
                'sql' => "
                    SELECT 
                        op.symbol,
                        op.plan_time,
                        ROUND((op.risk_json->>'entry')::numeric, 8) as entry,
                        ROUND((op.risk_json->>'stop')::numeric, 8) as stop,
                        op.risk_json->>'stop_from' as stop_from,
                        op.context_json->>'pivot_levels' as pivot_levels,
                        op.context_json->>'atr' as atr,
                        CASE 
                            WHEN op.side::text = 'LONG' THEN
                                ((op.risk_json->>'entry')::numeric - (op.risk_json->>'stop')::numeric) / 
                                (op.risk_json->>'entry')::numeric * 100 < 0.5
                            ELSE
                                ((op.risk_json->>'stop')::numeric - (op.risk_json->>'entry')::numeric) / 
                                (op.risk_json->>'entry')::numeric * 100 < 0.5
                        END as is_too_tight
                    FROM order_plan op
                    WHERE op.plan_time >= NOW() - INTERVAL '{$days} days'
                        AND op.risk_json->>'stop_from' = 'pivot'
                        AND op.risk_json->>'stop' IS NOT NULL
                    ORDER BY op.plan_time DESC
                    LIMIT 50
                ",
                'analysis' => 'is_too_tight = true signifie SL < 0.5% (risque élevé de déclenchement).'
            ],
            [
                'title' => '7. Ordres consécutifs avec intervalles',
                'description' => 'Détecte les envois multiples pour le même symbole dans un court laps de temps',
                'sql' => "
                    SELECT 
                        eo1.symbol,
                        eo1.client_order_id,
                        eo1.kind,
                        eo1.submitted_at,
                        eo2.client_order_id as next_order_id,
                        eo2.kind as next_kind,
                        eo2.submitted_at as next_submitted_at,
                        ROUND(EXTRACT(EPOCH FROM (eo2.submitted_at - eo1.submitted_at))::numeric, 2) as seconds_between
                    FROM exchange_order eo1
                    LEFT JOIN LATERAL (
                        SELECT eo2.*
                        FROM exchange_order eo2
                        WHERE eo2.symbol = eo1.symbol
                            AND eo2.submitted_at > eo1.submitted_at
                            AND eo2.submitted_at <= eo1.submitted_at + INTERVAL '5 minutes'
                        ORDER BY eo2.submitted_at ASC
                        LIMIT 1
                    ) eo2 ON true
                    WHERE eo1.submitted_at >= NOW() - {$interval}
                        AND eo2.client_order_id IS NOT NULL
                    ORDER BY eo1.symbol, eo1.submitted_at
                    LIMIT 50
                ",
                'analysis' => 'seconds_between < 60 indique des envois très rapprochés (potentiel problème).'
            ],
            [
                'title' => '8. Statistiques globales sur les SL',
                'description' => 'Statistiques globales sur les ordres stop-loss',
                'sql' => "
                    SELECT 
                        COUNT(*) as total_sl_orders,
                        COUNT(DISTINCT symbol) as symbols_affected,
                        COUNT(CASE WHEN status = 'FILLED' THEN 1 END) as sl_filled,
                        COUNT(CASE WHEN status = 'SUBMITTED' THEN 1 END) as sl_pending,
                        COUNT(CASE WHEN status = 'CANCELLED' THEN 1 END) as sl_cancelled,
                        ROUND(AVG(EXTRACT(EPOCH FROM (updated_at - submitted_at)))::numeric, 2) as avg_lifetime_seconds
                    FROM exchange_order
                    WHERE kind = 'SL'
                        AND submitted_at >= NOW() - {$interval}
                ",
                'analysis' => 'sl_filled / total_sl_orders donne le taux d\'exécution des SL.'
            ],
        ];
    }
}

