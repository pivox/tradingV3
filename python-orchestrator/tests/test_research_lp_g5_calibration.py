"""Calibration of the G5 decision rule under H0 and the B2 non-regression (LP-G5 §2.3 (B) xxii; quant review m7, B2), offline and
synthetic, through the evaluator's own instants, controls, measures, instant unit and day-block bootstrap.

The model, per instrument and per window (the price is only drawn where the rule reads it): an efficient log-price random walk
(σ 2 bp per minute) plus a **day shock** shared by every instrument and every window of a UTC day (a drift N(0, 0.2 bp) per
minute), a **burst at minute 0** of every hour (a jump N(0, σ_J) on the minute starting at hh:00, followed by a reversal of ρ × the
jump spread over the minutes starting at hh:06 … hh:15), and a **bid-ask bounce** on the last price (± a half-spread drawn at every
minute). Nothing distinguishes the event instants from the control instants under H0.

Declared choices: 1 000 data sets, 1 000 bootstrap resamples per data set (the frozen spec has 10 000; LP-G5 §12 m1), the spec's
seed for the bootstrap draws; three instruments (BTC, ETH, SOL listing times) on the real Gate 1 window; T01 with near-zero rates (no
funding difference) and P̂ classes drawn once (the classifier does not depend on the price). Criteria: the rate of {LB90(D) > 0} ≤
α + 3 Monte-Carlo standard errors per type, the uniformity of p not rejected by Kolmogorov–Smirnov at 1 %, the FWER of the local Holm
(K = 2, α 0.10) ≤ α + 3 standard errors; B2: with the burst planted, the events at minute 30 against controls at minute 30 give an
unbiased D (|mean| ≤ 3 standard errors) and against controls at minute 0 a detected bias; a planted +30 bp effect: power reported."""

from __future__ import annotations

import copy
import json
import math
import socket
import urllib.request
from pathlib import Path

import numpy as np
import pytest

from scripts.research_lp import g5_eval, g5_fetch, tape

US = tape.SECOND_US
MINUTE_US = 60 * US
HOUR_US = 60 * MINUTE_US
DAY_US = tape.DAY_S * US
REAL_SPEC = Path(__file__).resolve().parents[1] / "scripts" / "research_lp" / "specs" / "g5_gate1_spec.json"
BASE = json.loads(REAL_SPEC.read_text())
UNIVERSE = {"BTC-USD_UM_XPERP-310404": "2026-03-30T04:30:00Z", "ETH-USD_UM_XPERP-310404": "2026-04-01T04:00:00Z", "SOL-USD_UM_XPERP-310404": "2026-04-01T04:25:00Z"}
D_COV = tape.parse_iso_utc("2026-08-04T08:00:00Z")
SEEDS = 1000
RESAMPLES = 1000
ALPHA = 0.10
MC_SE = math.sqrt(ALPHA * (1 - ALPHA) / SEEDS)
KS_1PCT = 1.6276 / math.sqrt(SEEDS)  # asymptotic Kolmogorov critical value at 1 %
H0_MODEL = {"sigma": 2.0, "day_sigma": 0.2, "jump_sigma": 6.0, "reversal": 0.5, "half_spread": 1.5}
B2_MODEL = {"sigma": 2.0, "day_sigma": 0.2, "jump_sigma": 15.0, "reversal": 0.6, "half_spread": 1.5}
# Power under a noisier "design" model (LP-G5 §7.4 takes σ of the instant value at 30–60 bp for T01 and 60–120 bp for T05: a hypothesis).
POWER_MODEL = {"sigma": 8.0, "day_sigma": 0.5, "jump_sigma": 10.0, "reversal": 0.5, "half_spread": 3.0}


@pytest.fixture(autouse=True)
def _no_network(monkeypatch):
    def refuse(*args, **kwargs):  # pragma: no cover - the assertion is the point
        raise AssertionError("network access attempted")

    monkeypatch.setattr(socket.socket, "connect", refuse)
    monkeypatch.setattr(socket.socket, "connect_ex", refuse)
    monkeypatch.setattr(socket.socket, "sendto", refuse)
    monkeypatch.setattr(socket, "create_connection", refuse)
    monkeypatch.setattr(socket, "getaddrinfo", refuse)  # no name resolution either (CTO review-b m4)
    monkeypatch.setattr(urllib.request, "urlopen", refuse)
    monkeypatch.setattr(g5_fetch.UrllibClient, "get", refuse)


def iso(us: int) -> str:
    return tape.iso_utc(int(us))[:19] + "Z"


