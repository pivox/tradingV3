"""scalp-q3-r2: markdown tables of `results.json` for the reports (numbers are copied, never retyped).

    python -m scripts.research_exec.q3r2_report --results DIR/results.json > DIR/tables.md
"""

from __future__ import annotations

import argparse
import json
from pathlib import Path

ORDER = ("C1-Z3", "C1-SL", "C2-Z3", "C2-SL", "C3-Z3", "C3-SL", "C4-Z3", "C4-SL")
LABEL = {"C1": "C1 S7 calendar", "C2": "C2 Q3 calendar", "C3": "C3 S7 active", "C4": "C4 Q3 active"}


def f(x, digits=1, sign=True):
    if x is None:
        return "—"
    if isinstance(x, bool):
        return "oui" if x else "non"
    if isinstance(x, int) and not isinstance(x, bool):
        return f"{x:,}".replace(",", " ")
    s = f"{x:+.{digits}f}" if sign else f"{x:.{digits}f}"
    return s.replace(".", ",")


def ci(item):
    lo, hi = item.get("ci90", [None, None])
    return f"[{f(lo)} ; {f(hi)}]"


def p(x):
    return "—" if x is None else f"{x:.3f}".replace(".", ",")


def name(key):
    c, v = key.split("-")
    return f"{LABEL[c]} · {'Z3 (réf.)' if v == 'Z3' else 'Z3-SL200'}"


def table(header, rows):
    out = ["| " + " | ".join(header) + " |", "|" + "|".join("---" for _ in header) + "|"]
    out += ["| " + " | ".join(str(c) for c in row) + " |" for row in rows]
    return "\n".join(out)


