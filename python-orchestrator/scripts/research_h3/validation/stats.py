"""Pure-Python statistics for the H3 validation framework (floats, deterministic, no numpy or scipy).

Why not reuse the existing helpers: ``scripts/research_h2/stats.py`` (month-block bootstrap) and
``scripts/research_lev/stats.py`` (cluster bootstrap, Holm) import numpy at module level and are specialised to their
protocols' observation shapes; the H3 package must run without numpy. ``holm`` below is the same step-down rule as
``research_lev.stats.holm`` (a test cross-checks them when numpy is installed).

Precision. Accounting stays in Decimal in ``records`` and ``metrics``; statistics convert to float (53-bit binary)
here. Bootstrap draws use ``random.Random(seed)``, so a result depends only on its inputs and seed.

Procedures for a one-sided test of "mean > 0" on a dependent series (``one_sided_test``):
- ``cbb_percentile``: circular block bootstrap of the mean (Politis and Romano 1992), block length round(n^(1/3)),
  percentile p = (1 + #{resampled mean <= 0}) / (B + 1). The synthetic power study 1 found it anti-conservative under
  persistent block dependence at 13 to 52 blocks (it is kept for reproduction and descriptive use).
- ``sn_gaussian``: self-normalised statistic (Lobato 2001; Shao 2010), calibrated on the i.i.d. Gaussian null of the
  same length (seeded simulation, 20,000 draws, cached per process); no bandwidth or block length.
- ``batch_t``: batch-means t-test, max(3, round(sqrt(n))) consecutive batches, Student t with k - 1 degrees of freedom.
The null "mean <= 0" is rejected when p <= alpha.
"""
from __future__ import annotations

import bisect
import math
import random
from collections.abc import Mapping, Sequence
from dataclasses import dataclass


class StatsError(ValueError):
    """Invalid statistical input. The message is a stable reason code."""


def _finite(values: Sequence[float]) -> list[float]:
    out = [float(v) for v in values]
    if any(not math.isfinite(v) for v in out):
        raise StatsError('stats_non_finite_input')
    return out


def mean(values: Sequence[float]) -> float | None:
    values = _finite(values)
    return sum(values) / len(values) if values else None


def stdev(values: Sequence[float]) -> float | None:
    """Sample standard deviation (n - 1)."""
    values = _finite(values)
    if len(values) < 2:
        return None
    m = sum(values) / len(values)
    return math.sqrt(sum((v - m) ** 2 for v in values) / (len(values) - 1))


# ----------------------------------------------------------------------------------------------------- bootstrap

def block_length_rule(n: int) -> int:
    """DRAFT a-priori block length: round(n ** (1/3)), at least 1 (the usual n^(1/3) rate for block bootstraps)."""
    if n < 1:
        raise StatsError('stats_sample_empty')
    return max(1, int(round(n ** (1.0 / 3.0))))


def circular_block_bootstrap_means(values: Sequence[float], block_len: int, resamples: int, seed: int) -> list[float]:
    values = _finite(values)
    n = len(values)
    if n == 0:
        raise StatsError('stats_sample_empty')
    if block_len < 1 or block_len > n or resamples < 1:
        raise StatsError('stats_bootstrap_parameters_invalid')
    doubled = values + values
    prefix = [0.0]
    for v in doubled:
        prefix.append(prefix[-1] + v)
    full, rest = divmod(n, block_len)
    rng = random.Random(seed)
    draw = rng.random
    out = []
    for _ in range(resamples):
        total = 0.0
        for _ in range(full):
            start = int(draw() * n)
            total += prefix[start + block_len] - prefix[start]
        if rest:
            start = int(draw() * n)
            total += prefix[start + rest] - prefix[start]
        out.append(total / n)
    return out


@dataclass(frozen=True)
class OneSidedResult:
    """One-sided test of "mean > 0" (``procedure``: see ``one_sided_test``)."""

    estimate: float | None
    lower_bound: float | None
    p_value: float | None
    alpha: float
    n: int
    block_len: int
    resamples: int
    seed: int
    rejected: bool
    reason: str
    procedure: str = 'cbb_percentile'

    def as_dict(self) -> dict:
        return dict(self.__dict__)


