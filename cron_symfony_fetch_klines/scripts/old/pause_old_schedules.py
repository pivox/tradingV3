
# scripts/pause_old_schedules.py
import os, sys, argparse, asyncio
from typing import List, Optional

from temporalio.client import Client

# Reuse env defaults (same as create_old_schedules.py)
TEMPORAL_ADDRESS   = os.getenv("TEMPORAL_ADDRESS", "temporal-grpc:7233")
TEMPORAL_NAMESPACE = os.getenv("TEMPORAL_NAMESPACE", "default")

# Optional import of the SCHEDULES dict from create_old_schedules.py to get known IDs
KNOWN_SCHEDULE_IDS = []
try:
    # The file is expected to be importable on PYTHONPATH; if not, we foldback to server listing.
    import importlib.util, pathlib
    current_dir = pathlib.Path(__file__).resolve().parent
    candidate = current_dir / "create_old_schedules.py"
    if candidate.exists():
        spec = importlib.util.spec_from_file_location("create_old_schedules", candidate)
        mod = importlib.util.module_from_spec(spec)  # type: ignore
        assert spec and spec.loader
        spec.loader.exec_module(mod)  # type: ignore
        if hasattr(mod, "SCHEDULES") and isinstance(mod.SCHEDULES, dict):
            for k, v in mod.SCHEDULES.items():
                sid = v.get("schedule_id")
                if sid:
                    KNOWN_SCHEDULE_IDS.append(str(sid))
except Exception as e:
    # Non-fatal
    pass

async def pause_or_resume_known(client: Client, *, pause: bool, filter_prefix: Optional[str], dry_run: bool) -> None:
    if not KNOWN_SCHEDULE_IDS:
        print("[info] Aucun schedule connu via create_old_schedules.py, on bascule en mode list...")
        await pause_or_resume_by_listing(client, pause=pause, filter_prefix=filter_prefix, dry_run=dry_run)
        return

    targets = [sid for sid in KNOWN_SCHEDULE_IDS if (not filter_prefix or sid.startswith(filter_prefix))]
    if not targets:
        print("[warn] Aucun schedule correspondant au filtre.")
        return

    for sid in targets:
        handle = client.get_schedule_handle(sid)
        if dry_run:
            print(f"[dry-run] {'PAUSE' if pause else 'RESUME'} -> {sid}")
            continue
        if pause:
            await handle.pause(note="bulk pause_old_schedules")
            print(f"[done] paused {sid}")
        else:
            await handle.unpause(note="bulk pause_old_schedules")
            print(f"[done] resumed {sid}")

async def pause_or_resume_by_listing(client: Client, *, pause: bool, filter_prefix: Optional[str], dry_run: bool) -> None:
    # List schedules from server (requires server & SDK support). Folds back silently if not available.
    try:
        async for item in client.list_schedules():
            sid = item.id  # type: ignore[attr-defined]
            if filter_prefix and not str(sid).startswith(filter_prefix):
                continue
            if dry_run:
                print(f"[dry-run] {'PAUSE' if pause else 'RESUME'} -> {sid}")
                continue
            handle = client.get_schedule_handle(str(sid))
            if pause:
                await handle.pause(note="bulk pause_old_schedules")
                print(f"[done] paused {sid}")
            else:
                await handle.unpause(note="bulk pause_old_schedules")
                print(f"[done] resumed {sid}")
    except AttributeError:
        # Older SDKs may not support list_schedules(); in that case, require known IDs.
        print("[error] Votre SDK ne supporte pas list_schedules(). Réessayez sans --list en vous basant sur KNOWN_SCHEDULE_IDS (import depuis create_old_schedules.py).")

async def main(args: argparse.Namespace) -> None:
    client = await Client.connect(TEMPORAL_ADDRESS, namespace=TEMPORAL_NAMESPACE)
    if args.list:
        await pause_or_resume_by_listing(client, pause=(not args.unpause), filter_prefix=args.filter, dry_run=args.dry_run)
    else:
        await pause_or_resume_known(client, pause=(not args.unpause), filter_prefix=args.filter, dry_run=args.dry_run)

def parse_args(argv: List[str]) -> argparse.Namespace:
    p = argparse.ArgumentParser(description="Mettre en PAUSE (par défaut) ou RESUME un lot de Schedules Temporal.")
    p.add_argument("--unpause", action="store_true", help="Au lieu de PAUSE, faire RESUME (unpause).")
    p.add_argument("--filter", type=str, default=None, help="Filtrer par préfixe d'ID (ex: 'cron-symfony-').")
    p.add_argument("--dry-run", action="store_true", help="Afficher ce qui serait fait sans exécuter.")
    p.add_argument("--list", action="store_true", help="Lister côté serveur et agir sur tous les schedules (au lieu d'utiliser la liste connue du fichier create_old_schedules.py).")
    return p.parse_args(argv)

if __name__ == "__main__":
    try:
        asyncio.run(main(parse_args(sys.argv[1:])))
    except KeyboardInterrupt:
        pass