def render(r: dict) -> str:
    rows = r["rows"]
    parts = []
    par = r["parity"]
    parts.append("### Parité\n\n" + table(
        ["Config.", "Trades (run_policy)", "Trades q3x", "Identique à q3x", "Net moyen", "Table = run_policy", "Contrôle hist. médian", "q3x médian", "Contrôle identique"],
        [[k, f(v["trades"]), f(v.get("q3x_trades")), f(v.get("identical_to_q3x")), f(v["mean_net_bp"], 2), f(v["table_equals_run_policy"]), f(v["historical_control_median_bp"], 3),
          f(v.get("q3x_control_median_bp"), 3), f(v.get("control_identical_to_q3x"))] for k, v in par["configs"].items()])
        + f"\n\nSéries en écart table/run_policy : {len(par['rp_vs_table_mismatch_series'])} ; compteurs en écart : {len(par['rp_counters_mismatch'])} ; parité globale : {f(par['ok'])}.")
    parts.append("### Niveau signal (une position par paire, coûts de base)\n\n" + table(
        ["Configuration", "Décisions", "Ordres", "Rempl.", "Trades", "Net moyen", "Médiane", "Brut", "PF", "Gain %", "Contrôle épisodes", "Surplus [IC 90 % jours]", "p", "Contrôle bande sans flux", "Surplus flux", "p flux"],
        [[name(k), f(x["counters"]["decisions"]), f(x["counters"]["orders"]), f(100 * (x["fill_rate"] or 0), 0, False) + " %", f(x["signal"]["trades"]),
          f(x["signal"].get("mean_net_bp")), f(x["signal"].get("median_net_bp")), f(x["signal"].get("mean_gross_bp")), f(x["signal"].get("profit_factor"), 2, False),
          f(100 * (x["signal"].get("win_rate") or 0), 0, False), f(x["control_shift"]["mean_bp"]), f(x["control_shift"]["surplus_day_blocks"]["point"]) + " " + ci(x["control_shift"]["surplus_day_blocks"]),
          p(x["control_shift"]["p_value"]), f(x["control_band_state"]["mean_bp"]), f(x["control_band_state"]["surplus_day_blocks"]["point"]), p(x["control_band_state"]["p_value"])]
         for k, x in ((k, rows[k]) for k in ORDER)]))
    parts.append("### Robustesse (bp par trade)\n\n" + table(
        ["Configuration", "Pessimiste", "Stress latence 1 s", "Avec funding", "Funding moyen", "Tronquée 5/5", "Sans 5 % meilleurs", "Sans 5 % pires", "Sans meilleur jour (somme)", "Majors", "Mid-caps", "Fév.–mai", "Juin–oct.", "Surplus IC fenêtres"],
        [[name(k), f(x["signal_pessimistic"].get("mean_net_bp")), f(x["signal_stress"].get("mean_net_bp")) + f" ({f(x['signal_stress'].get('trades'))})", f(x["signal_with_funding"].get("mean_net_bp")),
          f(x["mean_funding_bp"], 2), f(x["tails"].get("trimmed_5_5_mean_bp")), f(x["tails"].get("without_top5_mean_bp")), f(x["tails"].get("without_bottom5_mean_bp")),
          f(x["without_best_day_sum_bp"], 0), f(x["majors_mean_bp"]), f(x["midcaps_mean_bp"]), f(x["first_half_mean_bp"]), f(x["second_half_mean_bp"]), ci(x["control_shift"]["surplus_window_blocks"])]
         for k, x in ((k, rows[k]) for k in ORDER)]))
    parts.append("### Queues et excursions (bp)\n\n" + table(
        ["Configuration", "Q1 %", "Q5 %", "Q50 %", "Q95 %", "Q99 %", "Pire", "Pertes P95", "Pertes P99", "MAE méd.", "MAE 5 %", "MAE < −100", "MAE < −200", "MFE méd.", "Série de pertes"],
        [[name(k), *(f(x["tails"]["quantiles_bp"][q], 0) for q in ("1", "5", "50", "95", "99")), f(x["tails"]["worst_bp"], 0), f(x["tails"]["loss_p95_bp"], 0, False), f(x["tails"]["loss_p99_bp"], 0, False),
          f(x["mae_quantiles_bp"].get("50"), 0), f(x["mae_quantiles_bp"].get("5"), 0), f(100 * x["share_mae_below"]["100"], 0, False) + " %", f(100 * x["share_mae_below"]["200"], 0, False) + " %",
          f(x["mfe_quantiles_bp"].get("50"), 0), f(x["tails"]["longest_loss_streak"])] for k, x in ((k, rows[k]) for k in ORDER)]))
    stop_rows = []
    for c in ("C1", "C2", "C3", "C4"):
        x = rows[f"{c}-SL"]["stop"]
        cost = r["comparisons"]["stop_cost"][c]
        stop_rows.append([LABEL[c], f(x["stops"]), f(100 * (x["share"] or 0), 1, False) + " %", f(x["stopped_mean_bp"], 0), f(x["same_orders_z3_mean_bp"], 0), f(x["losses_avoided_bp"], 0, False),
                          f(x["gains_forgone_bp"], 0, False), f(x["winners_interrupted"]), f(x["slippage_bp"].get("50"), 1, False), f(x["slippage_bp"].get("90"), 1, False), f(x["max_slippage_bp"], 0, False),
                          f(x["worst_stopped_bp"], 0), f(cost["mean_diff_bp"]["point"]) + " " + ci(cost["mean_diff_bp"])])
    parts.append("### Stop −200 bp (niveau signal)\n\n" + table(
        ["Signal", "Stops", "Part", "Net moyen stoppés", "Mêmes ordres sans stop", "Pertes évitées (bp)", "Gains abandonnés (bp)", "Gagnants interrompus", "Slippage méd.", "Slippage P90", "Slippage max", "Pire stoppé", "Coût du stop [IC 90 %]"], stop_rows))
    for scen, title in (("base", "coûts de base"), ("pessimistic", "scénario pessimiste"), ("stress", "stress d'exécution")):
        prow = []
        for k in ORDER:
            x = rows[k]["portfolio"][scen]
            ref = rows[k]["portfolio_control"] if scen == "base" else None
            prow.append([name(k), f(x["final_equity"], 2, False), f(x["pnl_usdt"], 2), f(x["trades"]), f(x["orders"]), " / ".join(f(x["refusals"].get(kk, 0)) for kk in ("slots", "min_notional", "exposure", "risk", "capital")),
                         f(x["partial_fills"]), f(x["fees_usdt"], 2, False), f(x["slippage_usdt"], 2, False), f(x["funding_usdt"], 2), f(x["max_dd_mtm_usdt"], 2, False), f(x["worst_trade_usdt"], 2),
                         f(x["worst_trade_risk_multiple"], 2, False), f(x["worst_day_usdt"], 2), f(x["longest_loss_streak"]), f(x["mean_open_positions"], 2, False), f(x["mean_notional_usdt"], 0, False),
                         (f(ref["mean_pnl_usdt"], 2) + " / p " + p(ref["p_value"]) + " / " + ci(ref["surplus_day_blocks"])) if ref else "—"])
        parts.append(f"### Portefeuille 500 USDT — {title}\n\n" + table(
            ["Configuration", "Equity finale", "PnL", "Trades", "Ordres", "Refus slots / min / expo / risque / capital", "Partiels", "Frais", "Slippage", "Funding", "DD max (MTM)", "Pire trade",
             "× risque nominal", "Pire jour", "Série pertes", "Positions moy.", "Notionnel moy.", "Contrôle : PnL moyen / p / IC surplus"], prow))
    crit = r["criteria"]
    names = list(next(iter(crit.values()))["criteria"].keys())
    parts.append("### Critères (configurations protégées)\n\n" + table(["Configuration", *names, "Passe"], [[k, *(("✔" if v["criteria"][n] else "✘") for n in names), f(v["passes"])] for k, v in crit.items()]))
    fnames = list(next(iter(crit.values()))["fatal"].keys())
    parts.append(table(["Configuration", *fnames, "Au moins un fatal"], [[k, *(("✘" if v["fatal"][n] else "·") for n in fnames), f(v["any_fatal"])] for k, v in crit.items()])
                 + f"\n\n**Verdict calculé : `{r['verdict']['verdict']}`** (passent : {', '.join(r['verdict']['passing']) or 'aucune'}).")
    comp = r["comparisons"]
    parts.append("### S7 contre Q3\n\n" + table(["Comparaison", "PnL portefeuille", "Différence quotidienne [IC 90 %]", "Net moyen", "Trades", "Différence de surplus [IC 90 %]", "Lecture"],
                                               [[k, " / ".join(f(x, 2) for x in v["portfolio_pnl_usdt"]), f(v["portfolio_daily_diff"]["point"], 2) + " " + ci(v["portfolio_daily_diff"]),
                                                 " / ".join(f(x) for x in v["mean_net_bp"]), " / ".join(f(x) for x in v["trades"]), f(v["signal_surplus_diff"]["point"]) + " " + ci(v["signal_surplus_diff"]), v["reading"]]
                                                for k, v in comp["s7_vs_q3"].items()]))
    parts.append("### Volatilité active contre calendaire\n\n" + table(["Comparaison", "Trades", "Variation", "Différence de surplus [IC 90 %]", "Paires + (part)", "Fenêtres +", "PnL portefeuille", "Lecture"],
                                                                       [[k, " / ".join(f(x) for x in v["trades"]), f(100 * (v["trade_change"] or 0), 0) + " %", f(v["surplus_diff"]["point"]) + " " + ci(v["surplus_diff"]),
                                                                         " / ".join(f(100 * x, 0, False) + " %" for x in v["pairs_positive_share"]), " / ".join(str(x) for x in v["windows_positive"]),
                                                                         " / ".join(f(x, 2) for x in v["portfolio_pnl_usdt"]), v["reading"]] for k, v in comp["active_vs_calendar"].items()]))
    pair_names = sorted({pp for k in ORDER for pp in rows[k]["by_pair"]})
    parts.append("### Par paire (net moyen bp, trades), configurations protégées\n\n" + table(["Paire", *(name(k) for k in ORDER if k.endswith("SL"))],
                                                                                           [[pp, *((f(rows[k]["by_pair"][pp]["mean_bp"]) + f" ({rows[k]['by_pair'][pp]['trades']})") if pp in rows[k]["by_pair"] else "—" for k in ORDER if k.endswith("SL"))] for pp in pair_names]))
    windows = ("february", "march", "april", "may", "june", "july", "august", "september", "october")
    parts.append("### Par fenêtre (net moyen bp, trades)\n\n" + table(["Fenêtre", *(name(k) for k in ORDER)],
                                                                      [[w, *((f(rows[k]["by_window"][w]["mean_bp"]) + f" ({rows[k]['by_window'][w]['trades']})") if w in rows[k]["by_window"] else "—" for k in ORDER)] for w in windows]))
    parts.append("### Diagnostics d'exécution\n\n" + table(["Configuration", "Âge cotation méd. / P90 / P99 (s)", "Délai de remplissage méd. / P90 (s)", "Bid au remplissage vs entrée P10 / méd. / P90 (bp)", "Sorties passives au volume insuffisant", "Raisons de sortie"],
                                                         [[name(k), " / ".join(f(rows[k]["quote_age_at_decision_s"].get(q), 2, False) for q in ("50", "90", "99")), " / ".join(f(rows[k]["fill_delay_s"].get(q), 1, False) for q in ("50", "90")),
                                                           " / ".join(f(rows[k]["bid_move_to_fill_bp"].get(q), 1) for q in ("10", "50", "90")),
                                                           f"{rows[k]['portfolio']['base']['passive_thr_short']} / {rows[k]['portfolio']['base']['passive_exits']}", str(rows[k]["exit_reasons"])] for k in ORDER]))
    return "\n\n".join(parts) + "\n"


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--results", type=Path, required=True)
    a = ap.parse_args()
    print(render(json.loads(a.results.read_text())))


if __name__ == "__main__":
    main()