def one_sided_bootstrap(values: Sequence[float], *, alpha: float, resamples: int, seed: int,
                        block_len: int | None = None, min_n: int = 2) -> OneSidedResult:
    """Reject "mean <= 0" when p <= alpha. Fewer than ``min_n`` observations: not tested (reason code)."""
    values = _finite(values)
    n = len(values)
    if not 0 < alpha < 1:
        raise StatsError('stats_alpha_invalid')
    if n < max(2, min_n):
        return OneSidedResult(mean(values) if values else None, None, None, alpha, n, 0, 0, seed, False,
                              'stats_insufficient_observations')
    length = block_length_rule(n) if block_len is None else block_len
    means = sorted(circular_block_bootstrap_means(values, length, resamples, seed))
    estimate = sum(values) / n
    at_or_below_zero = sum(1 for m in means if m <= 0.0)
    p = (1 + at_or_below_zero) / (resamples + 1)
    lower = means[min(len(means) - 1, max(0, int(math.floor(alpha * resamples))))]
    rejected = p <= alpha
    return OneSidedResult(estimate, lower, p, alpha, n, length, resamples, seed, rejected,
                          'stats_rejects_null' if rejected else 'stats_does_not_reject_null')


# ------------------------------------------------------------------------- dependence-robust alternatives (study 2)

def batch_count_rule(n: int) -> int:
    """DRAFT number of batches for the batch-means t-test: max(3, round(sqrt(n)))."""
    return max(3, int(round(math.sqrt(n))))


def batch_means(values: Sequence[float], batches: int) -> list[float]:
    """Means of ``batches`` consecutive batches of near-equal length."""
    values = _finite(values)
    n = len(values)
    if batches < 2 or batches > n:
        raise StatsError('stats_batches_invalid')
    out = []
    for i in range(batches):
        lo, hi = (i * n) // batches, ((i + 1) * n) // batches
        out.append(sum(values[lo:hi]) / (hi - lo))
    return out


def _betacf(a: float, b: float, x: float) -> float:
    tiny = 1e-300
    qab, qap, qam = a + b, a + 1.0, a - 1.0
    c, d = 1.0, 1.0 - qab * x / qap
    d = tiny if abs(d) < tiny else d
    d = 1.0 / d
    h = d
    for m in range(1, 10_000):
        m2 = 2 * m
        aa = m * (b - m) * x / ((qam + m2) * (a + m2))
        d = 1.0 + aa * d
        d = tiny if abs(d) < tiny else d
        c = 1.0 + aa / c
        c = tiny if abs(c) < tiny else c
        d = 1.0 / d
        h *= d * c
        aa = -(a + m) * (qab + m) * x / ((a + m2) * (qap + m2))
        d = 1.0 + aa * d
        d = tiny if abs(d) < tiny else d
        c = 1.0 + aa / c
        c = tiny if abs(c) < tiny else c
        d = 1.0 / d
        delta = d * c
        h *= delta
        if abs(delta - 1.0) < 1e-15:
            break
    return h


def regularized_beta(a: float, b: float, x: float) -> float:
    """I_x(a, b), the regularised incomplete beta function."""
    if not 0.0 <= x <= 1.0 or a <= 0 or b <= 0:
        raise StatsError('stats_beta_invalid')
    if x in (0.0, 1.0):
        return x
    front = math.exp(math.lgamma(a + b) - math.lgamma(a) - math.lgamma(b) + a * math.log(x) + b * math.log(1.0 - x))
    if x < (a + 1.0) / (a + b + 2.0):
        return front * _betacf(a, b, x) / a
    return 1.0 - front * _betacf(b, a, 1.0 - x) / b


def student_t_sf(t: float, df: float) -> float:
    """P(T > t) for Student's t with ``df`` degrees of freedom."""
    if df <= 0:
        raise StatsError('stats_df_invalid')
    tail = 0.5 * regularized_beta(df / 2.0, 0.5, df / (df + t * t))
    return tail if t >= 0 else 1.0 - tail


def student_t_ppf(p: float, df: float) -> float:
    """Inverse CDF of Student's t by bisection on ``student_t_sf`` (p in (0, 1))."""
    if not 0.0 < p < 1.0:
        raise StatsError('stats_probability_invalid')
    lo, hi = -1e3, 1e3
    for _ in range(200):
        mid = (lo + hi) / 2.0
        if 1.0 - student_t_sf(mid, df) < p:
            lo = mid
        else:
            hi = mid
    return (lo + hi) / 2.0


