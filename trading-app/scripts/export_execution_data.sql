-- Script SQL pour exporter les données persistées
-- Usage: psql -d trading_app -f scripts/export_execution_data.sql -v trace_id='PEOPLEUSDT-104323' -v run_id='9ae327c7-1939-4221-9d24-9698ff1d3039'

\set ON_ERROR_STOP on

-- Créer un fichier JSON avec toutes les données
\o /tmp/execution_data_export.json

SELECT json_build_object(
    'metadata', json_build_object(
        'trace_id', :'trace_id',
        'run_id', :'run_id',
        'exported_at', now()::text
    ),
    'data', json_build_object(
        'indicator_snapshots', (
            SELECT COALESCE(json_agg(row_to_json(t)), '[]'::json)
            FROM (
                SELECT * FROM indicator_snapshots 
                WHERE trace_id = :'trace_id'
                ORDER BY kline_time
            ) t
        ),
        'mtf_audit', (
            SELECT COALESCE(json_agg(row_to_json(t)), '[]'::json)
            FROM (
                SELECT * FROM mtf_audit 
                WHERE run_id = :'run_id'::uuid OR trace_id = :'trace_id'
                ORDER BY created_at
            ) t
        ),
        'mtf_run', (
            SELECT COALESCE(json_agg(row_to_json(t)), '[]'::json)
            FROM (
                SELECT * FROM mtf_run 
                WHERE id = :'run_id'::uuid
            ) t
        ),
        'mtf_run_symbol', (
            SELECT COALESCE(json_agg(row_to_json(t)), '[]'::json)
            FROM (
                SELECT * FROM mtf_run_symbol 
                WHERE run_id = :'run_id'::uuid
            ) t
        ),
        'mtf_run_metric', (
            SELECT COALESCE(json_agg(row_to_json(t)), '[]'::json)
            FROM (
                SELECT * FROM mtf_run_metric 
                WHERE run_id = :'run_id'::uuid
            ) t
        ),
        'mtf_switch', (
            SELECT COALESCE(json_agg(row_to_json(t)), '[]'::json)
            FROM (
                SELECT * FROM mtf_switch 
                WHERE symbol = (SELECT symbol FROM mtf_run WHERE id = :'run_id'::uuid LIMIT 1)
                ORDER BY created_at DESC 
                LIMIT 10
            ) t
        ),
        'mtf_lock', (
            SELECT COALESCE(json_agg(row_to_json(t)), '[]'::json)
            FROM (
                SELECT * FROM mtf_lock 
                WHERE symbol = (SELECT symbol FROM mtf_run WHERE id = :'run_id'::uuid LIMIT 1)
                ORDER BY created_at DESC 
                LIMIT 10
            ) t
        ),
        'mtf_state', (
            SELECT COALESCE(json_agg(row_to_json(t)), '[]'::json)
            FROM (
                SELECT * FROM mtf_state 
                WHERE symbol = (SELECT symbol FROM mtf_run WHERE id = :'run_id'::uuid LIMIT 1)
                ORDER BY updated_at DESC 
                LIMIT 10
            ) t
        ),
        'order_intent', (
            SELECT COALESCE(json_agg(row_to_json(t)), '[]'::json)
            FROM (
                SELECT * FROM order_intent 
                WHERE client_order_id = (
                    SELECT client_order_id FROM trade_lifecycle_event 
                    WHERE run_id = :'run_id' 
                    LIMIT 1
                )
                OR (
                    symbol = (SELECT symbol FROM mtf_run WHERE id = :'run_id'::uuid LIMIT 1)
                    AND created_at >= (SELECT MIN(happened_at) FROM trade_lifecycle_event WHERE run_id = :'run_id') - INTERVAL '1 hour'
                )
                ORDER BY created_at DESC 
                LIMIT 10
            ) t
        ),
        'futures_order', (
            SELECT COALESCE(json_agg(row_to_json(t)), '[]'::json)
            FROM (
                SELECT * FROM futures_order 
                WHERE client_order_id = (
                    SELECT client_order_id FROM trade_lifecycle_event 
                    WHERE run_id = :'run_id' 
                    LIMIT 1
                )
                OR order_id = (
                    SELECT order_id FROM trade_lifecycle_event 
                    WHERE run_id = :'run_id' AND order_id IS NOT NULL 
                    LIMIT 1
                )
            ) t
        ),
        'futures_order_trade', (
            SELECT COALESCE(json_agg(row_to_json(t)), '[]'::json)
            FROM (
                SELECT fot.* FROM futures_order_trade fot
                INNER JOIN futures_order fo ON fot.futures_order_id = fo.id
                WHERE fo.client_order_id = (
                    SELECT client_order_id FROM trade_lifecycle_event 
                    WHERE run_id = :'run_id' 
                    LIMIT 1
                )
                OR fo.order_id = (
                    SELECT order_id FROM trade_lifecycle_event 
                    WHERE run_id = :'run_id' AND order_id IS NOT NULL 
                    LIMIT 1
                )
            ) t
        ),
        'futures_plan_order', (
            SELECT COALESCE(json_agg(row_to_json(t)), '[]'::json)
            FROM (
                SELECT * FROM futures_plan_order 
                WHERE client_order_id = (
                    SELECT client_order_id FROM trade_lifecycle_event 
                    WHERE run_id = :'run_id' 
                    LIMIT 1
                )
            ) t
        ),
        'trade_lifecycle_event', (
            SELECT COALESCE(json_agg(row_to_json(t)), '[]'::json)
            FROM (
                SELECT * FROM trade_lifecycle_event 
                WHERE run_id = :'run_id' 
                OR client_order_id IN (
                    SELECT client_order_id FROM trade_lifecycle_event 
                    WHERE run_id = :'run_id'
                )
                ORDER BY happened_at
            ) t
        ),
        'trade_zone_events', (
            SELECT COALESCE(json_agg(row_to_json(t)), '[]'::json)
            FROM (
                SELECT * FROM trade_zone_events 
                WHERE symbol = (SELECT symbol FROM mtf_run WHERE id = :'run_id'::uuid LIMIT 1)
                AND (
                    decision_key = (SELECT plan_id FROM trade_lifecycle_event WHERE run_id = :'run_id' AND plan_id IS NOT NULL LIMIT 1)
                    OR decision_key LIKE '%' || (SELECT plan_id FROM trade_lifecycle_event WHERE run_id = :'run_id' AND plan_id IS NOT NULL LIMIT 1) || '%'
                )
                AND happened_at >= (SELECT MIN(happened_at) FROM trade_lifecycle_event WHERE run_id = :'run_id') - INTERVAL '1 hour'
                AND happened_at <= (SELECT MAX(happened_at) FROM trade_lifecycle_event WHERE run_id = :'run_id') + INTERVAL '1 hour'
                ORDER BY happened_at
            ) t
        )
    )
);

\o

