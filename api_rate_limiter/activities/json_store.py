# activities/json_store.py
from temporalio import activity
from typing import Dict, Any, Optional
import json, os, time, uuid, tempfile
from datetime import datetime

DEFAULT_PATH = os.getenv("RESULTS_JSON_PATH", "./data/results.json")
LOCK_SUFFIX = ".lock"

def _ensure_dir(path: str) -> None:
    os.makedirs(os.path.dirname(path), exist_ok=True)

def _acquire_lock(lock_path: str, timeout: float = 5.0, interval: float = 0.05) -> None:
    """
    Lock-file best-effort, portable (sans dépendances externes).
    Crée lock_path en mode exclusif; attend sinon jusqu'à timeout.
    """
    deadline = time.time() + timeout
    while True:
        try:
            # O_CREAT|O_EXCL garantit l'échec si le fichier existe déjà
            fd = os.open(lock_path, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o644)
            os.close(fd)
            return
        except FileExistsError:
            if time.time() > deadline:
                # on abandonne proprement (mieux: journaliser et réessayer côté caller)
                raise TimeoutError(f"Lock timeout: {lock_path}")
            time.sleep(interval)

def _release_lock(lock_path: str) -> None:
    try:
        os.unlink(lock_path)
    except FileNotFoundError:
        pass

def _atomic_write(path: str, data: bytes) -> None:
    """
    Écrit de façon atomique: fichier temporaire + rename (os.replace).
    """
    _ensure_dir(path)
    dir_name = os.path.dirname(path) or "."
    with tempfile.NamedTemporaryFile(prefix=".tmp-", dir=dir_name, delete=False) as tf:
        tmp_name = tf.name
        tf.write(data)
        tf.flush()
        os.fsync(tf.fileno())
    os.replace(tmp_name, path)

def _load_json_dict(path: str) -> Dict[str, Any]:
    if not os.path.exists(path):
        return {}
    with open(path, "rb") as f:
        raw = f.read()
        if not raw:
            return {}
        return json.loads(raw.decode("utf-8"))

def _dump_json_dict(obj: Dict[str, Any]) -> bytes:
    return (json.dumps(obj, ensure_ascii=False, separators=(",", ":"), sort_keys=True) + "\n").encode("utf-8")

def _resolve_path(path: Optional[str]) -> str:
    return path or DEFAULT_PATH

@activity.defn(name="store_result_json")
async def store_result_json(request_id: str, payload: Dict[str, Any], path: Optional[str] = None) -> None:
    """
    Stocke/écrase le résultat sous la clé request_id dans un JSON dict {request_id: payload}.
    Ajoute des métadonnées utiles.
    """
    p = _resolve_path(path)
    lock_path = p + LOCK_SUFFIX
    _acquire_lock(lock_path)
    try:
        data = _load_json_dict(p)
        # enrichissement minimal
        payload = {
            **payload,
            "_stored_at": datetime.utcnow().isoformat(timespec="seconds") + "Z",
            "_result_id": payload.get("_result_id") or str(uuid.uuid4()),
        }
        data[request_id] = payload
        _atomic_write(p, _dump_json_dict(data))
    finally:
        _release_lock(lock_path)

@activity.defn(name="fetch_result_json")
async def fetch_result_json(request_id: str, path: Optional[str] = None) -> Optional[Dict[str, Any]]:
    """
    Récupère le résultat par request_id (ou None s’il n’existe pas).
    """
    p = _resolve_path(path)
    # lecture sans lock: OK car on écrit de façon atomique
    data = _load_json_dict(p)
    return data.get(request_id)
