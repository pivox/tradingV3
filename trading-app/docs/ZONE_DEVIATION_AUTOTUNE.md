# Zone Deviation Auto-Tuning

The skipped-out-of-zone persistence makes it possible to adapt `zone_max_deviation_pct` dynamically.  
The new flow stores per-mode overrides inside `var/config/zone_deviation_overrides.json` and feeds them straight into the `TradeEntryRequestBuilder`.

## CLI Command

Run the cron-friendly command to compute the P80 deviation and update overrides:

```bash
docker-compose exec trading-app-php php bin/console trade-entry:zone:auto-adjust \
    --mode=regular --mode=scalper \
    --hours=24 \
    --threshold=0.05 \
    --min-events=3
```

Options:

| Option | Default | Description |
| --- | --- | --- |
| `--mode=` | `regular, scalper` | Which TradeEntry config modes to update (`default` targets `config/app/trade_entry.yaml`). |
| `--hours=` | `24` | Lookback window for analyzer statistics. |
| `--threshold=` | `0.05` | Relative delta required before applying an override (5% = ±0.05). |
| `--min-events=` | `3` | Minimum `trade_zone_events` rows per symbol. |
| `--dry-run` | _false_ | Only print the adjustments without persisting the JSON file. |

The command produces a table summarising each action (`set`, `update`, or `remove`).  
When `--dry-run` is omitted, overrides are written and flushed once at the end.

## Runtime Usage

`TradeEntryRequestBuilder` now reads the override store on every build:

1. Load the defaults from the mode-specific TradeEntry config.
2. If an override exists for `(mode, symbol)`, replace `zone_max_deviation_pct`.
3. The rest of the pipeline (EntryZone builder, guards, logging) automatically uses the tuned value.

This makes it safe to schedule the command every few hours so the analyzer keeps `zone_max_deviation_pct` aligned with the actual deviations observed in production.
