from temporalio import activity
import subprocess

@activity.defn
async def fetch_contracts_activity() -> list:
    """Appelle la commande Symfony pour fetch les contrats et extrait les symboles."""
    result = subprocess.run(
        ["php", "bin/console", "app:bitmart:fetch-contracts"],
        capture_output=True,
        text=True
    )

    symbols = []
    for line in result.stdout.splitlines():
        if "Persisted contract:" in line:
            parts = line.strip().split(": ")
            if len(parts) == 2:
                symbols.append(parts[1])

    return list(sorted(set(symbols)))


@activity.defn
async def sync_symbol_activity(symbol: str):
    """Appelle la commande Symfony pour synchroniser un symbole spécifique."""
    subprocess.run(
        ["php", "bin/console", "bitmart:kline:sync-all", f"--symbol={symbol}"],
        check=True
    )