def batch_t_one_sided(values: Sequence[float], *, alpha: float, batches: int | None = None) -> OneSidedResult:
    """Batch-means t-test of "mean > 0": k consecutive batch means, one-sample t with k - 1 degrees of freedom."""
    values = _finite(values)
    n = len(values)
    k = batch_count_rule(n) if batches is None else batches
    if n < max(3, k):
        return OneSidedResult(mean(values) if values else None, None, None, alpha, n, 0, 0, 0, False,
                              'stats_insufficient_observations', 'batch_t')
    bm = batch_means(values, k)
    m = sum(bm) / k
    sd = stdev(bm) or 0.0
    if sd == 0.0:
        p = 0.0 if m > 0 else 1.0
        lower = m
    else:
        se = sd / math.sqrt(k)
        p = student_t_sf(m / se, k - 1)
        lower = m - student_t_ppf(1.0 - alpha, k - 1) * se
    rejected = p <= alpha
    return OneSidedResult(sum(values) / n, lower, p, alpha, n, n // k, 0, 0, rejected,
                          'stats_rejects_null' if rejected else 'stats_does_not_reject_null', 'batch_t')


def sn_statistic(values: Sequence[float]) -> tuple[float, float] | None:
    """Self-normalised statistic (Lobato 2001; Shao 2010): T = sqrt(n) mean / sqrt(V), V = n^-2 sum S_t^2 with S_t
    the partial sums of (x - mean). Returns (T, sqrt(V / n)); None when V = 0."""
    values = _finite(values)
    n = len(values)
    if n < 2:
        return None
    m = sum(values) / n
    s = v = 0.0
    for x in values:
        s += x - m
        v += s * s
    v /= n * n
    if v <= 0.0:
        return None
    scale = math.sqrt(v / n)
    return m / scale, scale


SN_NULL_DRAWS = 20_000
SN_NULL_SEED = 20_261_015
_SN_NULL_CACHE: dict[tuple[int, int, int], list[float]] = {}


def sn_null_sample(n: int, draws: int = SN_NULL_DRAWS, seed: int = SN_NULL_SEED) -> list[float]:
    """Sorted null draws of the self-normalised statistic for i.i.d. Gaussian data of length ``n`` (it does not
    depend on the variance). Seeded and cached per process."""
    key = (n, draws, seed)
    if key not in _SN_NULL_CACHE:
        rng = random.Random(seed * 1_000 + n)
        gauss = rng.gauss
        out = []
        while len(out) < draws:
            result = sn_statistic([gauss(0.0, 1.0) for _ in range(n)])
            if result is not None:
                out.append(result[0])
        _SN_NULL_CACHE[key] = sorted(out)
    return _SN_NULL_CACHE[key]


def _upper_quantile(sorted_values: Sequence[float], level: float) -> float:
    index = min(len(sorted_values) - 1, max(0, int(math.ceil(level * len(sorted_values))) - 1))
    return sorted_values[index]


def sn_one_sided(values: Sequence[float], *, alpha: float, null_draws: int = SN_NULL_DRAWS,
                 null_seed: int = SN_NULL_SEED) -> OneSidedResult:
    """Self-normalised one-sided test of "mean > 0", calibrated on the Gaussian i.i.d. null of the same length:
    p = (1 + #{T_null >= T}) / (M + 1)."""
    values = _finite(values)
    n = len(values)
    result = sn_statistic(values) if n >= 3 else None
    if result is None:
        estimate = mean(values) if values else None
        degenerate_positive = n >= 3 and estimate is not None and estimate > 0
        return OneSidedResult(estimate, estimate if degenerate_positive else None, 0.0 if degenerate_positive else None,
                              alpha, n, 0, 0, null_seed, degenerate_positive,
                              'stats_rejects_null' if degenerate_positive else 'stats_insufficient_observations',
                              'sn_gaussian')
    t, scale = result
    null = sn_null_sample(n, null_draws, null_seed)
    at_or_above = len(null) - bisect.bisect_left(null, t)
    p = (1 + at_or_above) / (len(null) + 1)
    lower = (sum(values) / n) - _upper_quantile(null, 1.0 - alpha) * scale
    rejected = p <= alpha
    return OneSidedResult(sum(values) / n, lower, p, alpha, n, 0, len(null), null_seed, rejected,
                          'stats_rejects_null' if rejected else 'stats_does_not_reject_null', 'sn_gaussian')


