"""H1 X-Perp listing inventory (metadata only: no price, no return, no PnL)."""
import json, re, sys
from collections import Counter, defaultdict
from datetime import datetime, timezone
from pathlib import Path

D = Path(sys.argv[1]); H1 = Path(sys.argv[2])
DAY = 86_400_000; HOUR = 3_600_000
def iso(v): return None if v is None else datetime.fromtimestamp(v / 1000, tz=timezone.utc).strftime("%Y-%m-%dT%H:%MZ")
def norm(b): return re.sub(r"^(1000000|1000|1M)(?=[A-Z])", "", b.upper())
def ceil_h(t): return -(-t // HOUR) * HOUR
PROGRAMME = {"BTC","ETH","BNB","XRP","ADA","DOGE","SOL","LTC","LINK","AVAX","DOT","TRX","BCH","TON","SHIB","NEAR","UNI","APT","ATOM","ETC"}

xp = [i for i in json.load(open(D / "raw/eea-futures.json"))["data"] if "XPERP" in i["instId"]]
okx_now = {i["instId"]: i for i in json.load(open(D / "raw/okx-global-swap.json"))["data"]}
bin_now = {s["symbol"]: s for s in json.load(open(D / "raw/binance-fapi.json"))["symbols"]}
okx_ev = json.load(open(H1 / "audit/okx-events.json")); bin_ev = json.load(open(H1 / "audit/binance-events.json"))
classified = {(r["venue"], r["base"]): r for r in json.load(open(H1 / "audit/events-classified.json"))}

first_okx, first_bin = {}, {}
for b, e in okx_ev.items(): first_okx[b] = (e["t0_ms"], e["instId"], e["date_source"], e.get("premarket"))
for k, i in okx_now.items():
    if i["settleCcy"] == "USDT" and k.endswith("-USDT-SWAP") and i.get("instCategory") == "1":
        b = k.split("-")[0]; t = int(i["listTime"])
        if b not in first_okx or t < first_okx[b][0]:
            first_okx[b] = (t, k, "okx_instruments_listTime_2026-10-15", i.get("ruleType") == "pre_market" or bool(i.get("preMktSwTime")))
for b, e in bin_ev.items(): first_bin[b] = (e["t0_ms"], e["symbol"], e["date_source"])
for s, i in bin_now.items():
    if s.endswith("USDT") and i["contractType"] == "PERPETUAL" and i.get("underlyingType") == "COIN":
        b = norm(s[:-4]); t = int(i["onboardDate"])
        if b not in first_bin or t < first_bin[b][0]:
            first_bin[b] = (t, s, "binance_exchangeinfo_onboardDate_2026-10-15")

rows = []
for i in sorted(xp, key=lambda i: int(i["listTime"])):
    fam = i["instFamily"]; base = fam.split("-")[0]; nb = norm(base)
    lt = int(i["listTime"]); cat = i["instCategory"]
    o = first_okx.get(nb) or first_okx.get(base); bn = first_bin.get(nb)
    cands = [(x[0], "okx") for x in [o] if x] + [(x[0], "binance") for x in [bn] if x]
    t0, venue = min(cands) if cands else (None, None)
    r = {"instId": i["instId"], "base": base, "category": {"1": "crypto", "3": "equity/etf", "4": "commodity"}.get(cat, cat),
         "ruleType": i["ruleType"], "xperp_listTime": iso(lt), "state": i["state"], "ctVal": i["ctVal"], "ctValCcy": i["ctValCcy"],
         "minSz": i["minSz"], "lotSz": i["lotSz"], "tickSz": i["tickSz"], "lever_max": i["lever"], "expTime": iso(int(i["expTime"])),
         "initPxLmtPct": i.get("initPxLmtPct"), "maxPxLmtPct": i.get("maxPxLmtPct"), "posLmtAmt": i.get("posLmtAmt"),
         "first_okx_perp": iso(o[0]) if o else None, "okx_premarket": o[3] if o else None,
         "first_binance_perp": iso(bn[0]) if bn else None, "first_perp_t0": iso(t0), "first_perp_venue": venue}
    if cat != "1":
        r["class"] = "NON_CRYPTO"
    elif t0 is None:
        r["class"] = "NO_OKX_BINANCE_PERP (X-Perp is the first observed perpetual)"
    else:
        lag_h = (lt - t0) / HOUR; r["lag_hours"] = round(lag_h, 1)
        T = ceil_h(t0 + DAY)
        r["available_at_T24"] = lt <= T; r["available_at_T48"] = lt <= ceil_h(t0 + 2 * DAY)
        age_days = (lt - t0) / DAY
        if nb in PROGRAMME or base in PROGRAMME: r["class"] = "ESTABLISHED (programme major)"
        elif age_days > 180: r["class"] = "ESTABLISHED (first perp > 180 d before the X-Perp)"
        elif lag_h <= 48: r["class"] = "NEW_TOKEN, X-Perp within 48 h of the first perp"
        else: r["class"] = "NEW_TOKEN, secondary X-Perp listing (> 48 h, <= 180 d)"
        h1 = classified.get((venue, nb))
        r["h1_audit_row"] = None if h1 is None else {k: h1[k] for k in ("order", "date_verified", "relisting", "delisted")}
        r["premarket_flag"] = bool(o and o[3] and venue == "okx")
    rows.append(r)
json.dump(rows, open(D / "xperp-inventory.json", "w"), indent=1)
c = Counter(r["class"] for r in rows); print(json.dumps(c, indent=1))
cr = [r for r in rows if r["category"] == "crypto"]
by_m = Counter(r["xperp_listTime"][:7] for r in cr); print("crypto X-Perps by month", sorted(by_m.items()))
new = [r for r in cr if r["class"].startswith("NEW_TOKEN") or r["class"].startswith("NO_OKX")]
for r in sorted(new, key=lambda r: r["xperp_listTime"]):
    print(r["instId"], r["xperp_listTime"], "first", r["first_perp_t0"], r["first_perp_venue"], "lag_h", r.get("lag_hours"), "T24", r.get("available_at_T24"), "T48", r.get("available_at_T48"), "pm", r.get("premarket_flag"))
