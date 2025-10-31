from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any, Dict, Iterable, List, Optional


def _to_bool(value: Any, default: bool) -> bool:
    if value is None:
        return default
    if isinstance(value, bool):
        return value
    if isinstance(value, (int, float)):
        return bool(value)
    if isinstance(value, str):
        lowered = value.strip().lower()
        if lowered in {"1", "true", "yes", "on"}:
            return True
        if lowered in {"0", "false", "no", "off"}:
            return False
    return default


def _normalize_symbols(raw: Any) -> Optional[List[str]]:
    if raw is None:
        return None
    if isinstance(raw, str):
        raw = raw.split(",")
    if isinstance(raw, Iterable):
        items = [str(item).strip() for item in raw if str(item).strip()]
        return items or None
    return None


@dataclass
class MtfJob:
    url: str
    workers: int = 5
    dry_run: bool = True
    force_run: bool = False
    force_timeframe_check: bool = False
    current_tf: Optional[str] = None
    symbols: Optional[List[str]] = field(default_factory=list)
    timeout_minutes: int = 15

    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> "MtfJob":
        url = str(data.get("url"))
        workers = int(data.get("workers", 5))
        dry_run = _to_bool(data.get("dry_run"), True)
        force_run = _to_bool(data.get("force_run"), False)
        force_timeframe_check = _to_bool(data.get("force_timeframe_check"), False)
        current_tf_raw = data.get("current_tf")
        current_tf = str(current_tf_raw) if current_tf_raw else None
        symbols = _normalize_symbols(data.get("symbols")) or []
        timeout_minutes = int(data.get("timeout_minutes", 15))

        return cls(
            url=url,
            workers=max(1, workers),
            dry_run=dry_run,
            force_run=force_run,
            force_timeframe_check=force_timeframe_check,
            current_tf=current_tf,
            symbols=symbols,
            timeout_minutes=max(1, timeout_minutes),
        )

    def payload(self) -> Dict[str, Any]:
        payload: Dict[str, Any] = {
            "workers": max(1, self.workers),
            "dry_run": self.dry_run,
            "force_run": self.force_run,
            "force_timeframe_check": self.force_timeframe_check,
        }

        if self.current_tf:
            payload["current_tf"] = self.current_tf
        if self.symbols:
            payload["symbols"] = self.symbols

        return payload
