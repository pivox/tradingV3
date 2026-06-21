"""Workflow cron minimal vers l'orchestrateur Python (TM-001 / TM-002).

``OrchestratorCronWorkflow`` est un déclencheur cron supervisé basique : il
exécute l'unique activity ``orchestrator_run`` (un seul appel HTTP vers
``POST /orchestrator/run``), journalise ``run_id`` + ``summary``, puis :

- sur ``ok=true`` → retourne le ``RunResponse`` tel quel ;
- sur ``ok=false`` (``no_sets`` / ``failed`` / ``partial_failure`` / ``error``)
  → lève une ``ApplicationError`` pour que Temporal marque le tick en échec
  (TM-002). Sans cette levée, un échec métier ou un appel HTTP raté serait
  affiché « réussi » dans la Schedule view.

Il NE reconstruit AUCUNE logique métier : pas de sélection de contrats, pas de
jobs multiples, pas d'agrégation. Tout cela reste côté API Python (PY-005/006).
L'activity reste la source de vérité (« return verbatim ») : la levée vit
uniquement ici, dans le workflow.

Déterminisme Temporal : aucune I/O ni ``datetime.now()`` dans le workflow. Le
``tick_timestamp`` transmis dans le ``RunRequest`` est dérivé via
``workflow.now()`` (horloge déterministe Temporal), pas ``datetime`` direct. Le
log précède toujours la levée.
"""

from __future__ import annotations

from datetime import timedelta
from typing import Any, Dict, Optional

from temporalio import workflow
from temporalio.exceptions import ApplicationError

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
            summary}``), propagé tel quel lorsque ``ok=true``.

        Raises:
            ApplicationError: lorsque le ``RunResponse`` a ``ok=false`` (échec
                métier ou appel HTTP raté normalisé par l'activity). Marquée
                ``non_retryable`` : un tick ``ok=false`` ne doit pas être
                re-tenté en boucle dans le même tick — le prochain tick cron est
                le « retry » naturel (overlap ``BUFFER_ONE``).
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
        # Le log précède TOUJOURS la levée éventuelle (déterminisme + traçabilité
        # du tick en échec).
        ok = result.get("ok")
        run_id = result.get("run_id")
        status = result.get("status")
        summary = result.get("summary")
        workflow.logger.info(
            "[OrchestratorCron] %s ok=%s run_id=%s status=%s summary=%s",
            "✅" if ok else "❌",
            ok,
            run_id,
            status,
            summary,
        )

        # TM-002 : un RunResponse ok=false (no_sets / failed / partial_failure /
        # error) DOIT faire échouer le tick Temporal, sinon la Schedule view
        # l'affiche « réussi » et les échecs passent inaperçus. On lève après le
        # log, en réutilisant le RunResponse comme source de vérité (aucune
        # logique métier reconstruite).
        #
        # non_retryable=True : pas de retry en boucle dans le même tick. Le
        # prochain tick cron est le « retry » naturel (overlap BUFFER_ONE).
        if not ok:
            raise ApplicationError(
                f"orchestrator run failed: ok=false status={status} "
                f"run_id={run_id} summary={summary}",
                type="OrchestratorRunFailed",
                non_retryable=True,
            )

        return result
