"""Workflow cron minimal vers l'orchestrateur Python (TM-001).

``OrchestratorCronWorkflow`` est un déclencheur cron supervisé basique : il
exécute l'unique activity ``orchestrator_run`` (un seul appel HTTP vers
``POST /orchestrator/run``), journalise ``run_id`` + ``summary`` et retourne le
``RunResponse`` tel quel.

Il NE reconstruit AUCUNE logique métier : pas de sélection de contrats, pas de
jobs multiples, pas d'agrégation. Tout cela reste côté API Python (PY-005/006).

Déterminisme Temporal : aucune I/O ni ``datetime.now()`` dans le workflow. Le
``tick_timestamp`` transmis dans le ``RunRequest`` est dérivé via
``workflow.now()`` (horloge déterministe Temporal), pas ``datetime`` direct.

Hors-scope TM-001 : ne PAS lever d'exception sur ``ok=false`` (c'est TM-002).
"""

from __future__ import annotations

from datetime import timedelta
from typing import Any, Dict, Optional

from temporalio import workflow

# URL réseau interne par défaut de l'orchestrateur (container python_orchestrator,
# port 8099, profile compose ``orchestrator``). Surchargeable via la config du
# schedule (clé ``url``).
DEFAULT_ORCHESTRATOR_URL = "http://python-orchestrator:8099/orchestrator/run"


@workflow.defn(name="OrchestratorCronWorkflow")
class OrchestratorCronWorkflow:
    @workflow.run
    async def run(self, config: Optional[Dict[str, Any]] = None) -> Dict[str, Any]:
        """Exécute l'unique activity orchestrator et retourne son ``RunResponse``.

        Args:
            config: Config du tick (transmise par le schedule). Clés :
                - ``url`` : URL de l'orchestrateur (défaut
                  ``DEFAULT_ORCHESTRATOR_URL``) ;
                - ``dashboard_id`` : dashboard d'origine (RunRequest) ;
                - ``schedule_id`` : schedule Temporal d'origine (RunRequest) ;
                - ``idempotency_key`` : clé d'idempotence explicite (optionnel) ;
                - ``dry_run`` : forçage dry-run demandé par l'appelant (optionnel) ;
                - ``timeout_minutes`` : timeout de l'activity (défaut 15).

        Returns:
            Le ``RunResponse`` retourné par l'activity (``{ok, run_id, status,
            summary}``), propagé tel quel.
        """
        config = config or {}
        url = config.get("url") or DEFAULT_ORCHESTRATOR_URL

        # Construit le RunRequest minimal. Le tick_timestamp est dérivé de
        # l'horloge déterministe Temporal (workflow.now()), jamais de
        # datetime.now() — sinon le workflow ne serait pas rejouable.
        request: Dict[str, Any] = {
            "tick_timestamp": workflow.now().isoformat(),
        }
        if config.get("dashboard_id") is not None:
            request["dashboard_id"] = config["dashboard_id"]
        if config.get("schedule_id") is not None:
            request["schedule_id"] = config["schedule_id"]
        # Idempotence triviale (TM-001) : on transmet la clé seulement si
        # fournie. La vraie idempotence est SAFE-002.
        if config.get("idempotency_key") is not None:
            request["idempotency_key"] = config["idempotency_key"]
        if config.get("dry_run") is not None:
            request["dry_run"] = config["dry_run"]

        timeout_minutes = int(config.get("timeout_minutes", 15))

        workflow.logger.info(
            "[OrchestratorCron] calling %s with request=%s (timeout=%d min)",
            url,
            request,
            timeout_minutes,
        )

        result: Dict[str, Any] = await workflow.execute_activity(
            "orchestrator_run",
            args=[url, request],
            start_to_close_timeout=timedelta(minutes=timeout_minutes),
        )

        # Journalise run_id + summary (le JSON complet reste côté API Python).
        # On ne lève pas sur ok=false ici (TM-002) : on propage le RunResponse.
        workflow.logger.info(
            "[OrchestratorCron] ✅ ok=%s run_id=%s status=%s summary=%s",
            result.get("ok"),
            result.get("run_id"),
            result.get("status"),
            result.get("summary"),
        )

        return result