def make_spec(mutate=None) -> g5_eval.Spec:
    document = copy.deepcopy(BASE)
    document["universe"]["analysed"] = [{"inst_id": i, "index_id": i.split("_")[0], "list_time_utc": l, "eligible_from_utc": iso(tape.parse_iso_utc(l) + 30 * DAY_US),
                                         "ct_val": 0.0001, "tick_sz": 0.01} for i, l in UNIVERSE.items()]
    for item in document["events"]:
        item.pop("audit_expected", None)
    document["economic"]["audit_break_even_net_bp"] = {}
    rows = [{"instId": i, "uly": i.split("_")[0], "listTime": str(tape.parse_iso_utc(l) // 1000)} for i, l in UNIVERSE.items()]
    estimate = g5_fetch.spec_estimate(rows, 30, tape.day_of_date("2026-08-04") * DAY_US, {"candles_1m": 300, "mark_1m": 100, "index_1m": 100, "funding_hist": 400})
    document["fetch_plan"]["pre_freeze"]["requests_estimate"] = estimate["funding_hist"]
    for stream in ("candles_1m", "mark_1m", "index_1m"):
        document["fetch_plan"]["post_freeze"][stream]["requests_estimate"] = estimate[stream]
    document["fetch_plan"]["requests_total_estimate"] = sum(estimate.values())
    document["statistics"]["bootstrap"]["resamples"] = RESAMPLES
    if mutate is not None:
        mutate(document)
    return g5_eval.parse_spec(document)


class Bench:
    """The instants of one type on the three instruments (computed once by the evaluator's own generators and control rule) and
    sparse price arrays redrawn per data set at the windows the rule reads."""

    def __init__(self, spec: g5_eval.Spec, name: str, offsets: tuple[int, int], seed: int = 0) -> None:
        self.spec, self.tspec, self.lo, self.hi = spec, spec.type(name), offsets[0], offsets[1]
        grid = g5_eval.spec_grid(spec)
        first = (spec.pad_start_us // (8 * HOUR_US) + 1) * 8 * HOUR_US
        settlements = np.arange(first, g5_fetch.END_EXCLUSIVE_US, 8 * HOUR_US, dtype=np.int64)
        rng = np.random.default_rng(seed)
        common = g5_eval.common_instants(spec)
        self.series, self.events, self.controls = {}, {}, {}
        for inst in UNIVERSE:
            meta = spec.instrument(inst)
            classes = rng.choice(np.array([-1, 0, 1]), size=settlements.size, p=[0.4, 0.2, 0.4])
            premium = np.zeros(grid.size)
            k_s = (settlements - int(grid[0])) // MINUTE_US
            for k, c in zip(k_s.tolist(), classes.tolist()):
                if k - 5 >= 0:
                    premium[max(0, k - 484): min(k - 5, grid.size - 1) + 1] = c * 0.0012
            nan = np.full(grid.size, np.nan)
            rates = np.where(classes != 0, 1e-9 * classes, 0.0)  # near-zero funding: no difference between events and controls under H0
            series = g5_eval.make_series(meta, grid, nan.copy(), nan.copy(), nan.copy(), np.ones(grid.size), 100.0 * (1 + premium), np.full(grid.size, 100.0), settlements, rates, D_COV)
            instants, _, _ = g5_eval.generator_instants(series, self.tspec, spec, common)
            raw, _ = g5_eval.restrict(series, instants, self.tspec, spec)
            controls, _ = g5_eval.control_instants(series, self.tspec, spec, raw, g5_eval.exclusion_intervals(series, self.tspec, spec, common))
            self.series[inst], self.events[inst], self.controls[inst] = series, raw, controls
            if self.tspec.classifier is not None:
                series.p_hat(series.index_of(raw) + self.tspec.arm_min, self.tspec.classifier)  # cached once: the classifier does not depend on the price
                series.p_hat(series.index_of(controls) + self.tspec.arm_min, self.tspec.classifier)
        days = np.unique(np.concatenate([np.concatenate([self.events[i], self.controls[i]]) for i in UNIVERSE]) // DAY_US)
        self.days = days

    def draw(self, seed: int, model: dict, effect_bp: float = 0.0) -> None:
        rng = np.random.default_rng(seed)
        shock = dict(zip(self.days.tolist(), rng.normal(0.0, model["day_sigma"], self.days.size).tolist()))
        cols = np.arange(self.lo, self.hi + 1)
        for inst in UNIVERSE:
            s = self.series[inst]
            ev_set = set(self.events[inst].tolist())
            instants = np.unique(np.concatenate([self.events[inst], self.controls[inst]]))
            n, width = instants.size, cols.size
            minute = (instants // MINUTE_US)[:, None] + cols[None, :] - 1  # the minute ending at boundary j starts at j − 1
            moh = minute % 60
            hour = minute // 60
            rel = hour - hour[:, :1]
            jumps = rng.normal(0.0, model["jump_sigma"], (n, int(rel.max()) + 2))
            inc = rng.normal(0.0, model["sigma"], (n, width)) + np.array([shock[int(t // DAY_US)] for t in instants.tolist()])[:, None]
            take = np.take_along_axis(jumps, rel + 1, axis=1)
            inc += np.where(moh == 0, take, 0.0)
            inc -= np.where((moh >= 6) & (moh <= 15), model["reversal"] * take / 10.0, 0.0)
            inc[:, 0] = 0.0
            efficient = np.cumsum(inc, axis=1)
            k0 = s.index_of(instants)
            bounce = rng.choice(np.array([-1.0, 1.0]), size=(n, width)) * model["half_spread"]
            observed = efficient + bounce
            if effect_bp:
                # Planted at the events only, on the side the rule itself reads (P̂ class, or the sign of the observed first minutes),
                # after the decision: a ramp from arm_min to the exit.
                is_event = np.array([t in ev_set for t in instants.tolist()])
                if self.tspec.classifier is not None:
                    side = g5_eval.classify(s.p_hat(k0 + self.tspec.arm_min, self.tspec.classifier), self.tspec.classifier["high"], self.tspec.classifier["low"]).astype(np.float64)
                else:
                    a, b = self.tspec.sign["from_min"] - self.lo, self.tspec.sign["to_min"] - self.lo
                    side = np.sign(observed[:, b] - observed[:, a])
                ramp = np.clip((cols - self.tspec.arm_min) / (self.tspec.return_window[1] - self.tspec.arm_min), 0, 1)
                observed = observed + np.where(is_event, side, 0.0)[:, None] * effect_bp * ramp[None, :]
            close = 100.0 * np.exp(observed / 1e4)
            spread = rng.random((n, width)) * 2e-4
            prev = np.concatenate([close[:, :1], close[:, :-1]], axis=1)
            low, high = np.minimum(prev, close) * (1 - spread), np.maximum(prev, close) * (1 + spread)
            ks = (k0[:, None] + cols[None, :]).ravel()
            s.close[ks], s.low[ks], s.high[ks] = close.ravel(), low.ravel(), high.ravel()

    def statistic(self) -> dict:
        ev = [g5_eval.measure(self.series[i], self.events[i], self.tspec, self.spec, "event") for i in UNIVERSE]
        ct = [g5_eval.measure(self.series[i], self.controls[i], self.tspec, self.spec, "control") for i in UNIVERSE]
        ev_ts, ev_v, _ = g5_eval.instant_means(ev, "net_bp")
        ct_ts, ct_v, _ = g5_eval.instant_means(ct, "net_bp")
        out = g5_eval.boot(self.spec, ev_ts, ev_v, ct_ts, ct_v)
        out["n_instants"], out["n_control_instants"] = int(ev_ts.size), int(ct_ts.size)
        return out


def ks_uniform(p: np.ndarray) -> float:
    p = np.sort(np.asarray(p, dtype=np.float64))
    n = p.size
    i = np.arange(1, n + 1)
    return float(max(np.max(i / n - p), np.max(p - (i - 1) / n)))


@pytest.fixture(scope="module")
def benches():
    spec = make_spec()
    return {"T01_funding_8h": Bench(spec, "T01_funding_8h", (-6, 15), seed=1), "T05_us_open": Bench(spec, "T05_us_open", (0, 60), seed=2)}


def test_h0_calibration_over_1000_seeds_lb90_rate_ks_and_holm_fwer(benches, tmp_path: Path):
    results = {name: {"p": [], "lb_pos": 0, "c1": 0, "D": []} for name in benches}
    fwer = 0
    for seed in range(SEEDS):
        pvalues = {}
        for j, (name, bench) in enumerate(benches.items()):
            bench.draw(10_000 + 2 * seed + j, H0_MODEL)
            st = bench.statistic()
            r = results[name]
            r["p"].append(st["p_one_sided"])
            r["D"].append(st["point"])
            r["lb_pos"] += int(st["lb90"] > 0)
            r["c1"] += int(st["point"] >= 10.0 and st["lb90"] > 0)
            pvalues[name] = st["p_one_sided"]
        fwer += int(bool(g5_eval.holm(pvalues, ALPHA)["confirmed"]))
    report = {"seeds": SEEDS, "resamples": RESAMPLES, "alpha": ALPHA, "mc_se": MC_SE, "bound": ALPHA + 3 * MC_SE, "ks_critical_1pct": KS_1PCT, "model": H0_MODEL,
              "holm_fwer": fwer / SEEDS, "types": {}}
    for name, r in results.items():
        p = np.array(r["p"])
        report["types"][name] = {"lb90_gt_0_rate": r["lb_pos"] / SEEDS, "c1_false_pass_rate": r["c1"] / SEEDS, "ks": ks_uniform(p), "mean_D": float(np.mean(r["D"])),
                                 "sd_D": float(np.std(r["D"])), "n_instants": benches[name].statistic()["n_instants"]}
        assert r["lb_pos"] / SEEDS <= ALPHA + 3 * MC_SE, (name, r["lb_pos"] / SEEDS)
        assert ks_uniform(p) <= KS_1PCT, (name, ks_uniform(p))
        assert r["c1"] / SEEDS <= ALPHA + 3 * MC_SE
    assert fwer / SEEDS <= ALPHA + 3 * MC_SE, fwer / SEEDS
    (tmp_path / "h0-calibration.json").write_text(json.dumps(report, indent=1))
    print(json.dumps(report))


def test_b2_non_regression_controls_at_the_minute_of_the_event_and_the_planted_power(benches, tmp_path: Path):
    """Quant B2: the burst and its bounce at minute 0, the events at minute 30 (T05): controls at minute 30 (the protocol) leave D
    unbiased; controls at minute 0 (the first draft; with the 00/08/16 grid that admits minute-0 controls) bias D towards the PASS."""
    seeds = 200

    def mean_se(bench, model, effect=0.0, offset=0):
        values = []
        for seed in range(seeds):
            bench.draw(50_000 + offset + seed, model, effect)
            values.append(bench.statistic()["point"])
        values = np.array(values)
        return float(values.mean()), float(values.std(ddof=1) / math.sqrt(values.size))

    same_minute = benches["T05_us_open"]
    mean30, se30 = mean_se(same_minute, B2_MODEL)
    assert abs(mean30) <= 3 * se30, (mean30, se30)

    def minute_zero(document):
        document["events"][1]["controls"].update({"minute_of_hour": 0, "default_settlement_grid_utc_hours": [0, 8, 16]})

    zero = Bench(make_spec(minute_zero), "T05_us_open", (0, 60), seed=2)
    assert all(int(c) % HOUR_US == 0 for i in UNIVERSE for c in zero.controls[i].tolist()) and sum(zero.controls[i].size for i in UNIVERSE) > 300
    mean0, se0 = mean_se(zero, B2_MODEL)
    assert mean0 > 3 * se0 and mean0 > mean30 + 3 * math.hypot(se0, se30), (mean0, se0, mean30, se30)
    # Power of the decision statistic for a planted effect at the events (reported, not asserted as a boolean), under the H0 model and
    # under the noisier design model; at +10 bp the point condition D >= 10 caps the C1 power near 1/2 (LP-G5 §7.4).
    power, runs = {}, 150
    for label, model in (("h0_model", H0_MODEL), ("design_model", POWER_MODEL)):
        for effect in (30.0, 10.0):
            for name, bench in benches.items():
                passed = lb = 0
                points = []
                for seed in range(runs):
                    bench.draw(70_000 + seed, model, effect_bp=effect)
                    st = bench.statistic()
                    points.append(st["point"])
                    lb += int(st["lb90"] > 0)
                    passed += int(st["point"] >= 10.0 and st["lb90"] > 0)
                power[f"{label}:{name}:+{effect:g}bp"] = {"c1_power": passed / runs, "lb90_gt_0_rate": lb / runs, "mean_D": float(np.mean(points)), "sd_D": float(np.std(points))}
                assert 0.0 <= passed / runs <= 1.0
                if effect == 10.0:
                    assert passed / runs <= 0.5 + 3 * math.sqrt(0.25 / runs), (label, name, passed / runs)
    report = {"seeds": seeds, "model": B2_MODEL, "minute_30_controls": {"mean_D": mean30, "se": se30}, "minute_0_controls": {"mean_D": mean0, "se": se0},
              "power_runs": runs, "power_models": {"h0_model": H0_MODEL, "design_model": POWER_MODEL}, "planted_power": power}
    (tmp_path / "b2-and-power.json").write_text(json.dumps(report, indent=1))
    print(json.dumps(report))
