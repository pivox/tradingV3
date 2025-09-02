from typing import Callable, Dict, Any

REGISTRY: Dict[str, Callable[..., Any]] = {}

def register(name: str, fn):
    REGISTRY[name] = fn

def get(name: str):
    return REGISTRY.get(name)
