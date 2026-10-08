-- Paper zero-trade diagnosis for exactly one disposable replay run.
-- Usage: psql -X -v ON_ERROR_STOP=1 -v run_id='bt-...' -f this-file.sql
-- Read only. Never point this at a shared Paper database.
\set ON_ERROR_STOP on
BEGIN TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY;

-- Fail rather than silently reporting zero when the run is absent or ambiguous.
SELECT count(*) / (count(*) = 1)::int AS exactly_one_cell
FROM paper_execution_cell
WHERE run_id = :'run_id';

SELECT c.run_id, c.id AS cell_id, c.network, c.market_data_venue,
       c.eligibility, c.terminal_state, c.dataset_id, c.dataset_events_sha256,
       k.next_source_position, k.journal_ordinal, k.killed
FROM paper_execution_cell c
JOIN paper_execution_checkpoint k ON k.cell_id = c.id
WHERE c.run_id = :'run_id';

SELECT e.event_type, count(*) AS journal_rows
FROM paper_execution_event e
JOIN paper_execution_cell c ON c.id = e.cell_id
WHERE c.run_id = :'run_id'
GROUP BY e.event_type
ORDER BY e.event_type;

-- A replay_batch is one journal row but may represent thousands of source events.
-- [null, null, count] means sources without a strategy observation, not missing evidence.
WITH observations AS (
    SELECT e.payload->>'status' AS status,
           e.payload->>'reason_code' AS reason_code,
           1::bigint AS source_count,
           'strategy_observed' AS storage
    FROM paper_execution_event e
    JOIN paper_execution_cell c ON c.id = e.cell_id
    WHERE c.run_id = :'run_id' AND e.event_type = 'strategy_observed'
    UNION ALL
    SELECT triplet.value->>0 AS status,
           triplet.value->>1 AS reason_code,
           (triplet.value->>2)::bigint AS source_count,
           'replay_batch' AS storage
    FROM paper_execution_event e
    JOIN paper_execution_cell c ON c.id = e.cell_id
    CROSS JOIN LATERAL jsonb_array_elements(e.payload->'observations') AS triplet(value)
    WHERE c.run_id = :'run_id' AND e.event_type = 'replay_batch'
)
SELECT storage, status, reason_code, sum(source_count) AS source_events
FROM observations
GROUP BY storage, status, reason_code
ORDER BY source_events DESC, storage, status, reason_code;

-- Cross-check folded event counts against the completed checkpoint.
SELECT sum((e.payload->>'event_count')::bigint) AS folded_source_events,
       count(*) AS replay_batch_rows
FROM paper_execution_event e
JOIN paper_execution_cell c ON c.id = e.cell_id
WHERE c.run_id = :'run_id' AND e.event_type = 'replay_batch';

-- Downstream durable artifacts. An empty certified export alone does not establish these.
SELECT 'order_intent' AS stage, count(*) AS rows
FROM order_intent t JOIN paper_execution_cell c ON c.id = t.paper_execution_cell_id
WHERE c.run_id = :'run_id'
UNION ALL
SELECT 'trade_lineage', count(*)
FROM trade_lineage t JOIN paper_execution_cell c ON c.id = t.paper_execution_cell_id
WHERE c.run_id = :'run_id'
UNION ALL
SELECT 'trade_lifecycle_event', count(*)
FROM trade_lifecycle_event t JOIN paper_execution_cell c ON c.id = t.paper_execution_cell_id
WHERE c.run_id = :'run_id'
UNION ALL
SELECT 'fill_cost_ledger', count(*)
FROM fill_cost_ledger t JOIN paper_execution_cell c ON c.id = t.paper_execution_cell_id
WHERE c.run_id = :'run_id'
UNION ALL
SELECT 'trade_zone_events', count(*)
FROM trade_zone_events t JOIN paper_execution_cell c ON c.id = t.paper_execution_cell_id
WHERE c.run_id = :'run_id'
UNION ALL
SELECT 'position_trade_analysis_v2', count(*)
FROM position_trade_analysis_v2 t
WHERE t.run_id = :'run_id';

COMMIT;