PROCEDURES = ('cbb_percentile', 'sn_gaussian', 'batch_t')


def one_sided_test(values: Sequence[float], *, procedure: str, alpha: float, resamples: int = 9_999, seed: int = 0,
                   block_len: int | None = None) -> OneSidedResult:
    """Dispatch to the declared procedure."""
    if procedure == 'cbb_percentile':
        return one_sided_bootstrap(values, alpha=alpha, resamples=resamples, seed=seed, block_len=block_len)
    if procedure == 'sn_gaussian':
        return sn_one_sided(values, alpha=alpha)
    if procedure == 'batch_t':
        return batch_t_one_sided(values, alpha=alpha)
    raise StatsError('stats_procedure_unknown')


def two_sided_interval(values: Sequence[float], *, procedure: str, level: float = 0.90, resamples: int = 9_999,
                       seed: int = 0, block_len: int | None = None) -> list[float] | None:
    """Two-sided interval of the mean under the declared procedure (descriptive comparisons)."""
    values = _finite(values)
    n = len(values)
    tail = (1.0 - level) / 2.0
    if n < 3:
        return None
    m = sum(values) / n
    if procedure == 'cbb_percentile':
        means = sorted(circular_block_bootstrap_means(values, block_len or block_length_rule(n), resamples, seed))
        return [means[int(tail * (len(means) - 1))], means[int((1.0 - tail) * (len(means) - 1))]]
    if procedure == 'sn_gaussian':
        result = sn_statistic(values)
        if result is None:
            return [m, m]
        c = _upper_quantile(sn_null_sample(n), 1.0 - tail)
        return [m - c * result[1], m + c * result[1]]
    if procedure == 'batch_t':
        k = batch_count_rule(n)
        bm = batch_means(values, k)
        se = (stdev(bm) or 0.0) / math.sqrt(k)
        c = student_t_ppf(1.0 - tail, k - 1)
        centre = sum(bm) / k
        return [centre - c * se, centre + c * se]
    raise StatsError('stats_procedure_unknown')


# ------------------------------------------------------------------------------------------------- multiplicity

def holm(pvalues: Mapping[str, float | None]) -> dict[str, float]:
    """Holm step-down adjusted p-values; a test that could not run counts as p = 1."""
    items = sorted((1.0 if p is None else float(p), name) for name, p in pvalues.items())
    m = len(items)
    out: dict[str, float] = {}
    running = 0.0
    for k, (p, name) in enumerate(items):
        running = max(running, min(1.0, (m - k) * p))
        out[name] = running
    return out


def sidak_alpha(alpha: float, trials: int) -> float:
    """Per-trial level keeping the family-wise error at ``alpha`` over ``trials`` independent trials."""
    if trials < 1 or not 0 < alpha < 1:
        raise StatsError('stats_sidak_invalid')
    return 1.0 - (1.0 - alpha) ** (1.0 / trials)


# ------------------------------------------------------------------------------------------------------ ranking

def average_ranks(values: Sequence[float]) -> list[float]:
    """1-based ranks; ties share the average of their positions."""
    values = _finite(values)
    order = sorted(range(len(values)), key=lambda i: values[i])
    ranks = [0.0] * len(values)
    i = 0
    while i < len(order):
        j = i
        while j + 1 < len(order) and values[order[j + 1]] == values[order[i]]:
            j += 1
        rank = (i + j) / 2.0 + 1.0
        for k in range(i, j + 1):
            ranks[order[k]] = rank
        i = j + 1
    return ranks


def pearson(x: Sequence[float], y: Sequence[float]) -> float | None:
    x, y = _finite(x), _finite(y)
    if len(x) != len(y):
        raise StatsError('stats_length_mismatch')
    n = len(x)
    if n < 3:
        return None
    mx, my = sum(x) / n, sum(y) / n
    sxx = sum((a - mx) ** 2 for a in x)
    syy = sum((b - my) ** 2 for b in y)
    if sxx == 0 or syy == 0:
        return None
    return sum((a - mx) * (b - my) for a, b in zip(x, y)) / math.sqrt(sxx * syy)


