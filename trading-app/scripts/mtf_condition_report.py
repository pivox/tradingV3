#!/usr/bin/env python3
"""
Analyse les logs MTF pour produire un résumé des règles qui
bloquaient les symboles et des symboles qui passaient le plus
sur une fenêtre temporelle donnée.

Usage :
  ./mtf_condition_report.py --log var/log/mtf-2025-12-09.log --since "2025-12-09 18:35" --csv-prefix /tmp/mtf-summary
"""

from __future__ import annotations

import argparse
import csv
import re
from collections import Counter
from datetime import datetime
from pathlib import Path
from typing import Iterable, Tuple, Optional

LOG_TS_RE = re.compile(r'^\[(?P<ts>\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3})\]')
RULE_RE = re.compile(r'rule=([^ ]+)')
RESULT_RE = re.compile(r'result=(PASS|FAIL)')
SYMBOL_RE = re.compile(r'meta\.symbol=([^ ]+)')
CONTEXT_RE = re.compile(
    r'\[MTF Runner\]|\[MTF\] Context|Context timeframe invalid|context invalid'
)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Synthèse des règles MTF")
    parser.add_argument(
        "--log",
        type=Path,
        required=True,
        help="Chemin vers le fichier de log MTF à analyser",
    )
    parser.add_argument(
        "--since",
        type=str,
        required=True,
        help="Date/heure de début (format YYYY-MM-DD HH:MM)",
    )
    parser.add_argument(
        "--run-id",
        type=str,
        default=None,
        help="Filtrer uniquement les lignes associées à un run_id particulier",
    )
    parser.add_argument(
        "--csv-prefix",
        type=Path,
        default=None,
        help="Préfixe des fichiers CSV à générer (ajoute _fails.csv et _passes.csv)",
    )
    return parser.parse_args()


def iter_log_lines(path: Path, since: datetime, run_id: Optional[str]) -> Iterable[Tuple[int, str, datetime]]:
    with path.open(encoding="utf-8", errors="ignore") as fh:
        for idx, raw in enumerate(fh, start=1):
            raw = raw.rstrip("\n")
            match = LOG_TS_RE.match(raw)
            if not match:
                continue
            ts = datetime.strptime(match.group("ts"), "%Y-%m-%d %H:%M:%S.%f")
            if ts < since:
                continue
            if run_id and f"run_id={run_id}" not in raw:
                continue
            yield idx, raw, ts


def summarize(lines: Iterable[Tuple[int, str, datetime]]) -> Tuple[Counter, Counter, Counter, list]:
    fail_rules = Counter()
    pass_symbols = Counter()
    fail_symbols = Counter()
    context_invalid = []
    for idx, line, ts in lines:
        if "MTF_RULE_DEBUG" in line:
            rule_match = RULE_RE.search(line)
            result_match = RESULT_RE.search(line)
            sym_match = SYMBOL_RE.search(line)
            if rule_match and result_match and sym_match:
                rule = rule_match.group(1)
                result = result_match.group(1)
                symbol = sym_match.group(1)
                if result == "FAIL":
                    fail_rules[rule] += 1
                    fail_symbols[symbol] += 1
                else:
                    pass_symbols[symbol] += 1
        if "Context timeframe invalid" in line:
            ctx = re.search(r'symbol=([^ ]+)', line)
            tf = re.search(r'tf=([^ ]+)', line)
            reason = re.search(r'invalid_reason=([^ ]+)', line)
            context_invalid.append(
                (
                    idx,
                    ts,
                    ctx.group(1) if ctx else None,
                    tf.group(1) if tf else None,
                    reason.group(1) if reason else None,
                )
            )
    return fail_rules, pass_symbols, fail_symbols, context_invalid


def export_csv(prefix: Path, data: Iterable[Tuple[str, int]], headers: Tuple[str, str]) -> None:
    path = prefix.with_suffix("")  # avoid double suffix
    out_path = path.parent / f"{path.name}_{headers[0].lower()}.csv"
    with out_path.open("w", newline="", encoding="utf-8") as csvfile:
        writer = csv.writer(csvfile)
        writer.writerow(headers)
        for label, count in data:
            writer.writerow([label, count])


def main() -> None:
    args = parse_args()
    since = datetime.strptime(args.since, "%Y-%m-%d %H:%M")
    fail_rules, pass_symbols, fail_symbols, context_invalid = summarize(
        iter_log_lines(args.log, since, args.run_id)
    )

    print(f"Analyse depuis {since} dans {args.log}")
    print("\nTop 5 règles échouées:")
    for rule, count in fail_rules.most_common(5):
        print(f" - {rule}: {count}")
    print("\nTop 5 symboles bloqués:")
    for symbol, count in fail_symbols.most_common(5):
        print(f" - {symbol}: {count}")
    print("\nTop 5 symboles passés:")
    for symbol, count in pass_symbols.most_common(5):
        print(f" - {symbol}: {count}")
    if context_invalid:
        print("\nContexte timeframe invalid détecté:")
        for idx, ts, symbol, tf, reason in context_invalid[:5]:
            print(f" - ligne {idx} ({ts}) symbol={symbol} tf={tf} invalid_reason={reason}")

    if args.csv_prefix:
        export_csv(args.csv_prefix, fail_rules.most_common(), ("FailRule", "Count"))
        export_csv(args.csv_prefix, fail_symbols.most_common(), ("FailSymbol", "Count"))
        export_csv(args.csv_prefix, pass_symbols.most_common(), ("PassSymbol", "Count"))
        print(f"\nCSV écrits: {args.csv_prefix}_failrule.csv, {args.csv_prefix}_failsymbol.csv, {args.csv_prefix}_passsymbol.csv")


if __name__ == "__main__":
    main()
