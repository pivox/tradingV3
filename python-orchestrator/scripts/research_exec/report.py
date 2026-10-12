"""Markdown rendering of the execution study summaries."""

from __future__ import annotations

from .simulate import HORIZONS_S


def _fmt(value, digits: int = 2) -> str:
    if value is None:
        return "—"
    return f"{value:+.{digits}f}" if isinstance(value, float) else str(value)


def _pct(value) -> str:
    return "—" if value is None else f"{100 * value:.0f} %"


def render(summaries: list[dict], receipts: list[dict]) -> str:
    lines: list[str] = []
    for summary in summaries:
        params = summary["parameters"]
        lines.append(f"## {summary['venue']} — rule `{params['rule']}`, latency {params['latency_ms']:.0f} ms, TTL {params['ttl_s']:.0f} s")
        lines.append("")
        lines.append("| Symbol | Placement | Eligible | Fill rate | Delay p50 | Half-spread | m_0 | " + " | ".join(f"net m_{h}s (LB90)" for h in HORIZONS_S) + " | " + " | ".join(f"rt_{h}s" for h in HORIZONS_S) + " | d_60s |")
        lines.append("|---|---|---:|---:|---:|---:|---:|" + "---:|" * (2 * len(HORIZONS_S)) + "---:|")
        for symbol, item in sorted(summary["symbols"].items()):
            for placement in ("touch", "inside"):
                pooled = item[placement]["pooled"]
                cells = [symbol, placement, str(pooled["eligible"]), _pct(pooled["fill_rate"]), "—" if pooled["fill_delay_s_p50"] is None else f"{pooled['fill_delay_s_p50']:.1f} s", _fmt(pooled["half_spread_bp_mean"]), _fmt(pooled["m_0_mean"])]
                for h in HORIZONS_S:
                    hz = pooled["horizons"][str(h)]
                    cells.append(f"{_fmt(hz['net_mean_bp'])} ({_fmt(hz['net_lb90_bp'])})")
                for h in HORIZONS_S:
                    cells.append(_fmt(pooled["horizons"][str(h)]["round_trip_mean_bp"]))
                cells.append(_fmt(pooled["horizons"]["60"]["drift_mean_bp"]))
                lines.append("| " + " | ".join(cells) + " |")
        lines.append("")
        lines.append("Sides and sessions at the touch, net mark-out at 60 s (bp):")
        lines.append("")
        lines.append("| Symbol | Buy | Sell | Asia | Europe | US | Late |")
        lines.append("|---|---:|---:|---:|---:|---:|---:|")
        for symbol, item in sorted(summary["symbols"].items()):
            touch = item["touch"]
            cells = [symbol]
            for side in ("buy", "sell"):
                cells.append(_fmt(touch[side]["horizons"]["60"]["net_mean_bp"]))
            for session in ("asia", "europe", "us", "late"):
                entry = touch["sessions"].get(session)
                cells.append("—" if entry is None else f"{_fmt(entry['horizons']['60']['net_mean_bp'])} (n={entry['filled']})")
            lines.append("| " + " | ".join(cells) + " |")
        lines.append("")
        verdict = summary["verdict"]
        lines.append(f"Verdict ({verdict['rule']}, {verdict['placement']}): maker edge = **{verdict['maker_edge']}**; " + "; ".join(f"{symbol}: fill {'ok' if c['fill_rate_ok'] else 'low'}, primary {_fmt(c['primary_net_mean_bp'])} (LB {_fmt(c['primary_net_lb90_bp'])}), secondary {'ok' if c['secondary_ok'] else 'no'}" for symbol, c in sorted(verdict["symbols"].items())))
        lines.append("")
    for receipt in receipts:
        lines.append(f"### Capture receipt — {receipt['venue']} `{receipt['dataset_id']}`")
        lines.append("")
        lines.append(f"- events {receipt['event_count']:,}, sha256 `{receipt['events_file_sha256'][:16]}…` verified; counts {receipt['counts']}; synthetic quotes skipped {receipt['synthetic_quotes_skipped']}; crossed quotes skipped {receipt['crossed_quotes_skipped']}.")
        lines.append(f"- latency ms (received − exchange), quotes p50/p90/p99 {receipt['latency_ms']['quotes'].get('p50')}/{receipt['latency_ms']['quotes'].get('p90')}/{receipt['latency_ms']['quotes'].get('p99')}; trades {receipt['latency_ms']['trades'].get('p50')}/{receipt['latency_ms']['trades'].get('p90')}/{receipt['latency_ms']['trades'].get('p99')}.")
        for symbol, item in sorted(receipt["per_symbol"].items()):
            lines.append(f"- {symbol}: quotes {item['quotes']:,}, trades {item['trades']:,}, spread p50 {item.get('spread_ticks_p50')} ticks ({item.get('spread_bp_mean', 0):.3f} bp mean), tick {item.get('tick_bp_at_median_price', 0):.4f} bp, quote gap p99 {item.get('quote_gap_s_p99')} s, max {item.get('quote_gap_s_max')} s, live trade share {item.get('live_share')}.")
        lines.append("")
    return "\n".join(lines) + "\n"
