#!/usr/bin/env python3
"""
Script pour lancer et interagir avec le workflow ApiRateLimiterClient
Permet d'ajouter des requêtes API à la file d'attente et de surveiller le workflow
"""

import asyncio
import os
import sys
from typing import Dict, Any, Optional
from datetime import timedelta
from dotenv import load_dotenv

from temporalio.client import Client
from temporalio.common import RetryPolicy
from models.api_call_request import ApiCallRequest

# Chargement des variables d'environnement
load_dotenv()

class ApiRateLimiterClient:
    def __init__(self):
        self.temporal_address = os.getenv("TEMPORAL_ADDRESS", "temporal:7233")
        self.task_queue = os.getenv("TASK_QUEUE_NAME", "api_rate_limiter_queue")
        self.workflow_id = "api-rate-limiter-workflow"
        self.client: Optional[Client] = None

    async def connect(self):
        """Connexion au serveur Temporal"""
        try:
            self.client = await Client.connect(self.temporal_address)
            print(f"✅ Connecté à Temporal: {self.temporal_address}")
        except Exception as e:
            print(f"❌ Erreur de connexion à Temporal: {e}")
            sys.exit(1)

    async def start_workflow(self):
        """Démarre le workflow ApiRateLimiterClient"""
        if not self.client:
            await self.connect()
        
        try:
            # Vérifier si le workflow existe déjà
            try:
                handle = self.client.get_workflow_handle(self.workflow_id)
                await handle.describe()
                print(f"✅ Workflow déjà en cours: {self.workflow_id}")
                return handle
            except:
                pass

            # Démarrer un nouveau workflow
            handle = await self.client.start_workflow(
                "ApiRateLimiterClient",
                id=self.workflow_id,
                task_queue=self.task_queue,
                retry_policy=RetryPolicy(
                    initial_interval=timedelta(seconds=1),
                    maximum_interval=timedelta(seconds=10),
                    maximum_attempts=3,
                ),
            )
            print(f"✅ Workflow démarré: {self.workflow_id}")
            return handle
        except Exception as e:
            print(f"❌ Erreur lors du démarrage du workflow: {e}")
            sys.exit(1)

    async def add_api_request(self, uri: str, method: str = "GET", 
                            payload: Optional[Dict[str, Any]] = None,
                            headers: Optional[Dict[str, str]] = None,
                            timeout: int = 10):
        """Ajoute une requête API à la file d'attente"""
        if not self.client:
            await self.connect()
        
        try:
            handle = self.client.get_workflow_handle(self.workflow_id)
            
            # Créer la requête API
            api_request = ApiCallRequest(
                uri=uri,
                method=method,
                payload=payload,
                headers=headers,
                timeout=timeout
            )
            
            # Envoyer le signal pour ajouter à la file
            await handle.signal("enqueue", api_request)
            print(f"✅ Requête ajoutée à la file: {method} {uri}")
            
        except Exception as e:
            print(f"❌ Erreur lors de l'ajout de la requête: {e}")

    async def get_queue_size(self) -> int:
        """Récupère la taille actuelle de la file d'attente"""
        if not self.client:
            await self.connect()
        
        try:
            handle = self.client.get_workflow_handle(self.workflow_id)
            size = await handle.query("size")
            return size
        except Exception as e:
            print(f"❌ Erreur lors de la récupération de la taille: {e}")
            return -1

    async def close_workflow(self):
        """Ferme le workflow (arrête le traitement après vidage de la file)"""
        if not self.client:
            await self.connect()
        
        try:
            handle = self.client.get_workflow_handle(self.workflow_id)
            await handle.signal("close")
            print("✅ Signal de fermeture envoyé au workflow")
        except Exception as e:
            print(f"❌ Erreur lors de la fermeture: {e}")

async def main():
    """Fonction principale avec menu interactif"""
    client = ApiRateLimiterClient()
    
    print("🚀 API Rate Limiter - Gestionnaire de Workflow")
    print("=" * 50)
    
    # Démarrer le workflow
    await client.start_workflow()
    
    while True:
        print("\n📋 Menu:")
        print("1. Ajouter une requête API")
        print("2. Voir la taille de la file")
        print("3. Fermer le workflow")
        print("4. Quitter")
        
        choice = input("\nVotre choix (1-4): ").strip()
        
        if choice == "1":
            print("\n📤 Ajouter une requête API:")
            uri = input("URI (ex: https://api.bitmart.com/v2/contracts): ").strip()
            method = input("Méthode (GET/POST/PUT/DELETE) [GET]: ").strip().upper() or "GET"
            
            payload = None
            if method in ["POST", "PUT"]:
                payload_str = input("Payload JSON (optionnel): ").strip()
                if payload_str:
                    try:
                        import json
                        payload = json.loads(payload_str)
                    except json.JSONDecodeError:
                        print("❌ Format JSON invalide")
                        continue
            
            headers_str = input("Headers JSON (optionnel): ").strip()
            headers = None
            if headers_str:
                try:
                    import json
                    headers = json.loads(headers_str)
                except json.JSONDecodeError:
                    print("❌ Format JSON invalide")
                    continue
            
            timeout = input("Timeout en secondes [10]: ").strip()
            timeout = int(timeout) if timeout.isdigit() else 10
            
            await client.add_api_request(uri, method, payload, headers, timeout)
            
        elif choice == "2":
            size = await client.get_queue_size()
            if size >= 0:
                print(f"📊 Taille de la file d'attente: {size} requêtes")
            else:
                print("❌ Impossible de récupérer la taille")
                
        elif choice == "3":
            confirm = input("⚠️  Êtes-vous sûr de vouloir fermer le workflow? (y/N): ").strip().lower()
            if confirm == 'y':
                await client.close_workflow()
                print("✅ Workflow fermé")
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
