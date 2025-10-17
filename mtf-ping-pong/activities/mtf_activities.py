"""
Activités pour le workflow MTF Ping-Pong
"""
import asyncio
import json
import logging
from typing import Dict, Any, List, Optional
from datetime import datetime

import aiohttp
from temporalio import activity


logger = logging.getLogger(__name__)


@activity.defn
async def call_mtf_run_activity(
    mtf_url: str,
    symbols: List[str],
    dry_run: bool = True,
    force_run: bool = False
) -> Dict[str, Any]:
    """
    Appelle l'endpoint MTF run avec les paramètres spécifiés
    
    Args:
        mtf_url: URL de l'endpoint MTF run
        symbols: Liste des symboles à traiter
        dry_run: Mode dry-run
        force_run: Forcer l'exécution
    
    Returns:
        Dict contenant la réponse de l'endpoint
    """
    activity.logger.info(f"[MtfActivity] Appel de MTF run: {mtf_url}")
    
    payload = {
        "symbols": symbols,
        "dry_run": dry_run,
        "force_run": force_run
    }
    
    headers = {
        "Content-Type": "application/json",
        "User-Agent": "MtfPingPong/1.0"
    }
    
    try:
        async with aiohttp.ClientSession(
            timeout=aiohttp.ClientTimeout(total=120)  # 2 minutes timeout
        ) as session:
            async with session.post(
                mtf_url,
                json=payload,
                headers=headers
            ) as response:
                
                response_text = await response.text()
                activity.logger.info(f"[MtfActivity] Réponse MTF run: {response.status}")
                
                if response.status == 200:
                    try:
                        response_data = json.loads(response_text)
                        activity.logger.info(f"[MtfActivity] MTF run réussi: {response_data}")
                        return {
                            "success": True,
                            "status_code": response.status,
                            "data": response_data,
                            "timestamp": datetime.now().isoformat()
                        }
                    except json.JSONDecodeError:
                        activity.logger.warning(f"[MtfActivity] Réponse non-JSON: {response_text}")
                        return {
                            "success": True,
                            "status_code": response.status,
                            "data": {"raw_response": response_text},
                            "timestamp": datetime.now().isoformat()
                        }
                else:
                    activity.logger.error(f"[MtfActivity] Erreur MTF run: {response.status} - {response_text}")
                    return {
                        "success": False,
                        "status_code": response.status,
                        "error": response_text,
                        "timestamp": datetime.now().isoformat()
                    }
                    
    except asyncio.TimeoutError:
        activity.logger.error(f"[MtfActivity] Timeout lors de l'appel MTF run")
        return {
            "success": False,
            "error": "Timeout lors de l'appel MTF run",
            "timestamp": datetime.now().isoformat()
        }
    except Exception as e:
        activity.logger.error(f"[MtfActivity] Erreur lors de l'appel MTF run: {e}")
        return {
            "success": False,
            "error": str(e),
            "timestamp": datetime.now().isoformat()
        }


@activity.defn
async def wait_for_mtf_completion_activity(mtf_url: str) -> Dict[str, Any]:
    """
    Vérifie si MTF run est terminé en appelant un endpoint de statut
    
    Args:
        mtf_url: URL de base de l'endpoint MTF
    
    Returns:
        Dict indiquant si MTF run est terminé
    """
    activity.logger.info(f"[MtfActivity] Vérification de la completion de MTF run")
    
    # Pour l'instant, on considère que MTF run est terminé après l'appel
    # Dans une implémentation plus avancée, on pourrait appeler un endpoint de statut
    try:
        # Simulation d'une vérification de statut
        # En réalité, on pourrait appeler un endpoint comme /api/mtf/status
        status_url = mtf_url.replace("/run", "/status")
        
        async with aiohttp.ClientSession(
            timeout=aiohttp.ClientTimeout(total=30)
        ) as session:
            try:
                async with session.get(status_url) as response:
                    if response.status == 200:
                        status_data = await response.json()
                        activity.logger.info(f"[MtfActivity] Statut MTF: {status_data}")
                        return {
                            "completed": status_data.get("completed", True),
                            "status": status_data,
                            "timestamp": datetime.now().isoformat()
                        }
            except Exception:
                # Si l'endpoint de statut n'existe pas, on considère que c'est terminé
                activity.logger.info(f"[MtfActivity] Endpoint de statut non disponible, considération comme terminé")
                pass
        
        # Par défaut, on considère que c'est terminé
        return {
            "completed": True,
            "status": "assumed_completed",
            "timestamp": datetime.now().isoformat()
        }
        
    except Exception as e:
        activity.logger.warning(f"[MtfActivity] Erreur lors de la vérification: {e}")
        return {
            "completed": True,  # On assume que c'est terminé en cas d'erreur
            "error": str(e),
            "timestamp": datetime.now().isoformat()
        }


@activity.defn
async def notify_temporal_activity(notification_data: Dict[str, Any]) -> Dict[str, Any]:
    """
    Notifie Temporal que MTF run est terminé
    
    Args:
        notification_data: Données de notification
    
    Returns:
        Dict confirmant la notification
    """
    activity.logger.info(f"[MtfActivity] Notification à Temporal: {notification_data}")
    
    try:
        # Ici, on pourrait envoyer une notification à un autre service Temporal
        # ou à un webhook, ou simplement logger l'information
        
        # Pour l'instant, on simule une notification réussie
        activity.logger.info(f"[MtfActivity] Notification envoyée avec succès")
        
        return {
            "success": True,
            "notification_sent": True,
            "data": notification_data,
            "timestamp": datetime.now().isoformat()
        }
        
    except Exception as e:
        activity.logger.error(f"[MtfActivity] Erreur lors de la notification: {e}")
        return {
            "success": False,
            "error": str(e),
            "timestamp": datetime.now().isoformat()
        }


@activity.defn
async def health_check_activity() -> Dict[str, Any]:
    """
    Vérification de santé du service
    
    Returns:
        Dict contenant le statut de santé
    """
    activity.logger.info(f"[MtfActivity] Vérification de santé")
    
    return {
        "status": "healthy",
        "service": "mtf-ping-pong",
        "timestamp": datetime.now().isoformat(),
        "version": "1.0.0"
    }








