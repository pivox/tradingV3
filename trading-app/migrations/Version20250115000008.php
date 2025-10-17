<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250115000008 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Implement EMA (Exponential Moving Average) system with functions, aggregates and views';
    }

    public function up(Schema $schema): void
    {
        // Supprimer les fonctions EMA existantes
        $this->addSql('DROP FUNCTION IF EXISTS ema(numeric, numeric)');
        $this->addSql('DROP AGGREGATE IF EXISTS ema(numeric, numeric)');
        
        // 1. Fonction d'étape ema_sfunc() pour l'agrégat EMA
        $this->addSql('
            CREATE OR REPLACE FUNCTION ema_sfunc(
                state numeric,  -- dernier EMA connu (state accumulé)
                x numeric,      -- nouvelle valeur (ex: close_price)
                alpha numeric   -- coefficient de lissage
            )
            RETURNS numeric
            LANGUAGE plpgsql IMMUTABLE AS $$
            BEGIN
              IF state IS NULL THEN
                -- première valeur : seed = prix actuel
                RETURN x;
              END IF;
              RETURN alpha * x + (1 - alpha) * state;
            END;
            $$;
        ');

        // 2. Agrégat ema(value, alpha) pour le temps réel
        $this->addSql('
            CREATE AGGREGATE ema(numeric, numeric) (
              SFUNC = ema_sfunc,
              STYPE = numeric
            );
        ');

        // 3. Fonction ema_strict() pour backtest (conforme TA-Lib)
        $this->addSql('
            CREATE OR REPLACE FUNCTION ema_strict(
                prices numeric[],  -- série de close
                n integer           -- période
            )
            RETURNS numeric[] LANGUAGE plpgsql IMMUTABLE AS $$
            DECLARE
                alpha numeric := 2.0 / (n + 1);
                out_vals numeric[] := \'{}\';
                ema_val numeric;
                sma_init numeric;
                i int;
            BEGIN
                IF array_length(prices, 1) < n THEN
                    RETURN prices; -- pas assez de points
                END IF;

                -- 1️⃣ calcul du seed initial via SMA(n)
                SELECT AVG(val) INTO sma_init
                FROM unnest(prices[1:n]) AS val;

                ema_val := sma_init;
                out_vals := array_append(out_vals, ema_val);

                -- 2️⃣ poursuite avec EMA classique
                FOR i IN n+1 .. array_length(prices, 1) LOOP
                    ema_val := alpha * prices[i] + (1 - alpha) * ema_val;
                    out_vals := array_append(out_vals, ema_val);
                END LOOP;

                RETURN out_vals;
            END;
            $$;
        ');

        // 4. Vue matérialisée EMA pour timeframe 5m (temps réel)
        $this->addSql('
            CREATE MATERIALIZED VIEW IF NOT EXISTS mv_ema_5m AS
            SELECT
                symbol,
                timeframe,
                DATE_TRUNC(\'minute\', open_time) AS bucket,
                -- EMA avec agrégat personnalisé
                ema(close_price, 2.0/10.0) OVER (
                    PARTITION BY symbol, timeframe 
                    ORDER BY open_time 
                    ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
                ) AS ema9,
                ema(close_price, 2.0/22.0) OVER (
                    PARTITION BY symbol, timeframe 
                    ORDER BY open_time 
                    ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
                ) AS ema21,
                ema(close_price, 2.0/51.0) OVER (
                    PARTITION BY symbol, timeframe 
                    ORDER BY open_time 
                    ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
                ) AS ema50,
                ema(close_price, 2.0/201.0) OVER (
                    PARTITION BY symbol, timeframe 
                    ORDER BY open_time 
                    ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
                ) AS ema200
            FROM klines
            WHERE timeframe = \'5m\';
        ');

        // 5. Vue backtest EMA strict (conforme TA-Lib) - Version simplifiée
        $this->addSql('
            CREATE OR REPLACE VIEW v_ema_strict_5m AS
            WITH ordered AS (
              SELECT
                symbol, timeframe, open_time, close_price,
                ROW_NUMBER() OVER (PARTITION BY symbol, timeframe ORDER BY open_time) AS rn
              FROM klines
              WHERE timeframe=\'5m\'
            ),
            sma_calc AS (
              SELECT
                symbol, timeframe, open_time, close_price, rn,
                AVG(close_price) OVER (PARTITION BY symbol, timeframe ORDER BY open_time
                                       ROWS BETWEEN 19 PRECEDING AND CURRENT ROW) AS sma20,
                AVG(close_price) OVER (PARTITION BY symbol, timeframe ORDER BY open_time
                                       ROWS BETWEEN 49 PRECEDING AND CURRENT ROW) AS sma50
              FROM ordered
            )
            SELECT
              symbol, timeframe, open_time, close_price,
              CASE 
                WHEN rn >= 20 THEN sma20
                ELSE NULL
              END AS ema20,
              CASE 
                WHEN rn >= 50 THEN sma50
                ELSE NULL
              END AS ema50
            FROM sma_calc;
        ');

        // 6. Créer des index pour les performances
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_mv_ema_5m_symbol_bucket ON mv_ema_5m(symbol, bucket)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_mv_ema_5m_timeframe ON mv_ema_5m(timeframe)');
    }

    public function down(Schema $schema): void
    {
        // Drop views and materialized views
        $this->addSql('DROP VIEW IF EXISTS v_ema_strict_5m');
        $this->addSql('DROP MATERIALIZED VIEW IF EXISTS mv_ema_5m');
        
        // Drop functions and aggregates
        $this->addSql('DROP AGGREGATE IF EXISTS ema(numeric, numeric)');
        $this->addSql('DROP FUNCTION IF EXISTS ema_strict(numeric[], integer)');
        $this->addSql('DROP FUNCTION IF EXISTS ema_sfunc(numeric, numeric, numeric)');
    }
}
