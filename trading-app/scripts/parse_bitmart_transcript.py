#!/usr/bin/env python3
import argparse
import re
import sys
from collections import defaultdict, Counter
from datetime import datetime


def parse_decimal_fr(s: str) -> float:
    s = s.strip().replace('\xa0', ' ').replace(' ', '')
    s = s.replace(',', '.')
    # Remove trailing non-numeric like 'USDT' or token symbols
    s = re.sub(r'[^0-9.+-]+$', '', s)
    return float(s)


def parse_transcript(lines):
    trades = []
    open_positions = []

    # Patterns
    p_symbol = re.compile(r"^([A-Z0-9]+USDT)\s+–\s+(Longue|Courte)(?:\s+(\d+)X)?\s*$")
    p_entry_exit = re.compile(r"^Entrée\s*:\s*([0-9,\. ]+)\s*USDT\s*–\s*Sortie\s*:\s*([0-9,\. ]+)\s*USDT\s*$")
    p_amount_value = re.compile(r"^Montant\s+clôturé\s*:\s*([0-9,\. ]+)\s+[A-Z0-9]+\s*–\s*Valeur\s*:\s*([0-9,\. ]+)\s*USDT\s*$")
    p_pnl = re.compile(r"^(PnL|PnL\s+r\éalisé)\s*:\s*([+\-]?[0-9,\. ]+)\s*USDT\s*\(([+\-]?[0-9,\. ]+)\s*%\)\s*$", re.IGNORECASE)
    p_dt = re.compile(r"^Date/heure\s*:\s*([0-9\-: ]+)\s*$")

    # Open position patterns (Position actuelle)
    p_open_symbol = re.compile(r"^([A-Z0-9]+USDT)\s+–\s+(Longue|Courte)\s*$")
    p_open_entry = re.compile(r"^Ouverture\s*:\s*([0-9,\. ]+)\s*[A-Z0-9]+\s*à\s*([0-9,\. ]+)\s*USDT\s*$")
    p_open_fees = re.compile(r"^Frais\s*:\s*([+\-]?[0-9,\. ]+)\s*USDT\s*$")
    p_open_real = re.compile(r"^Profit/perte\s+r\éalisé\s*:\s*([+\-]?[0-9,\. ]+)\s*USDT.*$")

    i = 0
    n = len(lines)
    in_closed = False
    in_open = False

    # Detect sections loosely
    for idx, raw in enumerate(lines):
        txt = raw.strip()
        if txt.lower().startswith('position actuelle'):
            in_open = True
        if txt.lower().startswith('historique des positions'):
            in_closed = True

    i = 0
    current = {}
    while i < n:
        line = lines[i].strip()

        # Try open position block first
        m = p_open_symbol.match(line)
        if m:
            op = {
                'symbol': m.group(1),
                'side': 'long' if m.group(2).lower().startswith('long') else 'short',
                'qty': None,
                'entry': None,
                'fees': 0.0,
                'realized_pnl': 0.0,
            }
            # Expect next ~3 lines for details
            j = i + 1
            while j < min(i + 8, n):
                t = lines[j].strip()
                m1 = p_open_entry.match(t)
                if m1:
                    try:
                        op['qty'] = parse_decimal_fr(m1.group(1))
                        op['entry'] = parse_decimal_fr(m1.group(2))
                    except Exception:
                        pass
                m2 = p_open_fees.match(t)
                if m2:
                    op['fees'] = parse_decimal_fr(m2.group(1))
                m3 = p_open_real.match(t)
                if m3:
                    op['realized_pnl'] = parse_decimal_fr(m3.group(1))
                if t == '' or t.lower().startswith('historique des positions'):
                    break
                j += 1
            open_positions.append(op)
            i = j
            continue

        # Closed trade block starts with symbol – Longue/Courte [leverage]
        m = p_symbol.match(line)
        if m:
            current = {
                'symbol': m.group(1),
                'side': 'long' if m.group(2).lower().startswith('long') else 'short',
                'leverage': int(m.group(3)) if m.group(3) else None,
                'entry_price': None,
                'exit_price': None,
                'pnl_usdt': None,
                'pnl_pct': None,
                'dt': None,
            }
            i += 1
            # Parse subsequent lines until blank or next symbol
            while i < n:
                L = lines[i].strip()
                if not L:
                    i += 1
                    continue
                if p_symbol.match(L) or p_open_symbol.match(L):
                    # next block detected, step back to reprocess
                    break
                m1 = p_entry_exit.match(L)
                if m1:
                    try:
                        current['entry_price'] = parse_decimal_fr(m1.group(1))
                        current['exit_price'] = parse_decimal_fr(m1.group(2))
                    except Exception:
                        pass
                m2 = p_pnl.match(L)
                if m2:
                    try:
                        current['pnl_usdt'] = parse_decimal_fr(m2.group(2))
                        current['pnl_pct'] = parse_decimal_fr(m2.group(3))
                    except Exception:
                        pass
                m3 = p_dt.match(L)
                if m3:
                    try:
                        current['dt'] = datetime.strptime(m3.group(1), '%Y-%m-%d %H:%M:%S')
                    except Exception:
                        current['dt'] = m3.group(1)
                i += 1
            trades.append(current)
            continue

        i += 1

    return trades, open_positions