def spearman(x: Sequence[float], y: Sequence[float]) -> float | None:
    """Spearman rank correlation (Pearson on average ranks). None below 3 pairs or without variance."""
    if len(x) != len(y):
        raise StatsError('stats_length_mismatch')
    if len(x) < 3:
        return None
    return pearson(average_ranks(x), average_ranks(y))


# --------------------------------------------------------------------------------------------------- dependence

def autocorrelation(values: Sequence[float], lag: int) -> float | None:
    values = _finite(values)
    n = len(values)
    if lag < 1 or n <= lag + 1:
        return None
    m = sum(values) / n
    denominator = sum((v - m) ** 2 for v in values)
    if denominator == 0:
        return None
    return sum((values[i] - m) * (values[i + lag] - m) for i in range(n - lag)) / denominator


def effective_sample_size(values: Sequence[float], max_lag: int | None = None) -> float | None:
    """n / (1 + 2 sum_k (1 - k/n) rho_k), summing lags while rho_k > 0 (initial positive sequence); in [1, n]."""
    values = _finite(values)
    n = len(values)
    if n < 3:
        return float(n) if n else None
    limit = n // 2 if max_lag is None else min(max_lag, n - 2)
    total = 0.0
    for lag in range(1, limit + 1):
        rho = autocorrelation(values, lag)
        if rho is None or rho <= 0:
            break
        total += (1.0 - lag / n) * rho
    return max(1.0, min(float(n), n / (1.0 + 2.0 * total)))


def ljung_box(values: Sequence[float], lags: int) -> tuple[float, float] | None:
    """Ljung-Box Q over ``lags`` lags and its chi-square(lags) p-value; None when too short."""
    values = _finite(values)
    n = len(values)
    if lags < 1 or n <= lags + 2:
        return None
    q = 0.0
    for lag in range(1, lags + 1):
        rho = autocorrelation(values, lag)
        if rho is None:
            return None
        q += rho * rho / (n - lag)
    q *= n * (n + 2)
    return q, chi2_sf(q, lags)


def intraclass_correlation(groups: Sequence[Sequence[float]]) -> float | None:
    """One-way ANOVA ICC(1) of observations clustered in ``groups`` (e.g. trades by time block); may be < 0."""
    groups = [_finite(g) for g in groups if len(g) > 0]
    k = len(groups)
    n = sum(len(g) for g in groups)
    if k < 2 or n <= k:
        return None
    grand = sum(sum(g) for g in groups) / n
    ssb = sum(len(g) * (sum(g) / len(g) - grand) ** 2 for g in groups)
    ssw = sum(sum((v - sum(g) / len(g)) ** 2 for v in g) for g in groups)
    msb, msw = ssb / (k - 1), ssw / (n - k)
    n0 = (n - sum(len(g) ** 2 for g in groups) / n) / (k - 1)
    denominator = msb + (n0 - 1) * msw
    if denominator == 0:
        return None
    return (msb - msw) / denominator


def design_effect(mean_cluster_size: float, icc: float) -> float:
    """Kish design effect 1 + (m - 1) * ICC, floored at 1 (a negative ICC never adds information)."""
    return max(1.0, 1.0 + (mean_cluster_size - 1.0) * icc)


def runs_test(signs: Sequence[bool]) -> tuple[float, float] | None:
    """Wald-Wolfowitz runs test on a chronological sequence of outcomes (True = win): (z, two-sided p)."""
    n1 = sum(1 for s in signs if s)
    n2 = len(signs) - n1
    if n1 == 0 or n2 == 0:
        return None
    runs = 1 + sum(1 for a, b in zip(signs, signs[1:]) if a != b)
    n = n1 + n2
    expected = 2.0 * n1 * n2 / n + 1.0
    variance = 2.0 * n1 * n2 * (2.0 * n1 * n2 - n) / (n * n * (n - 1.0))
    if variance <= 0:
        return None
    z = (runs - expected) / math.sqrt(variance)
    return z, 2.0 * normal_sf(abs(z))


