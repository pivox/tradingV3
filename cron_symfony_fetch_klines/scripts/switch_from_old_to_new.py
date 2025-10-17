import argparse, asyncio, sys
from types import SimpleNamespace

# Import direct des deux scripts existants
from scripts.old import pause_old_schedules
from scripts.new import resume_new_schedules

def parse_args(argv):
    p = argparse.ArgumentParser(
        description="Bascule: pause des anciens schedules puis resume des nouveaux."
    )
    p.add_argument("--filter", type=str, default=None, help="Filtrer par préfixe d'ID.")
    p.add_argument("--dry-run", action="store_true", help="Simulation sans exécution réelle.")
    p.add_argument("--list", action="store_true", help="Forcer usage list_schedules() dans les deux scripts.")
    p.add_argument("--skip-old", action="store_true", help="Ne pas pauser les anciens.")
    p.add_argument("--skip-new", action="store_true", help="Ne pas resume les nouveaux.")
    return p.parse_args(argv)

async def run_switch(args):
    exit_code = 0

    if not args.skip_old:
        print("[step] Pause des anciens schedules...")
        old_args = SimpleNamespace(
            unpause=False,  # on veut PAUSE
            filter=args.filter,
            dry_run=args.dry_run,
            list=args.list,
        )
        try:
            await pause_old_schedules.main(old_args)
            print("[ok] Anciens schedules pausés (ou simulés).")
        except Exception as e:
            print(f"[error] Échec pause anciens: {e}")
            exit_code = 1
    else:
        print("[skip] Pause anciens schedules ignorée (--skip-old).")

    if not args.skip_new:
        print("[step] Resume des nouveaux schedules...")
        new_args = SimpleNamespace(
            pause=False,   # on veut RESUME
            filter=args.filter,
            dry_run=args.dry_run,
            list=args.list,
        )
        try:
            await resume_new_schedules.main(new_args)
            print("[ok] Nouveaux schedules resumed (ou simulés).")
        except Exception as e:
            print(f"[error] Échec resume nouveaux: {e}")
            exit_code = 1
    else:
        print("[skip] Resume nouveaux schedules ignoré (--skip-new).")

    return exit_code

def main():
    args = parse_args(sys.argv[1:])
    try:
        code = asyncio.run(run_switch(args))
    except KeyboardInterrupt:
        print("\n[warn] Interrompu par l'utilisateur.")
        code = 130
    sys.exit(code)

if __name__ == "__main__":
    main()