def summarize(trades):
    out = []
    n = len(trades)
    pnl_total = 0.0
    winners = []
    losers = []
    per_symbol = defaultdict(lambda: {'pnl': 0.0, 'wins': 0, 'losses': 0, 'count': 0})

    for t in trades:
        pnl = float(t['pnl_usdt'] or 0.0)
        pnl_total += pnl
        sym = t['symbol']
        per_symbol[sym]['pnl'] += pnl
        per_symbol[sym]['count'] += 1
        if pnl > 0:
            winners.append(pnl)
            per_symbol[sym]['wins'] += 1
        elif pnl < 0:
            losers.append(pnl)
            per_symbol[sym]['losses'] += 1

    win_rate = (len(winners) / n * 100.0) if n else 0.0
    avg_win = sum(winners) / len(winners) if winners else 0.0
    avg_loss = sum(losers) / len(losers) if losers else 0.0
    payoff = (avg_win / abs(avg_loss)) if (avg_win and avg_loss) else 0.0
    expectancy = pnl_total / n if n else 0.0

    return {
        'count': n,
        'pnl_total': round(pnl_total, 2),
        'win_rate': round(win_rate, 2),
        'avg_win': round(avg_win, 2),
        'avg_loss': round(avg_loss, 2),
        'payoff': round(payoff, 2),
        'expectancy': round(expectancy, 2),
        'per_symbol': per_symbol,
    }


def main():
    ap = argparse.ArgumentParser(description='Parse BitMart transcript (FR) and compute metrics.')
    ap.add_argument('file', help='Path to transcript text file')
    args = ap.parse_args()

    try:
        with open(args.file, 'r', encoding='utf-8') as f:
            lines = f.readlines()
    except Exception as e:
        print(f"Erreur lecture fichier: {e}", file=sys.stderr)
        sys.exit(1)

    trades, open_positions = parse_transcript(lines)
    summary = summarize(trades)

    print('=== Résumé Global ===')
    print(f"Trades clôturés: {summary['count']}")
    print(f"PnL total réalisé: {summary['pnl_total']} USDT")
    print(f"Taux de réussite: {summary['win_rate']} %")
    print(f"Gain moyen: {summary['avg_win']} USDT | Perte moyenne: {summary['avg_loss']} USDT")
    print(f"Payoff moyen (win/lose): {summary['payoff']}")
    print(f"Expectancy / trade: {summary['expectancy']} USDT\n")

    print('=== Par Symbole (PnL total | wins/losses/total) ===')
    for sym, s in sorted(summary['per_symbol'].items(), key=lambda kv: kv[1]['pnl']):
        print(f"- {sym}: {round(s['pnl'], 2)} USDT ({s['wins']}/{s['losses']}/{s['count']})")

    if open_positions:
        print('\n=== Positions Ouvertes ===')
        for op in open_positions:
            print(f"- {op['symbol']} {op['side']} qty={op['qty']} entry={op['entry']} fees={op['fees']} realized={op['realized_pnl']}")

    # Optional: flag concentration risks
    sym_counts = Counter([t['symbol'] for t in trades])
    top_sym, top_count = (None, 0)
    if sym_counts:
        top_sym, top_count = sym_counts.most_common(1)[0]
    if top_sym:
        print(f"\nAlerte: concentration de trades sur {top_sym} ({top_count} trades)")


if __name__ == '__main__':
    main()