def naive_iid_one_sided_p(values: Sequence[float]) -> float | None:
    """One-sided p of "mean > 0" treating every value as independent (normal approximation). Diagnostic only: this
    is the test the protocol forbids for correlated trades; ``power`` measures how wrong it is."""
    values = _finite(values)
    sd = stdev(values)
    if sd is None or sd == 0:
        return None
    return normal_sf((sum(values) / len(values)) / (sd / math.sqrt(len(values))))


# ------------------------------------------------------------------------------------------------ distributions

def normal_cdf(z: float) -> float:
    return 0.5 * math.erfc(-z / math.sqrt(2.0))


def normal_sf(z: float) -> float:
    return 0.5 * math.erfc(z / math.sqrt(2.0))


def normal_ppf(p: float) -> float:
    """Inverse standard normal CDF (Acklam's rational approximation refined by one Halley step)."""
    if not 0.0 < p < 1.0:
        raise StatsError('stats_probability_invalid')
    a = (-3.969683028665376e+01, 2.209460984245205e+02, -2.759285104469687e+02, 1.383577518672690e+02,
         -3.066479806614716e+01, 2.506628277459239e+00)
    b = (-5.447609879822406e+01, 1.615858368580409e+02, -1.556989798598866e+02, 6.680131188771972e+01,
         -1.328068155288572e+01)
    c = (-7.784894002430293e-03, -3.223964580411365e-01, -2.400758277161838e+00, -2.549732539343734e+00,
         4.374664141464968e+00, 2.938163982698783e+00)
    d = (7.784695709041462e-03, 3.224671290700398e-01, 2.445134137142996e+00, 3.754408661907416e+00)
    low = 0.02425
    if p < low:
        q = math.sqrt(-2.0 * math.log(p))
        x = (((((c[0] * q + c[1]) * q + c[2]) * q + c[3]) * q + c[4]) * q + c[5]) / \
            ((((d[0] * q + d[1]) * q + d[2]) * q + d[3]) * q + 1.0)
    elif p <= 1.0 - low:
        q = p - 0.5
        r = q * q
        x = (((((a[0] * r + a[1]) * r + a[2]) * r + a[3]) * r + a[4]) * r + a[5]) * q / \
            (((((b[0] * r + b[1]) * r + b[2]) * r + b[3]) * r + b[4]) * r + 1.0)
    else:
        q = math.sqrt(-2.0 * math.log(1.0 - p))
        x = -(((((c[0] * q + c[1]) * q + c[2]) * q + c[3]) * q + c[4]) * q + c[5]) / \
            ((((d[0] * q + d[1]) * q + d[2]) * q + d[3]) * q + 1.0)
    e = normal_cdf(x) - p
    u = e * math.sqrt(2.0 * math.pi) * math.exp(x * x / 2.0)
    return x - u / (1.0 + x * u / 2.0)


def _gamma_q(a: float, x: float) -> float:
    """Regularised upper incomplete gamma Q(a, x) (series below a + 1, continued fraction above)."""
    if x < 0 or a <= 0:
        raise StatsError('stats_gamma_invalid')
    if x == 0:
        return 1.0
    log_prefactor = -x + a * math.log(x) - math.lgamma(a)
    if x < a + 1.0:
        term = total = 1.0 / a
        ap = a
        for _ in range(10_000):
            ap += 1.0
            term *= x / ap
            total += term
            if abs(term) < abs(total) * 1e-15:
                break
        return max(0.0, 1.0 - total * math.exp(log_prefactor))
    tiny = 1e-300
    b = x + 1.0 - a
    c = 1.0 / tiny
    d = 1.0 / b
    h = d
    for i in range(1, 10_000):
        an = -i * (i - a)
        b += 2.0
        d = an * d + b
        d = tiny if abs(d) < tiny else d
        c = b + an / c
        c = tiny if abs(c) < tiny else c
        d = 1.0 / d
        delta = d * c
        h *= delta
        if abs(delta - 1.0) < 1e-15:
            break
    return min(1.0, math.exp(log_prefactor) * h)


def chi2_sf(x: float, k: int) -> float:
    """Survival function of the chi-square distribution with ``k`` degrees of freedom."""
    if k < 1:
        raise StatsError('stats_chi2_invalid')
    return _gamma_q(k / 2.0, x / 2.0)
