#!/usr/bin/env python3
import os
import time
import logging
import subprocess
from configparser import ConfigParser
from datetime import datetime
from pathlib import Path
from zoneinfo import ZoneInfo

TZ = ZoneInfo("Europe/Paris")
DC_BIN = os.getenv("DC_BIN", "docker-compose")
PHP_SVC = os.getenv("PHP_SERVICE", "php")
CONSOLE = os.getenv("SF_CONSOLE", "bin/console")
CMD_NAME = os.getenv("SF_CMD", "mtf:tick")
EXTRA_OPTS = os.getenv("SF_OPTS", "")
WORKDIR = Path(os.getenv("WORKDIR", Path(__file__).resolve().parents[1]))
INI_PATH = Path(os.getenv("MTF_LOOP_INI", Path(__file__).with_suffix(".ini")))

TIMEOUT = {
    "4h": 900,
    "1h": 900,
    "15m": 900,
    "5m": 900,
    "1m": 900,
}

PRIO = ["4h", "1h", "15m", "5m", "1m"]

logging.basicConfig(level=os.getenv("LOG_LEVEL", "INFO").upper(),
                    format="%(asctime)s | %(levelname)s | %(message)s")
log = logging.getLogger("mtf-loop")


def ensure_ini() -> None:
    if INI_PATH.exists():
        return
    cfg = ConfigParser()
    cfg["control"] = {"stop": "false"}
    INI_PATH.write_text("[control]\nstop = false\n", encoding="utf-8")


def should_stop() -> bool:
    cfg = ConfigParser()
    try:
        cfg.read(INI_PATH, encoding="utf-8")
        return cfg.getboolean("control", "stop", fallback=False)
    except Exception:
        return False


def is_aligned(tf: str, now: datetime) -> bool:
    m = now.minute
    if tf == "4h":
        return m == 0 and now.hour % 4 == 0
    if tf == "1h":
        return m == 0
    if tf == "15m":
        return m in (0, 15, 30, 45)
    if tf == "5m":
        return m % 5 == 0
    if tf == "1m":
        return True
    return False


def slot_key(tf: str, now: datetime) -> str:
    if tf == "4h":
        h = now.hour - (now.hour % 4)
        base = now.replace(hour=h, minute=0, second=0, microsecond=0)
    elif tf == "1h":
        base = now.replace(minute=0, second=0, microsecond=0)
    elif tf == "15m":
        base = now.replace(minute=(now.minute // 15) * 15, second=0, microsecond=0)
    elif tf == "5m":
        base = now.replace(minute=(now.minute // 5) * 5, second=0, microsecond=0)
    else:
        base = now.replace(second=0, microsecond=0)
    return f"{tf}|{base.isoformat()}"


def run_sf(tf: str) -> bool:
    cmd = [DC_BIN, "exec", "-T", PHP_SVC, "php", CONSOLE, CMD_NAME, f"--tf={tf}"]
    if EXTRA_OPTS.strip():
        cmd.extend(EXTRA_OPTS.strip().split())

    try:
        res = subprocess.run(cmd,
                             cwd=str(WORKDIR),
                             capture_output=True,
                             text=True,
                             timeout=TIMEOUT.get(tf, 900))
        if res.returncode == 0:
            log.info("OK tf=%s | %s", tf, (res.stdout or "").strip()[:400])
            return True
        log.warning("KO tf=%s | code=%s | err=%s", tf, res.returncode, (res.stderr or "").strip()[:400])
    except subprocess.TimeoutExpired:
        log.error("TIMEOUT tf=%s (>%ss)", tf, TIMEOUT.get(tf, 900))
    except Exception as exc:
        log.exception("Erreur exécution tf=%s: %s", tf, exc)
    return False


def main() -> None:
    ensure_ini()
    last_done = {tf: None for tf in PRIO}
    log.info("Boucle MTF démarrée. Modifie %s pour arrêter.", INI_PATH)

    while True:
        if should_stop():
            log.info("Flag d'arrêt détecté. Arrêt de la boucle.")
            break

        now = datetime.now(TZ)
        eligible = [tf for tf in PRIO if is_aligned(tf, now)]
        if eligible:
            tf = eligible[0]
            key = slot_key(tf, now)
            if last_done[tf] != key:
                log.info("==> RUN tf=%s | slot=%s", tf, key)
                run_sf(tf)
                last_done[tf] = key

        time.sleep(0.2)


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        log.info("Arrêt demandé par CTRL+C.")
