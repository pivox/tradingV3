#!/usr/bin/env python3
"""
Script CLI pour interagir avec le workflow ApiRateLimiterClient
- Démarre le workflow si absent
- Enfile des requêtes via le signal `submit` au format {bucket: [envelope, ...]}
- Permet de consulter la taille de file via la query `queue_size`

Les enveloppes construites sont compatibles avec l’activité `post_callback`
et le contrôleur Symfony `/api/callback/bitmart/get-kline` (validation MTF).
"""

import asyncio
import os
import sys
from typing import Dict, Any, Optional
from datetime import timedelta

# dotenv optionnel pour tests locaux
try:
    from dotenv import load_dotenv
except Exception:  # pragma: no cover - fallback si non installé
    def load_dotenv(*args, **kwargs):
        return None

from temporalio.client import Client
from temporalio.common import RetryPolicy
from models.api_call_request import ApiCallRequest
from models.envelope_utils import build_kline_envelope, TF_TO_BUCKET

# Chargement des variables d'environnement
load_dotenv()

# Config par défaut
TEMPORAL_ADDRESS = os.getenv("TEMPORAL_ADDRESS", "temporal:7233")
TASK_QUEUE = os.getenv("TASK_QUEUE_NAME", "api_rate_limiter_queue")
WORKFLOW_ID = os.getenv("WORKFLOW_ID", "api-rate-limiter-workflow")


class RateLimiterCli:
    def __init__(self):
        self.client: Optional[Client] = None

    async def connect(self):
        try:
            self.client = await Client.connect(TEMPORAL_ADDRESS)
            print(f"✅ Connecté à Temporal: {TEMPORAL_ADDRESS}")
        except Exception as e:
            print(f"❌ Erreur de connexion à Temporal: {e}")
            sys.exit(1)

    async def ensure_workflow(self):
        assert self.client is not None
        try:
            handle = self.client.get_workflow_handle(WORKFLOW_ID)
            await handle.describe()
            print(f"✅ Workflow déjà en cours: {WORKFLOW_ID}")
            return handle
        except Exception:
            pass

        try:
            handle = await self.client.start_workflow(
                "ApiRateLimiterClient",
                id=WORKFLOW_ID,
                task_queue=TASK_QUEUE,
                retry_policy=RetryPolicy(
                    initial_interval=timedelta(seconds=1),
                    maximum_interval=timedelta(seconds=10),
                    maximum_attempts=3,
                ),
            )
            print(f"✅ Workflow démarré: {WORKFLOW_ID}")
            return handle
        except Exception as e:
            print(f"❌ Erreur lors du démarrage du workflow: {e}")
            sys.exit(1)

    async def enqueue_bucket_map(self, bucket_map: Dict[str, list[Dict[str, Any]]]):
        """Envoie le signal `submit` avec un map {bucket: [items...]}."""
        assert self.client is not None
        try:
            handle = self.client.get_workflow_handle(WORKFLOW_ID)
            # Validation minimale
            if not isinstance(bucket_map, dict) or not bucket_map:
                raise ValueError("bucket_map doit être un dict non vide")
            await handle.signal("submit", bucket_map)
            print(f"✅ {sum(len(v) for v in bucket_map.values())} requête(s) soumise(s) dans {len(bucket_map)} bucket(s)")
        except Exception as e:
            print(f"❌ Erreur lors de l'envoi du signal submit: {e}")

    async def queue_size(self) -> int:
        assert self.client is not None
        try:
            handle = self.client.get_workflow_handle(WORKFLOW_ID)
            size = await handle.query("queue_size")
            return int(size)
        except Exception as e:
            print(f"❌ Erreur lors de la récupération de la taille: {e}")
            return -1

    async def close_workflow(self):
        assert self.client is not None
        try:
            handle = self.client.get_workflow_handle(WORKFLOW_ID)
            await handle.signal("close")
            print("✅ Signal de fermeture envoyé au workflow")
        except Exception as e:
            print(f"❌ Erreur lors de la fermeture: {e}")


async def main():
    cli = RateLimiterCli()
    await cli.connect()
    await cli.ensure_workflow()

    while True:
        print("\n📋 Menu:")
        print("1. Enfiler un callback get-kline (signal + validation)")
        print("2. Voir la taille de la file")
        print("3. Fermer le workflow")
        print("4. Quitter")
        choice = input("\nVotre choix (1-4): ").strip()

        if choice == "1":
            symbol = input("Symbole contrat (ex: BTCUSDT): ").strip().upper()
            timeframe = input("Timeframe (4h/1h/15m/5m/1m) [5m]: ").strip().lower() or "5m"
            limit_str = input("Limit (nb bougies) [270]: ").strip()
            try:
                limit = int(limit_str) if limit_str else 270
            except ValueError:
                print("❌ Limit invalide, fallback 270")
                limit = 270

            bucket = TF_TO_BUCKET.get(timeframe, "regular")
            envelope = build_kline_envelope(symbol, timeframe, limit)

            # Utilise ApiCallRequest pour normaliser → activité
            api_req = ApiCallRequest.from_dict(envelope).to_activity_payload()

            await cli.enqueue_bucket_map({bucket: [api_req]})

        elif choice == "2":
            size = await cli.queue_size()
            if size >= 0:
                print(f"📊 Taille de la file d'attente: {size} requête(s)")
            else:
                print("❌ Impossible de récupérer la taille")

        elif choice == "3":
            confirm = input("⚠️  Fermer le workflow après vidage? (y/N): ").strip().lower()
            if confirm == "y":
                await cli.close_workflow()
                break

        elif choice == "4":
            print("👋 Au revoir!")
            break
        else:
            print("❌ Choix invalide")


if __name__ == "__main__":
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        print("\n👋 Arrêt demandé par l'utilisateur")
    except Exception as e:
        print(f"❌ Erreur inattendue: {e}")
        sys.exit(1)
